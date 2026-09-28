<?php
/**
 * AttributeCatalog — discovers the merchant's OWN filterable / sortable / searchable product
 * attributes from Magento's attribute config, so the assistant indexes and filters by whatever a
 * catalogue actually uses (RAM, material, connector, strength, …) instead of hardcoded colour/size.
 * The principle (doc/sales-assistant-generalized-attributes.md): mirror what the merchant already
 * marked "Filterable (in layered navigation)" / "Searchable" / "Used for Sorting" — near-zero new
 * config. Computed once and cached (the flags are catalogue-global, not per product).
 *
 * Each descriptor says how to index the value: a keyword field `attr_<code>` (select/multiselect/
 * boolean/swatch — option LABELS) or a numeric field `attrn_<code>` (price/weight/decimal/int —
 * range + sort). Codes the serializer already indexes explicitly are excluded to avoid duplicates.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Ingest;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

class AttributeCatalog
{
    /** Already indexed explicitly by ProductSerializer (or pure system) — never re-index generically. */
    private const EXCLUDE = [
        'sku', 'name', 'price', 'special_price', 'status', 'visibility', 'color', 'size',
        'manufacturer', 'material', 'description', 'short_description', 'tax_class_id',
        'cost', 'weight', 'url_key',
    ];
    private const KEYWORD_INPUTS = ['select', 'multiselect', 'boolean', 'swatch_visual', 'swatch_text'];
    private const NUMERIC_INPUTS = ['price', 'weight'];
    private const NUMERIC_BACKENDS = ['decimal', 'int'];
    // Descriptor budget (§5): cap attributes + option values so the per-turn decompose prompt stays
    // small. A high-cardinality attribute (more values than the cap) is sent label-only — the LLM
    // still emits a value and the case-insensitive term filter resolves it.
    private const MAX_DESCRIPTOR_ATTRS = 20;
    private const MAX_DESCRIPTOR_VALUES = 24;
    // Fitment/compatibility attribute codes (Phase 3) — a multi-value list of vehicles a part fits.
    // Indexed nested (one match must stay within one vehicle); the FIRST that exists is used.
    private const FITMENT_CODES = [
        'fits_vehicle', 'fits_vehicles', 'compatible_vehicles', 'compatibility', 'fitment',
        'vehicle_fitment', 'ymm', 'fits',
    ];

    /** @var array<array{code:string,label:string,field:string,numeric:bool,filter:bool,sort:bool,search:bool,multi:bool,options:array}>|null */
    private ?array $cache = null;
    private ?string $fitmentCache = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * The indexable generic attributes (cached). Only attributes the merchant marked filterable,
     * sortable or searchable — that's the "mirror the storefront config" gate.
     *
     * @return array<array{code:string,label:string,field:string,numeric:bool,filter:bool,sort:bool,search:bool,multi:bool}>
     */
    public function indexable(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        try {
            $coll = $this->collectionFactory->create();
            // OR across the four merchant-set flags: filterable (layered nav / in search),
            // searchable, or used-for-sorting. is_filterable is 0/1/2; treat >0 as filterable.
            $coll->addFieldToFilter(
                ['is_filterable', 'is_filterable_in_search', 'is_searchable', 'used_for_sort_by'],
                [['gt' => 0], ['gt' => 0], ['gt' => 0], ['gt' => 0]]
            );
            foreach ($coll as $attr) {
                $code = (string)$attr->getAttributeCode();
                if ($code === '' || in_array($code, self::EXCLUDE, true)) {
                    continue;
                }
                $input = (string)$attr->getFrontendInput();
                $backend = (string)$attr->getBackendType();
                $multi = $input === 'multiselect';
                $isKeyword = in_array($input, self::KEYWORD_INPUTS, true);
                // An OPTION-based attribute (select/multiselect/swatch/boolean) is a KEYWORD filter even
                // though its backend type is `int` — that int is an option ID, not a magnitude. Only a
                // genuine numeric input/backend that is NOT option-based is a numeric RANGE. This mirrors
                // Magento: dropdowns are faceted OPTION filters (catalog_product_index_eav, filtered by
                // option id) and can be a configurable's variant axis (RAM 8/16/32 GB → the "16 GB"
                // option), while decimals are RANGE filters (…_eav_decimal, e.g. price/weight) and are
                // always product-level. Classifying a dropdown as numeric indexed its option id (and,
                // on a configurable, nothing) — so "16 GB RAM" matched no products.
                $numeric = !$isKeyword && (in_array($input, self::NUMERIC_INPUTS, true) || in_array($backend, self::NUMERIC_BACKENDS, true));
                // Index a value only if it's usable: numeric, a labelled option, or a short filterable
                // text code. Skip free-text/date/media that would just be noise as a hard filter.
                if (!$numeric && !$isKeyword && !($input === 'text' && (int)$attr->getIsFilterable() > 0)) {
                    // still allow searchable free text into the embedded text bucket
                    if (!(int)$attr->getIsSearchable()) {
                        continue;
                    }
                }
                $filter = ((int)$attr->getIsFilterable() > 0) || ((int)$attr->getIsFilterableInSearch() > 0);
                // Option labels for keyword attributes (for the decompose descriptor); best-effort.
                $options = [];
                if ($isKeyword) {
                    try {
                        foreach ($attr->getSource()->getAllOptions(false) as $o) {
                            $lbl = is_array($o) ? (string)($o['label'] ?? '') : (string)$o;
                            if ($lbl !== '') {
                                $options[] = $lbl;
                            }
                        }
                    } catch (\Throwable $e) {
                        $options = [];
                    }
                }
                $out[] = [
                    'code' => $code,
                    'label' => (string)($attr->getDefaultFrontendLabel() ?: $code),
                    'field' => ($numeric ? 'attrn_' : 'attr_') . $code,
                    'numeric' => $numeric,
                    'filter' => $filter,
                    'sort' => (int)$attr->getUsedForSortBy() > 0,
                    'search' => (int)$attr->getIsSearchable() > 0,
                    'multi' => $multi,
                    'options' => $options,
                ];
            }
        } catch (\Throwable $e) {
            // Discovery is best-effort — a failure just falls back to the explicit colour/size shape.
            $out = [];
        }
        $this->cache = $out;
        return $out;
    }

    /**
     * Compact filterable-attribute vocabulary for the backend decompose (§2c/§5): the attributes a
     * shopper can narrow by. Keyword attributes → `{key: attr_<code>, label, values?}` (the key IS the
     * index field, so the decompose emits `filters[key] = value` → case-insensitive term filter).
     * Numeric attributes with a parseable unit (Phase 1c) → `{key: attrn_<code>, label, unit}`, which
     * sa_plan uses to extract unit-anchored ranges ("16gb", "over 200hp"). A fitment attribute (Phase
     * 3) → `{key:'fits', fitment:true}`. Capped for prompt budget; a high-cardinality keyword attribute
     * is sent label-only.
     *
     * @return array<array{key:string,label:string,values?:array<string>,unit?:string,fitment?:bool}>
     */
    public function descriptor(): array
    {
        $out = [];
        foreach ($this->indexable() as $a) {
            if (count($out) >= self::MAX_DESCRIPTOR_ATTRS) {
                break;
            }
            if ($a['numeric']) {
                // Numeric attribute (Phase 1c): only usable if it's filterable/sortable AND we can
                // parse a UNIT from the label (e.g. "RAM (GB)") — the unit is the unambiguous anchor
                // the regex extractor keys off ("16gb"). No unit → no reliable extraction, so skip.
                if (!($a['filter'] || $a['sort'])) {
                    continue;
                }
                $unit = $this->parseUnit($a['label']);
                if ($unit === '') {
                    continue;
                }
                $out[] = ['key' => $a['field'], 'label' => $a['label'], 'unit' => $unit];
                continue;
            }
            if (!$a['filter']) {
                continue;
            }
            $entry = ['key' => $a['field'], 'label' => $a['label']];
            $vals = $a['options'] ?? [];
            if ($vals && count($vals) <= self::MAX_DESCRIPTOR_VALUES) {
                $entry['values'] = array_values($vals);
            }
            $out[] = $entry;
        }
        // Fitment marker (Phase 3): tells the backend this store lists vehicle compatibility, so the
        // planner extracts a vehicle target into filters.fits. Carries no values (open free text).
        if ($this->fitmentCode() !== '') {
            $out[] = ['key' => 'fits', 'fitment' => true];
        }
        return $out;
    }

    /**
     * The store's fitment/compatibility attribute code, or '' if it has none. Cached. The first of
     * FITMENT_CODES that exists as a product attribute — checked directly (fitment is often not
     * flagged filterable, so it wouldn't appear in indexable()).
     */
    public function fitmentCode(): string
    {
        if ($this->fitmentCache !== null) {
            return $this->fitmentCache;
        }
        $found = '';
        try {
            $coll = $this->collectionFactory->create();
            $coll->addFieldToFilter('attribute_code', ['in' => self::FITMENT_CODES]);
            $byCode = [];
            foreach ($coll as $attr) {
                $byCode[(string)$attr->getAttributeCode()] = true;
            }
            foreach (self::FITMENT_CODES as $code) {   // stable preference order
                if (isset($byCode[$code])) {
                    $found = $code;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $found = '';
        }
        $this->fitmentCache = $found;
        return $found;
    }

    /** A unit token from a numeric attribute's label, e.g. "RAM (GB)" → "gb", "Weight (kg)" → "kg".
     *  Only the trailing parenthetical form (unambiguous); '' otherwise. */
    private function parseUnit(string $label): string
    {
        if (preg_match('/\(\s*([\p{L}%\/]{1,6})\s*\)\s*$/u', $label, $m)) {
            return mb_strtolower(trim($m[1]));
        }
        return '';
    }
}
