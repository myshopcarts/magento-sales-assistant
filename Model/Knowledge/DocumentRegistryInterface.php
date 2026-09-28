<?php
/**
 * DocumentRegistryInterface — the knowledge-document registry seam (save + content-dedup
 * lookup). DocumentRepository is the real implementation (MySQL); the crawl ingestor depends
 * on this interface so it is unit-testable without a DB connection.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

interface DocumentRegistryInterface
{
    /** Insert or update a document by source_id. */
    public function save(array $doc): void;

    /** A document with the same content hash under a DIFFERENT source_id, or null. */
    public function findByContentHash(string $hash, string $exceptSourceId = ''): ?array;

    /** The existing document for this source_id, or null (for unchanged-content skip). */
    public function getBySourceId(string $sourceId): ?array;

    /**
     * Register/refresh a source's list row from index-derived fields only (title/url/hash/
     * chunk_count/status), preserving any richer prior save. Called by the indexer so EVERY
     * indexed source appears in the list, whatever produced it.
     */
    public function registerIndexed(
        string $sourceId,
        string $title,
        string $url,
        int $chunkCount,
        string $contentHash
    ): void;

    /** Remove a source's list row (paired with dropping its chunks). */
    public function deleteBySourceId(string $sourceId): void;
}
