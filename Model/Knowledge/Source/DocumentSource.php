<?php
/**
 * DocumentSource — the uploaded-document knowledge source (K2b brick 4). Yields the
 * stored documents for the Knowledge indexer: on a full reindex each document's saved
 * markdown is re-chunked LOCALLY (MarkdownChunker) so no backend call is needed —
 * extraction (binary → markdown) already happened once at upload time and its result is
 * persisted. Slots into the pluggable indexer `sources` alongside the CMS sources.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge\Source;

use Myshopcarts\SalesAssistant\Model\Knowledge\DocumentRepository;
use Myshopcarts\SalesAssistant\Model\Knowledge\MarkdownChunker;

class DocumentSource implements KnowledgeSourceInterface
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly MarkdownChunker $chunker
    ) {
    }

    public function documents(): iterable
    {
        foreach ($this->documents->list() as $doc) {
            $sourceId = (string)$doc['source_id'];
            $markdown = (string)($doc['markdown'] ?? '');
            $chunks = [];
            if (trim($markdown) !== '') {
                $chunks = $this->chunker->chunk($markdown, [
                    'title' => (string)($doc['title'] ?? ''),
                    'source_id' => $sourceId,
                    'url' => (string)($doc['url'] ?? ''),
                    'updated_at' => (string)($doc['updated_at'] ?? ''),
                    'content_hash' => (string)($doc['content_hash'] ?? ''),
                ]);
            }
            yield ['source_id' => $sourceId, 'chunks' => $chunks];
        }
    }
}
