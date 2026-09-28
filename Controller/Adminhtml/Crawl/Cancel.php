<?php
/**
 * Admin: CANCEL a running crawl (crawl brick C2). Flips the job to `cancelled`; the cron
 * worker stops advancing it on the next tick. Already-indexed pages stay in the knowledge
 * base (cancel halts further ingest, it does not roll back).
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Crawl;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlJobRepository;

class Cancel extends Action implements HttpPostActionInterface
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
            if (!in_array((string)$job['status'], ['done', 'cancelled', 'failed'], true)) {
                $this->jobs->setJobStatus($jobId, 'cancelled');
            }
            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
