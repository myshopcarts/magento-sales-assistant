<?php
/**
 * POST /salesassistant/widget/knowledge — the widget's local KNOWLEDGE search proxy
 * (K4). Sibling of Widget/Search (products). The widget (browser) can't reach the
 * merchant's OpenSearch, so it POSTs the shopper's question here; we run the local
 * eligibility-aware hybrid knowledge search and return the top section chunks (heading
 * path + text + source URL for citation). The widget relays these up the WS as
 * `knowledge_result`, and the backend streams a grounded, cited answer.
 *
 * Security (§2): the request body carries ONLY {query}. Edition/version eligibility is
 * derived server-side inside KnowledgeSearch (EligibilityContext) — never accepted from
 * the client — so a prompt-injected scope can't widen reachability. Read-only,
 * same-origin, CsrfAware; only runs when the assistant is configured.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Widget;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Myshopcarts\SalesAssistant\Model\Search\KnowledgeSearch;
use Psr\Log\LoggerInterface;

class Knowledge implements ActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly HttpRequest $request,
        private readonly Json $json,
        private readonly SalesAssistantClient $client,
        private readonly KnowledgeSearch $search,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        if (!$this->client->isEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['passages' => []]);
        }
        try {
            $body = $this->json->unserialize($this->request->getContent() ?: '{}');
        } catch (\Throwable $e) {
            $body = [];
        }
        $query = trim((string)($body['query'] ?? ''));
        if ($query === '') {
            return $result->setData(['passages' => []]);
        }
        try {
            $passages = $this->search->search($query);
        } catch (\Throwable $e) {
            $this->logger->error('SalesAssistant knowledge search failed: ' . $e->getMessage());
            return $result->setData(['passages' => []]);
        }
        return $result->setData(['passages' => $passages]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null; // read-only same-origin search
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
