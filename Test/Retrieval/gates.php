<?php
/**
 * §9 negative-retrieval CI gates — asserted at the RETRIEVAL-API LEVEL (before any LLM;
 * "did the model decline?" is the wrong layer). Zero dependencies: runs with just `php`
 * (no Magento, no OpenSearch, no PHPUnit), so it wires into any CI as one command:
 *
 *     php Test/Retrieval/gates.php    # exit 0 = all gates pass, 1 = a gate failed
 *
 * It exercises the real security boundary (EligibilityFilter + VersionScalar) and the
 * immutable-context invariant (§2): reachability is a pure function of the SERVER-derived
 * (version, edition) context — the shopper's query and any client-supplied scope are not
 * inputs, so they cannot widen what a request may retrieve.
 *
 * Coverage vs the design's five gates: this LOCAL, per-tenant extension physically
 * isolates by tenant (its own OpenSearch) and serves a single audience (public shopper),
 * so gates 1 (cross-tenant) and 2 (cross-audience) are enforced by topology, not a filter,
 * and become live gates at K5 (central multi-partition store). Gate 3 (version/edition)
 * and gates 4/5 (context-override / prompt-injection) ARE enforced by code here and are
 * asserted below.
 */
declare(strict_types=1);

$base = dirname(__DIR__, 2); // …/Myshopcarts/SalesAssistant
require $base . '/Model/Knowledge/VersionScalar.php';
require $base . '/Model/Knowledge/EligibilityFilter.php';
require __DIR__ . '/OpenSearchFilterEval.php';

use Myshopcarts\SalesAssistant\Model\Knowledge\EligibilityFilter;
use Myshopcarts\SalesAssistant\Model\Knowledge\VersionScalar;
use Myshopcarts\SalesAssistant\Test\Retrieval\OpenSearchFilterEval as FilterEval;

$FAILS = 0;
$section = static function (string $s): void { echo "\n== $s\n"; };
$check = static function (string $name, bool $ok) use (&$FAILS): void {
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n";
    if (!$ok) { $FAILS++; }
};

// ---- fixture corpus (the classes we ingest + future central content) --------------
$C1_all       = ['id' => 'c1', 'version_scope' => 'all'];                                            // merchant CMS
$C2_v249only  = ['id' => 'c2', 'version_scope' => 'bounded', 'version_min' => 2040900, 'version_max' => 2049999]; // 2.4.9-only feature
$C3_ee_only   = ['id' => 'c3', 'version_scope' => 'bounded', 'version_min' => 2040700, 'version_max' => 2049999, 'edition' => ['enterprise']];
$C4_oss_wide  = ['id' => 'c4', 'version_scope' => 'bounded', 'version_min' => 2040000, 'version_max' => 2049999]; // any 2.4.x
$C5_unknown   = ['id' => 'c5', 'version_scope' => 'unknown', 'version_min' => 2040000, 'version_max' => 2049999];

// ---- server-derived contexts (versionScalar, edition) -----------------------------
$ctx = [
    '2.4.7/community'   => [2040700, 'community'],
    '2.4.9/community'   => [2040900, 'community'],
    '2.4.8/enterprise'  => [2040800, 'enterprise'],
    'nullver/community' => [null,    'community'],
];
$reach = static function (array $c, array $doc): bool {
    return FilterEval::reachable(EligibilityFilter::build($c[0], $c[1]), $doc);
};

// ---- GATE 3: version/edition eligibility (fail-closed) -----------------------------
$section('Gate 3 — version/edition: incompatible chunks are not in the candidate set');
$check('2.4.7 ctx excludes a 2.4.9-only chunk',        !$reach($ctx['2.4.7/community'],  $C2_v249only));
$check('2.4.9 ctx includes the 2.4.9 chunk',            $reach($ctx['2.4.9/community'],  $C2_v249only));
$check('2.4.7 ctx includes an any-2.4.x chunk',         $reach($ctx['2.4.7/community'],  $C4_oss_wide));
$check('2.4.7 ctx includes a version-agnostic (all) chunk', $reach($ctx['2.4.7/community'], $C1_all));
$check('community ctx excludes an enterprise-only chunk', !$reach($ctx['2.4.7/community'], $C3_ee_only));
$check('enterprise ctx includes the enterprise chunk',   $reach($ctx['2.4.8/enterprise'], $C3_ee_only));
$check('edition-less bounded chunk reachable regardless of edition',
    $reach($ctx['2.4.7/community'], $C4_oss_wide) && $reach($ctx['2.4.8/enterprise'], $C4_oss_wide));

// ---- Fail-closed: unknown scope + unparseable store version -----------------------
$section('Fail-closed — unknown version_scope and null store version');
$unknownReachable = false;
foreach ($ctx as $c) { if ($reach($c, $C5_unknown)) { $unknownReachable = true; } }
$check('version_scope=unknown is NEVER reachable (any context)', !$unknownReachable);
$check('null store version → only version_scope=all reachable',
    $reach($ctx['nullver/community'], $C1_all)
    && !$reach($ctx['nullver/community'], $C2_v249only)
    && !$reach($ctx['nullver/community'], $C3_ee_only)
    && !$reach($ctx['nullver/community'], $C4_oss_wide));

// ---- GATES 4 & 5: immutable context — no client/LLM scope, no injection primitive --
$section('Gates 4 & 5 — context-override & prompt-injection: scope is server-only');
// (a) The boundary is a pure function of the SERVER context: build() has exactly two
//     params (?int version, string edition) — no query/tenant/audience/scope input.
$rm = new ReflectionMethod(EligibilityFilter::class, 'build');
$params = $rm->getParameters();
$paramNames = array_map(static fn($p) => $p->getName(), $params);
$check('EligibilityFilter::build takes exactly 2 params', count($params) === 2);
$check('build() exposes no tenant/audience/query/scope parameter',
    !array_intersect(array_map('strtolower', $paramNames),
        ['tenant', 'audience', 'query', 'scope', 'surface', 'store', 'edition_override', 'version_override']));
$check('build() is deterministic for a fixed server context',
    EligibilityFilter::build(2040700, 'community') === EligibilityFilter::build(2040700, 'community'));

// (b) Source-level: the retrieval API accepts only free-text; the controller reads only
//     {query}; the filter is fed ONLY the server-derived eligibility context.
$ksrc = file_get_contents($base . '/Model/Search/KnowledgeSearch.php');
$csrc = file_get_contents($base . '/Controller/Widget/Knowledge.php');
$check('KnowledgeSearch::search signature carries no scope param',
    (bool)preg_match('/function\s+search\s*\(\s*string\s+\$query\s*,\s*int\s+\$size/', $ksrc));
$check('KnowledgeSearch feeds ONLY the server eligibility context into the filter',
    (bool)preg_match('/EligibilityFilter::build\(\s*\$this->eligibility->versionScalar\(\)\s*,\s*\$this->eligibility->edition\(\)\s*\)/s', $ksrc));
$check('Knowledge controller reads only $body[\'query\']',
    str_contains($csrc, "\$body['query']")
    && !preg_match("/\\\$body\\[['\"](tenant|audience|edition|version|scope|surface)/", $csrc));

// ---- VersionScalar normalization + range (underpins gate 3) ------------------------
$section('VersionScalar — normalization + range');
$vs = new VersionScalar();
$check("'2.4.7'   → 2040700", $vs->toScalar('2.4.7') === 2040700);
$check("'2.4.7-p3'→ 2040703", $vs->toScalar('2.4.7-p3') === 2040703);
$check("'2.4.9'   → 2040900", $vs->toScalar('2.4.9') === 2040900);
$check("'v2.4.8'  → 2040800", $vs->toScalar('v2.4.8') === 2040800);
$check("garbage   → null",    $vs->toScalar('not-a-version') === null);
$check("null      → null",    $vs->toScalar(null) === null);
$check('inRange 2.4.7 within [2.4.0, 2.4.9]',  $vs->inRange(2040700, 2040000, 2049999) === true);
$check('inRange 2.4.7 excluded from [2.4.9, ∞)', $vs->inRange(2040700, 2040900, null) === false);

// ---- summary ----------------------------------------------------------------------
echo "\n" . ($FAILS === 0 ? "ALL §9 GATES PASS" : "§9 GATE FAILURES: $FAILS") . "\n";
exit($FAILS === 0 ? 0 : 1);
