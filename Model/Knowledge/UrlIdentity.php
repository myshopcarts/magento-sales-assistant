<?php
/**
 * UrlIdentity — one knowledge entry per crawled PAGE, not per URL spelling
 * (doc/sales-assistant-crawl-url-normalisation.md). `/a`, `/a/`, `/a?utm_source=x` and
 * `/a#top` are the same page; keyed by their raw strings they became separate entries, and
 * after the page changed the extra spelling kept the old text. The canonical URL:
 *   - https only, lower-case host, no #fragment;
 *   - no trailing slash, except the root "/";
 *   - tracking parameters dropped (utm_*, gclid, fbclid, msclkid, mc_cid, mc_eid), the rest
 *     sorted, an empty "?" dropped.
 * Fetching is unchanged — only how a page is keyed and which URL is stored with its chunks.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

final class UrlIdentity
{
    private const TRACKING = ['gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid'];

    /** The canonical URL of $url, or null when it isn't an https URL. */
    public static function canonical(string $url): ?string
    {
        $url = trim($url);
        if (($h = strpos($url, '#')) !== false) {
            $url = substr($url, 0, $h);
        }
        $p = parse_url($url);
        if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return null;
        }
        $path = ($p['path'] ?? '') !== '' ? $p['path'] : '/';
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }
        $keep = [];
        foreach (explode('&', (string)($p['query'] ?? '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
            if (!str_starts_with($name, 'utm_') && !in_array($name, self::TRACKING, true)) {
                $keep[] = $pair;
            }
        }
        sort($keep, SORT_STRING);
        $port = isset($p['port']) ? ':' . (int)$p['port'] : '';
        return 'https://' . strtolower($p['host']) . $port . $path . ($keep ? '?' . implode('&', $keep) : '');
    }

    /** Knowledge source id of a crawled page. */
    public static function sourceId(string $url): string
    {
        return 'url:' . md5(self::canonical($url) ?? $url);
    }

    /**
     * Keys this page may have been indexed under before the canonical key existed (the raw URL and
     * its toggled-slash twin) that differ from the canonical key — deleted on the next crawl.
     * @return string[]
     */
    public static function oldSourceIds(string $url): array
    {
        $raw = trim($url);
        $p = parse_url($raw);
        $twin = null;
        if (is_array($p) && !isset($p['query']) && ($p['path'] ?? '/') !== '/' && ($p['path'] ?? '') !== '') {
            $twin = str_ends_with($raw, '/') ? rtrim($raw, '/') : $raw . '/';
        }
        $canonical = self::sourceId($url);
        $out = [];
        foreach (array_filter([$raw, $twin]) as $u) {
            $id = 'url:' . md5($u);
            if ($id !== $canonical) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }
}
