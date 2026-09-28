<?php
/**
 * KnowledgeIndexInterface — the "embed + index chunks into the local knowledge index" seam.
 * KnowledgeIndexer is the real implementation; the crawl ingestor depends on this interface
 * so it can be unit-tested without OpenSearch or the backend embed call.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Ingest;

interface KnowledgeIndexInterface
{
    /** Remove all chunks for a source AND its registry row — a genuine REMOVAL (source deleted,
     *  toggled off, "Clear"). A rebuild-from-source must NOT use this; use reindexSource. */
    public function deleteSource(string $sourceId): void;

    /** Non-destructively re-index a source: clear only its OpenSearch chunks and re-add the given
     *  ones, LEAVING the registry row (and its durable `markdown`) intact. Use this on a
     *  rebuild-from-source. @return count indexed. @param iterable<array<string,mixed>> $chunks */
    public function reindexSource(string $sourceId, iterable $chunks): int;

    /** Embed + index the chunks; @return count indexed. @param iterable<array<string,mixed>> $chunks */
    public function index(iterable $chunks): int;

    /** Blue/green full rebuild: start a fresh index version and route index() calls into it. Returns
     *  the version name. Pair with commitRebuild()/abortRebuild(). */
    public function beginRebuild(): string;

    /** Verify + atomically publish the freshly-built version (alias swap, drop old); false if
     *  refused (e.g. empty over a non-empty current index), in which case it is discarded. */
    public function commitRebuild(): bool;

    /** Discard the in-progress rebuild version; the live index is untouched. */
    public function abortRebuild(): void;

    /** Rebuild the document registry from what's already in the index (for sources indexed before
     *  the registry tracked them). @return array{sources:int,chunks:int} */
    public function backfillRegistry(): array;
}
