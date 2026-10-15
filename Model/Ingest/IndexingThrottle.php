<?php
/**
 * IndexingThrottle — keeps the assistant's indexing from slowing the store down
 * (doc/sales-assistant-indexing-v3.md §3g). A large catalogue's first index is hours of
 * work; it must take only a fraction of the store's resources so shoppers never notice.
 *
 * yield($workSeconds) is a duty cycle for the BIG jobs (full rebuild, vector backfill):
 * after each batch, sleep so indexing works at most `indexing/cpu_share` percent of the
 * time (default 25). That bounds everything the indexing causes — its own PHP CPU and the
 * database / search-engine / embedding-call load — on any host.
 *
 * We deliberately do NOT change the process priority (nice): it can't be undone by an
 * unprivileged process and would carry over to whatever else runs in the same process
 * (Magento's own indexers in `indexer:reindex` or the `index` cron job, or a php-fpm worker
 * on an "Update on Save" admin save). We control how much work WE do, nothing else.
 *
 * The small incremental updates (a few changed products, run inside Magento's shared
 * `index` cron job or an admin save) are not throttled — sleeping there would delay
 * Magento's own indexers queued behind ours, or the save.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Ingest;

use Magento\Framework\App\Config\ScopeConfigInterface;

class IndexingThrottle
{
    public const XML_CPU_SHARE = 'myshopcarts_salesassistant/indexing/cpu_share';
    public const DEFAULT_SHARE = 25;
    private const MIN_SHARE = 5;
    private const MAX_SLEEP_SECONDS = 30.0;   // per batch — keeps progress visible

    /** @var callable(float):void */
    private $sleeper;

    public function __construct(
        private readonly ScopeConfigInterface $config,
        ?callable $sleeper = null
    ) {
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int)round($seconds * 1e6));
        };
    }

    /** Percent of the time indexing may work (5–100; 100 = no duty cycle). */
    public function share(): int
    {
        $v = $this->config->getValue(self::XML_CPU_SHARE);
        $share = ($v === null || $v === '') ? self::DEFAULT_SHARE : (int)$v;
        return max(self::MIN_SHARE, min(100, $share));
    }

    /** After $workSeconds of indexing work, sleep to stay within the configured share. */
    public function yield(float $workSeconds): void
    {
        $share = $this->share();
        if ($share >= 100 || $workSeconds <= 0) {
            return;
        }
        $sleep = min($workSeconds * (100 - $share) / $share, self::MAX_SLEEP_SECONDS);
        if ($sleep > 0.001) {
            ($this->sleeper)($sleep);
        }
    }
}
