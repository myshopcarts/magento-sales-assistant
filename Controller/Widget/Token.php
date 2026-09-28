<?php
/**
 * GET /salesassistant/widget/token — the server-side token exchange.
 *
 * The widget (browser) calls THIS same-origin endpoint; we read the encrypted
 * secret key, call the backend /widget-token, and return ONLY the short-lived
 * token to the browser. The secret never leaves the server. GET is CSRF-safe
 * (no state change); the token itself is account+session-scoped and expiring.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Widget;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Psr\Log\LoggerInterface;

class Token implements ActionInterface, HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly HttpRequest $request,
        private readonly SalesAssistantClient $client,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        if (!$this->client->isEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'assistant disabled']);
        }
        try {
            // Optional session_id for continuity across reconnects (widget-supplied).
            $sessionId = (string)$this->request->getParam('session_id', '');
            $minted = $this->client->mintWidgetToken($sessionId);
        } catch (\Throwable $e) {
            $this->logger->error('SalesAssistant widget-token exchange failed: ' . $e->getMessage());
            return $result->setHttpResponseCode(502)->setData(['error' => 'token unavailable']);
        }
        return $result->setData([
            'token' => $minted['token'] ?? '',
            'session_id' => $minted['session_id'] ?? '',
            'expires_in' => $minted['expires_in'] ?? 0,
            'ws_url' => $this->client->wsUrl(),
        ]);
    }
}
