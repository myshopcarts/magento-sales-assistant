<?php
/**
 * CatalogueEstimate — roughly how many embedding tokens turning on "Meaning search for products"
 * would use for THIS store's catalogue (doc/sales-assistant-indexing-v3.md §3h). Samples real
 * products through the same ProductSerializer the indexer uses (so long descriptions, many
 * attributes, extra chunks all count), converts characters to tokens at the CALIBRATED rate, and
 * scales by the number of products the indexer would embed.
 * Cached for a day: the admin config page and `salesassistant:status` must stay fast.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Ingest;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\CacheInterface;

class CatalogueEstimate
{
    private const CACHE_KEY = 'myshopcarts_salesassistant_catalogue_estimate';
    private const CACHE_TTL = 86400;
    private const SAMPLE = 60;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly Visibility $visibility,
        private readonly ProductSerializer $serializer,
        private readonly CacheInterface $cache
    ) {
    }

    /** @return array{products:int, per_product:int, tokens:int, sampled:int} */
    public function estimate(bool $refresh = false): array
    {
        if (!$refresh && ($hit = $this->cache->load(self::CACHE_KEY))) {
            $data = json_decode($hit, true);
            if (is_array($data) && isset($data['tokens'])) {
                return $data;
            }
        }
        $products = (int)$this->collectionFactory->create()
            ->setVisibility($this->visibility->getVisibleInSiteIds())
            ->addFieldToFilter('status', 1)
            ->getSize();
        $sample = $this->collectionFactory->create()
            ->addAttributeToSelect('*')
            ->setVisibility($this->visibility->getVisibleInSiteIds())
            ->addFieldToFilter('status', 1);
        // Spread the sample across the catalogue (ids step through the whole range) — cheaper
        // than ORDER BY RAND() on a large table and not biased to the oldest products.
        $step = max(1, intdiv($products, self::SAMPLE));
        $sample->getSelect()->where('e.entity_id % ' . $step . ' = 0')->limit(self::SAMPLE);
        $tokens = 0;
        $n = 0;
        foreach ($sample as $product) {
            try {
                foreach ($this->serializer->serialize($product)['chunks'] as $chunk) {
                    $tokens += self::tokens($chunk['text']);
                }
                $n++;
            } catch (\Throwable $e) {
                continue;
            }
        }
        $per = $n ? (int)round($tokens / $n) : 0;
        $out = ['products' => $products, 'per_product' => $per, 'tokens' => $per * $products, 'sampled' => $n];
        $this->cache->save((string)json_encode($out), self::CACHE_KEY, [], self::CACHE_TTL);
        return $out;
    }

    /**
     * ≈ 5 characters per billed token. Calibrated 2026-10-09 on dev: 60 products / 120 chunks /
     * 269,688 characters were billed 49,738 tokens by the embedding model (5.4 chars/token);
     * ÷5 errs slightly high (+8 %), the safe side for "is my allowance enough?".
     */
    public static function tokens(string $text): int
    {
        return max(1, intdiv(strlen($text), 5));
    }
}
