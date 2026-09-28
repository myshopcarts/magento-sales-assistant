<?php
/**
 * Default the Sales Assistant indexers to Update-by-Schedule on install.
 *
 * Both indexers do heavy embedding, so Realtime (Update on Save) — Magento's default for a NEW
 * indexer — would run a full synchronous re-embed on every product/CMS save (slow). Update-by-
 * Schedule processes changes via cron + the product/cms changelog instead; existing content
 * still gets its first index because a fresh indexer is "invalid" and the reindex-all-invalid
 * cron full-reindexes it.
 *
 * MUST be NonTransactionable. setScheduled() subscribes the indexer's mview, which issues DDL
 * (CREATE TRIGGER), and Magento forbids DDL inside the transaction that wraps a normal data
 * patch ("DDL statements are not allowed in transactions"). The previous patch
 * (SetIndexersScheduled) was transactional: its setScheduled() threw that error, a
 * swallow-and-continue hid it, and Magento still RECORDED the patch as applied — so the
 * indexers silently stayed on Update-on-Save and the patch never re-ran. This one is
 * renamed so it re-runs on stores that recorded the broken one, runs outside a transaction,
 * and LOGS a failure instead of hiding it. Runs once; a merchant can switch back under
 * System → Index Management.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Setup\Patch\Data;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;

class ScheduleAssistantIndexers implements DataPatchInterface, NonTransactionableInterface
{
    private const INDEXERS = [
        'myshopcarts_salesassistant_catalogue',
        'myshopcarts_salesassistant_knowledge',
    ];

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry
    ) {
    }

    public function apply(): self
    {
        foreach (self::INDEXERS as $id) {
            try {
                $indexer = $this->indexerRegistry->get($id);
                if (!$indexer->isScheduled()) {
                    $indexer->setScheduled(true);
                }
            } catch (\Throwable $e) {
                // Non-fatal — a merchant can flip it under System → Index Management. LOG rather
                // than silently continue: the silent swallow is exactly what hid the
                // DDL-in-transaction failure that left the indexers on Update-on-Save.
                error_log(sprintf(
                    'Myshopcarts_SalesAssistant: could not schedule indexer %s: %s',
                    $id,
                    $e->getMessage()
                ));
            }
        }
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
