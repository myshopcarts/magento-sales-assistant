<?php
/**
 * A non-2xx answer from the Sales Assistant backend. Carries the HTTP status (0 = no response:
 * timeout / connection failure) and the decoded body, so the indexing path can tell an exhausted
 * embedding allowance (402) from provider throttling (429) and other failures (5xx / 0). Extends
 * RuntimeException, so existing callers that catch that keep working unchanged.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model;

class SalesAssistantApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly array $payload = []
    ) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }
}
