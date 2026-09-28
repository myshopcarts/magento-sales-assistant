<?php
/**
 * Tier A retrieval eval (doc/sales-assistant-retrieval-eval.md §2A): assert the OpenSearch query
 * HybridSearch BUILDS for a given plan — with a MOCKED client, no index, no network — by checking
 * that the query's hard FILTER clauses make the right fixture products reachable and the wrong ones
 * not (via OpenSearchFilterEval), plus the sort/aggs/identifier/fitment construction. Industry-
 * independent: the fixtures span clothing / electronics / auto parts, so passing proves the generic
 * attribute + identifier + fitment query construction, not a colour/size shape. Guards exactly the
 * clauses we keep editing (the case-insensitive term, the numeric range, the aggs, the nested
 * fitment, the identifier short-circuit).
 *
 *     php Test/Retrieval/HybridSearchQueryTest.php    # exit 0 = pass, 1 = fail
 */
declare(strict_types=1);

$base = dirname(__DIR__, 2);

// Runtime-only types the class references but that aren't present under bare `php`.
if (!class_exists('OpenSearch\\Client')) {
    eval('namespace OpenSearch; class Client {}');
}

require $base . '/Model/OpenSearch/ClientProvider.php';
require $base . '/Model/OpenSearch/Schema.php';
require $base . '/Model/SalesAssistantClient.php';
require $base . '/Model/Search/HybridSearch.php';
require __DIR__ . '/OpenSearchFilterEval.php';

use Myshopcarts\SalesAssistant\Model\OpenSearch\ClientProvider;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Myshopcarts\SalesAssistant\Model\Search\HybridSearch;
use Myshopcarts\SalesAssistant\Test\Retrieval\OpenSearchFilterEval as FilterEval;

// --- mocks (no-op ctor subclasses; the recording client captures every query body) --------------
$rec = new class extends \OpenSearch\Client {
    public array $bodies = [];
    public function __construct() {}
    public function search(array $params = []): array
    {
        $this->bodies[] = $params['body'] ?? [];
        return ['hits' => ['hits' => []]];   // results don't matter — we assert the QUERY
    }
    public function last(): array { return $this->bodies[array_key_last($this->bodies)] ?? []; }
};
$schema = new class extends Schema {
    public function __construct() {}
    public function productsIndex(): string { return 'test_products'; }
    public function hasVector(string $index): bool { return false; }   // single BM25 leg → one body
};
$clients = new class($rec) extends ClientProvider {
    public function __construct(private $rec) {}
    public function get(): \OpenSearch\Client { return $this->rec; }
};
$saClient = new class extends SalesAssistantClient {
    public function __construct() {}
};
$hs = new HybridSearch($schema, $clients, $saClient);

// --- fixtures (industry-varied) -----------------------------------------------------------------
$shirt  = ['sku' => 'SH1', 'color' => ['Green'], 'attr_material' => ['Cotton'], 'price' => 25.0,
           'in_stock' => true, 'on_sale' => true, 'discount_pct' => 20.0, 'identifiers' => ['SH1'], 'fits' => []];
$laptop = ['sku' => 'LP1', 'attrn_ram' => 16.0, 'price' => 800.0, 'in_stock' => true,
           'on_sale' => false, 'discount_pct' => 0.0, 'identifiers' => ['LP1', '9781234567897'], 'fits' => []];
$brake  = ['sku' => 'BP1', 'price' => 40.0, 'in_stock' => true, 'identifiers' => ['BP1', 'OEM123'],
           'fits' => [['mm' => 'Honda Civic', 'year' => 2015, 'raw' => 'Honda Civic 2015'],
                      ['mm' => 'Honda Civic', 'year' => 2016, 'raw' => 'Honda Civic 2016']]];
$accord = ['sku' => 'BP2', 'price' => 42.0, 'in_stock' => true, 'identifiers' => ['BP2'],
           'fits' => [['mm' => 'Honda Accord', 'year' => 2015, 'raw' => 'Honda Accord 2015'],
                      ['mm' => 'Toyota Civic', 'year' => 2016, 'raw' => 'Toyota Civic 2016']]];

$fail = 0;
$ok = function (string $label, bool $cond) use (&$fail) {
    echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$cond) { $fail++; }
};
$filterOf = static fn(array $b): array => $b['query']['bool']['filter'] ?? [];
$reach = static fn(array $b, array $doc): bool => FilterEval::reachable($filterOf($b), $doc);

// --- attribute filters (keyword / generic / numeric / price / multi-value / stock) --------------
$rec->bodies = []; $hs->search('shirt', ['color' => 'green'], 8, null);
$ok('color=green reaches the green shirt (case-insensitive), not the laptop',
    $reach($rec->last(), $shirt) && !$reach($rec->last(), $laptop));

$rec->bodies = []; $hs->search('shirt', ['attr_material' => 'cotton'], 8, null);
$ok('attr_material=cotton reaches the cotton shirt, not the laptop',
    $reach($rec->last(), $shirt) && !$reach($rec->last(), $laptop));

$rec->bodies = []; $hs->search('laptop', ['attrn_ram' => ['min' => 8, 'max' => 32]], 8, null);
$ok('attrn_ram 8..32 reaches the 16GB laptop, not the shirt (no ram)',
    $reach($rec->last(), $laptop) && !$reach($rec->last(), $shirt));

$rec->bodies = []; $hs->search('laptop', ['attrn_ram' => 16], 8, null);
$ok('attrn_ram=16 (exact) reaches the laptop', $reach($rec->last(), $laptop));

$rec->bodies = []; $hs->search('x', ['min_price' => 20, 'max_price' => 30], 8, null);
$ok('price 20..30 reaches the $25 shirt, not the $800 laptop',
    $reach($rec->last(), $shirt) && !$reach($rec->last(), $laptop));

$rec->bodies = []; $hs->search('x', ['color' => ['red', 'green']], 8, null);
$ok('color in [red,green] (bool should) reaches the green shirt', $reach($rec->last(), $shirt));

$rec->bodies = []; $hs->search('x', ['in_stock' => true], 8, null);
$ok('in_stock=true reaches an in-stock product', $reach($rec->last(), $shirt));

// --- deals / on-sale (structured intent; filter + discount sort) ---------------------------------
$rec->bodies = []; $hs->search('x', ['on_sale' => true], 8, null);
$ok('on_sale=true reaches the discounted shirt, not the full-price laptop',
    $reach($rec->last(), $shirt) && !$reach($rec->last(), $laptop));

$rec->bodies = []; $hs->search('x', ['on_sale' => true], 8, ['field' => 'discount_pct', 'dir' => 'desc']);
$ok('deals sort by discount_pct desc (best deal first)',
    ($rec->last()['sort'][0] ?? null) === ['discount_pct' => ['order' => 'desc']]);

// --- identifier exact mode (short-circuit) ------------------------------------------------------
$rec->bodies = []; $hs->search('', ['identifier' => '9781234567897'], 8, null);
$idBody = $rec->last();
$ok('identifier reaches the product with that ISBN, not another',
    $reach($idBody, $laptop) && !$reach($idBody, $shirt));
$ok('identifier mode is a pure filter (no BM25 must)', empty($idBody['query']['bool']['must']));

// --- fitment v2 (nested, structured make/model + exact/range year; no cross-value contamination) --
$rec->bodies = []; $hs->search('brake pads', ['fits' => ['mm' => 'Honda Civic', 'year' => 2015]], 8, null);
$fitBody = $rec->last();
$ok('fits {Honda Civic, 2015} reaches the Civic part',
    $reach($fitBody, $brake));
$ok('fits {Honda Civic, 2015} does NOT reach a part fitting Accord-2015 + Toyota-Civic-2016',
    !$reach($fitBody, $accord));

$rec->bodies = []; $hs->search('filter', ['fits' => ['mm' => 'Honda Civic', 'year' => 2020]], 8, null);
$ok('fits {Honda Civic, 2020} does NOT reach a part that only fits 2015/2016 (year is exact)',
    !$reach($rec->last(), $brake));

$rec->bodies = []; $hs->search('filter', ['fits' => ['mm' => 'Honda Civic', 'year' => 2015, 'year_max' => 2018]], 8, null);
$ok('fits {Honda Civic, 2015-2018} reaches the Civic part via a year RANGE',
    $reach($rec->last(), $brake) && !$reach($rec->last(), $accord));

$rec->bodies = []; $hs->search('brake pads', ['fits' => 'Honda Civic 2015'], 8, null);
$ok('fits plain-string "Honda Civic 2015" (v1 fallback) still reaches the Civic part via fits.raw',
    $reach($rec->last(), $brake) && !$reach($rec->last(), $accord));

// --- sort (price + generic numeric) -------------------------------------------------------------
$rec->bodies = []; $hs->search('x', [], 8, ['field' => 'price', 'dir' => 'asc']);
$ok('sort price asc', ($rec->last()['sort'][0] ?? null) === ['price' => ['order' => 'asc']]);

$rec->bodies = []; $hs->search('x', [], 8, ['field' => 'attrn_ram', 'dir' => 'desc']);
$ok('sort attrn_ram desc', ($rec->last()['sort'][0] ?? null) === ['attrn_ram' => ['order' => 'desc']]);

// --- aggregates (facts) -------------------------------------------------------------------------
$rec->bodies = []; $hs->facts('x', ['color' => 'green']);
$fb = $rec->last();
$aggs = $fb['aggs'] ?? [];
$ok('facts: size 0 + cardinality(sku) + min/max(price), filtered to green',
    ($fb['size'] ?? null) === 0
    && ($aggs['products']['cardinality']['field'] ?? null) === 'sku'
    && ($aggs['pmin']['min']['field'] ?? null) === 'price'
    && ($aggs['pmax']['max']['field'] ?? null) === 'price'
    && FilterEval::reachable($fb['query']['bool']['filter'] ?? [], $shirt));

// --- shard sizing (blue/green re-picks shards per full rebuild; best practice ~500k products/shard) --
$ok('shardsFor(0) = 1 (unknown → small default)', $schema->shardsFor(0) === 1);
$ok('shardsFor(499999) = 1 (every realistic SMB store stays single-shard)', $schema->shardsFor(499999) === 1);
$ok('shardsFor(1.2M) = 3 (ceil to ~500k/shard)', $schema->shardsFor(1200000) === 3);
$ok('shardsFor(999M) = 12 (capped, never over-shard a single-node cluster)', $schema->shardsFor(999000000) === 12);

echo "\n" . (24 - $fail) . "/24 passed\n";
exit($fail ? 1 : 0);
