<?php
/**
 * Unit test for the dynamic CSP collector: assert that the connect-src host is
 * derived from the configured ws_url (scheme://host[:port], path stripped) and
 * that non-WebSocket / unset / malformed values add NO policy. Proves the widget's
 * WebSocket host comes from config, never a hardcoded literal — the property the
 * public release gate enforces.
 *
 *     php Test/Csp/EndpointPolicyCollectorTest.php    # exit 0 = pass, 1 = fail
 */
declare(strict_types=1);

$base = dirname(__DIR__, 2);

// Minimal stand-ins for the Magento_Csp / framework types the collector references
// (absent under bare `php`). FetchPolicy just records its id + host sources.
if (!interface_exists('Magento\\Framework\\App\\Config\\ScopeConfigInterface')) {
    eval('namespace Magento\\Framework\\App\\Config; interface ScopeConfigInterface {
        public function getValue($path, $scopeType = "default", $scopeCode = null);
        public function isSetFlag($path, $scopeType = "default", $scopeCode = null);
    }');
}
if (!interface_exists('Magento\\Csp\\Api\\Data\\PolicyInterface')) {
    eval('namespace Magento\\Csp\\Api\\Data; interface PolicyInterface {}');
}
if (!interface_exists('Magento\\Csp\\Api\\PolicyCollectorInterface')) {
    eval('namespace Magento\\Csp\\Api; interface PolicyCollectorInterface { public function collect(array $defaultPolicies = []): array; }');
}
if (!class_exists('Magento\\Csp\\Model\\Policy\\FetchPolicy')) {
    eval('namespace Magento\\Csp\\Model\\Policy; class FetchPolicy implements \\Magento\\Csp\\Api\\Data\\PolicyInterface {
        public string $id; public array $hostSources;
        public function __construct(string $id, bool $noneAllowed = false, array $hostSources = []) { $this->id = $id; $this->hostSources = $hostSources; }
        public function getId(): string { return $this->id; }
        public function getHostSources(): array { return $this->hostSources; }
    }');
}

require $base . '/Model/Csp/EndpointPolicyCollector.php';

use Myshopcarts\SalesAssistant\Model\Csp\EndpointPolicyCollector;

function cfg(?string $wsUrl): object
{
    return new class($wsUrl) implements \Magento\Framework\App\Config\ScopeConfigInterface {
        public function __construct(private ?string $ws) {}
        public function getValue($path, $scopeType = 'default', $scopeCode = null)
        {
            return $path === 'myshopcarts_salesassistant/api/ws_url' ? $this->ws : null;
        }
        public function isSetFlag($path, $scopeType = 'default', $scopeCode = null) { return false; }
    };
}

$fails = 0;
function check(string $name, bool $ok): void
{
    global $fails;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . "\n";
    if (!$ok) { $fails++; }
}

/** Return the connect-src host sources the collector produces for a ws_url, or []. */
function connectSrc(?string $wsUrl): array
{
    $c = new EndpointPolicyCollector(cfg($wsUrl));
    $out = [];
    foreach ($c->collect([]) as $p) {
        if ($p->getId() === 'connect-src') {
            $out = array_merge($out, $p->getHostSources());
        }
    }
    return $out;
}

// scheme+host, path stripped
check('wss host, path stripped', connectSrc('wss://ws.example.com/ws') === ['wss://ws.example.com']);
// explicit port preserved
check('port preserved',          connectSrc('wss://ws.example.com:8443/ws') === ['wss://ws.example.com:8443']);
// plain ws scheme allowed
check('ws scheme allowed',       connectSrc('ws://localhost:9000') === ['ws://localhost:9000']);
// a DIFFERENT merchant backend flows straight through — nothing is hardcoded
// (.example is the RFC-2606 reserved documentation TLD — never a real host).
check('portable to any host',    connectSrc('wss://chat.acme-merchant.example/socket') === ['wss://chat.acme-merchant.example']);

// no policy added for unusable inputs
check('empty -> no policy',      connectSrc('') === []);
check('null -> no policy',       connectSrc(null) === []);
check('https not a ws url',      connectSrc('https://api.example.com') === []);
check('garbage -> no policy',    connectSrc('not a url') === []);

// the default-policies array is passed through untouched when nothing is added
$c = new EndpointPolicyCollector(cfg(''));
check('passthrough preserves defaults', $c->collect(['sentinel']) === ['sentinel']);

echo $fails === 0 ? "\nPASS (9/9)\n" : "\nFAIL ($fails)\n";
exit($fails === 0 ? 0 : 1);
