<?php
/**
 * Admin: poll a crawl job's progress (crawl brick C2) for the live UI. Read-only: returns the
 * job status + progress counters, and (when finished) the per-URL outcomes for a spot-check.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Crawl;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlJobRepository;

class Status extends Action implements HttpPostActionInterface
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
            $status = (string)$job['status'];
            $done = (int)$job['done_urls'];
            $failed = (int)$job['failed_urls'];
            $skipped = (int)$job['skipped_urls'];
            $total = (int)$job['total_urls'];
            $processed = $done + $failed + $skipped;

            $data = [
                'success' => true,
                'status' => $status,
                'total' => $total,
                'done' => $done,
                'failed' => $failed,
                'skipped' => $skipped,
                'processed' => $processed,
                'finished' => in_array($status, ['done', 'cancelled', 'failed'], true),
            ];
            // Once finished, include the per-URL outcomes (capped) so the merchant can review.
            if ($data['finished']) {
                $rows = [];
                foreach ($this->jobs->jobUrls($jobId, 500) as $r) {
                    $rows[] = [
                        'url' => $r['url'],
                        'status' => $r['status'],
                        'reason' => $r['reason'],
                        'chunk_count' => (int)$r['chunk_count'],
                    ];
                }
                $data['urls'] = $rows;
            }
            return $result->setData($data);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
