<?php
/**
 * ClientProvider — an OpenSearch PHP client pointed at the SAME cluster Magento's
 * catalog search already uses (host/port/auth from `catalog/search/*`), so the
 * assistant's indices live in the merchant's own cluster (§3b: retrieval stays
 * local). Uses the opensearch-project/opensearch-php client bundled with Magento
 * 2.4.6+; falls back to the elasticsearch7 config keys when that engine is set.
 *
 * NB: this is written against the standard Magento config paths; verify on a real
 * store (engine/version specifics) — E5-E6 are store-dependent.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\OpenSearch;

use Magento\Framework\App\Config\ScopeConfigInterface;
use OpenSearch\ClientBuilder;
use OpenSearch\Client;

class ClientProvider
{
    private const BASE = 'catalog/search/';
    private ?Client $client = null;

    public function __construct(private readonly ScopeConfigInterface $config)
    {
    }

    public function get(): Client
    {
        if ($this->client === null) {
            $this->client = ClientBuilder::create()
                ->setHosts([$this->host()])
                ->build();
        }
        return $this->client;
    }

    /** Prefix Magento uses for its search indices; we namespace ours under it. */
    public function indexPrefix(): string
    {
        return $this->cfg($this->prefix() . 'index_prefix') ?: 'magento2';
    }

    private function host(): array
    {
        $p = $this->prefix();
        $host = [
            'host' => $this->cfg($p . 'server_hostname') ?: 'localhost',
            'port' => (int)($this->cfg($p . 'server_port') ?: 9200),
            'scheme' => ($this->cfg($p . 'enable_auth') ? 'https' : 'http'),
        ];
        if ($this->config->isSetFlag(self::BASE . $p . 'enable_auth')) {
            $host['user'] = $this->cfg($p . 'username');
            $host['pass'] = $this->cfg($p . 'password');
        }
        return $host;
    }

    /** opensearch_ vs elasticsearch7_ config-key prefix, by the configured engine. */
    private function prefix(): string
    {
        $engine = $this->cfg('engine');
        return str_starts_with($engine, 'elasticsearch') ? 'elasticsearch7_' : 'opensearch_';
    }

    private function cfg(string $key): string
    {
        return (string)$this->config->getValue(self::BASE . $key);
    }
}
