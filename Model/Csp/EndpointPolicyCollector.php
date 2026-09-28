<?php
/**
 * Dynamic Content-Security-Policy collector: whitelists the merchant's OWN
 * configured WebSocket host on connect-src, at runtime.
 *
 * Why this is not a static etc/csp_whitelist.xml:
 *   Under Magento CSP `restrict` mode (2.4.7+ enforces it on payment pages; PCI
 *   merchants turn it on globally) the browser BLOCKS any connect-src host that is
 *   not whitelisted. The widget opens a WebSocket to the URL handed to it by the
 *   same-origin /widget-token, i.e. this module's configured `ws_url`. Hardcoding
 *   that host in XML would (a) bake an environment-specific backend host into a
 *   public extension and (b) break any merchant who points the widget at a
 *   different backend — their host would never be whitelisted. So we derive the
 *   host from configuration, the same single source every other endpoint in this
 *   extension comes from. Nothing environment-specific is shipped in the code.
 *
 * The widget's other backend calls (/widget-token, /embed) are made SERVER-SIDE,
 * so the browser never needs api_base on connect-src — only the WebSocket host.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Csp;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;

class EndpointPolicyCollector implements PolicyCollectorInterface
{
    private const WS_URL = 'myshopcarts_salesassistant/api/ws_url';

    public function __construct(private readonly ScopeConfigInterface $config)
    {
    }

    /**
     * @param \Magento\Csp\Api\Data\PolicyInterface[] $defaultPolicies
     * @return \Magento\Csp\Api\Data\PolicyInterface[]
     */
    public function collect(array $defaultPolicies = []): array
    {
        $source = $this->wsSource();
        if ($source === null) {
            return $defaultPolicies;
        }
        // Merged (host-source union) with any existing connect-src policy by the
        // composite collector — additive, never a replacement.
        $defaultPolicies[] = new FetchPolicy('connect-src', false, [$source]);
        return $defaultPolicies;
    }

    /** scheme://host[:port] from the configured ws_url, or null if unusable/unset. */
    private function wsSource(): ?string
    {
        $ws = trim((string)$this->config->getValue(self::WS_URL));
        if ($ws === '') {
            return null;
        }
        $parts = parse_url($ws);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'ws' && $scheme !== 'wss') {
            return null; // only a WebSocket origin belongs on the widget's connect-src
        }
        $source = $scheme . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $source .= ':' . $parts['port'];
        }
        return $source;
    }
}
