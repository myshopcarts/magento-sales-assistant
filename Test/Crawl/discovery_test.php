<?php
/**
 * Crawl brick C1 — CrawlDiscovery unit checks. Zero-dependency (bare `php`, no Magento/curl),
 * like the §9 gates: a fake PageFetcher returns canned robots/sitemap/HTML so we assert the
 * sitemap-first strategy, sitemap-index recursion, the BFS fallback, and the security
 * invariants (same-origin lock, https-only, caps, no off-site links).
 *
 *     php Test/Crawl/discovery_test.php    # exit 0 = all pass, 1 = a check failed
 */
declare(strict_types=1);

$base = dirname(__DIR__, 2); // …/Myshopcarts/SalesAssistant
require $base . '/Model/Knowledge/PageFetcherInterface.php';
require $base . '/Model/Knowledge/CrawlDiscovery.php';

use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlDiscovery;
use Myshopcarts\SalesAssistant\Model\Knowledge\PageFetcherInterface;

/** A fetcher backed by a URL→body map; unknown URLs throw (like a 404/guard failure). */
final class FakeFetcher implements PageFetcherInterface
{
    /** @param array<string,string> $pages */
    public function __construct(private array $pages)
    {
    }

    public function fetch(string $url): string
    {
        if (!array_key_exists($url, $this->pages)) {
            throw new \RuntimeException("no such page: $url");
        }
        return $this->pages[$url];
    }
}

$FAILS = 0;
$check = static function (string $name, bool $ok) use (&$FAILS): void {
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n";
    if (!$ok) {
        $FAILS++;
    }
};
$urls = static fn(array $r): array => array_column($r['urls'], 'url');

// -- 1. sitemap via robots.txt, with a sitemap-index that fans out ------------
echo "\n== sitemap-first (robots → sitemap-index → urlsets)\n";
$pages = [
    'https://shop.example/robots.txt' => "User-agent: *\nSitemap: https://shop.example/sitemap_index.xml\n",
    'https://shop.example/sitemap_index.xml' =>
        '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<sitemap><loc>https://shop.example/sm-1.xml</loc></sitemap>'
        . '<sitemap><loc>https://shop.example/sm-2.xml</loc></sitemap>'
        . '<sitemap><loc>https://evil.example/sm-3.xml</loc></sitemap>'   // off-site → ignored
        . '</sitemapindex>',
    'https://shop.example/sm-1.xml' =>
        '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<url><loc>https://shop.example/a</loc><lastmod>2026-01-02</lastmod></url>'
        . '<url><loc>https://shop.example/b</loc></url>'
        . '</urlset>',
    'https://shop.example/sm-2.xml' =>
        '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<url><loc>https://shop.example/c</loc></url>'
        . '<url><loc>https://other.example/x</loc></url>'   // off-site → dropped
        . '</urlset>',
];
$r = (new CrawlDiscovery(new FakeFetcher($pages)))->discover('https://shop.example/');
$got = $urls($r);
sort($got);
$check('method is sitemap', $r['method'] === 'sitemap');
$check('collects a,b,c across the index', $got === ['https://shop.example/a', 'https://shop.example/b', 'https://shop.example/c']);
$check('captures lastmod', ($r['urls'][array_search('https://shop.example/a', $urls($r), true)]['lastmod'] ?? '') === '2026-01-02');
$check('drops off-site sitemap + off-site loc', !in_array('https://evil.example/sm-3.xml', $got, true) && !in_array('https://other.example/x', $got, true));

// -- 2. conventional /sitemap.xml when robots has none ------------------------
echo "\n== sitemap at the conventional /sitemap.xml (no robots)\n";
$pages = [
    'https://d.example/sitemap.xml' =>
        '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<url><loc>https://d.example/p1</loc></url><url><loc>https://d.example/p2</loc></url></urlset>',
];
$r = (new CrawlDiscovery(new FakeFetcher($pages)))->discover('https://d.example');
$check('finds it without robots.txt', $r['method'] === 'sitemap' && count($r['urls']) === 2);

// -- 3. BFS fallback when there is no sitemap ---------------------------------
echo "\n== crawl fallback (no sitemap → follow links, same-origin only)\n";
$home = '<html><body>'
    . '<a href="/docs/one">one</a>'
    . '<a href="/docs/two">two</a>'
    . '<a href="https://cdn.other/x">off</a>'          // off-site → ignored
    . '<a href="mailto:x@d.example">mail</a>'          // non-http → ignored
    . '<a href="#top">frag</a>'                         // fragment → ignored
    . '<a href="/cdn-cgi/l/email-protection">cf</a>'   // Cloudflare junk → ignored
    . '</body></html>';
$pages = [
    'https://c.example/' => $home,
    'https://c.example/docs/one' => '<html><body><a href="/docs/three">three</a></body></html>',
    'https://c.example/docs/two' => '<html><body>no links</body></html>',
    'https://c.example/docs/three' => '<html><body>leaf</body></html>',
];
$r = (new CrawlDiscovery(new FakeFetcher($pages)))->discover('https://c.example/');
$got = $urls($r);
sort($got);
$check('method is crawl', $r['method'] === 'crawl');
$check('follows same-origin links incl. one level deep',
    $got === ['https://c.example/', 'https://c.example/docs/one', 'https://c.example/docs/three', 'https://c.example/docs/two']);
$check('never leaves the origin', !in_array('https://cdn.other/x', $got, true));
$check('drops Cloudflare /cdn-cgi/ junk', !in_array('https://c.example/cdn-cgi/l/email-protection', $got, true));

// -- 4. security / hygiene ----------------------------------------------------
echo "\n== guards\n";
$d = new CrawlDiscovery(new FakeFetcher([]));
$threw = false;
try {
    $d->discover('http://insecure.example');   // http → rejected
} catch (\Throwable $e) {
    $threw = true;
}
$check('rejects non-https root', $threw);

$r = $d->discover('https://empty.example');     // nothing resolves/fetches
$check('empty site yields no urls, no throw', $r['method'] === 'crawl' && $r['urls'] === []);

// cap is honored
$many = [];
$body = '<html><body>';
for ($i = 0; $i < 30; $i++) {
    $body .= '<a href="/p' . $i . '">' . $i . '</a>';
}
$body .= '</body></html>';
$many['https://big.example/'] = $body;
$r = (new CrawlDiscovery(new FakeFetcher($many)))->discover('https://big.example/', 10);
$check('respects the maxUrls cap', count($r['urls']) === 10);

echo "\n" . ($FAILS ? "FAILED ($FAILS)\n" : "ALL PASS\n");
exit($FAILS ? 1 : 0);
