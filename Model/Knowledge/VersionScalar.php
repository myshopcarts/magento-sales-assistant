<?php
/**
 * VersionScalar — normalize a Magento version to a single monotonic integer so
 * eligibility is a native OpenSearch range filter (pushed down into retrieval),
 * not per-query string parsing (knowledge-slice-plan §4).
 *
 * Magento `-pN` is NOT npm-style semver, so we parse {major,minor,patch,
 * security_patch} explicitly and pack them:
 *
 *   scalar = major*1_000_000 + minor*10_000 + patch*100 + security_patch
 *
 * so 2.4.7 (=2_040_700) precedes 2.4.7-p3 (=2_040_703) precedes 2.4.8 (=2_040_800).
 * Each component is < 100 in practice (Magento minor/patch/-pN are small).
 *
 * This is the extension-side normalizer for the tenant's LOCAL knowledge index;
 * the central shared store (Magento docs, K5) has a Python counterpart that MUST
 * produce identical scalars.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

class VersionScalar
{
    /** Sortable scalar for a Magento version string, or null if unparseable. */
    public static function toScalar(?string $version): ?int
    {
        if ($version === null) {
            return null;
        }
        if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)(?:-p(\d+))?/i', trim($version), $m)) {
            return null;
        }
        [$major, $minor, $patch] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        $securityPatch = isset($m[4]) ? (int)$m[4] : 0;
        return $major * 1_000_000 + $minor * 10_000 + $patch * 100 + $securityPatch;
    }

    /**
     * Deterministic version eligibility (§4), used at retrieval time (K4). A chunk
     * is eligible for a tenant on $tenantScalar iff it is within [min, max] (null
     * bounds are open). `unknown`-scope chunks are handled by the caller's policy
     * (fail-closed for version-sensitive queries) BEFORE this check.
     */
    public static function inRange(int $tenantScalar, ?int $versionMin, ?int $versionMax): bool
    {
        if ($versionMin !== null && $tenantScalar < $versionMin) {
            return false;
        }
        if ($versionMax !== null && $tenantScalar > $versionMax) {
            return false;
        }
        return true;
    }
}
