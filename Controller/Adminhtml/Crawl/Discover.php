<?php
/**
 * Admin: discover candidate pages under a site root (crawl brick C1), BEFORE any ingest.
 * Runs on the MERCHANT's server via the SSRF-guarded fetcher; returns the sitemap/crawl URL
 * list for the merchant to review + curate. Nothing is embedded here.
 *
 * Consent gate (§6a): discovery already fetches the site, so it is gated on the merchant's
 * attestation that they own the site or have the owner's permission — enforced here, not only
 * in the UI.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Crawl;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlDiscovery;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;

class Discover extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Myshopcarts_SalesAssistant::knowledge';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly SalesAssistantClient $client,
        private readonly CrawlDiscovery $discovery
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            if (!$this->client->isEnabled()) {
                throw new \RuntimeException('The assistant is not configured.');
            }
            if (!$this->getRequest()->getParam('consent')) {
                throw new \RuntimeException(
                    'Please confirm you own the site or have permission to crawl it before continuing.'
                );
            }
            $root = trim((string)$this->getRequest()->getParam('root_url'));
            if ($root === '') {
                throw new \RuntimeException('Enter the site URL to crawl.');
            }
            $found = $this->discovery->discover($root);

            return $result->setData([
                'success' => true,
                'method' => $found['method'],
                'origin' => $found['origin'],
                'note' => $found['note'],
                'count' => count($found['urls']),
                'urls' => $found['urls'],
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
