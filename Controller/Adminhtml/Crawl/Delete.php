<?php
/**
 * Admin: DELETE crawl history (crawl brick C2). Bulk-removes the selected crawl JOB records +
 * their per-URL work items so the "Recent crawls" list doesn't grow unbounded. This clears only
 * the job log — already-indexed knowledge chunks are separate (delete those from the documents
 * list / Clear knowledge base). Accepts a `job_ids` list (JSON) or a single `job_id`.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Crawl;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlJobRepository;

class Delete extends Action implements HttpPostActionInterface
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
            $ids = $this->jobIds();
            if (!$ids) {
                throw new \RuntimeException('No crawl selected.');
            }
            $deleted = $this->jobs->deleteJobs($ids);
            return $result->setData(['success' => true, 'deleted' => $deleted]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /** @return int[] from `job_ids` (JSON array / repeated) or a single `job_id`. */
    private function jobIds(): array
    {
        $req = $this->getRequest();
        $ids = [];
        $raw = $req->getParam('job_ids');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = $decoded;
            }
        } elseif (is_array($raw)) {
            $ids = $raw;
        }
        if (!$ids) {
            $single = (int)$req->getParam('job_id');
            if ($single > 0) {
                $ids = [$single];
            }
        }
        $out = [];
        foreach ($ids as $i) {
            $i = (int)$i;
            if ($i > 0) {
                $out[$i] = true; // dedupe
            }
        }
        return array_keys($out);
    }
}
