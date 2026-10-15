<?php
/**
 * Schema — the two assistant indices (§3b) in the merchant's OpenSearch, plus
 * vector-capability detection (§3f).
 *
 *   {prefix}_myshopcarts_products   catalogue: text(BM25) + attrs(filter) + embedding(knn)
 *   {prefix}_myshopcarts_knowledge  docs/sites/text + embedding (E-later)
 *
 * Attribute bucketing (§3b): prose -> `text` (BM25 + embedded); sku/brand ->
 * keyword (exact/BM25); color/size/price/stock -> keyword/numeric FILTERS. Price &
 * stock are indexed for FILTERING only — the live values are re-fetched by SKU at
 * answer time (never embedded).
 *
 * VECTOR_DIM must equal the backend's embedding output_dimension (sa-config
 * embedding_dim, currently 1536) — a mismatch fails indexing.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\OpenSearch;

use Psr\Log\LoggerInterface;

class Schema
{
    public const VECTOR_DIM = 1536; // keep in sync with backend sa-config embedding_dim

    // Products index (re)build version — BUMP whenever the mapping changes (body()/bodyFor(): a new
    // field, a type change, a new dynamic template) OR the serializer changes WHAT it indexes into an
    // existing field so the docs must be repopulated (e.g. aggregating configurable variant colours
    // into `color`). Setup/RecurringData compares it to a stored flag on every setup:upgrade and
    // INVALIDATES the catalogue indexer when it changes, so an upgrade auto-reindexes (blue/green) on
    // the next cron — no forced, blocking reindex inside setup:upgrade (matching Adobe's guidance).
    public const MAPPING_VERSION = 7;

    // Shard sizing (OpenSearch/Elastic best practice: 10-50GB/shard, ~20-25GB sweet spot; Adobe
    // catalogsearch defaults to 1 and defers to this guidance for large catalogs). A product is ~2-3
    // chunks and each chunk carries a 1536-dim vector (~6KB stored + ~7KB HNSW graph) + text/attrs
    // ≈ 40KB/product, so ~500k products ≈ one ~20GB shard. Since a full rebuild creates a fresh version
    // (blue/green), the count is re-picked on every reindex, self-tuning as the catalogue grows. Capped
    // so a single-node tenant cluster is never over-sharded; replicas stay 0 (single node).
    private const PRODUCTS_PER_SHARD = 500000;
    private const MAX_PRODUCT_SHARDS = 12;

    public function __construct(
        private readonly ClientProvider $clients,
        private readonly LoggerInterface $logger
    ) {
    }

    /** The stable READ/WRITE name for the products index. Post-migration this is an ALIAS pointing at
     *  the currently-active concrete version ({name}_v{ts}); on a fresh/legacy store it is the concrete
     *  index itself (created by ensureProductsIndex on the first incremental write, migrated to the alias
     *  model by the first full rebuild). Readers and incremental writers use this name unchanged. */
    public function productsIndex(): string
    {
        return $this->clients->indexPrefix() . '_myshopcarts_products';
    }

    /** The stable READ/WRITE name for the knowledge index. Post-migration this is an ALIAS that
     *  points at the currently-active concrete version ({name}_v{ts}); on a fresh/legacy store it
     *  is the concrete index itself. Readers and incremental writers use this name unchanged. */
    public function knowledgeIndex(): string
    {
        return $this->clients->indexPrefix() . '_myshopcarts_knowledge';
    }

    /**
     * Create the products index if absent. Returns true if it was created WITH the
     * knn vector field (vector retrieval available), false if the cluster lacks
     * usable k-NN and we fell back to lexical-only (§3f).
     */
    public function ensureProductsIndex(): bool
    {
        $client = $this->clients->get();
        $index = $this->productsIndex();
        if ($client->indices()->exists(['index' => $index])) {
            return $this->hasVector($index);
        }
        // Try WITH vectors first; fall back to lexical-only if k-NN is unsupported.
        try {
            $client->indices()->create($this->body(true));
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('SalesAssistant: k-NN unavailable, lexical-only index. ' . $e->getMessage());
            if (!$client->indices()->exists(['index' => $index])) {
                $client->indices()->create($this->body(false));
            }
            return false;
        }
    }

    /**
     * Create the knowledge index if absent (K1). Same lucene k-NN posture as
     * products. Holds the merchant's uploaded-doc / URL CHUNKS: prose (BM25 +
     * embedded), source metadata, hierarchical parent linkage (small-to-big: index
     * the child, return the parent for context), and the §4 compatibility metadata
     * (edition + normalized version scalars + scope) so the LOCAL index shares the
     * eligibility model with the central shared store. Returns true if created WITH
     * vectors, false if the cluster forced lexical-only (§3f).
     */
    public function ensureKnowledgeIndex(): bool
    {
        $client = $this->clients->get();
        $index = $this->knowledgeIndex();
        if ($client->indices()->exists(['index' => $index])) {
            return $this->hasVector($index);
        }
        try {
            $client->indices()->create($this->knowledgeBody(true));
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('SalesAssistant: k-NN unavailable, lexical-only knowledge index. ' . $e->getMessage());
            if (!$client->indices()->exists(['index' => $index])) {
                $client->indices()->create($this->knowledgeBody(false));
            }
            return false;
        }
    }

    // --- blue/green rebuild (doc/sales-assistant-knowledge-reindex-v2.md §v2b) ------------------
    // A full rebuild builds a FRESH version, verifies it, then atomically repoints the read alias
    // and drops the old — so an embed failure / interrupted reindex can never serve an empty or
    // partial knowledge base, and there is no in-place delete-and-rebuild (no tombstone churn).

    /** Create a fresh, empty knowledge index VERSION ({name}_v{ts}) with the knn mapping (falls back
     *  lexical-only if the cluster lacks k-NN). Does NOT touch the read alias. Returns the concrete
     *  version name to build into. */
    public function buildKnowledgeVersion(): string
    {
        $client = $this->clients->get();
        $version = $this->knowledgeIndex() . '_v' . gmdate('YmdHis') . sprintf('%03d', (int)(explode('.', (string)microtime(true))[1] ?? 0) % 1000);
        try {
            $client->indices()->create($this->knowledgeBodyFor($version, true));
        } catch (\Throwable $e) {
            $this->logger->warning('SalesAssistant: k-NN unavailable, lexical-only knowledge version. ' . $e->getMessage());
            if (!$client->indices()->exists(['index' => $version])) {
                $client->indices()->create($this->knowledgeBodyFor($version, false));
            }
        }
        return $version;
    }

    /**
     * Publish $newVersion as the active knowledge index by repointing the read alias, then drop the
     * previous version(s). REFUSES to publish an EMPTY new index over a non-empty current one (guards
     * an embed failure or a no-op rebuild from wiping the live KB) — returns false, caller deletes
     * $newVersion. Handles the one-time migration from a legacy CONCRETE index of the alias name.
     * Returns true if the alias now points at $newVersion.
     */
    public function promoteKnowledgeVersion(string $newVersion): bool
    {
        $this->assertOwnKnowledgeIndex($newVersion, '_v');
        $client = $this->clients->get();
        $alias = $this->knowledgeIndex();

        if ($this->countDocs($newVersion) === 0 && $this->countDocs($alias) > 0) {
            $this->logger->warning('SalesAssistant: refusing to publish an empty knowledge index over a non-empty one; keeping the current version.');
            return false;
        }

        $aliasExists = $client->indices()->existsAlias(['name' => $alias]);
        // A real index sitting on the alias name = legacy/fresh store not yet migrated.
        $legacyConcrete = !$aliasExists && $client->indices()->exists(['index' => $alias]);

        $actions = [['add' => ['index' => $newVersion, 'alias' => $alias]]];
        $oldVersions = [];
        if ($aliasExists) {
            foreach (array_keys($client->indices()->getAlias(['name' => $alias])) as $concrete) {
                if ($concrete !== $newVersion) {
                    $actions[] = ['remove' => ['index' => $concrete, 'alias' => $alias]];
                    $oldVersions[] = $concrete;
                }
            }
        }
        if ($legacyConcrete) {
            // Can't create an alias whose name is a live index — remove the legacy concrete first.
            $client->indices()->delete(['index' => $alias]);
        }
        $client->indices()->updateAliases(['body' => ['actions' => $actions]]);
        foreach ($oldVersions as $old) {
            try {
                $client->indices()->delete(['index' => $old]);
            } catch (\Throwable $e) {
                $this->logger->warning('SalesAssistant: could not drop old knowledge version ' . $old . '. ' . $e->getMessage());
            }
        }
        return true;
    }

    /**
     * Versions of OUR products / knowledge index ({alias}_v…) that the read alias does NOT point at —
     * the leftovers of a rebuild that was killed before it could promote or abort. Never wildcard-deletes:
     * callers delete each name through the guarded delete*Version().
     * @return string[]
     */
    public function orphanVersions(string $alias): array
    {
        $client = $this->clients->get();
        try {
            $all = array_keys($client->indices()->get(['index' => $alias . '_v*', 'allow_no_indices' => true]));
        } catch (\Throwable $e) {
            return [];
        }
        $live = [];
        try {
            if ($client->indices()->existsAlias(['name' => $alias])) {
                $live = array_keys($client->indices()->getAlias(['name' => $alias]));
            }
        } catch (\Throwable $e) {
            return [];   // can't tell what's live → touch nothing
        }
        return array_values(array_filter($all, fn($i) => str_starts_with($i, $alias . '_v') && !in_array($i, $live, true)));
    }

    /** Delete one knowledge VERSION (a half-built rebuild that was aborted). Guarded to our own. */
    public function deleteKnowledgeVersion(string $version): void
    {
        $this->assertOwnKnowledgeIndex($version, '_v');
        $client = $this->clients->get();
        if ($client->indices()->exists(['index' => $version])) {
            $client->indices()->delete(['index' => $version]);
        }
    }

    /** Live doc count for an index OR alias; 0 if it doesn't exist. */
    public function countDocs(string $indexOrAlias): int
    {
        try {
            return (int)($this->clients->get()->count(['index' => $indexOrAlias])['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Guard: only ever act on OUR knowledge index/version — never a wildcard, blank, or foreign
     *  name. $suffix is the required marker after the owned prefix ('' for the alias, '_v' for a
     *  version — enforced as a substring so both `..._knowledge` and `..._knowledge_v{ts}` pass). */
    private function assertOwnKnowledgeIndex(string $name, string $suffix): void
    {
        $own = $this->clients->indexPrefix() . '_myshopcarts_knowledge';
        $ok = $name !== '' && strpos($name, '*') === false && str_starts_with($name, $own)
            && ($suffix === '' ? $name === $own : str_starts_with($name, $own . $suffix));
        if (!$ok) {
            throw new \RuntimeException('refusing to act on a non-knowledge index: "' . $name . '"');
        }
    }

    // ── Products blue/green (mirrors the knowledge index + Magento's own catalogsearch) ─────────────
    // A full rebuild builds a FRESH version with the CURRENT mapping, verifies it's non-empty, then
    // atomically repoints the read alias and drops the old version — so a mapping change (new fits/
    // identifiers/attr_ fields) is applied on EVERY full reindex with ZERO downtime, and an embed
    // failure / interrupted reindex can never serve an empty or wrong-mapped index. No in-place
    // delete-and-rebuild, so no separate "recreate" step is needed.

    /** Guard: only ever act on OUR products index/version — never a wildcard, blank, or foreign name.
     *  $suffix is '' for the alias (exact match) or '_v' for a version (prefix match). */
    private function assertOwnProductsIndex(string $name, string $suffix): void
    {
        $own = $this->clients->indexPrefix() . '_myshopcarts_products';
        $ok = $name !== '' && strpos($name, '*') === false && str_starts_with($name, $own)
            && ($suffix === '' ? $name === $own : str_starts_with($name, $own . $suffix));
        if (!$ok) {
            throw new \RuntimeException('refusing to act on a non-products index: "' . $name . '"');
        }
    }

    /** Create a fresh, empty products index VERSION ({name}_v{ts}) with the CURRENT mapping, sharded for
     *  $productCount (best practice; see shardsFor). Falls back lexical-only if the cluster lacks k-NN.
     *  Does NOT touch the read alias. Returns the concrete version name to build into. */
    public function buildProductsVersion(int $productCount = 0): string
    {
        $client = $this->clients->get();
        $shards = $this->shardsFor($productCount);
        $version = $this->productsIndex() . '_v' . gmdate('YmdHis') . sprintf('%03d', (int)(explode('.', (string)microtime(true))[1] ?? 0) % 1000);
        try {
            $client->indices()->create($this->bodyFor($version, true, $shards));
        } catch (\Throwable $e) {
            $this->logger->warning('SalesAssistant: k-NN unavailable, lexical-only products version. ' . $e->getMessage());
            if (!$client->indices()->exists(['index' => $version])) {
                $client->indices()->create($this->bodyFor($version, false, $shards));
            }
        }
        $this->logger->info(sprintf('SalesAssistant: building products version %s with %d shard(s) for ~%d products.', $version, $shards, $productCount));
        return $version;
    }

    /**
     * Publish $newVersion as the active products index by repointing the read alias, then drop the
     * previous version(s). REFUSES to publish an EMPTY new index over a non-empty current one (guards an
     * embed failure / interrupted reindex from wiping the live catalogue) — returns false, caller
     * deletes $newVersion. Handles the one-time migration from a legacy CONCRETE index of the alias
     * name. Returns true if the alias now points at $newVersion.
     */
    public function promoteProductsVersion(string $newVersion): bool
    {
        $this->assertOwnProductsIndex($newVersion, '_v');
        $client = $this->clients->get();
        $alias = $this->productsIndex();

        if ($this->countDocs($newVersion) === 0 && $this->countDocs($alias) > 0) {
            $this->logger->warning('SalesAssistant: refusing to publish an empty products index over a non-empty one; keeping the current version.');
            return false;
        }

        $aliasExists = $client->indices()->existsAlias(['name' => $alias]);
        $legacyConcrete = !$aliasExists && $client->indices()->exists(['index' => $alias]);

        $actions = [['add' => ['index' => $newVersion, 'alias' => $alias]]];
        $oldVersions = [];
        if ($aliasExists) {
            foreach (array_keys($client->indices()->getAlias(['name' => $alias])) as $concrete) {
                if ($concrete !== $newVersion) {
                    $actions[] = ['remove' => ['index' => $concrete, 'alias' => $alias]];
                    $oldVersions[] = $concrete;
                }
            }
        }
        if ($legacyConcrete) {
            // Can't create an alias whose name is a live index — remove the legacy concrete first.
            $client->indices()->delete(['index' => $alias]);
        }
        $client->indices()->updateAliases(['body' => ['actions' => $actions]]);
        foreach ($oldVersions as $old) {
            try {
                $client->indices()->delete(['index' => $old]);
            } catch (\Throwable $e) {
                $this->logger->warning('SalesAssistant: could not drop old products version ' . $old . '. ' . $e->getMessage());
            }
        }
        return true;
    }

    /** Delete one products VERSION (a half-built rebuild that was aborted or refused). Guarded to ours. */
    public function deleteProductsVersion(string $version): void
    {
        $this->assertOwnProductsIndex($version, '_v');
        $client = $this->clients->get();
        if ($client->indices()->exists(['index' => $version])) {
            $client->indices()->delete(['index' => $version]);
        }
    }

    /**
     * Drop OUR products index ONLY — every concrete version behind the read alias AND any legacy
     * concrete of the alias name. Owned-name guarded, never a wildcard; every other index in the
     * merchant's (shared) cluster is untouched. Recreated by the next full rebuild / ensureProductsIndex.
     */
    public function dropProductsIndex(): void
    {
        $alias = $this->productsIndex();
        $this->assertOwnProductsIndex($alias, '');
        $client = $this->clients->get();
        if ($client->indices()->existsAlias(['name' => $alias])) {
            foreach (array_keys($client->indices()->getAlias(['name' => $alias])) as $concrete) {
                $this->deleteProductsVersion($concrete);
            }
            return;
        }
        if ($client->indices()->exists(['index' => $alias])) {
            $client->indices()->delete(['index' => $alias]);
        }
    }

    /**
     * Drop OUR knowledge index ONLY — a deliberate clean-slate nuke (the "Clear knowledge base"
     * button). Removes the read alias' active version(s) AND any legacy concrete of the alias name;
     * every OTHER index in the merchant's (shared) cluster is untouched (owned-name guard, never a
     * wildcard). Recreated lazily by ensureKnowledgeIndex() on the next ingest.
     */
    public function dropKnowledgeIndex(): void
    {
        $alias = $this->knowledgeIndex();
        $this->assertOwnKnowledgeIndex($alias, '');
        $client = $this->clients->get();
        // Alias case: delete every concrete version behind it (the alias goes away with its last index).
        if ($client->indices()->existsAlias(['name' => $alias])) {
            foreach (array_keys($client->indices()->getAlias(['name' => $alias])) as $concrete) {
                $this->deleteKnowledgeVersion($concrete);
            }
            return;
        }
        // Legacy/fresh concrete case: delete the index that IS the name.
        if ($client->indices()->exists(['index' => $alias])) {
            $client->indices()->delete(['index' => $alias]);
        }
    }

    public function hasVector(string $index): bool
    {
        try {
            // getMapping keys by CONCRETE index name, so an alias returns its version(s) — scan all.
            foreach ($this->clients->get()->indices()->getMapping(['index' => $index]) as $entry) {
                if (isset($entry['mappings']['properties']['embedding'])) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function body(bool $withVector): array
    {
        return $this->bodyFor($this->productsIndex(), $withVector, 1);
    }

    private function bodyFor(string $index, bool $withVector, int $shards = 1): array
    {
        $properties = [
            'sku' => ['type' => 'keyword'],
            'parent_id' => ['type' => 'keyword'],
            'chunk_type' => ['type' => 'keyword'],
            'text' => ['type' => 'text'],
            'name' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
            'brand' => ['type' => 'keyword'],
            'categories' => ['type' => 'keyword'],
            'color' => ['type' => 'keyword'],
            'size' => ['type' => 'keyword'],
            'attribute_set' => ['type' => 'keyword'],
            'price' => ['type' => 'float'],
            'in_stock' => ['type' => 'boolean'],
            'on_sale' => ['type' => 'boolean'],       // has a live special price below regular (deals intent)
            'discount_pct' => ['type' => 'float'],    // % off vs regular price — sort "best deals first"
            'identifiers' => ['type' => 'keyword'],   // normalised SKU/ISBN/UPC/MPN for exact lookup (Phase 2)
            // Vehicle compatibility (Phase 3): NESTED so a fitment match ("2015 Honda Civic") is
            // required to fall within ONE vehicle entry, never spread across different ones.
            'fits' => ['type' => 'nested', 'properties' => [   // structured vehicle compatibility (Phase 3 v2)
                'mm' => ['type' => 'text'],                     // make+model (token match)
                'year' => ['type' => 'integer'],               // exact/range year match
                'raw' => ['type' => 'text'],                   // original string (v1 fallback)
            ]],
        ];
        $settings = $this->baseSettings($withVector, $shards);
        if ($withVector) {
            $properties['embedding'] = $this->vectorField();
        }
        // Generic merchant attributes are indexed as namespaced fields (doc/sales-assistant-
        // generalized-attributes.md): attrn_<code> numeric (range + sort), attr_<code> the option
        // LABEL(s) as keyword (case-insensitive term filter). Dynamic templates keep the mapping
        // fixed while the set of attributes is catalogue-specific. Disjoint prefixes ("attrn_*" is
        // NOT matched by "attr_*" — the 5th char is 'n', not '_'), so order is irrelevant.
        $dynamicTemplates = [
            ['sa_attr_numeric' => ['match' => 'attrn_*', 'mapping' => ['type' => 'double']]],
            ['sa_attr_keyword' => ['match' => 'attr_*', 'mapping' => ['type' => 'keyword']]],
        ];
        return [
            'index' => $index,
            'body' => ['settings' => $settings, 'mappings' => [
                'dynamic_templates' => $dynamicTemplates,
                'properties' => $properties,
            ]],
        ];
    }

    private function knowledgeBody(bool $withVector): array
    {
        return $this->knowledgeBodyFor($this->knowledgeIndex(), $withVector);
    }

    private function knowledgeBodyFor(string $index, bool $withVector): array
    {
        $properties = [
            // provenance
            'source_type' => ['type' => 'keyword'],   // document | url
            'source_id' => ['type' => 'keyword'],      // stable id of the doc/URL source
            'title' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
            'url' => ['type' => 'keyword'],
            // accept ISO-8601 AND MySQL "Y-m-d H:i:s" (CMS update_time) + epoch
            'updated_at' => ['type' => 'date', 'format' => 'strict_date_optional_time||yyyy-MM-dd HH:mm:ss||epoch_millis'],
            'content_hash' => ['type' => 'keyword'],   // skip-unchanged on re-crawl
            // chunk + hierarchy (§5: structure-first, heading-prefixed, small-to-big)
            'chunk_type' => ['type' => 'keyword'],     // section | clause | qa | procedure
            'heading_path' => ['type' => 'text'],      // "Backups > Retention"
            'text' => ['type' => 'text'],              // BM25 + embedded
            'parent_id' => ['type' => 'keyword'],      // parent section chunk
            'parent_text' => ['type' => 'text', 'index' => false], // returned for context, not searched
            // §4 eligibility metadata (merchant docs are usually version_scope=all;
            // present so local + central share one model, and future-proof)
            'edition' => ['type' => 'keyword'],        // [] of editions
            'version_min' => ['type' => 'long'],       // VersionScalar::toScalar()
            'version_max' => ['type' => 'long'],
            'version_scope' => ['type' => 'keyword'],  // all | bounded | unknown
        ];
        $settings = $this->baseSettings($withVector);
        if ($withVector) {
            $properties['embedding'] = $this->vectorField();
        }
        return [
            'index' => $index,
            'body' => ['settings' => $settings, 'mappings' => ['properties' => $properties]],
        ];
    }

    /** Shard count for a products index sized for $productCount visible products — 1 shard per
     *  PRODUCTS_PER_SHARD, floor 1, capped at MAX_PRODUCT_SHARDS (never over-shard a single-node
     *  tenant cluster). 0/unknown → 1 (small default; the next full rebuild re-sizes it). */
    public function shardsFor(int $productCount): int
    {
        if ($productCount <= 0) {
            return 1;
        }
        return max(1, min(self::MAX_PRODUCT_SHARDS, (int)ceil($productCount / self::PRODUCTS_PER_SHARD)));
    }

    /** @return array index settings; enables k-NN when vectors are used. */
    private function baseSettings(bool $withVector, int $shards = 1): array
    {
        $settings = ['index' => ['number_of_shards' => max(1, $shards), 'number_of_replicas' => 0]];
        if ($withVector) {
            $settings['index']['knn'] = true;
        }
        return $settings;
    }

    /**
     * The k-NN vector field. engine = lucene: pure-Java HNSW, no native libraries —
     * works on OpenSearch 2.x AND 3.0 (where nmslib is removed) and sidesteps the
     * native-lib-path crash. cosinesimil suits normalized text embeddings.
     */
    private function vectorField(): array
    {
        return [
            'type' => 'knn_vector',
            'dimension' => self::VECTOR_DIM,
            'method' => ['name' => 'hnsw', 'space_type' => 'cosinesimil', 'engine' => 'lucene'],
        ];
    }
}
