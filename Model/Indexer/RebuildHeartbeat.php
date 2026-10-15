<?php
/**
 * RebuildHeartbeat — a full rebuild says "still alive" as it goes (doc/sales-assistant-indexing-v3.md
 * §3i). Magento can't tell a running full reindex from one whose process was killed: both leave the
 * indexer `working` and its changelog view `suspended`. A killed rebuild never clears its heartbeat,
 * so a heartbeat that has gone quiet is how RebuildRecovery knows nothing is running any more.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Indexer;

use Magento\Framework\FlagManager;

class RebuildHeartbeat
{
    public const CATALOGUE = 'myshopcarts_salesassistant_catalogue';
    public const KNOWLEDGE = 'myshopcarts_salesassistant_knowledge';
    private const FLAG = 'myshopcarts_salesassistant_rebuild_';
    private const INTERRUPTED = 'myshopcarts_salesassistant_rebuild_interrupted';

    /** @var callable():int */
    private $clock;

    public function __construct(private readonly FlagManager $flags, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function start(string $indexerId, string $version = ''): void
    {
        $now = ($this->clock)();
        $this->flags->saveFlag(self::FLAG . $indexerId, ['version' => $version, 'started' => $now, 'beat' => $now]);
    }

    public function beat(string $indexerId): void
    {
        $hb = $this->get($indexerId);
        if ($hb) {
            $hb['beat'] = ($this->clock)();
            $this->flags->saveFlag(self::FLAG . $indexerId, $hb);
        }
    }

    /** The rebuild ended (promoted, refused or aborted) — no longer in progress. */
    public function finish(string $indexerId, bool $completed): void
    {
        $this->flags->deleteFlag(self::FLAG . $indexerId);
        if ($completed) {
            $this->clearInterrupted($indexerId);
        }
    }

    /** @return array{version:string, started:int, beat:int}|null */
    public function get(string $indexerId): ?array
    {
        $hb = $this->flags->getFlagData(self::FLAG . $indexerId);
        return is_array($hb) && isset($hb['beat']) ? $hb : null;
    }

    public function isFresh(string $indexerId, int $staleSeconds): bool
    {
        $hb = $this->get($indexerId);
        return $hb !== null && ($this->clock)() - (int)$hb['beat'] < $staleSeconds;
    }

    /** Remember that a rebuild was found dead and cleaned up, for the status output. */
    public function markInterrupted(string $indexerId, int $startedAt): void
    {
        $all = $this->interrupted();
        $all[$indexerId] = ['started' => $startedAt, 'cleaned' => ($this->clock)()];
        $this->flags->saveFlag(self::INTERRUPTED, $all);
        $this->flags->deleteFlag(self::FLAG . $indexerId);
    }

    /** @return array<string, array{started:int, cleaned:int}> */
    public function interrupted(): array
    {
        $all = $this->flags->getFlagData(self::INTERRUPTED);
        return is_array($all) ? $all : [];
    }

    private function clearInterrupted(string $indexerId): void
    {
        $all = $this->interrupted();
        if (isset($all[$indexerId])) {
            unset($all[$indexerId]);
            $this->flags->saveFlag(self::INTERRUPTED, $all);
        }
    }
}
