<?php
/**
 * Runs on EVERY `setup:upgrade` (Magento recurring-data hook). When the products index MAPPING has
 * changed since the last run — Schema::MAPPING_VERSION differs from the stored flag — it INVALIDATES
 * the catalogue indexer so cron's reindex-all-invalid rebuilds it (blue/green → the new mapping) out
 * of band. This is how an ext upgrade that adds/changes index fields (fits/identifiers/attr_) gets its
 * new mapping applied WITHOUT forcing a blocking reindex inside setup:upgrade — matching Adobe's
 * upgrade guidance (reindex is a separate, out-of-setup step; indexers stay on Update by Schedule).
 *
 * invalidate() only writes indexer state (no DDL), so this is safe as ordinary recurring data.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Setup;

use Magento\Framework\FlagManager;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Psr\Log\LoggerInterface;

class RecurringData implements InstallDataInterface
{
    private const FLAG = 'myshopcarts_salesassistant_products_mapping_version';
    private const CATALOGUE_INDEXER = 'myshopcarts_salesassistant_catalogue';

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        try {
            $applied = (int)($this->flagManager->getFlagData(self::FLAG) ?? 0);
            if ($applied === Schema::MAPPING_VERSION) {
                return;   // mapping unchanged since the last upgrade — nothing to do
            }
            // Mark the catalogue index stale; cron (reindex-all-invalid) rebuilds it with the new
            // mapping. A fresh install is also "changed" (flag 0), which harmlessly invalidates an
            // already-invalid new indexer.
            $this->indexerRegistry->get(self::CATALOGUE_INDEXER)->invalidate();
            $this->flagManager->saveFlag(self::FLAG, Schema::MAPPING_VERSION);
            $this->logger->info(sprintf(
                'SalesAssistant: products mapping version %d (was %d) — catalogue indexer invalidated for reindex.',
                Schema::MAPPING_VERSION,
                $applied
            ));
        } catch (\Throwable $e) {
            // Non-fatal — a merchant/operator can reindex manually. Never fail the upgrade over this.
            $this->logger->warning('SalesAssistant: mapping-version invalidation skipped: ' . $e->getMessage());
        }
    }
}
