<?php
/**
 * CatalogIndexer — the ingestion pipeline (§3b, E6): products → concept chunks →
 * embeddings (OUR /embed, batched ≤96) → the merchant's OpenSearch. One doc per
 * (product, chunk); the doc carries the chunk text (BM25), the filter/display
 * attrs, and the embedding (k-NN, when the cluster supports it — else lexical).
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Ingest;

use Myshopcarts\SalesAssistant\Model\OpenSearch\ClientProvider;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Magento\Catalog\Api\Data\ProductInterface;
use Psr\Log\LoggerInterface;

class CatalogIndexer
{
    private const EMBED_BATCH = 90; // < Cohere embed-v4 per-call limit (96)

    /** @var array<array{id:string,doc:array,text:string}> */
    private array $pending = [];
    private bool $vector = false;
    /** When set, index() writes into this concrete version (a blue/green full rebuild) instead of the
     *  live read alias; null = the incremental path writes straight to the alias. */
    private ?string $targetIndex = null;

    public function __construct(
        private readonly Schema $schema,
        private readonly ClientProvider $clients,
        private readonly ProductSerializer $serializer,
        private readonly SalesAssistantClient $client,
        private readonly LoggerInterface $logger,
        private readonly EmbedGate $gate
    ) {
    }

    /**
     * Begin a blue/green full rebuild: create a fresh index VERSION with the CURRENT mapping and return
     * its name. Pass that name to index() for every page, then promoteFullRebuild() to publish it. This
     * is how a mapping change is applied on a full reindex with zero downtime (mirrors the knowledge
     * index + Magento's catalogsearch); no separate "recreate" is needed.
     */
    public function beginFullRebuild(int $productCount = 0): string
    {
        return $this->schema->buildProductsVersion($productCount);
    }

    /** Publish a completed rebuild version as the live index (atomic alias swap). Returns false and
     *  deletes the version if it would publish an EMPTY index over a non-empty live one. */
    public function promoteFullRebuild(string $version): bool
    {
        if ($this->schema->promoteProductsVersion($version)) {
            return true;
        }
        $this->schema->deleteProductsVersion($version);
        return false;
    }

    /** Discard a half-built rebuild version (an interrupted/failed reindex). Best-effort. */
    public function abortFullRebuild(string $version): void
    {
        try {
            $this->schema->deleteProductsVersion($version);
        } catch (\Throwable $e) {
            $this->logger->warning('SalesAssistant: could not discard aborted products version ' . $version . '. ' . $e->getMessage());
        }
    }

    /**
     * Index an iterable of products. Returns the number of chunks indexed. When $targetIndex is given
     * (a blue/green full rebuild), docs go into that version; otherwise the incremental path writes to
     * the live alias (ensuring it exists with the current mapping first).
     * @param iterable<ProductInterface> $products
     */
    public function index(iterable $products, ?string $targetIndex = null): int
    {
        $this->targetIndex = $targetIndex;
        $this->vector = $targetIndex !== null
            ? $this->schema->hasVector($targetIndex)
            : $this->schema->ensureProductsIndex();
        $count = 0;
        foreach ($products as $product) {
            $serialized = $this->serializer->serialize($product);
            foreach ($serialized['chunks'] as $chunk) {
                $this->pending[] = [
                    'id' => $serialized['doc']['sku'] . '_' . $chunk['chunk_type'],
                    'doc' => ['chunk_type' => $chunk['chunk_type'], 'text' => $chunk['text']] + $serialized['doc'],
                    'text' => $chunk['text'],
                ];
                if (count($this->pending) >= self::EMBED_BATCH) {
                    $count += $this->flush();
                }
            }
        }
        $count += $this->flush();
        return $count;
    }

    private function flush(): int
    {
        if (!$this->pending) {
            return 0;
        }
        $batch = $this->pending;
        $this->pending = [];

        // Never degrade silently (doc/sales-assistant-indexing-v3.md §3c): when the gate is closed
        // (allowance spent / provider trouble) or the call fails, the batch is still written — it
        // stays keyword-searchable, never missing — and VectorBackfill adds the vectors later.
        $vectors = [];
        // A refusal from the backend's shared indexing budget (reason `budget`) is paced: wait out
        // the minute and retry a few times, so a rebuild keeps its vectors instead of racing ahead.
        $embed = $this->vector && $this->client->catalogueMeaningSearch();   // keyword-only catalogue by default
        for ($attempt = 1; $embed && ($this->gate->allows() || $this->gate->pace()); $attempt++) {
            try {
                $resp = $this->client->embed(array_column($batch, 'text'), 'search_document');
                $vectors = $resp['embeddings'] ?? [];
                if (count($vectors) !== count($batch)) {
                    throw new \RuntimeException(sprintf('embed returned %d vectors for %d texts', count($vectors), count($batch)));
                }
                $this->gate->success();
                break;
            } catch (\Throwable $e) {
                $reason = $this->gate->failure($e);
                if ($reason === 'budget' && $attempt < 3) {
                    continue;
                }
                $this->logger->warning(sprintf(
                    'SalesAssistant embed unavailable (%s); %d chunks indexed without vectors for now — the vector backfill job adds them later. %s',
                    $reason, count($batch), $e->getMessage()
                ));
                $vectors = [];
                break;
            }
        }

        $index = $this->targetIndex ?? $this->schema->productsIndex();
        $bulk = [];
        foreach ($batch as $i => $item) {
            $doc = $item['doc'];
            if (isset($vectors[$i]) && is_array($vectors[$i])) {
                $doc['embedding'] = $vectors[$i];
            }
            $bulk[] = ['index' => ['_index' => $index, '_id' => $item['id']]];
            $bulk[] = $doc;
        }
        $res = $this->clients->get()->bulk(['body' => $bulk]);
        if (!empty($res['errors'])) {
            $this->logger->warning('SalesAssistant bulk index had item errors (see OpenSearch).');
        }
        return count($batch);
    }
}
