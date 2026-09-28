<?php
/**
 * Crawl brick C2 — RobotsPolicy parse/isAllowed checks (bare `php`, fake fetcher). Asserts
 * the applicable-group selection (our UA over *), prefix Disallow matching, allow-all on an
 * empty Disallow, and fail-open when robots.txt is missing.
 *
 *     php Test/Crawl/robots_test.php
 */
declare(strict_types=1);

$base = dirname(__DIR__, 2);
require $base . '/Model/Knowledge/PageFetcherInterface.php';
require $base . '/Model/Knowledge/RobotsPolicy.php';

use Myshopcarts\SalesAssistant\Model\Knowledge\PageFetcherInterface;
use Myshopcarts\SalesAssistant\Model\Knowledge\RobotsPolicy;

final class FakeFetcher implements PageFetcherInterface
{
    public function __construct(private array $pages)
    {
    }

    public function fetch(string $url): string
    {
        if (!array_key_exists($url, $this->pages)) {
            throw new \RuntimeException("404 $url");
        }
        return $this->pages[$url];
    }
}

$FAILS = 0;
$check = static function (string $name, bool $ok) use (&$FAILS): void {
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n";
    if (!$ok) {
        $FAILS++;
    }
};

$rp = new RobotsPolicy(new FakeFetcher([]));

echo "\n== star group, prefix disallow\n";
$dis = $rp->parse("User-agent: *\nDisallow: /admin\nDisallow: /cart\n");
$check('/admin blocked', !$rp->isAllowed('https://s.example/admin/login', $dis));
$check('/cart blocked', !$rp->isAllowed('https://s.example/cart', $dis));
$check('/docs allowed', $rp->isAllowed('https://s.example/docs/x', $dis));

echo "\n== our UA group wins over star\n";
$txt = "User-agent: *\nDisallow: /\n\nUser-agent: MyShopCartsAssistant\nDisallow: /private\n";
$dis = $rp->parse($txt);
$check('/docs allowed (our group, not the * block-all)', $rp->isAllowed('https://s.example/docs', $dis));
$check('/private blocked (our group)', !$rp->isAllowed('https://s.example/private/x', $dis));

echo "\n== empty Disallow allows all; block-all\n";
$check('empty Disallow = allow', $rp->isAllowed('https://s.example/x', $rp->parse("User-agent: *\nDisallow:\n")));
$check('Disallow: / blocks everything', !$rp->isAllowed('https://s.example/', $rp->parse("User-agent: *\nDisallow: /\n")));

echo "\n== fail-open when robots.txt is missing\n";
$dis = $rp->forOrigin('https://norobots.example');   // fetcher throws → []
$check('missing robots → nothing disallowed', $dis === [] && $rp->isAllowed('https://norobots.example/anything', $dis));

echo "\n" . ($FAILS ? "FAILED ($FAILS)\n" : "ALL PASS\n");
exit($FAILS ? 1 : 0);
