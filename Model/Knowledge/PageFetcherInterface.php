<?php
/**
 * PageFetcherInterface — the SSRF-guarded "fetch one URL" seam. UrlFetcher is the real
 * implementation (https-only, private-IP refusal, IP-pinned, no-redirect); crawl discovery
 * and ingest depend on THIS interface so the same guard applies to every fetched URL — and
 * so the logic is unit-testable with a fake fetcher (no Magento/curl).
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

interface PageFetcherInterface
{
    /** @return string raw response body. Throws on any SSRF-guard violation or fetch failure. */
    public function fetch(string $url): string;
}
