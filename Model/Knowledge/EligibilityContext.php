<?php
/**
 * EligibilityContext (§2 immutable retrieval context, §4 versioning) — the
 * SERVER-derived scoping the knowledge retrieval layer applies. Derived here from
 * the store's OWN Magento metadata (edition + version), NEVER from the widget/LLM
 * or the request body: the shopper's client passes only {query}, and this class —
 * running in the merchant's PHP — supplies the technical-eligibility facet. That is
 * the whole point of §2: the LLM controls no scoping, so a prompt-injection can't
 * widen edition/version reachability.
 *
 * `version` is normalized to the sortable scalar (VersionScalar) so eligibility is a
 * native OpenSearch range filter pushed down into retrieval, not post-processing.
 * `edition` is a lowercase token ('community' | 'enterprise') matched against a
 * chunk's edition[] list; version-scoped central content carries these, merchant
 * CMS content is version_scope=all and ignores both facets.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

use Magento\Framework\App\ProductMetadataInterface;

class EligibilityContext
{
    private ?int $versionScalar = null;
    private bool $resolved = false;
    private string $edition = '';

    public function __construct(
        private readonly ProductMetadataInterface $metadata,
        private readonly VersionScalar $scalar
    ) {
    }

    /** Normalized store version scalar, or null if it can't be parsed (→ fail-closed). */
    public function versionScalar(): ?int
    {
        $this->resolve();
        return $this->versionScalar;
    }

    /** 'community' | 'enterprise' | '' (unknown). */
    public function edition(): string
    {
        $this->resolve();
        return $this->edition;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;
        try {
            $this->versionScalar = $this->scalar->toScalar($this->metadata->getVersion());
            $this->edition = strtolower(trim((string)$this->metadata->getEdition()));
        } catch (\Throwable $e) {
            $this->versionScalar = null;
            $this->edition = '';
        }
    }
}
