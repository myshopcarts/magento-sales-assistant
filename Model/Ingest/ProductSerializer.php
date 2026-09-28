<?php
/**
 * ProductSerializer — turn a Magento product into (a) filter/display METADATA and
 * (b) a small set of self-contained concept CHUNKS (§3b), each prefixed with an
 * identity header so a retrieved spec chunk is meaningful on its own.
 *
 * Attribute bucketing (§3b): prose (description/short_description/features) →
 * embedded text; sku/brand → keyword; color/size/price/stock → filters. Price and
 * stock are indexed for FILTERING only; live values are re-fetched by SKU at
 * answer time (never embedded).
 *
 * Store-dependent + attribute-code assumptions (manufacturer/color/size/material)
 * — verify/allow-config on a real store.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Ingest;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;

class ProductSerializer
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly StockRegistryInterface $stock,
        private readonly AttributeCatalog $attributeCatalog
    ) {
    }

    /** @return array{doc: array, chunks: array<array{chunk_type:string,text:string}>} */
    public function serialize(ProductInterface $product): array
    {
        $name = (string)$product->getName();
        $sku = (string)$product->getSku();
        $brand = $this->attr($product, 'manufacturer');
        $categories = $this->categoryNames($product);
        $color = $this->withVariantOptions($product, 'color', $this->attrValues($product, 'color'));
        $size = $this->withVariantOptions($product, 'size', $this->attrValues($product, 'size'));
        // Merchant-declared filterable/sortable/searchable attributes beyond colour/size (§ generalized
        // attributes): `fields` are the namespaced index fields (attr_<code> keyword / attrn_<code>
        // numeric); `specs` folds their labels into the embedded text so semantic search sees them too.
        $generic = $this->genericAttributes($product);

        $identityHeader = trim(sprintf(
            "Product: %s | SKU: %s%s%s",
            $name,
            $sku,
            $brand ? " | Brand: {$brand}" : '',
            $categories ? ' | Category: ' . implode(' > ', $categories) : ''
        ));

        $chunks = [];
        $chunks[] = $this->chunk('identity', $identityHeader, $this->identityBody($product, $brand, $categories, $color, $size, $generic['specs']));
        if ($desc = $this->prose($product, ['description', 'short_description'])) {
            $chunks[] = $this->chunk('description', $identityHeader, $desc);
        }
        if ($spec = $this->prose($product, ['additional_information', 'features', 'material'])) {
            $chunks[] = $this->chunk('specifications', $identityHeader, $spec);
        }

        $doc = [
            'sku' => $sku,
            'parent_id' => (string)$product->getId(),
            'name' => $name,
            'brand' => $brand,
            'categories' => $categories,
            'color' => $color,
            'size' => $size,
            'attribute_set' => (string)$product->getAttributeSetId(),
            'price' => (float)$product->getPrice(),
            'in_stock' => $this->inStock($product),
            'identifiers' => $this->identifiers($product, $sku),   // exact-lookup keys (Phase 2)
            'fits' => $this->fitment($product),                    // nested vehicle compatibility (Phase 3)
        ] + $this->saleInfo($product)                              // on_sale + discount_pct (deals intent)
          + $generic['fields'];   // attr_<code> / attrn_<code> beside the explicit fields
        return ['doc' => $doc, 'chunks' => $chunks];
    }

    private function chunk(string $type, string $identity, string $body): array
    {
        // Prepend identity so the chunk stands alone (§3b); no prose-overlap needed.
        return ['chunk_type' => $type, 'text' => $identity . "\n[" . strtoupper($type) . "]\n" . $body];
    }

    private function identityBody(ProductInterface $p, string $brand, array $cats, array $color, array $size, string $specs = ''): string
    {
        $parts = [];
        if ($brand) { $parts[] = "Brand: {$brand}."; }
        if ($cats) { $parts[] = 'Category: ' . implode(' > ', $cats) . '.'; }
        if ($color) { $parts[] = 'Colours: ' . implode(', ', $color) . '.'; }
        if ($size) { $parts[] = 'Sizes: ' . implode(', ', $size) . '.'; }
        if ($m = $this->attr($p, 'material')) { $parts[] = "Material: {$m}."; }
        if ($specs !== '') { $parts[] = $specs; }
        return implode(' ', $parts) ?: (string)$p->getName();
    }

    /**
     * Build the generic attribute index fields + their embedded-text "specs" line from the
     * merchant's declared filterable/sortable/searchable attributes (AttributeCatalog). Numeric
     * attrs → a float in `attrn_<code>`; option attrs → the labels (array) in `attr_<code>`. Empty
     * values are skipped. Best-effort per attribute so one odd attribute never breaks a product.
     *
     * @return array{fields: array<string,mixed>, specs: string}
     */
    private function genericAttributes(ProductInterface $p): array
    {
        $fields = [];
        $specs = [];
        foreach ($this->attributeCatalog->indexable() as $a) {
            try {
                if ($a['numeric']) {
                    $raw = $p->getData($a['code']);
                    if ($raw === null || $raw === '' || !is_numeric($raw)) {
                        continue;
                    }
                    $fields[$a['field']] = (float)$raw;
                    $specs[] = $a['label'] . ': ' . rtrim(rtrim(number_format((float)$raw, 2, '.', ''), '0'), '.');
                } else {
                    // A configurable's VARIANT-AXIS keyword attribute (like colour/size) lives on the
                    // children, so aggregate its variant options too — else the attr_<code> FILTER
                    // misses configurables that vary by THIS attribute (e.g. a store whose variants
                    // split on "material" or "flavour"). Same root cause as colour/size; simples and
                    // non-axis attributes are unchanged (withVariantOptions returns the parent value).
                    $vals = $this->withVariantOptions($p, $a['code'], $this->attrValues($p, $a['code']));
                    if (!$vals) {
                        continue;
                    }
                    $fields[$a['field']] = $vals;                 // keyword[] beside color/size
                    $specs[] = $a['label'] . ': ' . implode(', ', $vals);
                }
            } catch (\Throwable $e) {
                // skip a single unreadable attribute
            }
        }
        return ['fields' => $fields, 'specs' => $specs ? implode('. ', $specs) . '.' : ''];
    }

    private function prose(ProductInterface $p, array $codes): string
    {
        $out = [];
        foreach ($codes as $c) {
            $v = trim(strip_tags((string)$p->getData($c)));
            if ($v !== '') { $out[] = $v; }
        }
        return implode("\n", $out);
    }

    private function attr(ProductInterface $p, string $code): string
    {
        if (method_exists($p, 'getAttributeText')) {
            $t = $p->getAttributeText($code);
            // Multiselect attributes return an array of option labels.
            if (is_array($t)) { $t = implode(', ', array_map('strval', $t)); }
            if (is_string($t) && $t !== '') { return $t; }
        }
        // getData() can also be an array (multiselect / multi-value) — join, never
        // (string)-cast an array (that warns "Array to string conversion").
        $v = $p->getData($code);
        if (is_array($v)) { $v = implode(', ', array_map('strval', $v)); }
        return trim((string)$v);
    }

    private function attrValues(ProductInterface $p, string $code): array
    {
        $v = $this->attr($p, $code);
        return $v !== '' ? array_values(array_filter(array_map('trim', explode(',', $v)))) : [];
    }

    /**
     * For a CONFIGURABLE product, colour/size live on the child VARIANTS, so the parent's own value is
     * empty — indexing only that makes the `color`/`size` FILTER miss every configurable. A "green
     * pants" query then finds nothing (or dead-ends via the colour near-miss) even though the product
     * comes in green, and the shopper sees "not available in green, but comes in … Green". Merge in the
     * configurable's super-attribute option LABELS so the indexed set matches what the shopper sees.
     * Read from the product's own attribute config (getConfigurableAttributesAsArray) — NO child-product
     * load, so it stays cheap at reindex time. Simples are returned unchanged.
     *
     * @param array<string> $own the parent's own value(s) (usually empty for a configurable)
     * @return array<string>
     */
    private function withVariantOptions(ProductInterface $product, string $code, array $own): array
    {
        if ($product->getTypeId() !== 'configurable') {
            return $own;
        }
        $labels = $own;
        try {
            foreach ($product->getTypeInstance()->getConfigurableAttributesAsArray($product) as $attr) {
                if (($attr['attribute_code'] ?? '') !== $code) {
                    continue;
                }
                foreach ($attr['values'] ?? [] as $opt) {
                    $lbl = trim((string)($opt['label'] ?? $opt['store_label'] ?? $opt['default_label'] ?? ''));
                    if ($lbl !== '') {
                        $labels[] = $lbl;
                    }
                }
            }
        } catch (\Throwable $e) {
            return $own;   // best-effort — a type quirk must never break indexing
        }
        $seen = [];
        $out = [];
        foreach ($labels as $l) {                    // dedupe case-insensitively, keep first spelling
            $k = strtolower($l);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $l;
            }
        }
        return $out;
    }

    private function categoryNames(ProductInterface $p): array
    {
        $names = [];
        foreach ((array)$p->getCategoryIds() as $id) {
            try {
                $names[] = (string)$this->categories->get((int)$id)->getName();
            } catch (\Throwable $e) {
                // skip unreadable category
            }
        }
        return array_values(array_filter($names));
    }

    /** Common product-identifier attribute codes — indexed (normalised) for exact lookup (Phase 2).
     *  SKU is always included; the rest only when the merchant's catalogue actually has them. */
    private const IDENTIFIER_CODES = [
        'mpn', 'manufacturer_part_number', 'isbn', 'isbn_10', 'isbn_13', 'upc', 'ean', 'gtin',
        'barcode', 'model_number', 'oem', 'part_number', 'product_code', 'item_code',
    ];

    /**
     * Normalised identifier keys for exact lookup: SKU + any identifier attributes the product has.
     * Normalisation (strip non-alphanumerics, uppercase) mirrors the decompose's `_normalize_code`,
     * so "978-3-16-148410-0" and "9783161484100" match the same indexed value. Deduped.
     *
     * @return array<string>
     */
    private function identifiers(ProductInterface $p, string $sku): array
    {
        $out = [];
        $add = static function ($v) use (&$out): void {
            $n = strtoupper(preg_replace('/[^0-9a-z]/i', '', (string)$v));
            if ($n !== '') {
                $out[$n] = true;
            }
        };
        $add($sku);
        foreach (self::IDENTIFIER_CODES as $code) {
            $v = $p->getData($code);
            if (is_array($v)) {
                foreach ($v as $vv) {
                    $add($vv);
                }
            } elseif ($v !== null && $v !== '') {
                $add($v);
            }
        }
        return array_keys($out);
    }

    /**
     * The product's vehicle-compatibility list as STRUCTURED nested values (Phase 3 v2): each fitment
     * string is parsed into {mm: make+model text, year: int?, raw: original}. Nested so a shopper's
     * target must match make/model AND the year within ONE vehicle (not spread across entries) — and,
     * because the year is its own numeric field, a query can match it EXACTLY or over a RANGE
     * ("fits 2015-2018 Civics") which v1's token match on a concatenated string could not. `raw` keeps
     * the whole string so a plain string filter (v1 shape) still matches via fits.mm token-AND.
     * Empty when the store has no fitment attribute. attrValues covers both a multiselect (option
     * labels) and a delimited text attribute.
     *
     * @return array<array{mm:string,year?:int,raw:string}>
     */
    private function fitment(ProductInterface $p): array
    {
        $code = $this->attributeCatalog->fitmentCode();
        if ($code === '') {
            return [];
        }
        $out = [];
        foreach ($this->attrValues($p, $code) as $v) {
            $v = trim((string)$v);
            if ($v === '') {
                continue;
            }
            $entry = ['raw' => $v];
            if (preg_match('/\b(?:19|20)\d{2}\b/', $v, $m)) {
                $entry['year'] = (int)$m[0];
            }
            $mm = preg_replace('/\b(?:19|20)\d{2}\b/', ' ', $v);
            $mm = trim(preg_replace('/\s{2,}/', ' ', $mm), " ,-–—");
            $entry['mm'] = $mm !== '' ? $mm : $v;   // fall back to the raw string if it was year-only
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Sale signal for the "deals / on-sale" intent: a manual special_price below the regular price and
     * inside its date window (when set) → on_sale=true + discount_pct (% off). Lets "what's on sale?"
     * filter to genuinely discounted products and sort by biggest saving — generic to any catalogue,
     * no per-store content. Catalog price RULES (dynamic) are NOT captured (they're computed at runtime,
     * not on the product); v1 covers the common special-price case. Configurable → the parent's special.
     *
     * @return array{on_sale:bool,discount_pct:float}
     */
    private function saleInfo(ProductInterface $p): array
    {
        $none = ['on_sale' => false, 'discount_pct' => 0.0];
        try {
            $price = (float)$p->getPrice();
            $special = $p->getSpecialPrice();
            if ($price <= 0 || $special === null || $special === '' || (float)$special <= 0 || (float)$special >= $price) {
                return $none;
            }
            $now = time();
            $from = $p->getSpecialFromDate();
            $to = $p->getSpecialToDate();
            if ($from && strtotime((string)$from) > $now) {
                return $none;   // special not started yet
            }
            if ($to && strtotime((string)$to) < $now) {
                return $none;   // special expired
            }
            return ['on_sale' => true, 'discount_pct' => round(($price - (float)$special) / $price * 100, 1)];
        } catch (\Throwable $e) {
            return $none;
        }
    }

    private function inStock(ProductInterface $p): bool
    {
        try {
            return (bool)$this->stock->getStockItem($p->getId())->getIsInStock();
        } catch (\Throwable $e) {
            return true;
        }
    }
}
