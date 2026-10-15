<?php
/**
 * SetupContext — is this process a `bin/magento setup:*` command (setup:upgrade, setup:install …)?
 *
 * setup:upgrade reindexes EVERY indexer when Magento's sample-data modules are installed (their
 * RecurringData calls reindexAll() on each run), and Indexer::reindexAll() ignores the
 * "suspended" state. The assistant's full catalogue rebuild must not run there
 * (doc/sales-assistant-indexing-v3.md §3e2). Static and dependency-free so it never changes a
 * constructor that compiled DI metadata already describes.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Ingest;

final class SetupContext
{
    public static function insideSetupCommand(?array $argv = null, string $sapi = PHP_SAPI): bool
    {
        if ($sapi !== 'cli') {
            return false;
        }
        foreach ($argv ?? ($_SERVER['argv'] ?? []) as $arg) {
            if (is_string($arg) && str_starts_with($arg, 'setup:')) {
                return true;
            }
        }
        return false;
    }
}
