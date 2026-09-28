<?php
/**
 * Admin: START a bulk crawl (crawl brick C2) over a curated URL set. Consent-gated. Creates
 * the job + its per-URL work items and flips it to `running`; the cron worker (RunCrawls)
 * then ingests them in bounded batches. Nothing is fetched here — this just enqueues.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Crawl;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlDiscovery;
use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlJobRepository;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;

class Start extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Myshopcarts_SalesAssistant::knowledge';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly SalesAssistantClient $client,
        private readonly CrawlJobRepository $jobs
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
            $req = $this->getRequest();
            if (!$req->getParam('consent')) {
                throw new \RuntimeException(
                    'Please confirm you own the site or have permission to crawl it before continuing.'
                );
            }
            $rootUrl = trim((string)$req->getParam('root_url'));
            $origin = trim((string)$req->getParam('origin'));
            $method = (string)$req->getParam('method') === 'sitemap' ? 'sitemap' : 'crawl';

            $decoded = json_decode((string)$req->getParam('urls'), true);
            $urls = [];
            foreach (is_array($decoded) ? $decoded : [] as $u) {
                $url = trim((string)(is_array($u) ? ($u['url'] ?? '') : $u));
                if ($url !== '') {
                    $urls[] = ['url' => $url, 'lastmod' => (string)(is_array($u) ? ($u['lastmod'] ?? '') : '')];
                }
            }
            if (!$urls) {
                throw new \RuntimeException('No pages selected to crawl.');
            }
            if (count($urls) > CrawlDiscovery::MAX_URLS) {
                $urls = array_slice($urls, 0, CrawlDiscovery::MAX_URLS);
            }

            $jobId = $this->jobs->createJob($rootUrl, $origin, $method, true);
            $this->jobs->addUrls($jobId, $urls);
            $this->jobs->setJobStatus($jobId, 'running');

            return $result->setData(['success' => true, 'job_id' => $jobId, 'total' => count($urls)]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
