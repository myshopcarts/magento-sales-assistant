<?php
/**
 * bin/magento salesassistant:reindex — thin CLI wrapper that triggers the Sales
 * Assistant catalogue INDEXER (Model/Indexer/Catalogue). The reindex logic lives in
 * the indexer so `indexer:reindex`, the portal Maintenance "Reindex" button, and this
 * command all share one path (no SSH-only reindex).
 *
 * The full rebuild is BLUE/GREEN (see Model/Indexer/Catalogue::executeFull): it builds a
 * fresh index version with the CURRENT mapping and atomically swaps the read alias, so a
 * mapping change from an ext upgrade (new fits/identifiers/attr_ fields) is applied on every
 * reindex with zero downtime — no separate "recreate" step, matching Magento's own
 * catalogsearch behaviour.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Console\Command;

use Magento\Framework\Indexer\IndexerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Reindex extends Command
{
    private const INDEXER_ID = 'myshopcarts_salesassistant_catalogue';

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('salesassistant:reindex')
            ->setDescription('Reindex the catalogue into the Sales Assistant search index');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Sales Assistant: rebuilding catalogue index…');
        $this->indexerRegistry->get(self::INDEXER_ID)->reindexAll();
        $output->writeln('<info>Sales Assistant: catalogue reindexed.</info>');
        return Command::SUCCESS;
    }
}
