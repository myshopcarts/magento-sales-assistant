<?php
/**
 * Admin: fetch a crawl job's per-URL outcomes ON DEMAND (crawl brick C2). Separate from the
 * lightweight progress poll (Status) so the potentially-large row set is loaded only when the
 * merchant clicks "View results" / "Download" — never during the live progress polling.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Crawl;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlJobRepository;

class Results extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Myshopcarts_SalesAssistant::knowledge';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CrawlJobRepository $jobs
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $jobId = (int)$this->getRequest()->getParam('job_id');
            $job = $jobId ? $this->jobs->getJob($jobId) : null;
            if (!$job) {
                throw new \RuntimeException('Crawl not found.');
            }
            $urls = [];
            foreach ($this->jobs->jobUrls($jobId, 2000) as $r) {
                $urls[] = [
                    'url' => $r['url'],
                    'status' => $r['status'],
                    'reason' => $r['reason'],
                    'chunk_count' => (int)$r['chunk_count'],
                ];
            }
            return $result->setData([
                'success' => true,
                'root_url' => $job['root_url'],
                'status' => $job['status'],
                'total' => (int)$job['total_urls'],
                'done' => (int)$job['done_urls'],
                'skipped' => (int)$job['skipped_urls'],
                'failed' => (int)$job['failed_urls'],
                'urls' => $urls,
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
