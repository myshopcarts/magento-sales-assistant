<?php
/**
 * Catalogue indexer — makes the catalogue→embeddings reindex a first-class Magento
 * indexer, so `bin/magento indexer:reindex` AND the portal Maintenance "Reindex"
 * button rebuild the Sales Assistant vector index (no CLI-only custom command, no
 * SSH needed — works on any tenant incl. EC2). The single indexing path:
 *
 *   executeFull()        full rebuild (indexer:reindex / portal)  — pages VISIBLE products
 *   execute()/List/Row   partial (mview changelog / on-save)      — specific product ids
 *
 * Replaces the old catalog_product_save_after observer: incremental indexing now
 * flows through the standard indexer framework (Update-by-Schedule recommended, since
 * embedding is heavy). Gated on isEnabled() so unconfigured stores pay nothing.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Indexer;

use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\Product\Visibility;
use Myshopcarts\SalesAssistant\Model\Ingest\CatalogIndexer;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Psr\Log\LoggerInterface;

class Catalogue implements ActionInterface, MviewActionInterface
{
    private const PAGE_SIZE = 200;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly CatalogIndexer $indexer,
        private readonly Visibility $visibility,
        private readonly SalesAssistantClient $client,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Full rebuild — pages VISIBLE, enabled products (runs in the indexer/global area). */
    public function executeFull(): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }
        // Blue/green: build a fresh version with the CURRENT mapping, page into it, then atomically swap
        // the read alias. A mapping change (new fits/identifiers/attr_ fields) is applied on every full
        // reindex with zero downtime; an interrupted rebuild is discarded, never served. The version is
        // sharded for the current catalogue size (getSize = one cheap COUNT), so it self-tunes as the
        // catalogue grows without a manual reindex.
        $count = (int)$this->collectionFactory->create()
            ->setVisibility($this->visibility->getVisibleInSiteIds())
            ->addFieldToFilter('status', 1)
            ->getSize();
        $version = $this->indexer->beginFullRebuild($count);
        try {
            $page = 1;
            do {
                $collection = $this->collectionFactory->create();
                $collection->addAttributeToSelect('*')
                    ->setVisibility($this->visibility->getVisibleInSiteIds())
                    ->addFieldToFilter('status', 1)
                    ->setPageSize(self::PAGE_SIZE)
                    ->setCurPage($page);
                $items = $collection->getItems();
                if (!$items) {
                    break;
                }
                $this->indexer->index($items, $version);
                $last = (int)$collection->getLastPageNumber();
                $page++;
            } while ($page <= $last);
        } catch (\Throwable $e) {
            $this->indexer->abortFullRebuild($version);
            throw $e;
        }
        if (!$this->indexer->promoteFullRebuild($version)) {
            $this->logger->warning('SalesAssistant: full reindex produced an empty index; kept the previous version.');
        }
    }

    /** mview changelog batch. */
    public function execute($ids): void
    {
        $this->executeList(is_array($ids) ? $ids : [$ids]);
    }

    /** Partial rebuild for specific product ids (on-save / changelog). */
    public function executeList(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids || !$this->client->isEnabled()) {
            return;
        }
        try {
            $collection = $this->collectionFactory->create();
            $collection->addAttributeToSelect('*')
                ->addFieldToFilter('entity_id', ['in' => $ids]);
            $items = $collection->getItems();
            if ($items) {
                $this->indexer->index($items);
            }
        } catch (\Throwable $e) {
            // best-effort: a partial-index failure must never block the product save
            $this->logger->error('SalesAssistant partial index failed: ' . $e->getMessage());
        }
    }

    public function executeRow($id): void
    {
        $this->executeList([(int)$id]);
    }
}
