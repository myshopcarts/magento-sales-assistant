<?php
/**
 * UrlFetcher — fetch a merchant-supplied page for the knowledge base (K3), under a strict
 * SSRF guard. The fetch runs on the MERCHANT's server (the extension), not our backend, so
 * arbitrary URLs never touch our infrastructure; even so we refuse anything that could
 * reach internal resources:
 *   - https only (no http/file/gopher/…),
 *   - resolve the host and REFUSE if any resolved IP is private/loopback/link-local/
 *     reserved (blocks 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7, …
 *     and cloud metadata 169.254.169.254),
 *   - PIN the validated IP via CURLOPT_RESOLVE so libcurl can't re-resolve to a different
 *     address (DNS-rebinding), and do NOT follow redirects (a 30x could bounce to an
 *     internal host) — the merchant enters the final URL,
 *   - bounded timeout + response size.
 * One page only: no crawling. Returns the raw HTML for main-content extraction.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

use Magento\Framework\HTTP\Client\CurlFactory;

class UrlFetcher implements PageFetcherInterface
{
    private const TIMEOUT_SECONDS = 15;
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly CurlFactory $curlFactory
    ) {
    }

    /** @return string raw HTML. Throws on any SSRF-guard violation or fetch failure. */
    public function fetch(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new \RuntimeException('Only https:// URLs are allowed.');
        }
        $host = (string)$parts['host'];
        $ips = $this->resolve($host);
        if (!$ips) {
            throw new \RuntimeException('Could not resolve the URL host.');
        }
        foreach ($ips as $ip) {
            if (!$this->isPublic($ip)) {
                throw new \RuntimeException('That URL resolves to a private/internal address and cannot be fetched.');
            }
        }

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,                 // no SSRF-via-redirect
            CURLOPT_RESOLVE => array_map(static fn($ip) => $host . ':443:' . $ip, $ips),
            CURLOPT_USERAGENT => 'MyShopCartsAssistant/1.0',
        ]);
        $curl->get($url);

        $status = $curl->getStatus();
        if ($status >= 300 && $status < 400) {
            throw new \RuntimeException('The page redirected — please enter the final page URL.');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Could not fetch the page (HTTP %d).', $status));
        }
        $body = (string)$curl->getBody();
        if ($body === '') {
            throw new \RuntimeException('The page returned no content.');
        }
        return strlen($body) > self::MAX_BYTES ? substr($body, 0, self::MAX_BYTES) : $body;
    }

    /** @return array<int,string> resolved public-facing candidate IPs (A + AAAA). */
    private function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaa as $rec) {
            if (!empty($rec['ipv6'])) {
                $ips[] = (string)$rec['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

    private function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
