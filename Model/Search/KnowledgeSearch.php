<?php
/**
 * KnowledgeSearch (K4 retrieval layer, §4 eligibility, §5 small-to-big, §8 RRF) —
 * the knowledge counterpart of HybridSearch. Runs the shopper's question against the
 * merchant's OWN knowledge index: lexical (BM25 over text + heading_path) + vector
 * (k-NN, when available) fused by Reciprocal Rank Fusion (rank-based — the two score
 * scales aren't comparable), with the §4 technical-eligibility filter PUSHED DOWN into
 * OpenSearch (not post-filtered).
 *
 * Security (§2): the eligibility context (edition + normalized store version) is
 * supplied by EligibilityContext — derived server-side from the store's own Magento
 * metadata — never from the caller. This class takes only the free-text `query`; there
 * is deliberately no tenant/audience/edition/version parameter in the signature, so no
 * prompt-injected argument can widen reachability. Tenant isolation is physical (the
 * merchant's own OpenSearch); audience is the public shopper surface.
 *
 * Small-to-big (§5): the child chunk is what's indexed/ranked, but we return
 * `parent_text` when present so the LLM sees the whole section for grounding.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Search;

use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRepository;
use Myshopcarts\SalesAssistant\Model\Knowledge\EligibilityContext;
use Myshopcarts\SalesAssistant\Model\Knowledge\EligibilityFilter;
use Myshopcarts\SalesAssistant\Model\OpenSearch\ClientProvider;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;

class KnowledgeSearch
{
    private const CANDIDATES = 40; // per leg
    private const RRF_K = 60;

    public function __construct(
        private readonly Schema $schema,
        private readonly ClientProvider $clients,
        private readonly SalesAssistantClient $client,
        private readonly EligibilityContext $eligibility,
        private readonly DocumentRepository $documents
    ) {
    }

    /**
     * @return array<array{title:string,heading_path:string,source_type:string,
     *                     url:string,text:string,score:float}>
     */
    public function search(string $query, int $size = 5): array
    {
        $index = $this->schema->knowledgeIndex();
        $client = $this->clients->get();
        $filter = $this->eligibilityFilter();

        // Lexical (BM25) — heading_path weighted so the section title carries the topic.
        $bm25 = $this->hits($client->search(['index' => $index, 'body' => [
            'size' => self::CANDIDATES,
            'query' => ['bool' => [
                'must' => [['multi_match' => [
                    'query' => $query,
                    'fields' => ['heading_path^2', 'title^1.5', 'text'],
                ]]],
                'filter' => $filter,
            ]],
        ]]));

        // Vector (k-NN) — only when the query embeds and the index has vectors.
        $knn = [];
        if ($this->schema->hasVector($index)) {
            $vec = $this->embed($query);
            if ($vec) {
                $knn = $this->hits($client->search(['index' => $index, 'body' => [
                    'size' => self::CANDIDATES,
                    'query' => ['bool' => [
                        'must' => [['knn' => ['embedding' => ['vector' => $vec, 'k' => self::CANDIDATES]]]],
                        'filter' => $filter,
                    ]],
                ]]));
            }
        }

        return $this->collapse($this->applyPriority($this->rrf([$bm25, $knn])), $size);
    }

    /**
     * Merchant-set retrieval priority (1=normal, 2=high, 3=highest), applied AFTER fusion by
     * multiplying the fused score. Relevance-gated by construction: a prioritized source only
     * rises when it already scored for the query — a high-priority plans doc jumps to the top of
     * a plans question, but stays out of an unrelated one (its score there is ~0). One batched
     * registry read per search.
     * @param array<array{id:string,src:array,score:float}> $ranked
     */
    private function applyPriority(array $ranked): array
    {
        if (!$ranked) {
            return $ranked;
        }
        $ids = [];
        foreach ($ranked as $r) {
            $sid = (string)($r['src']['source_id'] ?? '');
            if ($sid !== '') {
                $ids[$sid] = true;
            }
        }
        $prio = $this->documents->prioritiesFor(array_keys($ids)); // source_id => 1|2|3
        if (!$prio) {
            return $ranked;
        }
        foreach ($ranked as &$r) {
            $p = $prio[(string)($r['src']['source_id'] ?? '')] ?? 1;
            if ($p > 1) {
                $r['score'] *= $p;
            }
        }
        unset($r);
        usort($ranked, static fn($a, $b) => $b['score'] <=> $a['score']);
        return $ranked;
    }

    /**
     * §4 eligibility, fail-closed — delegated to the pure EligibilityFilter (the §9
     * security boundary, unit-tested standalone). Context is SERVER-derived here; the
     * query never feeds it, so no client/LLM scope can widen reachability.
     */
    private function eligibilityFilter(): array
    {
        return EligibilityFilter::build(
            $this->eligibility->versionScalar(),
            $this->eligibility->edition()
        );
    }

    /** @return array<array{id:string,src:array}> */
    private function hits(array $resp): array
    {
        $out = [];
        foreach ($resp['hits']['hits'] ?? [] as $h) {
            $out[] = ['id' => (string)$h['_id'], 'src' => $h['_source'] ?? []];
        }
        return $out;
    }

    /** Reciprocal Rank Fusion over ranked chunk-id lists → ranked [id => src], desc. */
    private function rrf(array $lists): array
    {
        $score = [];
        $src = [];
        foreach ($lists as $list) {
            foreach ($list as $rank => $hit) {
                $id = $hit['id'];
                $score[$id] = ($score[$id] ?? 0) + 1.0 / (self::RRF_K + $rank + 1);
                $src[$id] = $hit['src'];
            }
        }
        arsort($score);
        $ranked = [];
        foreach ($score as $id => $s) {
            $ranked[] = ['id' => $id, 'src' => $src[$id], 'score' => $s];
        }
        return $ranked;
    }

    /**
     * Take the top-N fused chunks; return one entry per chunk with the section text
     * (small-to-big: parent_text when present, else the child text). De-dupes on the
     * parent so two children of one section don't both fill the answer window.
     */
    private function collapse(array $ranked, int $size): array
    {
        $out = [];
        $seenParent = [];
        foreach ($ranked as $hit) {
            $s = $hit['src'];
            $parent = (string)($s['parent_id'] ?? '');
            if ($parent !== '') {
                if (isset($seenParent[$parent])) {
                    continue;
                }
                $seenParent[$parent] = true;
            }
            $text = (string)($s['parent_text'] ?? '');
            if ($text === '') {
                $text = (string)($s['text'] ?? '');
            }
            if (trim($text) === '') {
                continue;
            }
            $out[] = [
                'title' => $this->cleanTitle((string)($s['title'] ?? '')),
                'heading_path' => (string)($s['heading_path'] ?? ''),
                'source_type' => (string)($s['source_type'] ?? ''),
                'url' => (string)($s['url'] ?? ''),
                'text' => $text,
                'score' => round((float)$hit['score'], 5),
            ];
            if (count($out) >= $size) {
                break;
            }
        }
        return $out;
    }

    /** Strip a trailing " | Site Name" suffix that page <title>s carry (e.g.
     *  "Backups & Recovery | MyShopCarts Docs" → "Backups & Recovery"), so citations +
     *  source links read as a clean page name. Only the last pipe-separated segment is
     *  dropped, and never the whole title. */
    private function cleanTitle(string $title): string
    {
        $title = trim($title);
        $pos = strrpos($title, ' | ');
        if ($pos !== false && $pos > 0) {
            $title = trim(substr($title, 0, $pos));
        }
        return $title;
    }

    private function embed(string $query): array
    {
        try {
            $resp = $this->client->embed([$query], 'search_query');
            return $resp['embeddings'][0] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
