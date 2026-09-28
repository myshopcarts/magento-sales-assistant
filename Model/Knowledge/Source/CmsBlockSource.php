<?php
/**
 * CmsBlockSource — ingest active CMS static blocks as knowledge. Many CMS pages are
 * just a {{widget}} wrapper around a static block (e.g. Backups, Sending Emails), so
 * the real content lives here, not on the page. Same HTML→markdown→structure-first
 * chunking as CmsPageSource; source id = "cms_block:<identifier>".
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge\Source;

use Magento\Cms\Model\ResourceModel\Block\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Myshopcarts\SalesAssistant\Model\Knowledge\HtmlToMarkdown;
use Myshopcarts\SalesAssistant\Model\Knowledge\MarkdownChunker;

class CmsBlockSource implements KnowledgeSourceInterface
{
    private const ENABLE_PATH = 'myshopcarts_salesassistant/knowledge/index_cms_blocks';

    public function __construct(
        private readonly CollectionFactory $blocks,
        private readonly HtmlToMarkdown $html,
        private readonly MarkdownChunker $chunker,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function documents(): iterable
    {
        $enabled = $this->scopeConfig->isSetFlag(self::ENABLE_PATH);
        $collection = $this->blocks->create();
        $collection->addFieldToFilter('is_active', 1);
        foreach ($collection as $block) {
            $identifier = (string)$block->getIdentifier();
            $sourceId = 'cms_block:' . $identifier;
            // Off → yield empty chunks so the reindexer purges anything previously indexed.
            if (!$enabled) {
                yield ['source_id' => $sourceId, 'chunks' => []];
                continue;
            }
            $markdown = $this->html->convert((string)$block->getContent());
            $chunks = [];
            if (trim($markdown) !== '') {
                $chunks = $this->chunker->chunk($markdown, [
                    'title' => (string)$block->getTitle(),
                    'source_id' => $sourceId,
                    'updated_at' => $this->iso((string)$block->getData('update_time')),
                    'content_hash' => sha1((string)$block->getContent()),
                ]);
                foreach ($chunks as &$chunk) {
                    $chunk['source_type'] = 'cms_block';
                }
                unset($chunk);
            }
            yield ['source_id' => $sourceId, 'chunks' => $chunks];
        }
    }

    private function iso(string $v): string
    {
        $ts = $v !== '' ? strtotime($v) : false;
        return gmdate('c', $ts !== false ? $ts : time());
    }
}
