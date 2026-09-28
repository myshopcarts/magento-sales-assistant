<?php
/**
 * Admin: set retrieval PRIORITY (1=normal, 2=high, 3=highest) on the selected knowledge sources
 * — uploads or crawled pages. A higher priority boosts a source's ranking WHEN it's relevant to
 * the shopper's question, so a deliberately-curated reference (e.g. a plans doc) reliably wins
 * its topic over scraped page fragments, without hijacking unrelated questions. Registry-only —
 * KnowledgeSearch reads the priority at query time.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Adminhtml\Document;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRepository;

class Priority extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Myshopcarts_SalesAssistant::knowledge';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
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
                throw new \RuntimeException('No document selected.');
            }
            $priority = (int)$this->getRequest()->getParam('priority');
            if ($priority < 1 || $priority > 3) {
                throw new \RuntimeException('Invalid priority.');
            }
            $updated = $this->repository->setPriority($ids, $priority);
            return $result->setData(['success' => true, 'updated' => $updated]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /** @return string[] from `source_ids` (JSON array / repeated) or a single `source_id`. */
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
                $out[$s] = true;
            }
        }
        return array_keys($out);
    }
}
