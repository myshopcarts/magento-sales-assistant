<?php
/**
 * CmsPageSource — ingest the store's active CMS pages (policies, backups, migration,
 * T&Cs …) as knowledge. HTML → markdown (heading structure preserved) → structure-first
 * chunks. Source id = "cms:<identifier>"; each chunk carries source_type=cms_page and the
 * page's public URL for citation.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge\Source;

use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Myshopcarts\SalesAssistant\Model\Knowledge\HtmlToMarkdown;
use Myshopcarts\SalesAssistant\Model\Knowledge\MarkdownChunker;

class CmsPageSource implements KnowledgeSourceInterface
{
    private const ENABLE_PATH = 'myshopcarts_salesassistant/knowledge/index_cms_pages';
    /** Magento's built-in utility pages — never assistant knowledge, always skipped. (The home
     *  page is NOT skipped — a merchant may put real content there.) */
    private const SYSTEM_PAGES = ['no-route', 'enable-cookies'];

    public function __construct(
        private readonly CollectionFactory $pages,
        private readonly HtmlToMarkdown $html,
        private readonly MarkdownChunker $chunker,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function documents(): iterable
    {
        $enabled = $this->scopeConfig->isSetFlag(self::ENABLE_PATH);
        $base = $this->baseUrl();
        $collection = $this->pages->create();
        $collection->addFieldToFilter('is_active', 1);
        foreach ($collection as $page) {
            $identifier = (string)$page->getIdentifier();
            $sourceId = 'cms:' . $identifier;
            // Skip when the CMS-page source is off, or the page is a Magento system page. Yield the
            // source with EMPTY chunks (not `continue`) so the reindexer's deleteSource PURGES
            // anything it had previously indexed for this id — turning the toggle off cleans up.
            if (!$enabled || in_array($identifier, self::SYSTEM_PAGES, true)) {
                yield ['source_id' => $sourceId, 'chunks' => []];
                continue;
            }
            $markdown = $this->html->convert((string)$page->getContent());
            $chunks = [];
            // Empty pages (e.g. a page that is only a {{widget}} wrapper — real content
            // lives in a static block, see CmsBlockSource) still yield the doc with no
            // chunks, so the indexer's deleteSource() clears any stale prior chunks.
            if (trim($markdown) !== '') {
                $chunks = $this->chunker->chunk($markdown, [
                    'title' => (string)$page->getTitle(),
                    'source_id' => $sourceId,
                    'url' => $base . ltrim($identifier, '/'),
                    'updated_at' => $this->iso((string)$page->getData('update_time')),
                    'content_hash' => sha1((string)$page->getContent()),
                ]);
                foreach ($chunks as &$chunk) {
                    $chunk['source_type'] = 'cms_page';
                }
                unset($chunk);
            }
            yield ['source_id' => $sourceId, 'chunks' => $chunks];
        }
    }

    private function baseUrl(): string
    {
        try {
            return $this->storeManager->getStore()->getBaseUrl();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** MySQL "Y-m-d H:i:s" (or empty) -> ISO-8601. */
    private function iso(string $v): string
    {
        $ts = $v !== '' ? strtotime($v) : false;
        return gmdate('c', $ts !== false ? $ts : time());
    }
}
