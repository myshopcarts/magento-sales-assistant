<?php
/**
 * Admin: remove a knowledge document (K2b brick 4) — drop its chunks from the store's
 * OpenSearch (deleteSource) and its registry row. The durable raw source in the central
 * bucket is left in place (retention/audit); a full purge is a later concern.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Document;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Ingest\KnowledgeIndexer;
use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRepository;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Myshopcarts_SalesAssistant::knowledge';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly KnowledgeIndexer $indexer,
        private readonly DocumentRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $ids = $this->sourceIds();
            if (!$ids) {
                throw new \RuntimeException('No document specified.');
            }
            foreach ($ids as $sourceId) {
                $this->indexer->deleteSource($sourceId);   // drops chunks + the list row
                $this->repository->deleteBySourceId($sourceId); // belt-and-suspenders
            }
            return $result->setData(['success' => true, 'deleted' => count($ids)]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Accept either a single `source_id` (back-compat) or a `source_ids` list (JSON array or a
     * repeated param) for a bulk delete. De-duplicated, blanks dropped.
     * @return string[]
     */
    private function sourceIds(): array
    {
        $req = $this->getRequest();
        $ids = [];
        $raw = $req->getParam('source_ids');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = $decoded;
            }
        } elseif (is_array($raw)) {
            $ids = $raw;
        }
        if (!$ids) {
            $single = (string)$req->getParam('source_id');
            if ($single !== '') {
                $ids = [$single];
            }
        }
        $out = [];
        foreach ($ids as $s) {
            $s = trim((string)$s);
            if ($s !== '') {
                $out[$s] = true; // dedupe
            }
        }
        return array_keys($out);
    }
}
