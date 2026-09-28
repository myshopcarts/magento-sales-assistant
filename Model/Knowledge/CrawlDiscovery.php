<?php
/**
 * CrawlDiscovery (crawl brick C1) — turn a root site URL into a list of candidate page URLs
 * to ingest, WITHOUT fetching anything for embedding yet. Discovery is the cheap "what's
 * here?" step the merchant reviews before committing a crawl (discover → estimate → curate →
 * ingest). Two strategies, sitemap-first:
 *   1. SITEMAP — read robots.txt `Sitemap:` directives + the conventional /sitemap.xml,
 *      following sitemap-index files; the full URL list (+ lastmod) with zero crawling.
 *   2. CRAWL   — no sitemap → a BOUNDED same-origin breadth-first pass that only COLLECTS
 *      <a href> links (depth + fetch caps), fetching nothing for embedding.
 *
 * Every network read goes through the SSRF-guarded PageFetcherInterface (UrlFetcher), so the
 * same private-IP/redirect/https protections apply to every discovered URL. Same-origin lock:
 * only the entered host is kept — off-site links are never followed. Everything is capped
 * (MAX_URLS / fetch budgets) so a huge or hostile site can't run away.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

class CrawlDiscovery
{
    public const MAX_URLS = 500;
    private const MAX_SITEMAP_FETCHES = 60;   // sitemap-index can nest; bound the fan-out
    private const MAX_BFS_FETCHES = 40;        // pages we fetch to harvest links (fallback)
    private const BFS_MAX_DEPTH = 2;

    public function __construct(
        private readonly PageFetcherInterface $fetcher
    ) {
    }

    /**
     * @return array{method:string,origin:string,urls:array<int,array{url:string,lastmod:string}>,note:string}
     */
    public function discover(string $rootUrl, int $maxUrls = self::MAX_URLS): array
    {
        $root = $this->normalize($rootUrl);
        if ($root === null) {
            throw new \RuntimeException('Enter a valid https:// site URL.');
        }
        $host = (string)parse_url($root, PHP_URL_HOST);
        $origin = 'https://' . $host;
        $maxUrls = max(1, min($maxUrls, self::MAX_URLS));

        $fromSitemap = $this->fromSitemaps($origin, $host, $maxUrls);
        if ($fromSitemap) {
            return [
                'method' => 'sitemap',
                'origin' => $origin,
                'urls' => $fromSitemap,
                'note' => sprintf('Found %d page(s) in the site’s sitemap.', count($fromSitemap)),
            ];
        }

        $fromCrawl = $this->fromCrawl($root, $host, $maxUrls);
        return [
            'method' => 'crawl',
            'origin' => $origin,
            'urls' => $fromCrawl,
            'note' => $fromCrawl
                ? sprintf('No sitemap found — discovered %d page(s) by following links from the site.', count($fromCrawl))
                : 'No sitemap was found and no crawlable links were discovered from that URL.',
        ];
    }

    // ---- strategy 1: sitemaps -------------------------------------------------

    /** @return array<int,array{url:string,lastmod:string}> */
    private function fromSitemaps(string $origin, string $host, int $maxUrls): array
    {
        $queue = [];
        try {
            foreach (preg_split('/\r?\n/', $this->fetcher->fetch($origin . '/robots.txt')) ?: [] as $line) {
                if (preg_match('/^\s*Sitemap:\s*(\S+)/i', $line, $m)) {
                    $u = $this->normalize($m[1]);
                    if ($u !== null && $this->sameHost($u, $host)) {
                        $queue[] = $u;
                    }
                }
            }
        } catch (\Throwable $e) {
            // no robots.txt — fine, fall through to the conventional location
        }
        $queue[] = $origin . '/sitemap.xml';
        $queue = array_values(array_unique($queue));

        $out = [];          // url => lastmod (dedup by url)
        $seen = [];
        $fetches = 0;
        while ($queue && $fetches < self::MAX_SITEMAP_FETCHES && count($out) < $maxUrls) {
            $sm = array_shift($queue);
            if (isset($seen[$sm])) {
                continue;
            }
            $seen[$sm] = true;
            $fetches++;
            try {
                $body = $this->gunzipMaybe($this->fetcher->fetch($sm));
            } catch (\Throwable $e) {
                continue;
            }
            $xml = $this->parseXml($body);
            if ($xml === null) {
                continue;
            }
            if (strtolower($xml->getName()) === 'sitemapindex') {
                foreach ($xml->sitemap as $s) {
                    $loc = $this->normalize((string)$s->loc);
                    if ($loc !== null && $this->sameHost($loc, $host)) {
                        $queue[] = $loc;
                    }
                }
                continue;
            }
            foreach ($xml->url as $u) {          // <urlset>
                $loc = $this->normalize((string)$u->loc);
                if ($loc === null || !$this->sameHost($loc, $host) || isset($out[$loc])) {
                    continue;
                }
                $out[$loc] = trim((string)($u->lastmod ?? ''));
                if (count($out) >= $maxUrls) {
                    break;
                }
            }
        }
        return $this->shape($out, $maxUrls);
    }

    // ---- strategy 2: bounded same-origin crawl (links only) -------------------

    /** @return array<int,array{url:string,lastmod:string}> */
    private function fromCrawl(string $root, string $host, int $maxUrls): array
    {
        $out = [];        // candidates (a page is a candidate once it's reached, or harvested as a link)
        $visited = [];    // pages we've fetched to harvest links from
        $queue = [[$root, 0]];
        $fetches = 0;
        while ($queue && $fetches < self::MAX_BFS_FETCHES && count($out) < $maxUrls) {
            [$url, $depth] = array_shift($queue);
            if (isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;
            $fetches++;
            try {
                $html = $this->fetcher->fetch($url);
            } catch (\Throwable $e) {
                continue;   // a page that can't be fetched (404/guard) is not a candidate
            }
            $out[$url] = '';    // reached OK → candidate
            if ($depth >= self::BFS_MAX_DEPTH) {
                continue;       // do not expand further, but keep it
            }
            foreach ($this->extractLinks($html, $url) as $link) {
                if (!$this->sameHost($link, $host)) {
                    continue;
                }
                if (!isset($out[$link])) {
                    $out[$link] = '';   // harvested same-origin link → candidate (not fetched here)
                    if (count($out) >= $maxUrls) {
                        break;
                    }
                }
                if (!isset($visited[$link])) {
                    $queue[] = [$link, $depth + 1];   // may be expanded for more links
                }
            }
        }
        return $this->shape($out, $maxUrls);
    }

    /** @return array<int,string> absolute, normalized, deduped links found in the HTML. */
    private function extractLinks(string $html, string $base): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $dom->loadHTML($html);
        libxml_clear_errors();
        if (!$ok) {
            return [];
        }
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#' || preg_match('#^(mailto:|tel:|javascript:|data:)#i', $href)) {
                continue;
            }
            $abs = $this->normalize($this->resolveUrl($base, $href));
            if ($abs !== null && !$this->isJunk($abs)) {
                $links[$abs] = true;
            }
        }
        return array_keys($links);
    }

    /** Non-content URLs to never collect — e.g. Cloudflare's /cdn-cgi/ endpoints (the
     *  email-protection link CF injects is a JS-only stub that 404s when fetched). */
    private function isJunk(string $url): bool
    {
        return stripos((string)parse_url($url, PHP_URL_PATH), '/cdn-cgi/') !== false;
    }

    // ---- url helpers ----------------------------------------------------------

    /** Absolute-ize a possibly-relative href against a base URL (handles //, /, and ../). */
    private function resolveUrl(string $base, string $href): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        $host = (string)parse_url($base, PHP_URL_HOST);
        $origin = 'https://' . $host;
        if ($href === '' ) {
            return $base;
        }
        if ($href[0] === '/') {
            return $origin . $this->collapse($href);
        }
        $path = (string)(parse_url($base, PHP_URL_PATH) ?: '/');
        $dir = substr($path, 0, (int)strrpos($path, '/') + 1);
        return $origin . $this->collapse(($dir ?: '/') . $href);
    }

    /** Collapse ./ and ../ segments in a path. */
    private function collapse(string $path): string
    {
        $query = '';
        if (($q = strpos($path, '?')) !== false) {
            $query = substr($path, $q);
            $path = substr($path, 0, $q);
        }
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return '/' . implode('/', $out) . $query;
    }

    /** Normalize to a canonical https URL (lowercased host, no fragment) or null if not https. */
    private function normalize(string $url): ?string
    {
        $url = trim($url);
        if (($h = strpos($url, '#')) !== false) {
            $url = substr($url, 0, $h);
        }
        $p = parse_url($url);
        if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return null;   // https-only (matches UrlFetcher); http/other → dropped
        }
        $path = ($p['path'] ?? '') !== '' ? $p['path'] : '/';
        $query = isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '';
        return 'https://' . strtolower($p['host']) . $path . $query;
    }

    private function sameHost(string $url, string $host): bool
    {
        return strtolower((string)parse_url($url, PHP_URL_HOST)) === $host;
    }

    private function gunzipMaybe(string $body): string
    {
        if (strlen($body) > 2 && substr($body, 0, 2) === "\x1f\x8b" && function_exists('gzdecode')) {
            $out = @gzdecode($body);
            if ($out !== false) {
                return $out;
            }
        }
        return $body;
    }

    private function parseXml(string $body): ?\SimpleXMLElement
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        return $xml === false ? null : $xml;
    }

    /** @param array<string,string> $urlToLastmod @return array<int,array{url:string,lastmod:string}> */
    private function shape(array $urlToLastmod, int $maxUrls): array
    {
        $out = [];
        foreach ($urlToLastmod as $url => $lastmod) {
            $out[] = ['url' => $url, 'lastmod' => $lastmod];
            if (count($out) >= $maxUrls) {
                break;
            }
        }
        return $out;
    }
}
