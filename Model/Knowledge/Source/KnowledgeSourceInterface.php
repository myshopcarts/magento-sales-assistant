<?php
/**
 * A knowledge source yields documents to ingest into the knowledge index. Pluggable
 * (registered in di.xml into the Knowledge indexer): CMS pages now; uploaded docs
 * (from the AI-extension S3 bucket, §12b) and URLs/sitemaps later — each just a new
 * implementation, no indexer change.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge\Source;

interface KnowledgeSourceInterface
{
    /**
     * @return iterable<array{source_id:string, chunks:array<int,array<string,mixed>>}>
     *   one entry per document; chunks are ready for KnowledgeIndexer.
     */
    public function documents(): iterable;
}
