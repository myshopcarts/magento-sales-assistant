<?php
/**
 * A full rebuild killed mid-way leaves Magento's changelog view SUSPENDED and the indexer WORKING —
 * product/page changes then silently stop reaching the assistant (seen on dev 2026-10-09 after a
 * load test stopped two rebuilds). RebuildRecovery must clean that up, and must never touch a
 * rebuild that is still alive or one that has only just started.
 *
 *     php Test/Indexer/rebuild_recovery_test.php   # exit 0 = pass, 1 = fail
 */
declare(strict_types=1);

$base = dirname(__DIR__, 2);
if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log; interface LoggerInterface {}');
}
if (!class_exists('OpenSearch\\Client')) {
    eval('namespace OpenSearch; class Client {}');
}
if (!class_exists('Magento\\Framework\\FlagManager')) {
    eval('namespace Magento\\Framework; class FlagManager { private array $d = [];
        public function getFlagData($c) { return $this->d[$c] ?? null; }
        public function saveFlag($c, $v) { $this->d[$c] = $v; return true; }
        public function deleteFlag($c) { unset($this->d[$c]); return true; } }');
}
if (!interface_exists('Magento\\Framework\\Indexer\\StateInterface')) {
    eval('namespace Magento\\Framework\\Indexer; interface StateInterface {
        const STATUS_WORKING = "working"; const STATUS_VALID = "valid"; const STATUS_INVALID = "invalid"; }');
}
if (!class_exists('Magento\\Framework\\Indexer\\IndexerRegistry')) {
    eval('namespace Magento\\Framework\\Indexer; class IndexerRegistry { public array $map = [];
        public function get($id) { return $this->map[$id]; } }');
}
require $base . '/Model/OpenSearch/ClientProvider.php';
require $base . '/Model/OpenSearch/Schema.php';
require $base . '/Model/Indexer/RebuildHeartbeat.php';
require $base . '/Model/Indexer/RebuildRecovery.php';

use Magento\Framework\FlagManager;
use Magento\Framework\Indexer\IndexerRegistry;
use Myshopcarts\SalesAssistant\Model\Indexer\RebuildHeartbeat as HB;
use Myshopcarts\SalesAssistant\Model\Indexer\RebuildRecovery;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;

final class NullLog implements \Psr\Log\LoggerInterface
{
    public array $warnings = [];
    public function __call($m, $a) { if ($m === 'warning') { $this->warnings[] = $a[0]; } }
}

/** Fake Magento indexer: status + state(updated) + view(state, resume). */
final class FakeIndexer
{
    public string $status; public string $view; public string $updated; public bool $resumed = false;
    public function __construct(string $status, string $view, string $updated)
    {
        $this->status = $status; $this->view = $view; $this->updated = $updated;
    }
    public function getStatus() { return $this->status; }
    public function getState()
    {
        $ix = $this;
        return new class($ix) {
            public function __construct(private $ix) {}
            public function getUpdated() { return $this->ix->updated; }
            public function setStatus($s) { $this->ix->status = $s; return $this; }
            public function save() { return $this; }
        };
    }
    public function getView()
    {
        $ix = $this;
        return new class($ix) {
            public function __construct(private $ix) {}
            public function getState() { $ix = $this->ix; return new class($ix) { public function __construct(private $ix) {} public function getStatus() { return $this->ix->view; } }; }
            public function resume() { $this->ix->view = 'idle'; $this->ix->resumed = true; }
        };
    }
}

final class FakeSchema extends Schema
{
    public array $orphans = ['products' => [], 'kn' => []];
    public array $deleted = [];
    public function __construct() {}
    public function productsIndex(): string { return 'products'; }
    public function knowledgeIndex(): string { return 'kn'; }
    public function orphanVersions(string $alias): array { return $this->orphans[$alias]; }
    public function deleteProductsVersion(string $version): void { $this->deleted[] = $version; $this->orphans['products'] = []; }
    public function deleteKnowledgeVersion(string $version): void { $this->deleted[] = $version; $this->orphans['kn'] = []; }
}

$FAILS = 0;
$check = function (string $name, bool $ok) use (&$FAILS): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . "\n";
    $FAILS += $ok ? 0 : 1;
};
$now = 1_800_000_000;
$clock = function () use (&$now): int { return $now; };
$ts = fn(int $t): string => gmdate('Y-m-d H:i:s', $t);
$setup = function (FakeIndexer $cat) use ($clock, $ts, &$now) {
    $reg = new IndexerRegistry();
    $reg->map = [HB::CATALOGUE => $cat, HB::KNOWLEDGE => new FakeIndexer('valid', 'idle', $ts($now - 99999))];
    $hb = new HB(new FlagManager(), $clock);
    $schema = new FakeSchema();
    $log = new NullLog();
    return [$reg, $hb, $schema, $log, new RebuildRecovery($reg, $hb, $schema, $log, $clock)];
};

echo "== a rebuild killed an hour ago\n";
$cat = new FakeIndexer('working', 'suspended', $ts($now - 3600));
[$reg, $hb, $schema, $log, $rec] = $setup($cat);
$hb->start(HB::CATALOGUE, 'products_v1');
$schema->orphans['products'] = ['products_v1'];
$now += 3600;                                  // heartbeat went quiet an hour ago
$cat->updated = $ts($now - 3600);
$found = $rec->inspect();
$check('found: stuck + one half-built version', isset($found[HB::CATALOGUE]) && $found[HB::CATALOGUE]['stuck'] && $found[HB::CATALOGUE]['orphans'] === ['products_v1']);
$check('knowledge (healthy) not reported', !isset($found[HB::KNOWLEDGE]));
$done = $rec->heal();
$check('change tracking resumed', $cat->resumed && $cat->view === 'idle');
$check('indexer set back to valid (never invalid — that would trigger a full re-embed)', $cat->status === 'valid');
$check('half-built version deleted', $schema->deleted === ['products_v1']);
$check('interruption recorded for status, heartbeat cleared', isset($hb->interrupted()[HB::CATALOGUE]) && $hb->get(HB::CATALOGUE) === null);
$check('logged once', count($log->warnings) === 1);
$check('a second pass finds nothing', $rec->inspect() === [] && $rec->heal() === []);

echo "== a rebuild that is alive\n";
$cat = new FakeIndexer('working', 'suspended', $ts($now - 7200));   // running for 2 h …
[$reg, $hb, $schema, $log, $rec] = $setup($cat);
$hb->start(HB::CATALOGUE, 'products_v2');
$now += 600;
$hb->beat(HB::CATALOGUE);                      // … and made progress just now
$schema->orphans['products'] = ['products_v2'];
$check('alive rebuild: nothing reported', $rec->inspect() === []);
$check('alive rebuild: nothing touched', $rec->heal() === [] && !$cat->resumed && $schema->deleted === []);

echo "== a rebuild that has only just started (state set, no heartbeat yet)\n";
$cat = new FakeIndexer('working', 'suspended', $ts($now - 20));
[$reg, $hb, $schema, $log, $rec] = $setup($cat);
$check('just started: not touched', $rec->inspect() === [] && !$cat->resumed);

echo "== the dev case: status reset by hand, view left suspended\n";
$cat = new FakeIndexer('valid', 'suspended', $ts($now - 1900));
[$reg, $hb, $schema, $log, $rec] = $setup($cat);
$rec->heal();
$check('suspended view resumed even though the indexer says valid', $cat->resumed && $cat->status === 'valid');

echo "== a finished rebuild clears an old interruption note\n";
$cat = new FakeIndexer('valid', 'idle', $ts($now - 99999));
[$reg, $hb, $schema, $log, $rec] = $setup($cat);
$hb->markInterrupted(HB::CATALOGUE, $now - 5000);
$hb->start(HB::CATALOGUE, 'v3');
$hb->finish(HB::CATALOGUE, true);
$check('note cleared on a completed rebuild', $hb->interrupted() === []);

echo $FAILS === 0 ? "\nALL PASS\n" : "\n$FAILS FAILED\n";
exit($FAILS === 0 ? 0 : 1);
