<?php
/**
 * DocumentRepository — lean registry for uploaded knowledge documents (K2b brick 4),
 * over the myshopcarts_salesassistant_document table via ResourceConnection (no ORM
 * boilerplate for an internal-only registry). Stores the extracted markdown as the
 * operational source of truth so reindex re-chunks + re-embeds locally.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

use Magento\Framework\App\ResourceConnection;

class DocumentRepository implements DocumentRegistryInterface
{
    private const TABLE = 'myshopcarts_salesassistant_document';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /** Insert or update by source_id. $doc keys: source_id,title,filename,s3_key,
     *  content_hash,markdown,chunk_count,status. */
    public function save(array $doc): void
    {
        $conn = $this->resource->getConnection();
        $row = [
            'source_id' => (string)$doc['source_id'],
            'title' => (string)($doc['title'] ?? ''),
            'filename' => (string)($doc['filename'] ?? ''),
            's3_key' => (string)($doc['s3_key'] ?? ''),
            'url' => (string)($doc['url'] ?? ''),
            'content_hash' => (string)($doc['content_hash'] ?? ''),
            'markdown' => (string)($doc['markdown'] ?? ''),
            'chunk_count' => (int)($doc['chunk_count'] ?? 0),
            'status' => (string)($doc['status'] ?? 'indexed'),
        ];
        $conn->insertOnDuplicate($this->table(), $row, array_keys($row));
    }

    /** @return array<int,array<string,mixed>> all documents, newest first. */
    public function list(): array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()->from($this->table())->order('updated_at DESC');
        return $conn->fetchAll($select);
    }

    /** @return array<string,mixed>|null */
    public function getBySourceId(string $sourceId): ?array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()->from($this->table())->where('source_id = ?', $sourceId)->limit(1);
        $row = $conn->fetchRow($select);
        return $row ?: null;
    }

    /** Find a document with the same extracted-content hash under a DIFFERENT source_id
     *  (identical content uploaded under another filename). @return array<string,mixed>|null */
    public function findByContentHash(string $hash, string $exceptSourceId = ''): ?array
    {
        if ($hash === '') {
            return null;
        }
        $conn = $this->resource->getConnection();
        $select = $conn->select()->from($this->table())->where('content_hash = ?', $hash);
        if ($exceptSourceId !== '') {
            $select->where('source_id != ?', $exceptSourceId);
        }
        $row = $conn->fetchRow($select->limit(1));
        return $row ?: null;
    }

    public function deleteBySourceId(string $sourceId): void
    {
        $conn = $this->resource->getConnection();
        $conn->delete($this->table(), ['source_id = ?' => $sourceId]);
    }

    /** Clear the whole document registry (paired with Schema::dropKnowledgeIndex for a nuke). */
    public function deleteAll(): void
    {
        $conn = $this->resource->getConnection();
        $conn->delete($this->table());
    }

    /** Set retrieval priority (1=normal, 2=high, 3=highest) for one or more sources. */
    public function setPriority(array $sourceIds, int $priority): int
    {
        $ids = array_values(array_filter(array_map('strval', $sourceIds), static fn($s) => $s !== ''));
        if (!$ids) {
            return 0;
        }
        $priority = max(1, min(3, $priority));
        $conn = $this->resource->getConnection();
        return (int)$conn->update($this->table(), ['priority' => $priority], ['source_id IN (?)' => $ids]);
    }

    /** Priority per source_id for the given ids (default 1 when a source has no row). Batched so
     *  KnowledgeSearch applies the boost with one query, not N.
     *  @param string[] $sourceIds @return array<string,int> */
    public function prioritiesFor(array $sourceIds): array
    {
        $ids = array_values(array_filter(array_map('strval', $sourceIds), static fn($s) => $s !== ''));
        if (!$ids) {
            return [];
        }
        $conn = $this->resource->getConnection();
        $rows = $conn->fetchPairs(
            $conn->select()->from($this->table(), ['source_id', 'priority'])->where('source_id IN (?)', $ids)
        );
        $out = [];
        foreach ($rows as $sid => $p) {
            $out[(string)$sid] = (int)$p;
        }
        return $out;
    }

    /**
     * Register/refresh the list row for a source that was just indexed, from what the chunks
     * carry. Touches ONLY the index-derived columns via insertOnDuplicate, so a richer prior
     * save (markdown/s3_key/filename from an upload or crawl) is preserved. This is what makes
     * EVERY indexed source — CMS pages included — appear in the Knowledge documents list.
     */
    public function registerIndexed(
        string $sourceId,
        string $title,
        string $url,
        int $chunkCount,
        string $contentHash
    ): void {
        $conn = $this->resource->getConnection();
        $row = [
            'source_id' => $sourceId,
            'title' => $title,
            'url' => $url,
            'content_hash' => $contentHash,
            'chunk_count' => $chunkCount,
            'status' => 'indexed',
        ];
        // Update only these columns on duplicate — never clobber filename/s3_key/markdown.
        $conn->insertOnDuplicate($this->table(), $row, ['title', 'url', 'content_hash', 'chunk_count', 'status']);
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
