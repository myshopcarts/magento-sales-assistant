<?php
/**
 * POST /salesassistant/widget/search — the widget's local search proxy (E7 query).
 *
 * The widget (browser) cannot reach the merchant's OpenSearch directly (server-side,
 * env.php creds), so it POSTs the decomposed {query, filters} here; we run the
 * local hybrid search and return the top products with LIVE price/stock (re-fetched
 * by SKU — never the indexed values, which are for filtering only, §3b). The widget
 * relays these up the WS as `search_result`.
 *
 * Read-only same-origin search → CsrfAware (no mutation); only runs when configured.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Controller\Widget;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;
use Myshopcarts\SalesAssistant\Model\Search\HybridSearch;
use Psr\Log\LoggerInterface;

class Search implements ActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Enrich only the top few hits with live per-variant availability. Resolving a configurable's
     * children + their stock is real work; the answer only discusses the leading results, so cap
     * the cost rather than expanding every child of every hit on every search.
     */
    private const VARIANT_TOP_N = 8;

    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly HttpRequest $request,
        private readonly Json $json,
        private readonly SalesAssistantClient $client,
        private readonly HybridSearch $search,
        private readonly ProductRepositoryInterface $products,
        private readonly StockRegistryInterface $stock,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        if (!$this->client->isEnabled()) {
            return $result->setHttpResponseCode(404)->setData(['products' => []]);
        }
        try {
            $body = $this->json->unserialize($this->request->getContent() ?: '{}');
        } catch (\Throwable $e) {
            $body = [];
        }
        $query = trim((string)($body['query'] ?? ''));
        $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];
        // Structured intent (Phase 1): sort / price range ride inside filters (the widget forwards
        // filters unchanged). See doc/sales-assistant-structured-retrieval.md.
        $sort = is_array($filters['sort'] ?? null) ? $filters['sort'] : null;
        $limit = (int)($filters['limit'] ?? 0);
        $minP = isset($filters['min_price']) && is_numeric($filters['min_price']) ? (float)$filters['min_price'] : null;
        $maxP = isset($filters['max_price']) && is_numeric($filters['max_price']) ? (float)$filters['max_price'] : null;
        $structured = $sort !== null || $minP !== null || $maxP !== null;
        if ($query === '' && !$structured) {
            return $result->setData(['products' => []]);
        }
        // Over-fetch a window past the final limit so the live-price re-sort can correct
        // indexed≠live near the boundary (indexed price is filter-only; live is re-fetched by SKU).
        $window = $limit > 0 ? min($limit + 5, 24) : 8;
        try {
            $hits = $this->search->search($query, $filters, $window, $sort);
        } catch (\Throwable $e) {
            $this->logger->error('SalesAssistant search failed: ' . $e->getMessage());
            return $result->setData(['products' => []]);
        }
        $products = [];
        $n = 0;
        foreach ($hits as $hit) {
            $products[] = $this->withLive($hit, $n < self::VARIANT_TOP_N);
            $n++;
        }
        // Graceful degradation: if the full filter set matched NOTHING and there are generic ATTRIBUTE
        // filters (attr_/attrn_), those are the likeliest culprit — a sparsely-populated or mismatched
        // attribute the decompose turned into a HARD filter (e.g. "man pants" → attr_gender:Men on a
        // catalogue that models gender via CATEGORY, so the pants carry no gender value → 0, while a
        // normal Magento relevance search returns them). Relax ONLY the attribute filters and re-search,
        // keeping the query + the reliable colour/price/stock filters, and flag the hits `relaxed` (with
        // the human attribute codes) so the assistant says "I couldn't match <X> exactly — here's the
        // closest" instead of dead-ending. Runs BEFORE the colour near-miss so a green+gender miss keeps
        // GREEN and only drops the gender filter. Never relaxes an exact identifier or a price/colour.
        if (!$products && $query !== '') {
            $attrKeys = array_values(array_filter(
                array_keys($filters),
                static fn($k) => strncmp((string)$k, 'attr', 4) === 0   // attr_* and attrn_*
            ));
            if ($attrKeys) {
                $relaxed = $filters;
                foreach ($attrKeys as $k) {
                    unset($relaxed[$k]);
                }
                try {
                    $rhits = $this->search->search($query, $relaxed, $window, $sort);
                } catch (\Throwable $e) {
                    $rhits = [];
                }
                $dropped = array_map(
                    static fn($k) => preg_replace('/^attrn?_/', '', (string)$k),   // attr_gender → gender
                    $attrKeys
                );
                $n = 0;
                foreach ($rhits as $hit) {
                    $p = $this->withLive($hit, $n < self::VARIANT_TOP_N);
                    $p['relaxed'] = true;
                    $p['relaxed_attrs'] = $dropped;   // what we couldn't match exactly
                    $products[] = $p;
                    $n++;
                }
            }
        }
        // Colour near-miss: a colour filter that matched NOTHING means the colour is genuinely not
        // stocked for this query (the 0.6.40 case-insensitive filter already matches any colour the
        // product IS offered in, so an empty result here is a true miss, not a case artifact).
        // Re-search WITHOUT the colour — a real query, not a guess — and flag the hits `near_miss`
        // with `missed_color`, so the assistant answers "not in <colour>; it comes in <colours>"
        // instead of a dead-end. Mirrors the price near-miss. Runs before the structured/price path
        // because "the colour isn't stocked" is the more specific, correct reason for the miss.
        if (!$products && !empty($filters['color']) && $query !== '') {
            return $result->setData(['products' => $this->colourNearMiss($query, $filters, $sort)]);
        }
        if ($structured) {
            $products = $this->applyLivePrice($products, $sort, $minP, $maxP, $limit, $query, $filters);
        }
        // Enumeration ("show me all …") and aggregates ("how many …", "price range") need FACTS over
        // the whole matching set, not just this window — attach the distinct-product count + price
        // range so the assistant states them verbatim instead of counting a relevance sample.
        if (!empty($filters['aggregate']) || !empty($filters['list'])) {
            return $result->setData($this->withFacts($products, $query, $filters));
        }
        return $result->setData(['products' => $products]);
    }

    /**
     * Re-apply the price range and order on LIVE prices (indexed price is filter-only), then trim to
     * the limit. If a `max_price` cap matched nothing, run ONE more search without the cap for the
     * nearest alternatives, flagged `near_miss` so the assistant offers them as "the closest I have",
     * never as matches. See doc/sales-assistant-structured-retrieval.md §3/§5.
     */
    private function applyLivePrice(array $products, ?array $sort, ?float $minP, ?float $maxP, int $limit, string $query, array $filters): array
    {
        $matching = array_values(array_filter($products, static function ($p) use ($minP, $maxP) {
            $price = (float)($p['price'] ?? 0);
            return ($minP === null || $price >= $minP) && ($maxP === null || $price <= $maxP);
        }));
        if ($sort !== null && ($sort['field'] ?? '') === 'price') {
            $desc = strtolower((string)($sort['dir'] ?? 'asc')) === 'desc';
            usort($matching, static fn($a, $b) => $desc
                ? ((float)($b['price'] ?? 0) <=> (float)($a['price'] ?? 0))
                : ((float)($a['price'] ?? 0) <=> (float)($b['price'] ?? 0)));
        }
        if ($limit > 0) {
            $matching = array_slice($matching, 0, $limit);
        }
        if ($matching) {
            return $matching;
        }
        // Nothing met the price cap — offer the nearest available (a real query, not a guess).
        if ($maxP !== null && $query !== '') {
            $nf = $filters;
            unset($nf['min_price'], $nf['max_price'], $nf['sort'], $nf['limit']);
            try {
                $nearHits = $this->search->search($query, $nf, 3, ['field' => 'price', 'dir' => 'asc']);
            } catch (\Throwable $e) {
                return [];
            }
            $near = [];
            foreach ($nearHits as $hit) {
                $p = $this->withLive($hit, false);
                $p['near_miss'] = true;
                $near[] = $p;
            }
            return $near;
        }
        return [];
    }

    /**
     * The colour wasn't stocked for this query — re-run the SAME search with the colour filter
     * dropped so we can show what the product DOES come in. Each hit is flagged `near_miss` (offer
     * as "the closest / what's available", never as a match) and carries `missed_color`; the top
     * hits get live variant options so the answer can list the real available colours from
     * `options.color.all`. See doc/sales-assistant-structured-retrieval.md §5.
     */
    private function colourNearMiss(string $query, array $filters, ?array $sort): array
    {
        $requested = (string)$filters['color'];
        $nf = $filters;
        unset($nf['color']);
        try {
            $nearHits = $this->search->search($query, $nf, 3, $sort);
        } catch (\Throwable $e) {
            return [];
        }
        $near = [];
        $n = 0;
        foreach ($nearHits as $hit) {
            $p = $this->withLive($hit, $n < self::VARIANT_TOP_N);   // withVariants → options.color.all
            // Only a near-miss if the product genuinely doesn't OFFER the requested colour. The colour
            // FILTER can miss a product that DOES come in the colour (a configurable whose indexed
            // colour set is stale/partial) — flagging THAT as "not available in <colour>" while its own
            // options list the colour contradicts itself ("not in green… comes in Blue, Brown, Green").
            // If the live options include it (case-insensitive), it's a real match: leave it unflagged.
            if (!$this->offersColour($p, $requested)) {
                $p['near_miss'] = true;
                $p['missed_color'] = $requested;
            }
            $near[] = $p;
            $n++;
        }
        return $near;
    }

    /** True if the product's live colour options include $colour (case-insensitive). */
    private function offersColour(array $p, string $colour): bool
    {
        $opts = $p['options']['color']['all'] ?? null;
        if (!is_array($opts)) {
            return false;
        }
        foreach ($opts as $c) {
            if (strcasecmp((string)$c, $colour) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Attach structured FACTS (whole-set distinct-product count + price range) for aggregate /
     * enumeration intents. The price bounds are currency-formatted HERE — the store's currency
     * config lives in the store, not in the backend. Best-effort: a facts failure still returns the
     * product window, so the answer degrades to "here's what I found" rather than erroring.
     */
    private function withFacts(array $products, string $query, array $filters): array
    {
        $data = ['products' => $products];
        try {
            $facts = $this->search->facts($query, $filters);
            if ($facts['price_min'] !== null) {
                $facts['price_min_formatted'] = $this->priceCurrency->format($facts['price_min'], false);
            }
            if ($facts['price_max'] !== null) {
                $facts['price_max_formatted'] = $this->priceCurrency->format($facts['price_max'], false);
            }
            $data['facts'] = $facts;
        } catch (\Throwable $e) {
            $this->logger->error('SalesAssistant facts failed: ' . $e->getMessage());
        }
        return $data;
    }

    /** Replace the indexed price/stock with LIVE values (by SKU); optionally add live variants. */
    private function withLive(array $p, bool $withVariants = false): array
    {
        try {
            $product = $this->products->get($p['sku']);
            $regular = (float)$product->getPrice();
            $price = (float)$product->getFinalPrice();
            // Keep the regular price when it's genuinely higher than the live price, so a deals answer
            // can say "was <regular>, now <price>" (the assistant states both verbatim). Formatted for
            // display like the sale price. Only set on an actual discount — no clutter on full-price items.
            if ($regular > $price + 0.001) {
                $p['regular_price'] = $regular;
                $p['regular_price_formatted'] = $this->priceCurrency->format($regular, false);
            }
            $p['price'] = $price;   // numeric — used for filters / max_price
            // Currency-formatted display string the assistant states verbatim (e.g. "$59.00").
            // The backend can't know the store's currency from a bare number, so we render it
            // here where the store's currency config lives; includeContainer=false = plain text,
            // no HTML wrapper.
            $p['price_formatted'] = $this->priceCurrency->format($price, false);
            $p['in_stock'] = (bool)$this->stock->getStockStatusBySku($p['sku'])->getStockStatus();
            // The product's own storefront URL, so the assistant can give the shopper a direct
            // link to open the item ("give me the link to X"). Built live here where the store's
            // base URL + url-rewrites live; the backend never knows the store's domain.
            $p['url'] = $product->getProductUrl();
            if ($withVariants) {
                $p = $this->addVariantOptions($p, $product);
            }
        } catch (\Throwable $e) {
            // keep indexed values on lookup failure
        }
        return $p;
    }

    /**
     * For a CONFIGURABLE product, attach its variant axes (e.g. size, colour) with which option
     * values are IN STOCK right now and which are offered at all. Indexed stock is a stale
     * parent-level flag, so this is resolved LIVE from the child variants — enabling "is size M in
     * stock?" and "what sizes do you have?". Compact per axis:
     *   options: { size: { in_stock: ["S","M"], all: ["S","M","L"] }, color: {...} }
     * Best-effort: any failure just omits `options` (the answer still has name/price/stock/url).
     */
    private function addVariantOptions(array $p, ProductInterface $product): array
    {
        if ($product->getTypeId() !== 'configurable') {
            return $p;
        }
        try {
            $rowsByAttr = $product->getTypeInstance()->getConfigurableOptions($product);
        } catch (\Throwable $e) {
            return $p;
        }
        $stockCache = [];
        $agg = [];
        foreach ($rowsByAttr as $rows) {
            foreach ($rows as $row) {
                $code = $row['attribute_code'] ?? null;
                $label = (string)($row['option_title'] ?? $row['default_title'] ?? '');
                $csku = (string)($row['sku'] ?? '');
                if ($code === null || $label === '' || $csku === '') {
                    continue;
                }
                if (!array_key_exists($csku, $stockCache)) {
                    try {
                        $stockCache[$csku] = (bool)$this->stock->getStockStatusBySku($csku)->getStockStatus();
                    } catch (\Throwable $e) {
                        $stockCache[$csku] = false;
                    }
                }
                $agg[$code]['all'][$label] = true;
                if ($stockCache[$csku]) {
                    $agg[$code]['in_stock'][$label] = true;
                }
            }
        }
        $options = [];
        foreach ($agg as $code => $sets) {
            $options[$code] = [
                'in_stock' => array_keys($sets['in_stock'] ?? []),
                'all' => array_keys($sets['all'] ?? []),
            ];
        }
        if ($options) {
            $p['options'] = $options;
        }
        return $p;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null; // read-only same-origin search
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
