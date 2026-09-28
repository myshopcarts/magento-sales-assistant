<?php
/**
 * KnowledgeIndexer — knowledge counterpart of CatalogIndexer (K2). Takes structure-
 * aware chunks (from MarkdownChunker or the backend /extract endpoint), batch-embeds
 * them via OUR /embed (search_document), and bulk-indexes into the tenant's LOCAL
 * knowledge index (Schema::ensureKnowledgeIndex). Re-ingest is idempotent per source:
 * delete-by-source_id then re-add.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Ingest;

use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRegistryInterface;
use Myshopcarts\SalesAssistant\Model\OpenSearch\ClientProvider;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Psr\Log\LoggerInterface;

class KnowledgeIndexer implements KnowledgeIndexInterface
{
    private const EMBED_BATCH = 90; // < Cohere embed-v4 per-call limit (96)

    /** @var array<int,array<string,mixed>> */
    private array $pending = [];
    private bool $vector = false;
    /** During a blue/green full rebuild, the fresh version being built into (null = live index). */
    private ?string $rebuildTarget = null;

    public function __construct(
        private readonly Schema $schema,
        private readonly ClientProvider $clients,
        private readonly SalesAssistantClient $client,
        private readonly LoggerInterface $logger,
        private readonly DocumentRegistryInterface $registry
    ) {
    }

    /**
     * Index knowledge chunks. Each chunk is a doc with an `id` plus the K1 fields.
     * @param iterable<array<string,mixed>> $chunks
     */
    public function index(iterable $chunks): int
    {
        // During a rebuild the target version already exists (vector capability set in beginRebuild);
        // otherwise ensure the live index exists and detect vectors.
        if ($this->rebuildTarget === null) {
            $this->vector = $this->schema->ensureKnowledgeIndex();
        }
        $count = 0;
        // Per-source stats derived from the chunks, so every indexed source gets a list row —
        // CMS pages included, not just uploads/crawls (the list must mirror the index).
        $sources = [];
        foreach ($chunks as $chunk) {
            $sid = (string)($chunk['source_id'] ?? '');
            if ($sid !== '') {
                if (!isset($sources[$sid])) {
                    $sources[$sid] = [
                        'title' => (string)($chunk['title'] ?? ''),
                        'url' => (string)($chunk['url'] ?? ''),
                        'content_hash' => (string)($chunk['content_hash'] ?? ''),
                        'count' => 0,
                    ];
                }
                $sources[$sid]['count']++;
            }
            $this->pending[] = $chunk;
            if (count($this->pending) >= self::EMBED_BATCH) {
                $count += $this->flush();
            }
        }
        $count += $this->flush();
        // Best-effort registration — a registry hiccup must not fail the ingest (the chunks are
        // already in OpenSearch); it only means the row is missing until the next reindex.
        foreach ($sources as $sid => $s) {
            try {
                $this->registry->registerIndexed(
                    $sid, $s['title'], $s['url'], $s['count'], $s['content_hash']
                );
            } catch (\Throwable $e) {
                $this->logger->warning('SalesAssistant: registerIndexed failed for ' . $sid . '. ' . $e->getMessage());
            }
        }
        return $count;
    }

    /**
     * Blue/green full rebuild (doc/sales-assistant-knowledge-reindex-v2.md §v2b). Create a fresh,
     * empty version and route every subsequent index() call into it. Then feed all sources and call
     * commitRebuild() to verify + swap the read alias, or abortRebuild() to discard on failure. The
     * live index keeps serving the whole time — a partial/failed build never reaches readers, and
     * because it is a fresh index there is no in-place delete-and-rebuild (no tombstone churn).
     */
    public function beginRebuild(): string
    {
        $this->rebuildTarget = $this->schema->buildKnowledgeVersion();
        $this->vector = $this->schema->hasVector($this->rebuildTarget);
        return $this->rebuildTarget;
    }

    /** Verify the freshly-built version and atomically publish it (alias swap + drop old). Returns
     *  false if promotion is refused (e.g. it would publish an empty index over a non-empty one) —
     *  in which case the half-built version is discarded and the current one keeps serving. */
    public function commitRebuild(): bool
    {
        if ($this->rebuildTarget === null) {
            return false;
        }
        $target = $this->rebuildTarget;
        $this->rebuildTarget = null; // stop routing writes into it before we touch the alias
        $promoted = $this->schema->promoteKnowledgeVersion($target);
        if (!$promoted) {
            try {
                $this->schema->deleteKnowledgeVersion($target);
            } catch (\Throwable $e) {
                $this->logger->warning('SalesAssistant: could not drop the un-promoted knowledge version ' . $target . '. ' . $e->getMessage());
            }
        }
        return $promoted;
    }

    /** Discard the in-progress version (build failed/interrupted); the live index is untouched. */
    public function abortRebuild(): void
    {
        if ($this->rebuildTarget === null) {
            return;
        }
        $target = $this->rebuildTarget;
        $this->rebuildTarget = null;
        try {
            $this->schema->deleteKnowledgeVersion($target);
        } catch (\Throwable $e) {
            $this->logger->warning('SalesAssistant: could not drop the aborted knowledge version ' . $target . '. ' . $e->getMessage());
        }
    }

    /**
     * Rebuild the document registry (the Knowledge documents list) from what's ALREADY in the
     * index — for sources indexed before the registry tracked them, so the list reflects the
     * index without re-crawling or re-uploading. Scans the knowledge index, aggregates chunks per
     * source_id (title/url/content_hash from the chunks, exactly like index()), and re-registers
     * each. insertOnDuplicate touches only the index-derived columns, so any existing priority /
     * uploaded markdown is preserved. Reads up to 10k chunks (OpenSearch's default result window).
     * @return array{sources:int,chunks:int}
     */
    public function backfillRegistry(): array
    {
        try {
            $resp = $this->clients->get()->search([
                'index' => $this->schema->knowledgeIndex(),
                'body' => [
                    'size' => 10000,
                    '_source' => ['source_id', 'title', 'url', 'content_hash'],
                    'query' => ['match_all' => (object)[]],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('SalesAssistant: backfillRegistry search failed. ' . $e->getMessage());
            return ['sources' => 0, 'chunks' => 0];
        }
        $hits = $resp['hits']['hits'] ?? [];
        $sources = [];
        foreach ($hits as $h) {
            $s = $h['_source'] ?? [];
            $sid = (string)($s['source_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            if (!isset($sources[$sid])) {
                $sources[$sid] = [
                    'title' => (string)($s['title'] ?? ''),
                    'url' => (string)($s['url'] ?? ''),
                    'content_hash' => (string)($s['content_hash'] ?? ''),
                    'count' => 0,
                ];
            }
            $sources[$sid]['count']++;
        }
        $registered = 0;
        foreach ($sources as $sid => $s) {
            try {
                $this->registry->registerIndexed($sid, $s['title'], $s['url'], $s['count'], $s['content_hash']);
                $registered++;
            } catch (\Throwable $e) {
                $this->logger->warning('SalesAssistant: backfill registerIndexed failed for ' . $sid . '. ' . $e->getMessage());
            }
        }
        return ['sources' => $registered, 'chunks' => count($hits)];
    }

    /**
     * NON-DESTRUCTIVE re-index of a source: clear only its OpenSearch chunks and re-add the given
     * ones. The registry ROW (and its `markdown` — the durable source of truth for uploaded/crawled
     * docs) is deliberately LEFT INTACT: index() -> registerIndexed() upserts only the index-derived
     * columns, so markdown is preserved. Use this on a rebuild-from-source (Knowledge::executeFull).
     *
     * This exists because deleteSource() drops the registry row: the old reindex path
     * (deleteSource-then-index) stripped the markdown every reindex, and the re-register could not
     * restore it, so TWO reindexes silently purged the entire knowledge base. @return chunks indexed.
     */
    public function reindexSource(string $sourceId, iterable $chunks): int
    {
        $this->clearIndexChunks($sourceId);
        return $this->index($chunks);
    }

    /** Remove a source's chunks AND its registry row, so the list stays in lockstep with the index.
     *  Use for a genuine REMOVAL (source deleted, toggled off, "Clear") — NEVER for a rebuild, which
     *  must use reindexSource() so the durable markdown survives. */
    public function deleteSource(string $sourceId): void
    {
        $this->clearIndexChunks($sourceId);
        try {
            $this->registry->deleteBySourceId($sourceId);
        } catch (\Throwable $e) {
            // no row yet, or DB hiccup — the row will be reconciled on next index()
        }
    }

    /** Delete only this source's chunks from the OpenSearch index; the registry row is untouched. */
    private function clearIndexChunks(string $sourceId): void
    {
        try {
            $this->clients->get()->deleteByQuery([
                'index' => $this->schema->knowledgeIndex(),
                'body' => ['query' => ['term' => ['source_id' => $sourceId]]],
                'refresh' => true,
            ]);
        } catch (\Throwable $e) {
            // index may not exist yet, or nothing to delete — fine
        }
    }

    private function flush(): int
    {
        if (!$this->pending) {
            return 0;
        }
        $batch = $this->pending;
        $this->pending = [];

        $vectors = [];
        if ($this->vector) {
            try {
                $resp = $this->client->embed(array_column($batch, 'text'), 'search_document');
                $vectors = $resp['embeddings'] ?? [];
            } catch (\Throwable $e) {
                $this->logger->error('SalesAssistant knowledge embed failed; indexing lexical-only. ' . $e->getMessage());
                $vectors = [];
            }
        }

        $index = $this->rebuildTarget ?? $this->schema->knowledgeIndex();
        $bulk = [];
        foreach ($batch as $i => $item) {
            $id = (string)$item['id'];
            unset($item['id']);
            if (isset($vectors[$i]) && is_array($vectors[$i])) {
                $item['embedding'] = $vectors[$i];
            }
            $bulk[] = ['index' => ['_index' => $index, '_id' => $id]];
            $bulk[] = $item;
        }
        $res = $this->clients->get()->bulk(['body' => $bulk]);
        if (!empty($res['errors'])) {
            $this->logger->warning('SalesAssistant knowledge bulk index had item errors (see OpenSearch).');
        }
        return count($batch);
    }
}
