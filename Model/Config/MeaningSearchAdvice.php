<?php
/**
 * MeaningSearchAdvice — the store-specific line shown under "Meaning search for products":
 * what the catalogue would cost in embedding tokens vs. the plan's allowance
 * (doc/sales-assistant-indexing-v3.md §3h). Pure: estimate + budget in, sentence out.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Config;

final class MeaningSearchAdvice
{
    /**
     * @param array{products:int, per_product:int, tokens:int} $est
     * @param array{metered?:bool, allowance?:int, remaining?:int}|null $budget  null = unknown
     */
    public static function text(array $est, ?array $budget): string
    {
        if ($est['products'] <= 0 || $est['per_product'] <= 0) {
            return '';
        }
        $line = sprintf('Your catalogue: %s products × about %s tokens each ≈ %s embedding tokens to switch this on '
            . '(once; after that only changed products are embedded again, and a full reindex embeds everything again).',
            number_format($est['products']), number_format($est['per_product']), self::short($est['tokens']));
        if ($budget === null) {
            return $line . ' Your allowance could not be checked right now.';
        }
        if (empty($budget['metered'])) {
            return $line;
        }
        $allowance = (int)($budget['allowance'] ?? 0);
        $left = max(0, (int)($budget['remaining'] ?? 0));
        $line .= sprintf(' Your plan\'s embedding allowance: %s per month, %s left this month.', self::short($allowance), self::short($left));
        if ($est['tokens'] <= $left) {
            return $line . ' ✔ Enough to embed your whole catalogue now.';
        }
        if ($est['tokens'] <= $allowance) {
            return $line . ' Not enough left this month — it would finish after your allowance renews. Products waiting for an embedding are still found by keyword, but the embedded ones rank higher until it completes.';
        }
        $share = $allowance > 0 ? (int)floor(100 * $allowance / $est['tokens']) : 0;
        return $line . sprintf(' ✘ More than your monthly allowance — it would embed only about %d%% of your catalogue, and those products would rank above the rest. Keep keyword search unless you raise the allowance.', $share);
    }

    private static function short(int $n): string
    {
        return match (true) {
            $n >= 1_000_000 => rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.') . 'M',
            $n >= 10_000 => number_format(intdiv($n, 1000)) . 'k',
            default => number_format($n),
        };
    }
}
