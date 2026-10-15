<?php
/**
 * bin/magento salesassistant:status [--backfill] — indexing health at a glance
 * (doc/sales-assistant-indexing-v3.md §3c/§3d): documents still waiting for a vector per index,
 * the embed gate (ok / paused: allowance / back-off), and this store's embedding budget.
 * --backfill runs one bounded vector-backfill pass now instead of waiting for the cron.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Console\Command;

use Myshopcarts\SalesAssistant\Model\Ingest\EmbedGate;
use Myshopcarts\SalesAssistant\Model\Ingest\VectorBackfill;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Myshopcarts\SalesAssistant\Model\Config\MeaningSearchAdvice;
use Myshopcarts\SalesAssistant\Model\Ingest\CatalogueEstimate;
use Myshopcarts\SalesAssistant\Model\Indexer\RebuildHeartbeat;
use Myshopcarts\SalesAssistant\Model\Indexer\RebuildRecovery;

class Status extends Command
{
    public function __construct(
        private readonly VectorBackfill $backfill,
        private readonly EmbedGate $gate,
        private readonly SalesAssistantClient $client,
        private readonly CatalogueEstimate $estimate,
        private readonly RebuildRecovery $recovery,
        private readonly RebuildHeartbeat $heartbeat,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('salesassistant:status')
            ->setDescription('Sales Assistant indexing status: content without vectors, embed gate, embedding budget')
            ->addOption('backfill', null, InputOption::VALUE_NONE, 'Run one vector-backfill pass now')
            ->addOption('estimate', null, InputOption::VALUE_NONE, 'Re-measure the catalogue embedding estimate now (else cached for a day)')
            ->addOption('heal', null, InputOption::VALUE_NONE, 'Clean up an interrupted full rebuild now (the backfill cron does it within 5 minutes)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('backfill')) {
            $r = $this->backfill->run(30000, microtime(true) + 240);
            $output->writeln(sprintf('Backfill: added %d vectors (%s).', $r['embedded'], $r['stopped']));
        }
        if ($input->getOption('heal')) {
            foreach ($this->recovery->heal() ?: ['nothing to clean up'] as $line) {
                $output->writeln('Healed:     ' . $line);
            }
        }
        $this->rebuildLines($output);
        $output->writeln('Catalogue:  ' . ($this->client->catalogueMeaningSearch()
            ? 'keyword + meaning search (products are embedded)'
            : 'keyword search only — meaning search off, no embedding tokens used for products'));
        foreach ($this->backfill->missing() as $kind => $n) {
            $output->writeln(sprintf('%-10s %s', $kind . ':', $n === null
                ? 'no vector index (missing, or the search engine has no vector support)'
                : sprintf('%d documents waiting for a vector', $n)));
        }
        $g = $this->gate->state();
        $line = 'Embedding:  ' . match ($g['state']) {
            'ok' => 'ok',
            'paused_allowance' => 'PAUSED — embedding allowance used up; resumes automatically when it renews or grows',
            'backoff' => $g['reason'] === 'budget'
                ? 'pacing — waiting for the shared indexing rate limit (resumes within a minute)'
                : sprintf('backing off after %d failure(s) (%s)', $g['failures'], $g['reason']),
            default => sprintf('backing off after %d failure(s) (%s)', $g['failures'], $g['reason']),
        };
        if ($g['state'] !== 'ok') {
            $line .= sprintf('; next check %s UTC', gmdate('Y-m-d H:i', (int)$g['until']));
        }
        $output->writeln($line);
        $b = null;
        try {
            $b = $this->client->embedBudget();
            $output->writeln(empty($b['metered'])
                ? 'Budget:     unmetered'
                : sprintf('Budget:     %s of %s tokens used, %s left', number_format((int)$b['used']),
                    number_format((int)$b['allowance']), number_format((int)$b['remaining'])));
        } catch (\Throwable $e) {
            $output->writeln('Budget:     unavailable (' . $e->getMessage() . ')');
        }
        try {
            $advice = MeaningSearchAdvice::text($this->estimate->estimate((bool)$input->getOption('estimate')), $b);
            if ($advice !== '') {
                $output->writeln('Estimate:   ' . $advice);
            }
        } catch (\Throwable $e) {
            $output->writeln('Estimate:   unavailable (' . $e->getMessage() . ')');
        }
        return Command::SUCCESS;
    }

    /** Running / interrupted / stuck full rebuilds (§3i). */
    private function rebuildLines(OutputInterface $output): void
    {
        foreach ([RebuildHeartbeat::CATALOGUE, RebuildHeartbeat::KNOWLEDGE] as $id) {
            $hb = $this->heartbeat->get($id);
            if ($hb && $this->heartbeat->isFresh($id, RebuildRecovery::STALE_SECONDS)) {
                $output->writeln(sprintf('Rebuild:    %s running since %s UTC (last progress %s UTC)',
                    $id, gmdate('Y-m-d H:i', (int)$hb['started']), gmdate('H:i', (int)$hb['beat'])));
            }
        }
        try {
            foreach ($this->recovery->inspect() as $id => $p) {
                $what = [];
                if ($p['stuck']) {
                    $what[] = sprintf('a full rebuild stopped without finishing (indexer %s, change tracking %s since %s UTC) — product/page changes are NOT reaching the assistant',
                        $p['status'], $p['view'], $p['since'] ? gmdate('Y-m-d H:i', $p['since']) : '?');
                }
                if ($p['orphans']) {
                    $what[] = count($p['orphans']) . ' half-built index version(s) left over';
                }
                $output->writeln(sprintf('Rebuild:    PROBLEM — %s: %s. The backfill cron cleans this up within 5 minutes, or run with --heal.', $id, implode('; ', $what)));
            }
        } catch (\Throwable $e) {
            $output->writeln('Rebuild:    check unavailable (' . $e->getMessage() . ')');
        }
        foreach ($this->heartbeat->interrupted() as $id => $i) {
            $output->writeln(sprintf('Rebuild:    the last full rebuild of %s (started %s UTC) was interrupted and cleaned up on %s UTC; the previous index is serving. Rebuild on purpose with bin/magento indexer:reindex %s',
                $id, gmdate('Y-m-d H:i', (int)$i['started']), gmdate('Y-m-d H:i', (int)$i['cleaned']), $id));
        }
    }
}
