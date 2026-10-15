<?php
/**
 * SalesAssistantClient — server-side connector to the MyShopCarts Sales Assistant
 * backend. Auth is a per-merchant SECRET key (Bearer), read from encrypted config
 * and NEVER exposed to the storefront (doc/sales-assistant-auth.md). Modeled on
 * PortalClient, but simpler: a static key, not client-credentials OAuth.
 *
 * Uses the Magento framework HTTP client (never raw curl/fopen) and treats the
 * configured base URL under SSRF rules (must be our https host). Main calls:
 *   - mintWidgetToken(): exchange the secret for a short-lived browser token.
 *   - embed(): text -> vectors (used by the E6 ingestion pipeline).
 *   - embedBudget(): this store's embedding allowance / used / remaining (budget-aware indexing).
 * Failures throw SalesAssistantApiException carrying the HTTP status (402 allowance spent,
 * 429 provider busy, 5xx / 0 provider or network trouble).
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class SalesAssistantClient
{
    private const XML = 'myshopcarts_salesassistant/api/';
    private const TIMEOUT_SECONDS = 25;        // total request cap (embed batches included)
    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly EncryptorInterface $encryptor,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isSetFlag(self::XML . 'enabled')
            && $this->cfg('api_base') !== ''
            && $this->cfg('ws_url') !== ''
            && $this->cfg('secret_key') !== '';
    }

    /**
     * Catalogue meaning search (embeddings for products). OFF by default: the catalogue is searched
     * by keyword (after the AI rewrites the shopper's question into search terms + filters) and
     * costs no embedding tokens; knowledge documents and pages are always embedded. Turning it on
     * needs no reindex — the vector backfill job embeds the existing catalogue documents.
     */
    public function catalogueMeaningSearch(): bool
    {
        return $this->config->isSetFlag('myshopcarts_salesassistant/catalogue/meaning_search');
    }

    public function wsUrl(): string
    {
        return $this->cfg('ws_url');
    }

    /** Exchange the secret for a short-lived widget token: {token, session_id, expires_in}. */
    public function mintWidgetToken(string $sessionId = ''): array
    {
        // Send an object body only when we have a session_id; otherwise no body
        // (an empty PHP array serialises to `[]`, a JSON list, not an object).
        return $this->request('POST', '/widget-token', $sessionId !== '' ? ['session_id' => $sessionId] : null);
    }

    /** Embed texts (ingest/query). input_type = search_document | search_query. */
    public function embed(array $texts, string $inputType = 'search_document'): array
    {
        return $this->request('POST', '/embed', ['texts' => $texts, 'input_type' => $inputType]);
    }

    /** This store's embedding budget: {metered, allowance, used, remaining, available}. */
    public function embedBudget(): array
    {
        return $this->request('GET', '/embed/budget');
    }

    /** Mint a presigned PUT for a knowledge-source upload: {upload_url, s3_key, ...} (K2b). */
    public function presignUpload(string $filename, string $contentType, int $size): array
    {
        return $this->request('POST', '/knowledge/upload-url',
            ['filename' => $filename, 'content_type' => $contentType, 'size' => $size]);
    }

    /** Parse a previously-uploaded document (by s3_key) into markdown + chunks (K2b).
     *  Pass the ORIGINAL filename so the derived source_id is stable (re-uploading the
     *  same file replaces it, rather than the timestamped s3_key creating a duplicate). */
    public function extract(string $s3Key, string $filename = ''): array
    {
        $body = ['s3_key' => $s3Key];
        if ($filename !== '') {
            $body['filename'] = $filename;
        }
        return $this->request('POST', '/extract', $body);
    }

    /** PUT raw bytes to a presigned S3 URL (the URL is self-authenticating — no bearer). */
    public function putPresigned(string $url, string $bytes, string $contentType): void
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('presigned upload URL must be https');
        }
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $bytes,
        ]);
        if ($contentType !== '') {
            $curl->addHeader('Content-Type', $contentType);
        }
        $curl->post($url, $bytes); // Client sends the body; CUSTOMREQUEST forces PUT.
        $status = $curl->getStatus();
        if ($status < 200 || $status >= 300) {
            $this->logger->error(sprintf('SalesAssistant S3 upload -> HTTP %d', $status));
            throw new \RuntimeException(sprintf('knowledge upload failed (HTTP %d)', $status));
        }
    }

    // ---- internals ---------------------------------------------------------

    private function cfg(string $key): string
    {
        return (string)$this->config->getValue(self::XML . $key);
    }

    private function secret(): string
    {
        return $this->encryptor->decrypt($this->cfg('secret_key'));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $base = rtrim($this->cfg('api_base'), '/');
        if (!str_starts_with($base, 'https://')) {
            throw new \RuntimeException('Sales Assistant API base must be an https URL');
        }
        $curl = $this->curlFactory->create(); // fresh instance — no cred/header leakage
        // Bounded timeouts: a slow/hanging backend must never pin a php-fpm worker
        // (an unbounded /embed call exhausted the pool during dev validation).
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->setOptions([CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS]);
        $curl->addHeader('Authorization', 'Bearer ' . $this->secret());
        $curl->addHeader('Content-Type', 'application/json');

        $url = $base . $path;
        if ($method === 'GET') {
            $curl->get($url);
        } else {
            $curl->post($url, $body !== null ? $this->json->serialize($body) : '');
        }

        $status = (int)$curl->getStatus();
        try {
            $decoded = $curl->getBody() !== '' ? $this->json->unserialize($curl->getBody()) : [];
        } catch (\InvalidArgumentException $e) {
            $decoded = [];   // non-JSON error page (gateway / proxy) — the status still decides
        }
        if ($status < 200 || $status >= 300) {
            $msg = is_array($decoded) ? (string)($decoded['error'] ?? '') : '';
            // Never log the secret; log only method/path/status.
            $this->logger->error(sprintf('SalesAssistantClient %s %s -> HTTP %d: %s', $method, $path, $status, $msg ?: 'unknown'));
            // Surface the backend's specific reason (admin-facing, e.g. "pdf is encrypted",
            // "no extractable text") so callers can show it; fall back to the status code.
            throw new SalesAssistantApiException(
                $msg !== '' ? $msg : sprintf('request failed (HTTP %d)', $status),
                $status,
                is_array($decoded) ? $decoded : []
            );
        }
        return is_array($decoded) ? $decoded : [];
    }
}
