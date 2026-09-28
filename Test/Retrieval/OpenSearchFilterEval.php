<?php
/**
 * OpenSearchFilterEval — a TINY, test-only evaluator for the subset of the OpenSearch
 * bool-filter DSL that EligibilityFilter emits (term, terms, range lte/gte, exists,
 * bool should/must/must_not + minimum_should_match). It lets the §9 gates assert real
 * reachability of a fixture chunk against a built filter — i.e. "would OpenSearch return
 * this chunk?" — without a live cluster. Not production code; scope is deliberately just
 * what our filter uses.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Test\Retrieval;

final class OpenSearchFilterEval
{
    /** A `filter` clause list is ANDed: the doc is reachable iff every clause matches. */
    public static function reachable(array $filterClauses, array $doc): bool
    {
        foreach ($filterClauses as $clause) {
            if (!self::matches($clause, $doc)) {
                return false;
            }
        }
        return true;
    }

    public static function matches(array $clause, array $doc): bool
    {
        $type = array_key_first($clause);
        $body = $clause[$type];
        switch ($type) {
            case 'term':
                $field = array_key_first($body);
                $want = $body[$field];
                $ci = false;
                // case-insensitive term shape: ['value' => x, 'case_insensitive' => true] (colour/size
                // + generic attr filters, Phase 1). A keyword ARRAY field matches if ANY element does.
                if (is_array($want) && array_key_exists('value', $want)) {
                    $ci = !empty($want['case_insensitive']);
                    $want = $want['value'];
                }
                $have = $doc[$field] ?? null;
                foreach (is_array($have) ? $have : [$have] as $h) {
                    if ($ci ? strcasecmp((string)$h, (string)$want) === 0 : self::scalarEq($h, $want)) {
                        return true;
                    }
                }
                return false;
            case 'terms':
                $field = array_key_first($body);
                $want = $body[$field];
                $have = $doc[$field] ?? null;
                if (is_array($have)) {                 // array field: any element in want
                    return (bool)array_intersect($have, $want);
                }
                return in_array($have, $want, true);
            case 'range':
                $field = array_key_first($body);
                if (!array_key_exists($field, $doc) || $doc[$field] === null) {
                    return false;                      // missing numeric → out of range
                }
                $v = $doc[$field];
                foreach ($body[$field] as $op => $bound) {
                    if ($op === 'lte' && !($v <= $bound)) return false;
                    if ($op === 'gte' && !($v >= $bound)) return false;
                    if ($op === 'lt'  && !($v <  $bound)) return false;
                    if ($op === 'gt'  && !($v >  $bound)) return false;
                }
                return true;
            case 'exists':
                $field = $body['field'];
                $have = $doc[$field] ?? null;
                return $have !== null && $have !== '' && $have !== [];
            case 'bool':
                return self::boolMatches($body, $doc);
            case 'nested':
                // Fitment (Phase 3): reachable iff ONE nested value satisfies the inner query — so a
                // multi-token / make+model+year target can't be spread across different vehicles. v2:
                // the inner query is a bool {must:[match fits.mm, term/range fits.year]}; v1: a bare match.
                $items = $doc[$body['path']] ?? [];
                foreach (is_array($items) ? $items : [] as $item) {
                    if (is_array($item) && self::nestedClause($body['query'], (string)$body['path'], $item)) {
                        return true;
                    }
                }
                return false;
            default:
                throw new \RuntimeException("unsupported clause: $type");
        }
    }

    /**
     * Evaluate one clause of a nested query against ONE nested item. `match` → token containment (the
     * v1/mm text match); `bool` → all `must` clauses hold on the item (the v2 make/model+year); a
     * scalar clause (`term`/`range`/…) is rewritten from `fits.<sf>` to `<sf>` and re-uses matches().
     */
    private static function nestedClause(array $clause, string $path, array $item): bool
    {
        $type = array_key_first($clause);
        if ($type === 'match') {
            return self::matchTokens($clause['match'], $path, $item);
        }
        if ($type === 'bool') {
            foreach ($clause['bool']['must'] ?? [] as $c) {
                if (!self::nestedClause($c, $path, $item)) {
                    return false;
                }
            }
            return true;
        }
        // term / terms / range / exists on fits.<sf> → strip the path prefix, evaluate on the item.
        $spec = $clause[$type];
        $field = $type === 'exists' ? ($spec['field'] ?? '') : array_key_first($spec);
        $sf = str_starts_with((string)$field, $path . '.') ? substr((string)$field, strlen($path) + 1) : (string)$field;
        $rewritten = $type === 'exists' ? ['exists' => ['field' => $sf]] : [$type => [$sf => $spec[$field]]];
        return self::matches($rewritten, $item);
    }

    /** A nested `match` (operator and/or) against ONE nested item, by token containment. */
    private static function matchTokens(array $spec, string $path, array $item): bool
    {
        $field = array_key_first($spec);                 // e.g. "fits.mm"
        $sf = substr($field, strlen($path) + 1);         // "mm"
        $q = $spec[$field];
        $qtext = is_array($q) ? (string)($q['query'] ?? '') : (string)$q;
        $op = is_array($q) ? strtolower((string)($q['operator'] ?? 'or')) : 'or';
        $tok = static fn($s) => array_values(array_filter(preg_split('/\W+/', strtolower($s))));
        $qtok = $tok($qtext);
        $htok = $tok((string)($item[$sf] ?? ''));
        if (!$qtok) {
            return false;
        }
        foreach ($qtok as $t) {
            $in = in_array($t, $htok, true);
            if ($op === 'and' && !$in) {
                return false;
            }
            if ($op !== 'and' && $in) {
                return true;
            }
        }
        return $op === 'and';
    }

    private static function boolMatches(array $body, array $doc): bool
    {
        foreach ($body['must'] ?? [] as $c) {
            if (!self::matches($c, $doc)) return false;
        }
        foreach ($body['must_not'] ?? [] as $c) {
            if (self::matches($c, $doc)) return false;
        }
        if (isset($body['should'])) {
            $min = $body['minimum_should_match'] ?? 1;
            $hits = 0;
            foreach ($body['should'] as $c) {
                if (self::matches($c, $doc)) $hits++;
            }
            if ($hits < $min) return false;
        }
        return true;
    }

    private static function scalarEq($have, $want): bool
    {
        return $have !== null && $have === $want;
    }
}
