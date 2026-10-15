<?php
/**
 * RebuildRecovery — finds and cleans up a full rebuild whose process died (doc/sales-assistant-indexing-v3.md
 * §3i). Magento suspends an indexer's changelog view for the length of a full reindex and resumes it at
 * the end; a rebuild that is killed (OOM, a deploy, a VM migration, someone stopping it) leaves the view
 * SUSPENDED and the indexer WORKING. Then product/page changes silently stop reaching the assistant —
 * nothing errors, the index just goes stale — and the half-built index version stays on disk.
 *
 * A rebuild is treated as dead only when ALL hold: the indexer is `working` or its view `suspended`; the
 * rebuild heartbeat is missing or quiet for STALE_SECONDS; and Magento's own state has not changed for
 * STALE_SECONDS either (so a rebuild that is just starting — state set, first page not done — is never
 * touched). Healing resumes the view (the changelog backlog is then processed by the normal cron),
 * sets the indexer back to valid — the live index was never replaced (blue/green), so it is correct,
 * and `invalid` would make Magento's cron start a full re-embed on its own — deletes the half-built
 * versions, and records the interruption for `salesassistant:status`. Run by the vector-backfill cron
 * (every 5 min) and shown by `salesassistant:status`.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Indexer;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\StateInterface;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Psr\Log\LoggerInterface;

class RebuildRecovery
{
    public const STALE_SECONDS = 1800;

    /** @var callable():int */
    private $clock;

    public function __construct(
        private readonly IndexerRegistry $indexers,
        private readonly RebuildHeartbeat $heartbeat,
        private readonly Schema $schema,
        private readonly LoggerInterface $logger,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * What is wrong right now, per indexer (empty = healthy or a rebuild is genuinely running).
     * @return array<string, array{status:string, view:string, since:int, stuck:bool, orphans:string[]}>
     */
    public function inspect(): array
    {
        $out = [];
        foreach ([RebuildHeartbeat::CATALOGUE => $this->schema->productsIndex(),
                  RebuildHeartbeat::KNOWLEDGE => $this->schema->knowledgeIndex()] as $id => $alias) {
            if ($this->heartbeat->isFresh($id, self::STALE_SECONDS)) {
                continue;   // a rebuild is alive — its state and its new version are expected
            }
            try {
                $indexer = $this->indexers->get($id);
                $status = (string)$indexer->getStatus();
                $view = (string)$indexer->getView()->getState()->getStatus();
                $since = (int)strtotime((string)$indexer->getState()->getUpdated() . ' UTC');
            } catch (\Throwable $e) {
                continue;
            }
            $stuck = ($status === StateInterface::STATUS_WORKING || $view === 'suspended')
                && ($since <= 0 || ($this->clock)() - $since >= self::STALE_SECONDS);
            $orphans = $this->schema->orphanVersions($alias);
            if ($stuck || $orphans) {
                $out[$id] = ['status' => $status, 'view' => $view, 'since' => $since,
                             'stuck' => $stuck, 'orphans' => $orphans];
            }
        }
        return $out;
    }

    /** Clean up everything inspect() found. Returns what was done, one line per action. */
    public function heal(): array
    {
        $done = [];
        foreach ($this->inspect() as $id => $p) {
            if ($p['stuck']) {
                $indexer = $this->indexers->get($id);
                $hb = $this->heartbeat->get($id);
                if ($p['view'] === 'suspended') {
                    $indexer->getView()->resume();
                    $done[] = "$id: resumed change tracking (was suspended)";
                }
                if ($p['status'] === StateInterface::STATUS_WORKING) {
                    $indexer->getState()->setStatus(StateInterface::STATUS_VALID)->save();
                    $done[] = "$id: status working → valid (the previous index is still serving)";
                }
                $this->heartbeat->markInterrupted($id, (int)($hb['started'] ?? $p['since']));
            }
            foreach ($p['orphans'] as $version) {
                try {
                    $id === RebuildHeartbeat::CATALOGUE
                        ? $this->schema->deleteProductsVersion($version)
                        : $this->schema->deleteKnowledgeVersion($version);
                    $done[] = "$id: deleted half-built version $version";
                } catch (\Throwable $e) {
                    $this->logger->warning("SalesAssistant: could not delete half-built version $version. " . $e->getMessage());
                }
            }
        }
        if ($done) {
            $this->logger->warning('SalesAssistant: cleaned up an interrupted full rebuild — ' . implode('; ', $done));
        }
        return $done;
    }
}
