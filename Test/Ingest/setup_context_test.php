<?php
/**
 * The catalogue full rebuild must skip itself inside `bin/magento setup:*` (setup:upgrade reindexes
 * every indexer via the sample-data modules' reindexAll()) — and only there.
 *
 *     php Test/Ingest/setup_context_test.php   # exit 0 = pass, 1 = fail
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/Model/Ingest/SetupContext.php';

use Myshopcarts\SalesAssistant\Model\Ingest\SetupContext;

$FAILS = 0;
$check = function (string $name, bool $ok) use (&$FAILS): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . "\n";
    $FAILS += $ok ? 0 : 1;
};
$check('setup:upgrade → skip', SetupContext::insideSetupCommand(['bin/magento', 'setup:upgrade', '--keep-generated'], 'cli'));
$check('setup:install → skip', SetupContext::insideSetupCommand(['bin/magento', 'setup:install'], 'cli'));
$check('indexer:reindex → rebuild', !SetupContext::insideSetupCommand(['bin/magento', 'indexer:reindex', 'myshopcarts_salesassistant_catalogue'], 'cli'));
$check('cron:run → rebuild', !SetupContext::insideSetupCommand(['bin/magento', 'cron:run', '--group=index'], 'cli'));
$check('web request → never', !SetupContext::insideSetupCommand(['setup:upgrade'], 'fpm-fcgi'));

echo $FAILS === 0 ? "\nALL PASS\n" : "\n$FAILS FAILED\n";
exit($FAILS === 0 ? 0 : 1);
