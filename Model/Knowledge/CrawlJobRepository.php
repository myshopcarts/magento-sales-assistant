<?php
/**
 * CrawlJobRepository (crawl brick C2) — persistence for bulk-crawl jobs + their per-URL work
 * items, over the two crawl tables via ResourceConnection (same lean style as
 * DocumentRepository). The cron worker claims pending URLs a bounded batch at a time and
 * records each outcome + the job's progress counters here; the UI polls the same rows.
 */
declare(strict_types=1);

namespace Myshopcarts\SalesAssistant\Model\Knowledge;

use Magento\Framework\App\ResourceConnection;

class CrawlJobRepository
{
    private const JOB = 'myshopcarts_salesassistant_crawl_job';
    private const URLTAB = 'myshopcarts_salesassistant_crawl_url';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /** Create a job (status pending) and return its id. */
    public function createJob(string $rootUrl, string $origin, string $method, bool $consent): int
    {
        $conn = $this->resource->getConnection();
        $conn->insert($this->t(self::JOB), [
            'root_url' => $rootUrl,
            'origin' => $origin,
            'method' => $method,
            'status' => 'pending',
            'consent' => $consent ? 1 : 0,
        ]);
        return (int)$conn->lastInsertId($this->t(self::JOB));
    }

    /** Add curated URL work items (dedup per job+url). @param array<int,array{url:string,lastmod?:string}> $urls
     *  @return int rows inserted. */
    public function addUrls(int $jobId, array $urls): int
    {
        if (!$urls) {
            return 0;
        }
        $conn = $this->resource->getConnection();
        $rows = [];
        foreach ($urls as $u) {
            $url = trim((string)($u['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $rows[] = ['job_id' => $jobId, 'url' => $url,
                       'lastmod' => (string)($u['lastmod'] ?? ''), 'status' => 'pending'];
        }
        if (!$rows) {
            return 0;
        }
        // Ignore duplicates on the (job_id,url) unique key — re-adding a URL is a no-op.
        $inserted = $conn->insertOnDuplicate($this->t(self::URLTAB), $rows, ['job_id']);
        $this->recountTotal($jobId);
        return (int)$inserted;
    }

    public function setJobStatus(int $jobId, string $status): void
    {
        $this->resource->getConnection()->update(
            $this->t(self::JOB),
            ['status' => $status],
            ['job_id = ?' => $jobId]
        );
    }

    /** @return array<string,mixed>|null */
    public function getJob(int $jobId): ?array
    {
        $conn = $this->resource->getConnection();
        $row = $conn->fetchRow($conn->select()->from($this->t(self::JOB))->where('job_id = ?', $jobId));
        return $row ?: null;
    }

    /** Jobs the cron worker should advance. @return array<int,array<string,mixed>> */
    public function runningJobs(int $limit = 5): array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()->from($this->t(self::JOB))
            ->where('status = ?', 'running')->order('job_id ASC')->limit($limit);
        return $conn->fetchAll($select);
    }

    /** @return array<int,array<string,mixed>> most-recent jobs for the admin list. */
    public function listJobs(int $limit = 20): array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()->from($this->t(self::JOB))->order('job_id DESC')->limit($limit);
        return $conn->fetchAll($select);
    }

    /** Claim up to $limit still-pending URLs for a job (the worker's batch). */
    public function pendingUrls(int $jobId, int $limit): array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()->from($this->t(self::URLTAB))
            ->where('job_id = ?', $jobId)->where('status = ?', 'pending')
            ->order('entity_id ASC')->limit($limit);
        return $conn->fetchAll($select);
    }

    public function countPending(int $jobId): int
    {
        $conn = $this->resource->getConnection();
        return (int)$conn->fetchOne(
            $conn->select()->from($this->t(self::URLTAB), 'COUNT(*)')
                ->where('job_id = ?', $jobId)->where('status = ?', 'pending')
        );
    }

    /** Record one URL's outcome and roll the job's progress counter for that status. */
    public function recordUrlResult(int $entityId, int $jobId, array $result): void
    {
        $conn = $this->resource->getConnection();
        $status = (string)$result['status'];   // indexed | skipped | error
        $conn->update($this->t(self::URLTAB), [
            'status' => $status,
            'source_id' => (string)($result['source_id'] ?? ''),
            'content_hash' => (string)($result['content_hash'] ?? ''),
            'chunk_count' => (int)($result['chunk_count'] ?? 0),
            'reason' => substr((string)($result['reason'] ?? ''), 0, 255),
        ], ['entity_id = ?' => $entityId]);

        $col = ['indexed' => 'done_urls', 'error' => 'failed_urls', 'skipped' => 'skipped_urls'][$status] ?? null;
        if ($col) {
            $conn->update($this->t(self::JOB), [$col => new \Zend_Db_Expr($col . ' + 1')], ['job_id = ?' => $jobId]);
        }
    }

    /** @return array<int,array<string,mixed>> the job's URL rows (for the results view). */
    public function jobUrls(int $jobId, int $limit = 1000): array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()->from($this->t(self::URLTAB))
            ->where('job_id = ?', $jobId)->order('entity_id ASC')->limit($limit);
        return $conn->fetchAll($select);
    }

    public function deleteJob(int $jobId): void
    {
        $conn = $this->resource->getConnection();
        $conn->delete($this->t(self::URLTAB), ['job_id = ?' => $jobId]);
        $conn->delete($this->t(self::JOB), ['job_id = ?' => $jobId]);
    }

    /** Bulk-delete crawl jobs + their URL work items (history cleanup). Deletes only the job
     *  records — already-indexed knowledge chunks are separate and untouched. @param int[] $jobIds
     *  @return int jobs deleted. */
    public function deleteJobs(array $jobIds): int
    {
        $ids = array_values(array_filter(array_map('intval', $jobIds), static fn($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $conn = $this->resource->getConnection();
        $conn->delete($this->t(self::URLTAB), ['job_id IN (?)' => $ids]);
        return (int)$conn->delete($this->t(self::JOB), ['job_id IN (?)' => $ids]);
    }

    private function recountTotal(int $jobId): void
    {
        $conn = $this->resource->getConnection();
        $total = (int)$conn->fetchOne(
            $conn->select()->from($this->t(self::URLTAB), 'COUNT(*)')->where('job_id = ?', $jobId)
        );
        $conn->update($this->t(self::JOB), ['total_urls' => $total], ['job_id = ?' => $jobId]);
    }

    private function t(string $name): string
    {
        return $this->resource->getTableName($name);
    }
}
