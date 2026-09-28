<?php
/**
 * When the merchant saves the Sales Assistant config (enable + subscription key), INVALIDATE the
 * assistant indexers IF their index is still empty — so activation actively triggers the FIRST index
 * (cron's reindex-all-invalid builds it on the next run, with the key now present) instead of racing
 * cron. Without this, cron can run before the merchant enables: executeFull() no-ops on a disabled
 * store, the indexer is marked valid+empty, and enabling never re-triggers it.
 *
 * Gated on "index is empty" so an ordinary settings change (e.g. the chat header colour) does NOT
 * kick off a full re-embed — only the first-index case fires. Invalidate, never reindex synchronously:
 * embedding a catalogue is heavy and must not block the admin Save (Adobe's guidance keeps reindex out
 * of the request path; cron does the work).
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Myshopcarts\SalesAssistant\Model\OpenSearch\Schema;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Psr\Log\LoggerInterface;

class InvalidateAssistantIndexers implements ObserverInterface
{
    /** indexer id => the index name to check for emptiness. */
    private const INDEXERS = [
        'myshopcarts_salesassistant_catalogue' => 'products',
        'myshopcarts_salesassistant_knowledge' => 'knowledge',
    ];

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly Schema $schema,
        private readonly SalesAssistantClient $client,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->client->isEnabled()) {
            return;   // still off — nothing to index yet
        }
        foreach (self::INDEXERS as $id => $which) {
            try {
                $index = $which === 'products' ? $this->schema->productsIndex() : $this->schema->knowledgeIndex();
                if ($this->schema->countDocs($index) > 0) {
                    continue;   // already built — don't re-embed on a cosmetic settings save
                }
                $indexer = $this->indexerRegistry->get($id);
                if ($indexer->isValid()) {
                    $indexer->invalidate();   // cron's reindex-all-invalid builds it (key now present)
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'SalesAssistant: could not invalidate indexer %s on config save: %s',
                    $id,
                    $e->getMessage()
                ));
            }
        }
    }
}
