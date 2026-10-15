<?php
/**
 * VectorBackfill — heals content that was indexed without a vector (doc/sales-assistant-indexing-v3.md
 * §3c). "Needs a vector" is read straight from the index: any product/knowledge chunk in a
 * vector-capable index that has no `embedding` field. Their stored `text` is embedded and the vector
 * is added to the existing document in place — no re-serialization, no full rebuild, the same for
 * products and knowledge. Also heals documents degraded before this mechanism existed.
 *
 * Bounded per call (document cap + wall-clock deadline) and gated by EmbedGate, so it never calls
 * /embed while the allowance is spent or the provider is backing off. Run by the
 * msc_salesassistant_vector_backfill cron (every 5 minutes) and by `salesassistant:status --backfill`.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Ingest;

use Myshopcarts\SalesAssistant\Model\OpenSearch\ClientProvider;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Psr\Log\LoggerInterface;

class VectorBackfill
{
    public const BATCH = 90; // < Cohere embed-v4 per-call limit (96), same as the indexers

    private const MISSING = ['bool' => ['must_not' => [['exists' => ['field' => 'embedding']]]]];

    public function __construct(
        private readonly Schema $schema,
        private readonly ClientProvider $clients,
        private readonly SalesAssistantClient $client,
        private readonly EmbedGate $gate,
        private readonly LoggerInterface $logger,
        private readonly IndexingThrottle $throttle
    ) {
    }

    /** The read aliases to heal: knowledge always, products only with catalogue meaning search on. */
    public function indexes(): array
    {
        $out = ['knowledge' => $this->schema->knowledgeIndex()];
        if ($this->client->catalogueMeaningSearch()) {
            $out = ['products' => $this->schema->productsIndex()] + $out;
        }
        return $out;
    }

    /** Documents still waiting for a vector, per index; null = index missing or not vector-capable. */
    public function missing(): array
    {
        $out = [];
        foreach ($this->indexes() as $kind => $index) {
            if (!$this->schema->hasVector($index)) {
                $out[$kind] = null;
                continue;
            }
            try {
                $out[$kind] = (int)($this->clients->get()->count(['index' => $index, 'body' => ['query' => self::MISSING]])['count'] ?? 0);
            } catch (\Throwable $e) {
                $out[$kind] = null;
            }
        }
        return $out;
    }

    /**
     * Add vectors to up to $maxDocs vector-less documents before $deadline (microtime).
     * @return array{embedded:int, stopped:string}  stopped = done|limit|deadline|gate|<failure reason>
     */
    public function run(int $maxDocs, float $deadline): array
    {
        $embedded = 0;
        foreach ($this->indexes() as $index) {
            if (!$this->schema->hasVector($index)) {
                continue;
            }
            while (true) {
                if ($embedded >= $maxDocs) {
                    return ['embedded' => $embedded, 'stopped' => 'limit'];
                }
                if (microtime(true) >= $deadline) {
                    return ['embedded' => $embedded, 'stopped' => 'deadline'];
                }
                if (!$this->gate->allows() && !$this->gate->pace($deadline)) {
                    return ['embedded' => $embedded, 'stopped' => 'gate'];
                }
                $t0 = microtime(true);
                $hits = $this->fetchMissing($index, min(self::BATCH, $maxDocs - $embedded));
                if (!$hits) {
                    break;   // this index is complete
                }
                try {
                    $resp = $this->client->embed(array_column($hits, 'text'), 'search_document');
                    $vectors = $resp['embeddings'] ?? [];
                    if (count($vectors) !== count($hits)) {
                        throw new \RuntimeException(sprintf('embed returned %d vectors for %d texts', count($vectors), count($hits)));
                    }
                    $this->gate->success();
                } catch (\Throwable $e) {
                    $reason = $this->gate->failure($e);
                    if ($reason === 'budget' && $this->gate->pace($deadline)) {
                        continue;   // shared indexing rate limit: waited out the minute, carry on
                    }
                    $this->logger->warning(sprintf('SalesAssistant vector backfill paused (%s). %s', $reason, $e->getMessage()));
                    return ['embedded' => $embedded, 'stopped' => $reason];
                }
                $bulk = [];
                foreach ($hits as $i => $hit) {
                    $bulk[] = ['update' => ['_index' => $hit['index'], '_id' => $hit['id']]];
                    $bulk[] = ['doc' => ['embedding' => $vectors[$i]]];
                }
                // refresh so the next search no longer returns these documents.
                $res = $this->clients->get()->bulk(['body' => $bulk, 'refresh' => true]);
                if (!empty($res['errors'])) {
                    $this->logger->warning('SalesAssistant vector backfill: bulk update had item errors (see OpenSearch).');
                }
                $embedded += count($hits);
                $this->throttle->yield(microtime(true) - $t0);
            }
        }
        return ['embedded' => $embedded, 'stopped' => 'done'];
    }

    /** @return array<array{index:string,id:string,text:string}> vector-less docs that have text */
    private function fetchMissing(string $index, int $size): array
    {
        try {
            $res = $this->clients->get()->search([
                'index' => $index,
                'body' => [
                    'size' => $size,
                    '_source' => ['text'],
                    // Only documents that have text to embed — an empty one would be fetched forever.
                    'query' => ['bool' => self::MISSING['bool'] + ['filter' => [['exists' => ['field' => 'text']]]]],
                ],
            ]);
        } catch (\Throwable $e) {
            return [];   // index missing / unreachable — nothing to do this tick
        }
        $out = [];
        foreach ($res['hits']['hits'] ?? [] as $hit) {
            $text = (string)($hit['_source']['text'] ?? '');
            if ($text !== '') {
                $out[] = ['index' => (string)$hit['_index'], 'id' => (string)$hit['_id'], 'text' => $text];
            }
        }
        return $out;
    }
}
