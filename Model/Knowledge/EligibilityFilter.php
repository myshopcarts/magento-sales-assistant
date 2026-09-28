<?php
/**
 * EligibilityFilter (§4) — the §9 security boundary as a PURE function.
 *
 * Builds the fail-closed OpenSearch filter that decides which knowledge chunks a
 * retrieval request may reach, from the SERVER-derived context only. It is a pure
 * function of (versionScalar, edition) with NO query/tenant/audience parameter — that
 * signature IS the immutable-context guarantee (§2): the shopper's query text and any
 * client-supplied scope cannot influence reachability, because they are not inputs.
 * Extracted from KnowledgeSearch so the boundary is unit-testable with no Magento nor
 * OpenSearch (see Test/Retrieval/gates.php), and reusable by the K5 central store.
 *
 * A chunk is eligible when:
 *   - version_scope = "all"  (merchant CMS + version-agnostic central content), OR
 *   - version_scope = "bounded" AND store version ∈ [version_min, version_max]
 *     AND (the chunk names no edition, OR the store edition is in its edition[]).
 * version_scope = "unknown" (and anything else) is EXCLUDED — never guessed eligible.
 * A null store version (unparseable) makes only "all" chunks reachable.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

final class EligibilityFilter
{
    /**
     * @return array the OpenSearch bool `filter` clause list (push-down, not post-filter)
     */
    public static function build(?int $versionScalar, string $edition): array
    {
        $all = ['term' => ['version_scope' => 'all']];
        if ($versionScalar === null) {
            // Fail-closed: no bounded chunk is reachable without a known store version.
            return [['bool' => ['should' => [$all], 'minimum_should_match' => 1]]];
        }

        $boundedMust = [
            ['term' => ['version_scope' => 'bounded']],
            ['range' => ['version_min' => ['lte' => $versionScalar]]],
            ['range' => ['version_max' => ['gte' => $versionScalar]]],
        ];
        // Edition narrows only when the chunk specifies one (fail-open on edition,
        // fail-closed on version): no edition field OR store edition ∈ edition[].
        $editionShould = [['bool' => ['must_not' => [['exists' => ['field' => 'edition']]]]]];
        if ($edition !== '') {
            $editionShould[] = ['terms' => ['edition' => [$edition]]];
        }
        $boundedMust[] = ['bool' => ['should' => $editionShould, 'minimum_should_match' => 1]];

        return [['bool' => [
            'should' => [$all, ['bool' => ['must' => $boundedMust]]],
            'minimum_should_match' => 1,
        ]]];
    }
}
