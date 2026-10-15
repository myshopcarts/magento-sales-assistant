<?php
/**
 * EmbedGate — decides whether the indexing path should call /embed right now, and remembers WHY
 * not (doc/sales-assistant-indexing-v3.md §3c). One small state record in Magento's flag table,
 * shared by the indexers, the vector-backfill cron and the status command:
 *
 *   ok                 embed normally.
 *   paused_allowance   the store's monthly embedding allowance is spent (backend 402). No embed
 *                      calls; once an hour the gate asks GET /embed/budget and resumes as soon as
 *                      budget is available again (monthly renewal, tier change, indexing pack).
 *   backoff            throttling (429), provider/network trouble (5xx / no response) or a bad
 *                      request (400): exponential back-off, 1 min doubling to 1 h, then probe.
 *                      A 429 that carries `retry_after` (the backend's shared per-minute indexing
 *                      budget is spent — doc/sales-assistant-indexing-v3.md §4.4) waits exactly
 *                      that long instead, without growing the back-off: indexing then proceeds
 *                      steadily at the budgeted rate.
 *
 * Content indexed while the gate is closed is written WITHOUT a vector (keyword-searchable, never
 * missing) and VectorBackfill adds the vectors later — nothing is silently left degraded.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Ingest;

use Magento\Framework\FlagManager;
use Myshopcarts\SalesAssistant\Model\SalesAssistantApiException;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Psr\Log\LoggerInterface;

class EmbedGate
{
    public const FLAG = 'myshopcarts_salesassistant_embed_gate';
    public const ALLOWANCE_RECHECK_SECONDS = 3600;
    public const BACKOFF_BASE_SECONDS = 60;
    public const BACKOFF_MAX_SECONDS = 3600;

    /** Longest single wait pace() will sleep through (the backend's budget is per minute). */
    public const MAX_PACE_SECONDS = 90;

    /** @var callable():int */
    private $clock;
    /** @var callable(int):void */
    private $sleeper;

    public function __construct(
        private readonly FlagManager $flags,
        private readonly SalesAssistantClient $client,
        private readonly LoggerInterface $logger,
        ?callable $clock = null,
        ?callable $sleeper = null
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * When the gate is closed ONLY because the backend's shared indexing budget is spent for this
     * minute (reason `budget`), sleep until it re-opens — so indexing proceeds at the budgeted rate
     * instead of giving up — provided the wait is short and ends before $deadline (microtime).
     * Returns true when the caller may try again now; false for any other closed state.
     */
    public function pace(float $deadline = INF): bool
    {
        $s = $this->state();
        if ($s['state'] !== 'backoff' || $s['reason'] !== 'budget') {
            return false;
        }
        $wait = (int)$s['until'] - ($this->clock)();
        if ($wait <= 0) {
            return true;
        }
        if ($wait > self::MAX_PACE_SECONDS || microtime(true) + $wait > $deadline) {
            return false;
        }
        ($this->sleeper)($wait);
        return true;
    }

    /** {state, reason, until, failures, since, last_error} — 'ok' when nothing is recorded. */
    public function state(): array
    {
        $s = $this->flags->getFlagData(self::FLAG);
        return is_array($s) && isset($s['state'])
            ? $s + ['reason' => '', 'until' => 0, 'failures' => 0, 'since' => 0, 'last_error' => '']
            : ['state' => 'ok', 'reason' => '', 'until' => 0, 'failures' => 0, 'since' => 0, 'last_error' => ''];
    }

    /** True when an /embed call should be attempted now. */
    public function allows(): bool
    {
        $s = $this->state();
        if ($s['state'] === 'ok') {
            return true;
        }
        $now = ($this->clock)();
        if ($now < (int)$s['until']) {
            return false;
        }
        if ($s['state'] === 'paused_allowance') {
            try {
                $budget = $this->client->embedBudget();
            } catch (\Throwable $e) {
                $this->save(['until' => $now + self::ALLOWANCE_RECHECK_SECONDS, 'last_error' => $e->getMessage()] + $s);
                return false;
            }
            if (!empty($budget['available'])) {
                $this->logger->info('SalesAssistant: embedding allowance available again; indexing resumes.');
                $this->reset();
                return true;
            }
            $this->save(['until' => $now + self::ALLOWANCE_RECHECK_SECONDS] + $s);
            return false;
        }
        return true;   // back-off elapsed: allow a probe call
    }

    /** An /embed call succeeded. */
    public function success(): void
    {
        if ($this->state()['state'] !== 'ok') {
            $this->reset();
        }
    }

    /** An /embed call failed: record it and return the reason (allowance|throttled|bad_request|provider). */
    public function failure(\Throwable $e): string
    {
        $status = $e instanceof SalesAssistantApiException ? $e->getStatus() : 0;
        $s = $this->state();
        $now = ($this->clock)();
        $since = $s['state'] === 'ok' ? $now : (int)$s['since'];
        if ($status === 402) {
            $this->save(['state' => 'paused_allowance', 'reason' => 'allowance',
                'until' => $now + self::ALLOWANCE_RECHECK_SECONDS, 'failures' => 0,
                'since' => $since, 'last_error' => $e->getMessage()]);
            return 'allowance';
        }
        $retryAfter = $e instanceof SalesAssistantApiException ? (int)($e->getPayload()['retry_after'] ?? 0) : 0;
        if ($status === 429 && $retryAfter > 0) {
            $this->save(['state' => 'backoff', 'reason' => 'budget', 'until' => $now + min($retryAfter, 120),
                'failures' => (int)$s['failures'], 'since' => $since, 'last_error' => $e->getMessage()]);
            return 'budget';
        }
        $reason = match (true) {
            $status === 429 => 'throttled',
            $status === 400 => 'bad_request',
            default => 'provider',
        };
        $failures = ($s['state'] === 'backoff' ? (int)$s['failures'] : 0) + 1;
        $delay = (int)min(self::BACKOFF_BASE_SECONDS * (2 ** ($failures - 1)), self::BACKOFF_MAX_SECONDS);
        $this->save(['state' => 'backoff', 'reason' => $reason, 'until' => $now + $delay,
            'failures' => $failures, 'since' => $since, 'last_error' => $e->getMessage()]);
        return $reason;
    }

    public function reset(): void
    {
        $this->flags->deleteFlag(self::FLAG);
    }

    private function save(array $state): void
    {
        $this->flags->saveFlag(self::FLAG, $state);
    }
}
