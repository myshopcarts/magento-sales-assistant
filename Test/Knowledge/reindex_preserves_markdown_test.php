<?php
/**
 * Regression: a knowledge REINDEX must never destroy the durable markdown of an uploaded/crawled
 * source. Reproduces — against the REAL KnowledgeIndexer — the production data-loss we confirmed
 * live on www-myshopcarts-com (a crawl indexed 25 docs / 218 chunks; ONE reindex stripped all
 * markdown; a SECOND reindex purged the whole knowledge base to 0).
 *
 * The bug: Knowledge::executeFull() did deleteSource() (which deletes the OpenSearch chunks AND the
 * registry row, incl. its markdown) then index() (whose registerIndexed() re-creates the row WITHOUT
 * markdown). So reindex #1 nulled the markdown source-of-truth and reindex #2 — finding empty
 * markdown -> empty chunks -> deleteSource — purged everything.
 *
 * The fix: reindexSource() clears only the index chunks and re-adds them, leaving the registry row
 * (markdown) intact; executeFull() uses it for any source that still yields chunks, and only falls
 * back to deleteSource() for a genuine removal (no chunks: CMS toggle off / deleted upload).
 *
 * Bare `php` (no Magento / OpenSearch): stubs the OpenSearch client + Psr logger, subclasses the
 * concrete collaborators with no-op ctors, and uses a STATEFUL registry that faithfully models the
 * DB semantics (registerIndexed preserves markdown only if the row still exists; deleteBySourceId
 * removes the whole row). The "control" cases prove that model reproduces the real bug, so the
 * survival assertions are meaningful.
 *
 *     php Test/Knowledge/reindex_preserves_markdown_test.php   # exit 0 = pass, 1 = fail
 */
declare(strict_types=1);

$base = dirname(__DIR__, 2);

// --- stubs for types that only exist at runtime in a real store -----------------------------
if (!interface_exists('Psr\\Log\\LoggerInterface')) {
    eval('namespace Psr\\Log; interface LoggerInterface {}');
}
if (!class_exists('OpenSearch\\Client')) {
    eval('namespace OpenSearch; class Client {}');
}
if (!class_exists('Magento\\Framework\\FlagManager')) {   // in-memory stand-in for the flag table
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

use Myshopcarts\SalesAssistant\Model\Ingest\KnowledgeIndexer;
use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRegistryInterface;
use Myshopcarts\SalesAssistant\Model\OpenSearch\ClientProvider;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;

/** Records the OpenSearch calls KnowledgeIndexer makes; enough to observe clear + re-add. */
final class FakeOsClient extends \OpenSearch\Client
{
    public array $deletedByQuery = [];
    public array $bulkIndexes = []; // the _index each bulk targeted
    public int $bulks = 0;

    public function __construct()
    {
    }

    public function deleteByQuery(array $params): array
    {
        $sid = $params['body']['query']['term']['source_id'] ?? '?';
        $this->deletedByQuery[] = $sid;
        return ['deleted' => 0];
    }

    public function bulk(array $params): array
    {
        $this->bulks++;
        $this->bulkIndexes[] = $params['body'][0]['index']['_index'] ?? '?';
        return ['errors' => false, 'items' => []];
    }
}

final class FakeClientProvider extends ClientProvider
{
    public function __construct(private FakeOsClient $c)
    {
    }

    public function get(): \OpenSearch\Client
    {
        return $this->c;
    }
}

final class FakeSchema extends Schema
{
    public array $built = [];
    public array $promoted = [];
    public array $deletedVersions = [];
    public bool $promoteResult = true;

    public function __construct()
    {
    }

    public function knowledgeIndex(): string
    {
        return 'magento2_myshopcarts_knowledge';
    }

    public function ensureKnowledgeIndex(): bool
    {
        return false; // lexical-only: no embed backend call needed for the test
    }

    public function hasVector(string $index): bool
    {
        return false;
    }

    public function buildKnowledgeVersion(): string
    {
        $v = $this->knowledgeIndex() . '_v' . count($this->built);
        $this->built[] = $v;
        return $v;
    }

    public function promoteKnowledgeVersion(string $version): bool
    {
        $this->promoted[] = $version;
        return $this->promoteResult;
    }

    public function deleteKnowledgeVersion(string $version): void
    {
        $this->deletedVersions[] = $version;
    }
}

final class FakeSaClient extends SalesAssistantClient
{
    public function __construct()
    {
    }
}

final class FakeLogger implements \Psr\Log\LoggerInterface
{
    public function error($m, array $c = []): void
    {
    }

    public function warning($m, array $c = []): void
    {
    }
}

/**
 * Stateful registry that models the real DB row semantics:
 *  - save(): the crawl/upload writes the full row INCLUDING markdown (source of truth).
 *  - registerIndexed(): insertOnDuplicate that only touches index-derived columns — so it PRESERVES
 *    markdown IF the row still exists, but a row re-created after a delete has NULL markdown.
 *  - deleteBySourceId(): drops the whole row (markdown gone).
 */
final class StatefulRegistry implements DocumentRegistryInterface
{
    /** @var array<string,array<string,mixed>> */
    private array $rows = [];
    public array $deleted = [];

    public function save(array $doc): void
    {
        $this->rows[(string)$doc['source_id']] = $doc;
    }

    public function findByContentHash(string $hash, string $exceptSourceId = ''): ?array
    {
        return null;
    }

    public function getBySourceId(string $sourceId): ?array
    {
        return $this->rows[$sourceId] ?? null;
    }

    public function registerIndexed(string $sourceId, string $title, string $url, int $chunkCount, string $contentHash): void
    {
        if (isset($this->rows[$sourceId])) {
            // insertOnDuplicate: update index-derived columns only, markdown preserved.
            $this->rows[$sourceId] = array_merge($this->rows[$sourceId], [
                'title' => $title, 'url' => $url, 'content_hash' => $contentHash,
                'chunk_count' => $chunkCount, 'status' => 'indexed',
            ]);
        } else {
            // fresh insert after the row was deleted: NO markdown column is written.
            $this->rows[$sourceId] = [
                'source_id' => $sourceId, 'title' => $title, 'url' => $url,
                'content_hash' => $contentHash, 'chunk_count' => $chunkCount,
                'status' => 'indexed', 'markdown' => null,
            ];
        }
    }

    public function deleteBySourceId(string $sourceId): void
    {
        unset($this->rows[$sourceId]);
        $this->deleted[] = $sourceId;
    }

    public function mdLen(string $sourceId): int
    {
        return strlen((string)($this->rows[$sourceId]['markdown'] ?? ''));
    }
}

$FAILS = 0;
$check = static function (string $name, bool $ok) use (&$FAILS): void {
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n";
    if (!$ok) {
        $FAILS++;
    }
};

$makeKi = static function (StatefulRegistry $reg, FakeOsClient $os): KnowledgeIndexer {
    return new KnowledgeIndexer(new FakeSchema(), new FakeClientProvider($os), new FakeSaClient(), new FakeLogger(), $reg, new \Myshopcarts\SalesAssistant\Model\Ingest\EmbedGate(new \Magento\Framework\FlagManager(), new FakeSaClient(), new FakeLogger()));
};
$seed = static function (StatefulRegistry $reg, string $sid): void {
    $reg->save([
        'source_id' => $sid, 'title' => 'Doc', 'url' => 'https://s.example/' . $sid,
        'content_hash' => 'h', 'chunk_count' => 1, 'status' => 'indexed',
        'markdown' => "# Doc\n\n" . str_repeat('This is durable crawled content. ', 20),
    ]);
};
$chunksFor = static function (StatefulRegistry $reg, string $sid): array {
    $row = $reg->getBySourceId($sid);
    $md = trim((string)($row['markdown'] ?? ''));
    return $md === '' ? [] : [[
        'id' => $sid . '__0', 'text' => $md, 'source_id' => $sid,
        'title' => (string)($row['title'] ?? ''), 'url' => (string)($row['url'] ?? ''),
        'content_hash' => (string)($row['content_hash'] ?? ''),
    ]];
};

// -- 1. reindexSource is non-destructive: clears chunks, re-indexes, KEEPS the registry row --
echo "\n== reindexSource preserves the durable markdown row\n";
$reg = new StatefulRegistry();
$os = new FakeOsClient();
$ki = $makeKi($reg, $os);
$seed($reg, 'url:x');
$check('seed row has markdown', $reg->mdLen('url:x') > 0);
$ki->reindexSource('url:x', $chunksFor($reg, 'url:x'));
$check('cleared the source index chunks', $os->deletedByQuery === ['url:x']);
$check('re-indexed (bulk write happened)', $os->bulks === 1);
$check('did NOT delete the registry row', $reg->deleted === []);
$check('markdown still intact', $reg->mdLen('url:x') > 0);

// -- 2. deleteSource IS destructive (genuine removal path) ----------------------------------
echo "\n== deleteSource removes the row (removal path)\n";
$ki->deleteSource('url:x');
$check('registry row deleted', $reg->deleted === ['url:x'] && $reg->getBySourceId('url:x') === null);

// -- 3. blue/green rebuild builds a FRESH version and publishes it (v2b) --------------------
echo "\n== blue/green rebuild targets a fresh version, commit promotes it (no deletes)\n";
$reg = new StatefulRegistry();
$os = new FakeOsClient();
$schema = new FakeSchema();
$ki = new KnowledgeIndexer($schema, new FakeClientProvider($os), new FakeSaClient(), new FakeLogger(), $reg, new \Myshopcarts\SalesAssistant\Model\Ingest\EmbedGate(new \Magento\Framework\FlagManager(), new FakeSaClient(), new FakeLogger()));
$seed($reg, 'url:y');
$version = $ki->beginRebuild();
$check('beginRebuild created a fresh version', str_contains($version, '_myshopcarts_knowledge_v'));
$ki->index($chunksFor($reg, 'url:y'));
$check('rebuild writes into the NEW version, not the live alias', $os->bulkIndexes === [$version]);
$check('rebuild NEVER clears chunks (fresh index → no tombstone churn)', $os->deletedByQuery === []);
$check('rebuild NEVER deletes the registry row/markdown', $reg->deleted === [] && $reg->mdLen('url:y') > 0);
$check('commit promotes the fresh version', $ki->commitRebuild() === true && $schema->promoted === [$version]);
$check('promoted version is not discarded', $schema->deletedVersions === []);

// -- 4. commit REFUSES a bad publish (e.g. empty over non-empty) and discards the version ----
echo "\n== commitRebuild refuses a bad publish and keeps the current index\n";
$os = new FakeOsClient();
$schema = new FakeSchema();
$schema->promoteResult = false;
$ki = new KnowledgeIndexer($schema, new FakeClientProvider($os), new FakeSaClient(), new FakeLogger(), new StatefulRegistry(), new \Myshopcarts\SalesAssistant\Model\Ingest\EmbedGate(new \Magento\Framework\FlagManager(), new FakeSaClient(), new FakeLogger()));
$version = $ki->beginRebuild();
$check('commit returns false when refused', $ki->commitRebuild() === false);
$check('the refused version is discarded', $schema->deletedVersions === [$version]);

// -- 5. abortRebuild discards the half-built version, never promotes -------------------------
echo "\n== abortRebuild discards the in-progress version\n";
$schema = new FakeSchema();
$ki = new KnowledgeIndexer($schema, new FakeClientProvider(new FakeOsClient()), new FakeSaClient(), new FakeLogger(), new StatefulRegistry(), new \Myshopcarts\SalesAssistant\Model\Ingest\EmbedGate(new \Magento\Framework\FlagManager(), new FakeSaClient(), new FakeLogger()));
$version = $ki->beginRebuild();
$ki->abortRebuild();
$check('abort deleted the version', $schema->deletedVersions === [$version]);
$check('abort never promoted', $schema->promoted === []);

// -- 6. CONTROL: the OLD delete-then-index path reproduces the live data loss ----------------
echo "\n== control: old deleteSource-then-index path purges (the bug this guards)\n";
$reg = new StatefulRegistry();
$os = new FakeOsClient();
$ki = $makeKi($reg, $os);
$seed($reg, 'url:z');
$rebuildOld = static function () use ($ki, $reg, $chunksFor): void {
    $c = $chunksFor($reg, 'url:z');
    $ki->deleteSource('url:z'); // old: always delete the row first
    $ki->index($c);             // old: then re-index (no markdown restore)
};
$rebuildOld();
$check('OLD path strips markdown after reindex #1', $reg->mdLen('url:z') === 0 && $reg->getBySourceId('url:z') !== null);
$rebuildOld();
$check('OLD path purges the doc after reindex #2', $reg->getBySourceId('url:z') === null);

echo "\n" . ($FAILS ? "FAILED ($FAILS)\n" : "ALL PASS\n");
exit($FAILS ? 1 : 0);
