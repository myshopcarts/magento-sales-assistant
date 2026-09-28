<?php
/**
 * HybridSearch (E7 query, §3a) — runs the shopper's decomposed query against the
 * merchant's OpenSearch: lexical (BM25) + vector (k-NN, when available) + hard
 * filters, merged by Reciprocal Rank Fusion (rank-based — BM25 and k-NN scores are
 * not comparable), then COLLAPSED from chunks to products (top-N with matched
 * evidence). Live price/stock are added by the controller, by SKU.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Search;

use Myshopcarts\SalesAssistant\Model\OpenSearch\ClientProvider;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;

class HybridSearch
{
    private const CANDIDATES = 50; // per leg
    private const RRF_K = 60;

    public function __construct(
        private readonly Schema $schema,
        private readonly ClientProvider $clients,
        private readonly SalesAssistantClient $client
    ) {
    }

    /**
     * @return array<array{sku:string,name:string,price:float,in_stock:bool,
     *                     matched:array<string>}>  (indexed price/stock — refresh live)
     */
    public function search(string $query, array $filters, int $size = 8, ?array $sort = null): array
    {
        $index = $this->schema->productsIndex();
        $client = $this->clients->get();

        // Identifier lookup (Phase 2): a shopper who typed an ISBN/UPC/SKU/MPN wants THAT product —
        // an exact keyword match, not the hybrid neighbourhood. Short-circuit to an exact term on the
        // normalised `identifiers` field. The decompose already normalised the code.
        if (!empty($filters['identifier'])) {
            return $this->identifierSearch($index, $client, (string)$filters['identifier'], $size);
        }

        $filterClauses = $this->filters($filters);

        // Structured intent (superlative / explicit sort): order by the field itself, not by
        // relevance, so "cheapest / most expensive" is a true min/max over the matching set — not
        // the min of a relevance-ranked sample. The descriptive `query` still RESTRICTS the set
        // (BM25 must), it just no longer decides the order. See
        // doc/sales-assistant-structured-retrieval.md §2.
        $sortField = (string)($sort['field'] ?? '');
        if ($sort !== null && ($sortField === 'price' || $sortField === 'discount_pct' || strncmp($sortField, 'attrn_', 6) === 0)) {
            return $this->sortedSearch($index, $client, $query, $filterClauses, $sortField, (string)($sort['dir'] ?? 'asc'), $size);
        }

        // Lexical (BM25).
        $bm25 = $this->hits($client->search(['index' => $index, 'body' => [
            'size' => self::CANDIDATES,
            'query' => ['bool' => [
                'must' => [['multi_match' => ['query' => $query, 'fields' => ['name^2', 'text']]]],
                'filter' => $filterClauses,
            ]],
        ]]));

        // Vector (k-NN) — only if the query embeds and the index has vectors.
        $knn = [];
        if ($this->schema->hasVector($index)) {
            $vec = $this->embed($query);
            if ($vec) {
                $knn = $this->hits($client->search(['index' => $index, 'body' => [
                    'size' => self::CANDIDATES,
                    'query' => ['bool' => [
                        'must' => [['knn' => ['embedding' => ['vector' => $vec, 'k' => self::CANDIDATES]]]],
                        'filter' => $filterClauses,
                    ]],
                ]]));
            }
        }

        return $this->collapse($this->rrf([$bm25, $knn]), $size);
    }

    /**
     * Price-ordered structured search: BM25 restricts to the descriptive query (or match_all when
     * there is none), the hard filters constrain, and OpenSearch SORTS by the indexed price. Collapse
     * preserves that order, so the returned products are the cheapest/most-expensive matches. Indexed
     * price is approximate (sale/tier prices are re-fetched live by the controller), so the caller
     * fetches a window and re-sorts on LIVE price; we over-fetch chunks (CANDIDATES) to cover $size
     * distinct products after collapse. No k-NN — relevance doesn't order a price sort.
     */
    private function sortedSearch($index, $client, string $query, array $filterClauses, string $field, string $dir, int $size): array
    {
        $order = strtolower($dir) === 'desc' ? 'desc' : 'asc';
        // Only allow sorting on numeric fields we index (price, or a generic attrn_<code>) — never
        // interpolate an arbitrary field name into the query.
        $sortField = ($field === 'price' || $field === 'discount_pct' || strncmp($field, 'attrn_', 6) === 0) ? $field : 'price';
        $must = $query !== ''
            ? [['multi_match' => ['query' => $query, 'fields' => ['name^2', 'text']]]]
            : [['match_all' => (object)[]]];
        $hits = $this->hits($client->search(['index' => $index, 'body' => [
            'size' => self::CANDIDATES,
            'query' => ['bool' => ['must' => $must, 'filter' => $filterClauses]],
            'sort' => [[$sortField => ['order' => $order]], '_score'],
        ]]));
        return $this->collapse($hits, $size);
    }

    /**
     * Structured FACTS over the whole matching set (not a relevance sample), via a size:0
     * aggregation — powers "how many …", "do you have any …", and "what's the price range".
     * `total` is DISTINCT PRODUCTS (cardinality on `sku`, since the index is chunked — several
     * chunks per product). `price_min`/`price_max` are the INDEXED price (every chunk of a product
     * carries the same value); sale/tier prices are only re-fetched live for the returned window,
     * so a stated range is indexed-approximate (design note §3). No k-NN — a count/range doesn't
     * need relevance ordering, so this also works on the lexical-only fallback.
     *
     * @return array{total:int, price_min:?float, price_max:?float}
     */
    public function facts(string $query, array $filters): array
    {
        $must = $query !== ''
            ? [['multi_match' => ['query' => $query, 'fields' => ['name^2', 'text']]]]
            : [['match_all' => (object)[]]];
        $resp = $this->clients->get()->search(['index' => $this->schema->productsIndex(), 'body' => [
            'size' => 0,
            'query' => ['bool' => ['must' => $must, 'filter' => $this->filters($filters)]],
            'aggs' => [
                'products' => ['cardinality' => ['field' => 'sku']],
                'pmin' => ['min' => ['field' => 'price']],
                'pmax' => ['max' => ['field' => 'price']],
            ],
        ]]);
        $a = $resp['aggregations'] ?? [];
        return [
            'total' => (int)($a['products']['value'] ?? 0),
            'price_min' => isset($a['pmin']['value']) ? (float)$a['pmin']['value'] : null,
            'price_max' => isset($a['pmax']['value']) ? (float)$a['pmax']['value'] : null,
        ];
    }

    /**
     * Exact identifier lookup: a term on the normalised `identifiers` keyword field (SKU + the
     * merchant's ISBN/UPC/EAN/MPN attributes, all stored normalised by the serializer). No BM25/k-NN
     * — an exact code should return its product, not a fuzzy set. Empty result = "no product with
     * that code" (the answer says so; never invents one).
     */
    private function identifierSearch($index, $client, string $code, int $size): array
    {
        $hits = $this->hits($client->search(['index' => $index, 'body' => [
            'size' => self::CANDIDATES,
            'query' => ['bool' => ['filter' => [
                ['term' => ['identifiers' => ['value' => $code, 'case_insensitive' => true]]],
            ]]],
        ]]));
        return $this->collapse($hits, $size);
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

    /** Reciprocal Rank Fusion over ranked chunk-id lists → [id => score], desc. */
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
            $ranked[] = ['id' => $id, 'src' => $src[$id]];
        }
        return $ranked;
    }

    /** Collapse ranked chunks to products (best chunk wins the rank); keep matched types. */
    private function collapse(array $ranked, int $size): array
    {
        $products = [];
        foreach ($ranked as $hit) {
            $s = $hit['src'];
            $sku = (string)($s['sku'] ?? '');
            if ($sku === '') {
                continue;
            }
            if (!isset($products[$sku])) {
                if (count($products) >= $size) {
                    continue;
                }
                $products[$sku] = [
                    'sku' => $sku,
                    'name' => (string)($s['name'] ?? ''),
                    'price' => (float)($s['price'] ?? 0),
                    'in_stock' => (bool)($s['in_stock'] ?? false),
                    'matched' => [],
                ];
            }
            $type = (string)($s['chunk_type'] ?? '');
            if ($type && !in_array($type, $products[$sku]['matched'], true)) {
                $products[$sku]['matched'][] = $type;
            }
        }
        return array_values($products);
    }

    private function filters(array $filters): array
    {
        $clauses = [];
        // Keyword attribute filters — colour/size (legacy explicit fields) + any generic attr_<code>
        // the decompose emitted (material, connector, …). All are option-LABEL keyword fields, so the
        // term is case_insensitive (the decompose lowercases; the index stores "Green"/"Cotton"): a
        // plain term would be case-sensitive → the "no items in <value>" false-negative. A multi-value
        // filter (an array) matches ANY of the values (bool should).
        foreach ($filters as $k => $v) {
            if (!($k === 'color' || $k === 'size' || strncmp($k, 'attr_', 5) === 0)) {
                continue;
            }
            $vals = is_array($v) ? $v : [$v];
            $should = [];
            foreach ($vals as $vv) {
                if ($vv === null || $vv === '') {
                    continue;
                }
                $should[] = ['term' => [$k => ['value' => (string)$vv, 'case_insensitive' => true]]];
            }
            if (count($should) === 1) {
                $clauses[] = $should[0];
            } elseif ($should) {
                $clauses[] = ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
            }
        }
        // Numeric attribute filters — any generic attrn_<code>: a scalar is exact, {min,max} is a range.
        foreach ($filters as $k => $v) {
            if (strncmp($k, 'attrn_', 6) !== 0) {
                continue;
            }
            if (is_array($v)) {
                $range = [];
                if (isset($v['min']) && is_numeric($v['min'])) {
                    $range['gte'] = (float)$v['min'];
                }
                if (isset($v['max']) && is_numeric($v['max'])) {
                    $range['lte'] = (float)$v['max'];
                }
                if ($range) {
                    $clauses[] = ['range' => [$k => $range]];
                }
            } elseif (is_numeric($v)) {
                $clauses[] = ['term' => [$k => (float)$v]];
            }
        }
        // Price range (legacy min_price/max_price keys).
        $range = [];
        if (isset($filters['min_price']) && is_numeric($filters['min_price'])) {
            $range['gte'] = (float)$filters['min_price'];
        }
        if (isset($filters['max_price']) && is_numeric($filters['max_price'])) {
            $range['lte'] = (float)$filters['max_price'];
        }
        if ($range) {
            $clauses[] = ['range' => ['price' => $range]];
        }
        if (!empty($filters['in_stock'])) {
            $clauses[] = ['term' => ['in_stock' => true]];
        }
        // Deals / on-sale: restrict to genuinely discounted products (indexed special price < regular,
        // in date). The best-discount-first order rides the generic numeric sort on `discount_pct`.
        if (!empty($filters['on_sale'])) {
            $clauses[] = ['term' => ['on_sale' => true]];
        }
        // Fitment (Phase 3): the vehicle target must match make/model AND the year WITHIN ONE nested
        // vehicle entry — so "2015 Honda Civic" won't match a part that separately fits a "Honda Accord
        // 2015" and a "Toyota Civic 2016". v2: `fits` is a STRUCTURED object {mm, year, year_max?} —
        // make/model is a token-AND match, the year is an exact term or (year..year_max) range. A plain
        // string (v1 shape) still works: token-AND on fits.mm.
        if (!empty($filters['fits'])) {
            $f = $filters['fits'];
            if (is_array($f)) {
                $must = [];
                if (!empty($f['mm'])) {
                    $must[] = ['match' => ['fits.mm' => ['query' => (string)$f['mm'], 'operator' => 'and']]];
                }
                if (isset($f['year']) && $f['year'] !== '') {
                    if (isset($f['year_max']) && $f['year_max'] !== '') {
                        $lo = min((int)$f['year'], (int)$f['year_max']);
                        $hi = max((int)$f['year'], (int)$f['year_max']);
                        $must[] = ['range' => ['fits.year' => ['gte' => $lo, 'lte' => $hi]]];
                    } else {
                        $must[] = ['term' => ['fits.year' => (int)$f['year']]];
                    }
                }
                if ($must) {
                    $clauses[] = ['nested' => ['path' => 'fits', 'query' => ['bool' => ['must' => $must]]]];
                }
            } else {
                // v1 fallback: a plain string still carries the year, so match the whole `raw` value.
                $clauses[] = ['nested' => ['path' => 'fits', 'query' => [
                    'match' => ['fits.raw' => ['query' => (string)$f, 'operator' => 'and']],
                ]]];
            }
        }
        return $clauses;
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
