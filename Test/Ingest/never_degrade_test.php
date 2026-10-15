<?php
/**
 * Regression: indexing never degrades silently (doc/sales-assistant-indexing-v3.md §3c).
 *
 * Before 1.2.0 an exhausted embedding allowance (backend 402) or a provider error made the indexers
 * write the batch WITHOUT vectors and move on — the rest of a large catalogue silently became
 * keyword-only and was never healed. Now:
 *   - EmbedGate: 402 → paused (no further /embed calls; re-checks the budget hourly, resumes when
 *     it's available); 429/5xx → exponential back-off; success → ok.
 *   - the indexer still writes the batch (keyword-searchable, never missing) and stops calling
 *     /embed while the gate is closed;
 *   - VectorBackfill finds every vector-less document in the index and adds the vectors in place.
 *
 * Bare `php` (no Magento / OpenSearch): in-memory flag table, OpenSearch docs and backend.
 *     php Test/Ingest/never_degrade_test.php   # exit 0 = pass, 1 = fail
 */
declare(strict_types=1);

$base = dirname(__DIR__, 2);

if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log; interface LoggerInterface {}');
}
if (!class_exists('OpenSearch\\Client')) {
    eval('namespace OpenSearch; class Client {}');
}
if (!interface_exists('Magento\\Framework\\App\\Config\\ScopeConfigInterface')) {
    eval('namespace Magento\\Framework\\App\\Config; interface ScopeConfigInterface {
        public function getValue($path, $scopeType = "default", $scopeCode = null);
        public function isSetFlag($path, $scopeType = "default", $scopeCode = null); }');
}
if (!class_exists('Magento\\Framework\\FlagManager')) {
    eval('namespace Magento\\Framework; class FlagManager { private array $d = [];
        public function getFlagData($c) { return $this->d[$c] ?? null; }
        public function saveFlag($c, $v) { $this->d[$c] = $v; return true; }
        public function deleteFlag($c) { unset($this->d[$c]); return true; } }');
}

require $base . '/Model/Ingest/KnowledgeIndexInterface.php';
require $base . '/Model/Knowledge/DocumentRegistryInterface.php';
require $base . '/Model/OpenSearch/ClientProvider.php';
require $base . '/Model/OpenSearch/Schema.php';
require $base . '/Model/SalesAssistantClient.php';
require $base . '/Model/SalesAssistantApiException.php';
require $base . '/Model/Ingest/EmbedGate.php';
require $base . '/Model/Ingest/KnowledgeIndexer.php';
require $base . '/Model/Ingest/IndexingThrottle.php';
require $base . '/Model/Ingest/VectorBackfill.php';

/** Config stub: indexing/cpu_share = $share. */
final class ShareConfig implements \Magento\Framework\App\Config\ScopeConfigInterface
{
    public function __construct(private $share = '100')
    {
    }

    public function getValue($path, $scopeType = 'default', $scopeCode = null)
    {
        return $this->share;
    }

    public function isSetFlag($path, $scopeType = 'default', $scopeCode = null)
    {
        return false;
    }
}

use Magento\Framework\FlagManager;
use Myshopcarts\SalesAssistant\Model\Ingest\EmbedGate;
use Myshopcarts\SalesAssistant\Model\Ingest\KnowledgeIndexer;
use Myshopcarts\SalesAssistant\Model\Ingest\VectorBackfill;
use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRegistryInterface;
use Myshopcarts\SalesAssistant\Model\OpenSearch\ClientProvider;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Myshopcarts\SalesAssistant\Model\SalesAssistantApiException;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;

/** In-memory index: bulk index/update + the two queries VectorBackfill uses. */
final class MemOs extends \OpenSearch\Client
{
    public array $docs = [];   // _id => doc
    public function __construct()
    {
    }

    public function bulk(array $p): array
    {
        $b = $p['body'];
        for ($i = 0; $i < count($b); $i += 2) {
            if (isset($b[$i]['index'])) {
                $this->docs[$b[$i]['index']['_id']] = $b[$i + 1];
            } elseif (isset($b[$i]['update'])) {
                $this->docs[$b[$i]['update']['_id']] = array_merge($this->docs[$b[$i]['update']['_id']], $b[$i + 1]['doc']);
            }
        }
        return ['errors' => false];
    }

    private function missingIds(): array
    {
        return array_keys(array_filter($this->docs, fn($d) => !isset($d['embedding']) && ($d['text'] ?? '') !== ''));
    }

    public function search(array $p): array
    {
        $hits = [];
        foreach (array_slice($this->missingIds(), 0, $p['body']['size']) as $id) {
            $hits[] = ['_index' => 'kn_v1', '_id' => $id, '_source' => ['text' => $this->docs[$id]['text']]];
        }
        return ['hits' => ['hits' => $hits]];
    }

    public function count(array $p): array
    {
        return ['count' => count($this->missingIds())];
    }

    public function deleteByQuery(array $p): array
    {
        return [];
    }
}

final class MemProvider extends ClientProvider
{
    public function __construct(private MemOs $c)
    {
    }

    public function get(): \OpenSearch\Client
    {
        return $this->c;
    }
}

final class VecSchema extends Schema
{
    public function __construct()
    {
    }

    public function knowledgeIndex(): string
    {
        return 'kn';
    }

    public function productsIndex(): string
    {
        return 'pr';
    }

    public function ensureKnowledgeIndex(): bool
    {
        return true;   // vector-capable
    }

    public function hasVector(string $index): bool
    {
        return $index === 'kn';   // products index absent in this test
    }
}

/** Programmable backend: embed() either returns one vector per text or throws $fail. */
final class ProgClient extends SalesAssistantClient
{
    public ?\Throwable $fail = null;
    public array $budget = ['available' => false];
    public int $embedCalls = 0;
    public int $budgetCalls = 0;
    public bool $meaning = true;   // catalogue meaning search on — these tests heal products too

    public function __construct()
    {
    }

    public function catalogueMeaningSearch(): bool
    {
        return $this->meaning;
    }

    public function embed(array $texts, string $inputType = 'search_document'): array
    {
        $this->embedCalls++;
        if ($this->fail) {
            throw $this->fail;
        }
        return ['embeddings' => array_map(fn() => [0.1, 0.2], $texts)];
    }

    public function embedBudget(): array
    {
        $this->budgetCalls++;
        return $this->budget;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

final class NullLog implements \Psr\Log\LoggerInterface
{
    public array $warnings = [];
    public function error($m, array $c = []): void
    {
    }

    public function warning($m, array $c = []): void
    {
        $this->warnings[] = $m;
    }

    public function info($m, array $c = []): void
    {
    }
}

final class NoopRegistry implements DocumentRegistryInterface
{
    public function save(array $doc): void
    {
    }

    public function findByContentHash(string $hash, string $exceptSourceId = ''): ?array
    {
        return null;
    }

    public function getBySourceId(string $sourceId): ?array
    {
        return null;
    }

    public function registerIndexed(string $sourceId, string $title, string $url, int $chunkCount, string $contentHash): void
    {
    }

    public function deleteBySourceId(string $sourceId): void
    {
    }
}

$FAILS = 0;
$check = static function (string $name, bool $ok) use (&$FAILS): void {
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n";
    if (!$ok) {
        $FAILS++;
    }
};
$chunks = static fn(int $from, int $n) => array_map(
    fn($i) => ['id' => "c$i", 'text' => "chunk $i", 'source_id' => 's', 'title' => 't'], range($from, $from + $n - 1)
);

$now = 1_000_000;
$clock = function () use (&$now): int {
    return $now;
};

// ------------------------------------------------------------------------------------------
echo "\n== EmbedGate state machine\n";
$flags = new FlagManager();
$api = new ProgClient();
$gate = new EmbedGate($flags, $api, new NullLog(), $clock);
$check('starts open', $gate->allows() && $gate->state()['state'] === 'ok');
$check('402 → reason allowance', $gate->failure(new SalesAssistantApiException('quota', 402)) === 'allowance');
$check('paused: no embed allowed', !$gate->allows() && $gate->state()['state'] === 'paused_allowance');
$check('no budget call before the recheck time', $api->budgetCalls === 0);
$now += EmbedGate::ALLOWANCE_RECHECK_SECONDS;
$check('after an hour, budget still spent → stays paused', !$gate->allows() && $api->budgetCalls === 1);
$now += EmbedGate::ALLOWANCE_RECHECK_SECONDS;
$api->budget = ['available' => true];
$check('budget available again → resumes', $gate->allows() && $gate->state()['state'] === 'ok');
$gate->failure(new SalesAssistantApiException('busy', 429));
$s1 = $gate->state();
$gate->failure(new SalesAssistantApiException('busy', 429));
$s2 = $gate->state();
$check('429 → back-off, doubling', $s1['reason'] === 'throttled' && $s1['until'] - $now === 60 && $s2['until'] - $now === 120);
$check('closed during back-off', !$gate->allows());
$now += 120;
$check('probe allowed after back-off', $gate->allows());
$gate->success();
$check('success → ok', $gate->state()['state'] === 'ok');
$check('no response (status 0) → provider back-off', $gate->failure(new \RuntimeException('timeout')) === 'provider');
$gate->reset();
$check('429 with retry_after → reason budget', $gate->failure(new SalesAssistantApiException('busy', 429, ['retry_after' => 17])) === 'budget');
$check('waits exactly retry_after, no growing back-off', $gate->state()['until'] - $now === 17 && $gate->state()['failures'] === 0);
$now += 17;
$check('open again after retry_after', $gate->allows());
$gate->reset();

// ------------------------------------------------------------------------------------------
echo "\n== Indexer: allowance runs out mid-run — nothing missing, nothing silently degraded\n";
$os = new MemOs();
$api = new ProgClient();
$gate = new EmbedGate(new FlagManager(), $api, new NullLog(), $clock);
$ki = new KnowledgeIndexer(new VecSchema(), new MemProvider($os), $api, new NullLog(), new NoopRegistry(), $gate);
$ki->index($chunks(1, 90));                                   // batch 1 embeds fine
$api->fail = new SalesAssistantApiException('embedding quota exhausted', 402);
$ki->index($chunks(91, 180));                                 // 402 on the first batch, then gate closed
$callsAfter = $api->embedCalls;
$check('every chunk was written (none missing)', count($os->docs) === 270);
$check('first 90 have vectors', count(array_filter($os->docs, fn($d) => isset($d['embedding']))) === 90);
$check('gate paused on 402', $gate->state()['state'] === 'paused_allowance');
$check('stopped calling /embed after the 402 (1 failing call, not one per batch)', $callsAfter === 2);
$bf = new VectorBackfill(new VecSchema(), new MemProvider($os), $api, $gate, new NullLog(), new \Myshopcarts\SalesAssistant\Model\Ingest\IndexingThrottle(new ShareConfig()));
$check('180 documents reported as waiting for a vector', $bf->missing()['knowledge'] === 180);
$api->meaning = false;
$check('meaning search off: products are not healed, knowledge still is', array_keys($bf->indexes()) === ['knowledge']);
$api->meaning = true;
$check('meaning search on: products healed too', array_keys($bf->indexes()) === ['products', 'knowledge']);
$r = $bf->run(1000, microtime(true) + 30);
$check('backfill does nothing while paused', $r['stopped'] === 'gate' && $r['embedded'] === 0 && $api->embedCalls === $callsAfter);

// ------------------------------------------------------------------------------------------
echo "\n== Backfill heals once the allowance is back\n";
$now += EmbedGate::ALLOWANCE_RECHECK_SECONDS;
$api->fail = null;
$api->budget = ['available' => true];
$r = $bf->run(1000, microtime(true) + 30);
$check('all 180 healed', $r['embedded'] === 180 && $r['stopped'] === 'done');
$check('no document left without a vector', $bf->missing()['knowledge'] === 0);
$check('products index without vector support is skipped', $bf->missing()['products'] === null);

echo "\n== Backfill respects its per-tick cap\n";
foreach ($os->docs as $id => $d) {
    unset($os->docs[$id]['embedding']);
}
$r = $bf->run(100, microtime(true) + 30);
$check('stops at the cap', $r['stopped'] === 'limit' && $r['embedded'] === 100);
$check('the rest wait for the next tick', $bf->missing()['knowledge'] === 170);

// ------------------------------------------------------------------------------------------
echo "\n== Shared indexing budget (429 + retry_after): paced, not abandoned (1.2.2)\n";
$slept = [];
$sleeper = function (int $s) use (&$now, &$slept): void {
    $slept[] = $s;
    $now += $s;
};
/** Fails the first $n embed calls with a budget 429, then succeeds. */
final class BudgetClient extends SalesAssistantClient
{
    public function catalogueMeaningSearch(): bool
    {
        return true;
    }

    public int $calls = 0;
    public function __construct(public int $refuse)
    {
    }

    public function embed(array $texts, string $inputType = 'search_document'): array
    {
        $this->calls++;
        if ($this->refuse-- > 0) {
            throw new SalesAssistantApiException('indexing budget busy, retry later', 429, ['retry_after' => 20]);
        }
        return ['embeddings' => array_map(fn() => [0.3], $texts)];
    }

    public function embedBudget(): array
    {
        return ['available' => true];
    }
}
$os = new MemOs();
foreach (range(1, 120) as $i) {
    $os->docs["d$i"] = ['text' => "doc $i"];
}
$api = new BudgetClient(1);
$gate = new EmbedGate(new FlagManager(), $api, new NullLog(), $clock, $sleeper);
$bf = new VectorBackfill(new VecSchema(), new MemProvider($os), $api, $gate, new NullLog(), new \Myshopcarts\SalesAssistant\Model\Ingest\IndexingThrottle(new ShareConfig()));
$r = $bf->run(1000, microtime(true) + 600);
$check('backfill waited out the budget minute and finished in the same run', $r['stopped'] === 'done' && $r['embedded'] === 120);
$check('slept exactly retry_after once', $slept === [20]);

$os = new MemOs();
$api = new BudgetClient(2);
$slept = [];
$gate = new EmbedGate(new FlagManager(), $api, new NullLog(), $clock, $sleeper);
$ki = new KnowledgeIndexer(new VecSchema(), new MemProvider($os), $api, new NullLog(), new NoopRegistry(), $gate);
$ki->index($chunks(1, 50));
$check('indexer paced twice and kept the vectors', count(array_filter($os->docs, fn($d) => isset($d['embedding']))) === 50 && $slept === [20, 20]);
$os = new MemOs();
$api = new BudgetClient(5);
$slept = [];
$gate = new EmbedGate(new FlagManager(), $api, new NullLog(), $clock, $sleeper);
$ki = new KnowledgeIndexer(new VecSchema(), new MemProvider($os), $api, new NullLog(), new NoopRegistry(), $gate);
$ki->index($chunks(1, 50));
$check('after 3 budget refusals it writes the batch anyway (keyword-only, healed later)', count($os->docs) === 50 && $api->calls === 3);

// ------------------------------------------------------------------------------------------
echo "\n== IndexingThrottle: indexing takes only a fraction of the time\n";
use Myshopcarts\SalesAssistant\Model\Ingest\IndexingThrottle;
$naps = [];
$t = new IndexingThrottle(new ShareConfig(null), function (float $s) use (&$naps): void { $naps[] = $s; });
$t->yield(1.0);
$check('default share 25 % → sleeps 3 s after 1 s of work', $t->share() === 25 && abs($naps[0] - 3.0) < 1e-9);
$naps = [];
$t = new IndexingThrottle(new ShareConfig('100'), function (float $s) use (&$naps): void { $naps[] = $s; });
$t->yield(5.0);
$check('100 % → no pauses', $naps === []);
$naps = [];
$t = new IndexingThrottle(new ShareConfig('1'), function (float $s) use (&$naps): void { $naps[] = $s; });
$t->yield(60.0);
$check('share clamped to ≥ 5 % and a single pause capped at 30 s', $t->share() === 5 && $naps === [30.0]);

echo $FAILS === 0 ? "\nALL PASS\n" : "\n$FAILS FAILED\n";
exit($FAILS === 0 ? 0 : 1);
