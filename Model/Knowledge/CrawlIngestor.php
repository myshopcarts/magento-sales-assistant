<?php
/**
 * CrawlIngestor (crawl brick C2) — ingest ONE crawled page into the local knowledge index,
 * reusing the exact single-URL pipeline (K3): SSRF-guarded fetch → main-content markdown →
 * content-hash dedup → chunk → embed+index → register. Pure per-URL unit: it takes a URL and
 * returns a structured result; the caller (cron worker) persists the crawl_url row + job
 * counters. No DB of its own → unit-testable with fakes.
 *
 * One entry per page (doc/sales-assistant-crawl-url-normalisation.md): the source id and the
 * stored URL come from the canonical URL (UrlIdentity), so `/a`, `/a/`, `/a?utm_source=x` are one
 * entry; keys from before that (raw URL, toggled slash) are deleted on the next crawl, and a page
 * skipped as a duplicate drops its own previously indexed copy.
 *
 * Result status mirrors the crawl_url table:
 *   indexed — fetched, chunked, embedded + indexed; source registered.
 *   skipped — reachable but nothing to add (no readable text, or duplicate content).
 *   error   — could not fetch/parse (SSRF guard, HTTP, empty).
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

use Myshopcarts\SalesAssistant\Model\Ingest\KnowledgeIndexInterface;

class CrawlIngestor
{
    public function __construct(
        private readonly PageFetcherInterface $fetcher,
        private readonly HtmlToMarkdown $html,
        private readonly MarkdownChunker $chunker,
        private readonly KnowledgeIndexInterface $indexer,
        private readonly DocumentRegistryInterface $registry
    ) {
    }

    /**
     * @return array{status:string,source_id:string,title:string,content_hash:string,chunk_count:int,reason:string}
     */
    public function ingest(string $url): array
    {
        $sourceId = UrlIdentity::sourceId($url);
        $pageUrl = UrlIdentity::canonical($url) ?? $url;
        $out = ['status' => 'error', 'source_id' => $sourceId, 'title' => '',
                'content_hash' => '', 'chunk_count' => 0, 'reason' => ''];

        try {
            $rawHtml = $this->fetchResolving($url);
        } catch (\Throwable $e) {
            $out['reason'] = $e->getMessage();
            return $out;
        }

        // Old spellings of this page's key (pre-canonical entries) — removed once, on this crawl.
        foreach (UrlIdentity::oldSourceIds($url) as $old) {
            if ($this->registry->getBySourceId($old)) {
                $this->indexer->deleteSource($old);
            }
        }

        $page = $this->html->convertPage($rawHtml);
        $markdown = trim((string)($page['markdown'] ?? ''));
        $out['title'] = ($page['title'] ?? '') !== '' ? (string)$page['title'] : $url;
        if ($markdown === '') {
            $out['status'] = 'skipped';
            $out['reason'] = 'no readable text found';
            return $out;
        }

        $hash = sha1($markdown);
        $out['content_hash'] = $hash;

        // Incremental refresh: this exact page is already indexed and UNCHANGED since last
        // crawl → skip, don't re-embed. Makes a re-crawl cheap + idempotent.
        $existing = $this->registry->getBySourceId($sourceId);
        if ($existing && (string)($existing['content_hash'] ?? '') === $hash) {
            $out['status'] = 'skipped';
            $out['reason'] = 'unchanged — already in your knowledge base';
            return $out;
        }

        // Content dedup: identical content already in the KB under another source (a canonical
        // duplicate — everywhere on real sites) → skip, don't double-index.
        $dupe = $this->registry->findByContentHash($hash, $sourceId);
        if ($dupe) {
            if ($existing) {
                // this page's own earlier copy would keep answering with the old text
                $this->indexer->deleteSource($sourceId);
            }
            $out['status'] = 'skipped';
            $out['reason'] = 'duplicate of “' . ($dupe['title'] ?: $dupe['source_id']) . '”';
            return $out;
        }

        $chunks = $this->chunker->chunk($markdown, [
            'title' => $out['title'],
            'source_id' => $sourceId,
            'url' => $pageUrl,
            'content_hash' => $hash,
        ]);
        if (!$chunks) {
            $out['status'] = 'skipped';
            $out['reason'] = 'no readable text found';
            return $out;
        }

        $this->indexer->deleteSource($sourceId);        // idempotent re-ingest
        $count = $this->indexer->index($chunks);
        $this->registry->save([
            'source_id' => $sourceId,
            'title' => $out['title'],
            'filename' => '',
            's3_key' => '',
            'url' => $pageUrl,
            'content_hash' => $hash,
            'markdown' => $markdown,
            'chunk_count' => $count,
            'status' => 'indexed',
        ]);

        $out['status'] = 'indexed';
        $out['chunk_count'] = $count;
        return $out;
    }

    /**
     * Fetch, tolerating a trailing-slash mismatch on directory/index URLs: a link like
     * `/magento` can 404 while the real page is `/magento/` (or vice-versa). On a failure,
     * retry the toggled-slash variant once. This also transparently covers a genuine
     * `/x` → `/x/` redirect (UrlFetcher refuses the 3xx; we just fetch the target directly).
     */
    private function fetchResolving(string $url): string
    {
        try {
            return $this->fetcher->fetch($url);
        } catch (\Throwable $e) {
            $alt = $this->toggleTrailingSlash($url);
            if ($alt === null) {
                throw $e;
            }
            return $this->fetcher->fetch($alt);   // if this also fails, its error propagates
        }
    }

    /** `/magento` ⇄ `/magento/`, only for extension-less, query-less paths; null otherwise. */
    private function toggleTrailingSlash(string $url): ?string
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host']) || isset($p['query'])) {
            return null;
        }
        $path = $p['path'] ?? '/';
        $last = substr($path, (int)strrpos($path, '/') + 1);
        if ($path === '/' || str_contains($last, '.')) {
            return null;   // root, or looks like a file — don't toggle
        }
        $newPath = str_ends_with($path, '/') ? rtrim($path, '/') : $path . '/';
        return 'https://' . $p['host'] . $newPath;
    }
}
