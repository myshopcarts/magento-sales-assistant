<?php
/**
 * BackfillVectors — cron worker (every 5 minutes, own `salesassistant` cron group) that adds vectors to
 * content indexed without one (doc/sales-assistant-indexing-v3.md §3c). Near-free when idle: one count
 * query per index, or nothing at all while the embed gate is closed (allowance spent / back-off).
 * Bounded per tick so it never overruns the next one; Magento's cron locking prevents overlap.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Cron;

use Myshopcarts\SalesAssistant\Model\Indexer\RebuildRecovery;
use Myshopcarts\SalesAssistant\Model\Ingest\VectorBackfill;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Psr\Log\LoggerInterface;

class BackfillVectors
{
    // Sized for big catalogues: one embed call (90 chunks) takes ~1-2 s, so a 4-minute tick heals
    // ~15-25k chunks (a 250k-product catalogue ≈ 750k chunks heals in hours, not days). The tick
    // ends early when there's nothing left or the gate closes; the own cron group + Magento's job
    // lock keep a long tick from delaying other jobs or overlapping the next one.
    private const MAX_DOCS_PER_TICK = 30000;
    private const TICK_BUDGET_SECONDS = 240;   // inside the 5-minute schedule

    public function __construct(
        private readonly VectorBackfill $backfill,
        private readonly SalesAssistantClient $client,
        private readonly LoggerInterface $logger,
        private readonly RebuildRecovery $recovery
    ) {
    }

    public function execute(): void
    {
        // Self-heal first: a full rebuild killed mid-way leaves change tracking suspended (product
        // changes silently stop reaching the assistant) and a half-built version on disk (§3i).
        try {
            $this->recovery->heal();
        } catch (\Throwable $e) {
            $this->logger->warning('SalesAssistant: rebuild recovery check failed. ' . $e->getMessage());
        }
        if (!$this->client->isEnabled()) {
            return;
        }
        $r = $this->backfill->run(self::MAX_DOCS_PER_TICK, microtime(true) + self::TICK_BUDGET_SECONDS);
        if ($r['embedded'] > 0) {
            $this->logger->info(sprintf('SalesAssistant vector backfill: added %d vectors (%s).', $r['embedded'], $r['stopped']));
        }
    }
}
