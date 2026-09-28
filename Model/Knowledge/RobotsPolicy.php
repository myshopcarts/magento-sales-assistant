<?php
/**
 * RobotsPolicy (crawl brick C2) — respect the target site's robots.txt Disallow rules when
 * ingesting (politeness + legal), even on the merchant's own site. Fetched once per origin
 * through the SSRF-guarded fetcher; fail-OPEN (no robots.txt / unreadable → nothing disallowed,
 * the common case). The applicable group is our own user-agent's, else the `*` group.
 *
 * Deliberately small: prefix Disallow matching (the overwhelming majority of real rules). It
 * is a politeness gate on top of the same-origin + SSRF guards, not a full RFC 9309 engine.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

class RobotsPolicy
{
    private const AGENT = 'myshopcartsassistant';   // our UA token (lowercased), see UrlFetcher

    public function __construct(
        private readonly PageFetcherInterface $fetcher
    ) {
    }

    /** Fetch + parse robots.txt for an origin → the Disallow prefixes that apply to us. */
    public function forOrigin(string $origin): array
    {
        try {
            return $this->parse($this->fetcher->fetch(rtrim($origin, '/') . '/robots.txt'));
        } catch (\Throwable $e) {
            return [];   // no robots / unreadable → nothing disallowed
        }
    }

    /** Parse robots.txt into the Disallow prefix list that applies to our agent (pure). */
    public function parse(string $robotsTxt): array
    {
        $groups = [];          // ua => list<disallow prefix>
        $current = [];
        $lastWasAgent = false;
        foreach (preg_split('/\r?\n/', $robotsTxt) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $val] = explode(':', $line, 2);
            $field = strtolower(trim($field));
            $val = trim($val);
            if ($field === 'user-agent') {
                if (!$lastWasAgent) {
                    $current = [];        // a User-agent after a rule starts a new group
                }
                $ua = strtolower($val);
                $current[] = $ua;
                $groups[$ua] ??= [];
                $lastWasAgent = true;
            } elseif ($field === 'disallow') {
                foreach ($current as $ua) {
                    $groups[$ua][] = $val;
                }
                $lastWasAgent = false;
            } else {
                $lastWasAgent = false;
            }
        }
        // Our own UA group wins over '*'.
        foreach ($groups as $ua => $rules) {
            if ($ua !== '*' && $ua !== '' && str_contains(self::AGENT, $ua)) {
                return $rules;
            }
        }
        return $groups['*'] ?? [];
    }

    /** True if the URL may be fetched under these Disallow prefixes. */
    public function isAllowed(string $url, array $disallows): bool
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query) {
            $path .= '?' . $query;
        }
        foreach ($disallows as $rule) {
            if ($rule === '') {
                continue;               // empty Disallow = allow everything
            }
            $prefix = rtrim($rule, '*'); // basic trailing-wildcard tolerance
            if ($prefix === '' || str_starts_with($path, $prefix)) {
                return false;
            }
        }
        return true;
    }
}
