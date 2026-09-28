<?php
/**
 * RunCrawls (crawl brick C2) — the async ingest worker, driven by Magento cron. A long crawl
 * would blow a web-request timeout, so ingestion runs here in BOUNDED batches that resume
 * across ticks: each run advances the running jobs by a small number of URLs, under a
 * wall-clock budget, with a small politeness delay between fetches. Cancel/pause take effect
 * on the next tick (a job that leaves `running` is skipped); a job with no pending URLs left
 * is marked `done`.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Cron;

use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlIngestor;
use Myshopcarts\SalesAssistant\Model\Knowledge\CrawlJobRepository;
use Myshopcarts\SalesAssistant\Model\Knowledge\RobotsPolicy;
use Psr\Log\LoggerInterface;

class RunCrawls
{
    private const BATCH_PER_JOB = 8;         // URLs advanced per job per tick
    private const TICK_BUDGET_SECONDS = 40;  // hard wall-clock cap for the whole tick
    private const POLITE_DELAY_US = 300000;  // 0.3s between fetches (rate-limit)

    public function __construct(
        private readonly CrawlJobRepository $jobs,
        private readonly CrawlIngestor $ingestor,
        private readonly RobotsPolicy $robots,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $deadline = microtime(true) + self::TICK_BUDGET_SECONDS;
        foreach ($this->jobs->runningJobs() as $job) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $this->advance((int)$job['job_id'], (string)$job['origin'], $deadline);
        }
    }

    private function advance(int $jobId, string $origin, float $deadline): void
    {
        $batch = $this->jobs->pendingUrls($jobId, self::BATCH_PER_JOB);
        if (!$batch) {
            $this->jobs->setJobStatus($jobId, 'done');   // nothing left → finished
            return;
        }
        $disallows = $this->robots->forOrigin($origin);
        foreach ($batch as $row) {
            if (microtime(true) >= $deadline) {
                return;   // resume the rest next tick
            }
            $entityId = (int)$row['entity_id'];
            $url = (string)$row['url'];
            try {
                if (!$this->robots->isAllowed($url, $disallows)) {
                    $this->jobs->recordUrlResult($entityId, $jobId,
                        ['status' => 'skipped', 'reason' => 'blocked by the site’s robots.txt']);
                    continue;
                }
                $result = $this->ingestor->ingest($url);
                $this->jobs->recordUrlResult($entityId, $jobId, $result);
            } catch (\Throwable $e) {
                // Never let one URL abort the batch — record it as an error and move on.
                $this->logger->warning('SalesAssistant crawl url failed: ' . $url . ' — ' . $e->getMessage());
                $this->jobs->recordUrlResult($entityId, $jobId,
                    ['status' => 'error', 'reason' => $e->getMessage()]);
            }
            usleep(self::POLITE_DELAY_US);
        }
        if ($this->jobs->countPending($jobId) === 0) {
            $this->jobs->setJobStatus($jobId, 'done');
        }
    }
}
