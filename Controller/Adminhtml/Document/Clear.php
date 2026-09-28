<?php
/**
 * Admin: CLEAR the knowledge base — a deliberate clean-slate nuke (safeguard for "start over,
 * be certain it's clean"). Drops ONLY this extension's own knowledge index in the merchant's
 * OpenSearch ({prefix}_myshopcarts_knowledge — never a wildcard, never the products index or any
 * other app's index; Schema::dropKnowledgeIndex enforces that) and clears the document registry,
 * so a fresh reindex / re-crawl rebuilds from nothing. Durable raw sources in the central bucket
 * are left in place; re-uploading / re-crawling repopulates. The index is recreated lazily on the
 * next ingest.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Document;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRepository;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;

class Clear extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Myshopcarts_SalesAssistant::knowledge';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Schema $schema,
        private readonly DocumentRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            // Drop the chunks first; only clear the registry once the index is actually gone.
            $this->schema->dropKnowledgeIndex();
            $this->repository->deleteAll();
            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
