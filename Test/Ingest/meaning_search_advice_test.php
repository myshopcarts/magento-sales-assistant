<?php
/**
 * The admin line under "Meaning search for products" must give the merchant a store-specific
 * token figure and an honest verdict against their allowance.
 *
 *     php Test/Ingest/meaning_search_advice_test.php   # exit 0 = pass, 1 = fail
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/Model/Config/MeaningSearchAdvice.php';

use Myshopcarts\SalesAssistant\Model\Config\MeaningSearchAdvice as A;

$FAILS = 0;
$check = function (string $name, bool $ok, string $got = '') use (&$FAILS): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($ok ? '' : "\n        got: $got") . "\n";
    $FAILS += $ok ? 0 : 1;
};
$big = ['products' => 250197, 'per_product' => 760, 'tokens' => 250197 * 760];
$small = ['products' => 1632, 'per_product' => 500, 'tokens' => 816000];

$t = A::text($big, ['metered' => true, 'allowance' => 60_000_000, 'remaining' => 29_400_000]);
$check('big catalogue: count, per-product and total shown', str_contains($t, '250,197 products') && str_contains($t, '760 tokens each') && str_contains($t, '190.1M'), $t);
$check('big catalogue: allowance shown', str_contains($t, '60M per month, 29.4M left'), $t);
$check('big catalogue: over the allowance → share + keep keyword advice', str_contains($t, 'about 31%') && str_contains($t, 'Keep keyword search'), $t);

$t = A::text($small, ['metered' => true, 'allowance' => 2_000_000, 'remaining' => 1_500_000]);
$check('small catalogue fits → enough', str_contains($t, '816k') && str_contains($t, 'Enough to embed your whole catalogue now'), $t);

$t = A::text($small, ['metered' => true, 'allowance' => 2_000_000, 'remaining' => 300_000]);
$check('fits the plan but not this month → finishes after renewal', str_contains($t, 'after your allowance renews'), $t);

$t = A::text($small, null);
$check('budget unknown → estimate + could not check', str_contains($t, '816k') && str_contains($t, 'could not be checked'), $t);

$check('empty catalogue → no line', A::text(['products' => 0, 'per_product' => 0, 'tokens' => 0], null) === '');

echo $FAILS === 0 ? "\nALL PASS\n" : "\n$FAILS FAILED\n";
exit($FAILS === 0 ? 0 : 1);
