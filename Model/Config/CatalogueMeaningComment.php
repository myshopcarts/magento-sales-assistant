<?php
/**
 * Dynamic admin comment for "Meaning search for products": the fixed explanation plus this
 * store's estimate vs. allowance (MeaningSearchAdvice). Never breaks the config page — any
 * failure just leaves the fixed explanation.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Config;

use Magento\Config\Model\Config\CommentInterface;
use Magento\Framework\Escaper;
use Myshopcarts\SalesAssistant\Model\Ingest\CatalogueEstimate;
use Myshopcarts\SalesAssistant\Model\SalesAssistantClient;

class CatalogueMeaningComment implements CommentInterface
{
    public const FIXED = 'No (default): the assistant finds products by keyword — it first rewrites the shopper\'s question '
        . 'into search terms and filters (price, colour, size…), so product names, SKUs, prices, attributes and most '
        . 'descriptive questions work well, and no embedding allowance is used for your catalogue. Yes: products are '
        . 'also matched by meaning ("something warm for a winter hike"), which uses embedding allowance for every '
        . 'product. Switching to Yes needs no reindex — products are embedded in the background. Your knowledge '
        . 'documents and pages always use meaning search.';

    public function __construct(
        private readonly CatalogueEstimate $estimate,
        private readonly SalesAssistantClient $client,
        private readonly Escaper $escaper
    ) {
    }

    public function getCommentText($elementValue)
    {
        $extra = '';
        try {
            $budget = null;
            if ($this->client->isEnabled()) {
                try {
                    $budget = $this->client->embedBudget();
                } catch (\Throwable $e) {
                    $budget = null;
                }
            }
            $extra = MeaningSearchAdvice::text($this->estimate->estimate(), $budget);
        } catch (\Throwable $e) {
            $extra = '';
        }
        return $this->escaper->escapeHtml(self::FIXED)
            . ($extra !== '' ? '<br/><br/><strong>' . $this->escaper->escapeHtml($extra) . '</strong>' : '');
    }
}
