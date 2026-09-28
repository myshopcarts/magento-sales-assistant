<?php
/**
 * Crawl brick C2 — CrawlIngestor unit checks. Bare `php` (no Magento/OpenSearch): fakes for
 * the fetcher, the knowledge index and the document registry, but the REAL HtmlToMarkdown +
 * MarkdownChunker (both pure). Asserts the per-URL outcomes: indexed, skipped (no text /
 * duplicate), error (fetch failure), and that indexing goes through delete-then-index +
 * register.
 *
 *     php Test/Crawl/ingest_test.php    # exit 0 = all pass, 1 = a check failed
 */
declare(strict_types=1);

$base = dirname(__DIR__, 2);
require $base . '/Model/Knowledge/PageFetcherInterface.php';
require $base . '/Model/Knowledge/DocumentRegistryInterface.php';
require $base . '/Model/Ingest/KnowledgeIndexInterface.php';
require $base . '/Model/Knowledge/HtmlToMarkdown.php';
require $base . '/Model/Knowledge/MarkdownChunker.php';
require $base . '/Model/Knowledge/CrawlIngestor.php';

use Myshopcarts\SalesAssistant\Model\Ingest\KnowledgeIndexInterface;
use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlIngestor;
use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRegistryInterface;
use Myshopcarts\SalesAssistant\Model\Knowledge\HtmlToMarkdown;
use Myshopcarts\SalesAssistant\Model\Knowledge\MarkdownChunker;
use Myshopcarts\SalesAssistant\Model\Knowledge\PageFetcherInterface;

final class FakeFetcher implements PageFetcherInterface
{
    public function __construct(private array $pages)
    {
    }

    public function fetch(string $url): string
    {
        if (!array_key_exists($url, $this->pages)) {
            throw new \RuntimeException('that URL resolves to a private/internal address and cannot be fetched.');
        }
        return $this->pages[$url];
    }
}

final class FakeIndex implements KnowledgeIndexInterface
{
    public array $deleted = [];
    public array $reindexed = [];
    public int $indexed = 0;

    public function deleteSource(string $sourceId): void
    {
        $this->deleted[] = $sourceId;
    }

    public function reindexSource(string $sourceId, iterable $chunks): int
    {
        $this->reindexed[] = $sourceId;
        return $this->index($chunks);
    }

    public function index(iterable $chunks): int
    {
        $n = is_array($chunks) ? count($chunks) : iterator_count($chunks);
        $this->indexed += $n;
        return $n;
    }

    public function backfillRegistry(): array
    {
        return ['sources' => 0, 'chunks' => 0];
    }

    // Blue/green rebuild lifecycle — unused by the crawl ingestor, stubbed for interface completeness.
    public string $rebuildTarget = '';

    public function beginRebuild(): string
    {
        return $this->rebuildTarget = 'idx_v1';
    }

    public function commitRebuild(): bool
    {
        return true;
    }

    public function abortRebuild(): void
    {
    }
}

final class FakeRegistry implements DocumentRegistryInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $saved = [];

    public function __construct(private array $byHash = [], private array $bySourceId = [])
    {
    }

    public function save(array $doc): void
    {
        $this->saved[] = $doc;
    }

    public function findByContentHash(string $hash, string $exceptSourceId = ''): ?array
    {
        return $this->byHash[$hash] ?? null;
    }

    public function getBySourceId(string $sourceId): ?array
    {
        return $this->bySourceId[$sourceId] ?? null;
    }

    // Called by the real KnowledgeIndexer (faked out here as FakeIndex), so these are stubs.
    public array $registered = [];

    public function registerIndexed(string $sourceId, string $title, string $url, int $chunkCount, string $contentHash): void
    {
        $this->registered[] = compact('sourceId', 'title', 'url', 'chunkCount', 'contentHash');
    }

    public array $deleted = [];

    public function deleteBySourceId(string $sourceId): void
    {
        $this->deleted[] = $sourceId;
    }
}

$FAILS = 0;
$check = static function (string $name, bool $ok) use (&$FAILS): void {
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n";
    if (!$ok) {
        $FAILS++;
    }
};
$make = static fn(PageFetcherInterface $f, FakeIndex $i, FakeRegistry $r): CrawlIngestor =>
    new CrawlIngestor($f, new HtmlToMarkdown(), new MarkdownChunker(), $i, $r);

$page = '<html><head><title>Import &amp; Export</title></head><body>'
    . '<h1>Importing and exporting</h1><p>You can export your store data as a database dump, '
    . 'then import it into another store. This paragraph is long enough to survive chunking '
    . 'and produce at least one indexed section for the knowledge base.</p>'
    . '<h2>Steps</h2><p>Open the portal, choose your store, and click Download database.</p>'
    . '</body></html>';

// -- 1. a good page indexes -------------------------------------------------
echo "\n== indexes a readable page\n";
$idx = new FakeIndex();
$reg = new FakeRegistry();
$r = $make(new FakeFetcher(['https://s.example/export' => $page]), $idx, $reg)->ingest('https://s.example/export');
$check('status indexed', $r['status'] === 'indexed');
$check('chunk_count > 0', $r['chunk_count'] > 0);
$check('stable url source_id', $r['source_id'] === 'url:' . md5('https://s.example/export'));
$check('title from <title>', $r['title'] === 'Import & Export');
$check('delete-then-index for the source', $idx->deleted === [$r['source_id']] && $idx->indexed === $r['chunk_count']);
$check('registered as indexed', count($reg->saved) === 1 && $reg->saved[0]['status'] === 'indexed'
    && $reg->saved[0]['url'] === 'https://s.example/export');

// -- 2. fetch failure → error (SSRF guard / HTTP) ---------------------------
echo "\n== fetch failure is an error, not a crash\n";
$idx = new FakeIndex();
$reg = new FakeRegistry();
$r = $make(new FakeFetcher([]), $idx, $reg)->ingest('https://blocked.example/x');
$check('status error', $r['status'] === 'error');
$check('carries the guard reason', str_contains($r['reason'], 'private/internal'));
$check('nothing indexed or saved', $idx->indexed === 0 && $reg->saved === []);

// -- 3. no readable text → skipped ------------------------------------------
echo "\n== empty page is skipped\n";
$r = $make(new FakeFetcher(['https://s.example/blank' => '<html><body>   </body></html>']),
    new FakeIndex(), new FakeRegistry())->ingest('https://s.example/blank');
$check('status skipped', $r['status'] === 'skipped');
$check('reason is no-text', str_contains($r['reason'], 'no readable text'));

// -- 4. duplicate content → skipped -----------------------------------------
echo "\n== duplicate content is skipped\n";
// Pre-compute the hash the ingestor will see for the page's extracted markdown.
$md = trim((new HtmlToMarkdown())->convertPage($page)['markdown']);
$hash = sha1($md);
$idx = new FakeIndex();
$reg = new FakeRegistry([$hash => ['title' => 'Import export', 'source_id' => 'url:other']]);
$r = $make(new FakeFetcher(['https://s.example/dup' => $page]), $idx, $reg)->ingest('https://s.example/dup');
$check('status skipped', $r['status'] === 'skipped');
$check('reason names the existing doc', str_contains($r['reason'], 'Import export'));
$check('not indexed or re-saved', $idx->indexed === 0 && $reg->saved === []);

// -- 5. trailing-slash retry: /magento 404s, /magento/ is the real page ------
echo "\n== retries the toggled-slash variant on a 404\n";
$idx = new FakeIndex();
$reg = new FakeRegistry();
// Only the slash form exists; the no-slash form throws (like a directory-index 404).
$r = $make(new FakeFetcher(['https://s.example/magento/' => $page]), $idx, $reg)->ingest('https://s.example/magento');
$check('indexed via the /magento/ retry', $r['status'] === 'indexed' && $r['chunk_count'] > 0);

echo "\n== a file-looking 404 is NOT retried (no false slash)\n";
$r = $make(new FakeFetcher([]), new FakeIndex(), new FakeRegistry())->ingest('https://s.example/thing.pdf');
$check('stays an error', $r['status'] === 'error');

// -- 6. incremental refresh: unchanged re-crawl is skipped, changed re-indexed
echo "\n== re-crawl: unchanged page is skipped, changed page re-indexed\n";
$url = 'https://s.example/page';
$sid = 'url:' . md5($url);
$md = trim((new HtmlToMarkdown())->convertPage($page)['markdown']);
$hash = sha1($md);
// unchanged: an existing doc with the SAME hash under this source_id
$idx = new FakeIndex();
$reg = new FakeRegistry([], [$sid => ['content_hash' => $hash, 'title' => 'Page']]);
$r = $make(new FakeFetcher([$url => $page]), $idx, $reg)->ingest($url);
$check('unchanged → skipped', $r['status'] === 'skipped' && str_contains($r['reason'], 'unchanged'));
$check('unchanged → not re-embedded', $idx->indexed === 0 && $reg->saved === []);
// changed: existing doc has a DIFFERENT hash → re-index
$idx = new FakeIndex();
$reg = new FakeRegistry([], [$sid => ['content_hash' => 'stale-hash', 'title' => 'Page']]);
$r = $make(new FakeFetcher([$url => $page]), $idx, $reg)->ingest($url);
$check('changed → re-indexed', $r['status'] === 'indexed' && $idx->indexed > 0);

echo "\n" . ($FAILS ? "FAILED ($FAILS)\n" : "ALL PASS\n");
exit($FAILS ? 1 : 0);
