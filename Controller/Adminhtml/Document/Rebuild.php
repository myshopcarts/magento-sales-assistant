<?php
/**
 * Admin: REBUILD the Knowledge documents list from the index. The list is a DB registry that
 * records each source as it's indexed; content indexed before that registry existed (or added
 * outside it) is searchable in OpenSearch but missing from the list. This scans the knowledge
 * index and re-registers every source it finds — so the list matches what the assistant actually
 * searches, WITHOUT re-crawling or re-uploading and without disturbing existing priorities or the
 * indexed chunks themselves. Read-only against OpenSearch; only writes registry rows.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Document;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Ingest\KnowledgeIndexInterface;

class Rebuild extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Myshopcarts_SalesAssistant::knowledge';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly KnowledgeIndexInterface $indexer
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $stats = $this->indexer->backfillRegistry();
            return $result->setData([
                'success' => true,
                'sources' => (int)($stats['sources'] ?? 0),
                'chunks' => (int)($stats['chunks'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
