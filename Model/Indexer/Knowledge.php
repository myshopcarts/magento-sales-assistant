<?php
/**
 * Knowledge indexer — makes knowledge ingestion a first-class, portal-triggerable
 * Magento indexer (like the catalogue one): `indexer:reindex
 * myshopcarts_salesassistant_knowledge` / the portal Maintenance "Reindex" button
 * rebuild the knowledge index from all registered sources (di.xml `sources` list —
 * CMS pages now; uploaded docs / URLs later). Per-source idempotent (deleteSource +
 * re-index). Gated on isEnabled().
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Indexer;

use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use Myshopcarts\SalesAssistant\Model\Ingest\KnowledgeIndexInterface;
use Myshopcarts\SalesAssistant\Model\Knowledge\Source\KnowledgeSourceInterface;
use Myshopcarts\SalesAssistant\Model\Ingest\IndexingThrottle;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Psr\Log\LoggerInterface;

class Knowledge implements ActionInterface, MviewActionInterface
{
    /** @param KnowledgeSourceInterface[] $sources */
    public function __construct(
        private readonly KnowledgeIndexInterface $indexer,
        private readonly SalesAssistantClient $client,
        private readonly LoggerInterface $logger,
        private readonly IndexingThrottle $throttle,
        private readonly RebuildHeartbeat $heartbeat,
        private readonly array $sources = []
    ) {
    }

    public function executeFull(): void
    {
        if (!$this->client->isEnabled()) {
            return;
        }
        // Blue/green rebuild (doc/sales-assistant-knowledge-reindex-v2.md §v2b): build a FRESH index
        // version, then verify + atomically swap the read alias to it. The live index keeps serving
        // throughout, so a failed, interrupted, or empty build never reaches readers, and — because
        // each rebuild is a clean index — there is no in-place delete-and-rebuild and no Lucene
        // tombstone churn. A source that yields no chunks (CMS toggle off, a removed upload) is
        // simply not carried into the fresh index; no destructive per-source delete is needed.
        $this->indexer->beginRebuild();
        $this->heartbeat->start(RebuildHeartbeat::KNOWLEDGE);   // §3i
        $published = false;
        try {
            foreach ($this->sources as $source) {
                foreach ($source->documents() as $doc) {
                    if (!empty($doc['chunks'])) {
                        $t0 = microtime(true);
                        $this->indexer->index($doc['chunks']);
                        $this->heartbeat->beat(RebuildHeartbeat::KNOWLEDGE);
                        $this->throttle->yield(microtime(true) - $t0);
                    }
                }
            }
            $published = $this->indexer->commitRebuild();
            if (!$published) {
                $this->logger->warning('SalesAssistant knowledge rebuild not published; kept the current index.');
            }
        } catch (\Throwable $e) {
            $this->indexer->abortRebuild();
            $this->logger->error('SalesAssistant knowledge rebuild failed; kept the current index. ' . $e->getMessage());
        } finally {
            $this->heartbeat->finish(RebuildHeartbeat::KNOWLEDGE, $published);
        }
    }

    // The knowledge corpus is small and changes rarely, so partial changes re-ingest
    // all sources (simple + correct). A per-source partial path is a later refinement.
    public function execute($ids): void
    {
        $this->executeFull();
    }

    public function executeList(array $ids): void
    {
        $this->executeFull();
    }

    public function executeRow($id): void
    {
        $this->executeFull();
    }
}
