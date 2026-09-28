<?php
/**
 * Admin block for the Knowledge Documents page (K2b brick 4). Feeds the template the
 * document list + the same-origin admin endpoint URLs. No secret is exposed — all backend
 * calls happen server-side in the controllers.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlJobRepository;
use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRepository;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;

class Knowledge extends Template
{
    public function __construct(
        Context $context,
        private readonly DocumentRepository $repository,
        private readonly SalesAssistantClient $client,
        private readonly CrawlJobRepository $crawlJobs,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isConfigured(): bool
    {
        return $this->client->isEnabled();
    }

    /** True if either CMS auto-index source is on (drives the "your content is auto-indexed" note). */
    public function isCmsAutoIndexOn(): bool
    {
        return $this->_scopeConfig->isSetFlag('myshopcarts_salesassistant/knowledge/index_cms_pages')
            || $this->_scopeConfig->isSetFlag('myshopcarts_salesassistant/knowledge/index_cms_blocks');
    }

    /** @return array<int,array<string,mixed>> */
    public function getDocuments(): array
    {
        return $this->repository->list();
    }

    public function getPreviewUrl(): string
    {
        return $this->getUrl('salesassistant/document/preview');
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('salesassistant/document/save');
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('salesassistant/document/delete');
    }

    public function getClearUrl(): string
    {
        return $this->getUrl('salesassistant/document/clear');
    }

    public function getRebuildUrl(): string
    {
        return $this->getUrl('salesassistant/document/rebuild');
    }

    public function getPriorityUrl(): string
    {
        return $this->getUrl('salesassistant/document/priority');
    }

    /** Human label for a stored priority level (for the document list). */
    public function priorityLabel(int $priority): string
    {
        return [1 => 'Normal', 2 => 'High', 3 => 'Highest'][$priority] ?? 'Normal';
    }

    /** Endpoint for discovering candidate pages under a site root (crawl brick C1). */
    public function getDiscoverUrl(): string
    {
        return $this->getUrl('salesassistant/crawl/discover');
    }

    /** Endpoints for the async crawl job (crawl brick C2). */
    public function getCrawlStartUrl(): string
    {
        return $this->getUrl('salesassistant/crawl/start');
    }

    public function getCrawlStatusUrl(): string
    {
        return $this->getUrl('salesassistant/crawl/status');
    }

    public function getCrawlCancelUrl(): string
    {
        return $this->getUrl('salesassistant/crawl/cancel');
    }

    public function getCrawlDeleteUrl(): string
    {
        return $this->getUrl('salesassistant/crawl/delete');
    }

    public function getCrawlResultsUrl(): string
    {
        return $this->getUrl('salesassistant/crawl/results');
    }

    /** Recent crawl jobs for the "Recent crawls" list. @return array<int,array<string,mixed>> */
    public function getCrawlJobs(): array
    {
        return $this->crawlJobs->listJobs(20);
    }
}
