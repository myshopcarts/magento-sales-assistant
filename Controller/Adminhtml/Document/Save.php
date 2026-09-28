<?php
/**
 * Admin: commit a previewed (and possibly edited) knowledge document (K2b brick 4).
 * Takes the reviewed markdown, chunks it locally (MarkdownChunker), embeds + indexes it
 * into the store's OWN OpenSearch (KnowledgeIndexer), and records it in the registry so a
 * later reindex can re-chunk from the stored markdown. deleteSource first so a re-save
 * replaces cleanly.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Document;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Ingest\KnowledgeIndexer;
use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRepository;
use Myshopcarts\SalesAssistant\Model\Knowledge\MarkdownChunker;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Myshopcarts_SalesAssistant::knowledge';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly MarkdownChunker $chunker,
        private readonly KnowledgeIndexer $indexer,
        private readonly DocumentRepository $repository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $req = $this->getRequest();
            $sourceId = trim((string)$req->getParam('source_id'));
            $markdown = (string)$req->getParam('markdown');
            $title = trim((string)$req->getParam('title'));
            $s3Key = (string)$req->getParam('s3_key');
            if ($sourceId === '' || trim($markdown) === '') {
                throw new \RuntimeException('Nothing to save — preview a document first.');
            }
            $url = (string)$req->getParam('url');
            $contentHash = sha1($markdown);

            // Identical content under a DIFFERENT source (e.g. the same file under another
            // name) — don't silently double-index it. Refuse with the existing doc's name;
            // the caller may retry with allow_duplicate to override.
            if (!$req->getParam('allow_duplicate')) {
                $dupe = $this->repository->findByContentHash($contentHash, $sourceId);
                if ($dupe) {
                    return $result->setData([
                        'success' => false,
                        'duplicate' => true,
                        'error' => sprintf('This is identical to "%s", already in your knowledge base.',
                            $dupe['title'] ?: $dupe['source_id']),
                    ]);
                }
            }

            $chunks = $this->chunker->chunk($markdown, [
                'title' => $title,
                'source_id' => $sourceId,
                'url' => $url,                              // citation for URL sources
                'content_hash' => $contentHash,
            ]);

            $this->indexer->deleteSource($sourceId);       // replace on re-save
            $count = $this->indexer->index($chunks);        // embed + index locally

            $this->repository->save([
                'source_id' => $sourceId,
                'title' => $title,
                'filename' => (string)$req->getParam('filename'),
                's3_key' => $s3Key,
                'url' => $url,
                'content_hash' => $contentHash,
                'markdown' => $markdown,
                'chunk_count' => $count,
                'status' => 'indexed',
            ]);

            return $result->setData(['success' => true, 'chunk_count' => $count]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
