<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Service;

use Contao\Database;
use Contao\PageModel;
use Psr\Log\LoggerInterface;

/**
 * Queue-based index management service
 * 
 * Only handles pages crawled by PageCrawler.
 */
class IndexQueue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DELETED = 'deleted';

    public const TYPE_PAGE = 'page';

    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Queue a page for indexing
     */
    public function queuePage(int $pageId, int $priority = 5): void
    {
        $page = PageModel::findByPk($pageId);
        if (!$page) {
            return;
        }

        // Skip non-indexable pages
        if (!$this->isPageIndexable($page)) {
            $this->markForDeletion(self::TYPE_PAGE, 'tl_page', $pageId);
            return;
        }

        $this->queue([
            'source_type' => self::TYPE_PAGE,
            'source_table' => 'tl_page',
            'source_id' => $pageId,
            'source_url' => $page->getFrontendUrl() ?? '',
            'priority' => $priority,
            'language' => $page->language ?: '',
            'root_page' => $page->rootId ?: 0,
            'title' => $page->title ?: '',
        ]);
    }

    /**
     * Mark a source for deletion from index
     */
    public function markForDeletion(string $sourceType, string $sourceTable, int $sourceId): void
    {
        $db = Database::getInstance();
        
        $existing = $db->prepare('SELECT id FROM tl_genai_index WHERE source_table=? AND source_id=?')
            ->limit(1)
            ->execute($sourceTable, $sourceId);

        if ($existing->numRows) {
            $db->prepare('UPDATE tl_genai_index SET status=?, tstamp=? WHERE source_table=? AND source_id=?')
                ->execute(self::STATUS_DELETED, time(), $sourceTable, $sourceId);
        }

        $this->log("Marked for deletion: {$sourceType} {$sourceId}");
    }

    /**
     * Add or update queue entry
     */
    protected function queue(array $data): void
    {
        $db = Database::getInstance();
        $now = time();

        $data['tstamp'] = $now;
        $data['status'] = self::STATUS_PENDING;

        // Check if already in queue
        $existing = $db->prepare('SELECT id, content_hash FROM tl_genai_index WHERE source_table=? AND source_id=?')
            ->limit(1)
            ->execute($data['source_table'], $data['source_id']);

        if ($existing->numRows) {
            // Update existing entry
            $data['previous_hash'] = $existing->content_hash;
            $data['retry_count'] = 0;
            $data['error_message'] = null;
            
            $db->prepare('UPDATE tl_genai_index %s WHERE id=?')
                ->set($data)
                ->execute($existing->id);
                
            $this->log("Updated queue entry: {$data['source_type']} {$data['source_id']}");
        } else {
            // Insert new entry
            $db->prepare('INSERT INTO tl_genai_index %s')
                ->set($data)
                ->execute();
                
            $this->log("Added to queue: {$data['source_type']} {$data['source_id']}");
        }
    }

    /**
     * Get next batch of pending items
     */
    public function getNextBatch(int $limit = 10): array
    {
        $db = Database::getInstance();
        
        return $db->prepare('
            SELECT * FROM tl_genai_index 
            WHERE status = ? AND retry_count < max_retries
            ORDER BY priority ASC, tstamp ASC
        ')
        ->limit($limit)
        ->execute(self::STATUS_PENDING)
        ->fetchAllAssoc();
    }

    /**
     * Get items marked for deletion
     */
    public function getDeletedItems(int $limit = 50): array
    {
        $db = Database::getInstance();
        
        return $db->prepare('SELECT * FROM tl_genai_index WHERE status = ?')
            ->limit($limit)
            ->execute(self::STATUS_DELETED)
            ->fetchAllAssoc();
    }

    /**
     * Mark item as processing
     */
    public function markProcessing(int $id): void
    {
        Database::getInstance()
            ->prepare('UPDATE tl_genai_index SET status=?, tstamp=? WHERE id=?')
            ->execute(self::STATUS_PROCESSING, time(), $id);
    }

    /**
     * Mark item as completed
     */
    public function markCompleted(int $id, string $contentHash = ''): void
    {
        $data = [
            'status' => self::STATUS_COMPLETED,
            'processed_at' => time(),
            'tstamp' => time(),
            'error_message' => null,
        ];
        
        if ($contentHash) {
            $data['content_hash'] = $contentHash;
        }

        Database::getInstance()
            ->prepare('UPDATE tl_genai_index %s WHERE id=?')
            ->set($data)
            ->execute($id);
    }

    /**
     * Mark item as failed
     */
    public function markFailed(int $id, string $error): void
    {
        Database::getInstance()
            ->prepare('UPDATE tl_genai_index SET status=?, retry_count=retry_count+1, error_message=?, tstamp=? WHERE id=?')
            ->execute(self::STATUS_FAILED, $error, time(), $id);

        // Reset to pending if retries remaining
        Database::getInstance()
            ->prepare('UPDATE tl_genai_index SET status=? WHERE id=? AND retry_count < max_retries')
            ->execute(self::STATUS_PENDING, $id);
    }

    /**
     * Remove completed deletion entries
     */
    public function removeDeletedEntry(int $id): void
    {
        Database::getInstance()
            ->prepare('DELETE FROM tl_genai_index WHERE id=?')
            ->execute($id);
    }

    /**
     * Get queue statistics
     */
    public function getStats(): array
    {
        $db = Database::getInstance();
        
        $stats = $db->execute("
            SELECT 
                status,
                COUNT(*) as count
            FROM tl_genai_index
            GROUP BY status
        ")->fetchAllAssoc();

        $result = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'deleted' => 0,
            'total' => 0,
        ];

        foreach ($stats as $row) {
            $result[$row['status']] = (int) $row['count'];
            $result['total'] += (int) $row['count'];
        }

        return $result;
    }

    /**
     * Check if page is indexable
     */
    protected function isPageIndexable(PageModel $page): bool
    {
        // Check publication status
        if (!$page->published) {
            return false;
        }

        // Check time restrictions
        $now = time();
        if ($page->start && $page->start > $now) {
            return false;
        }
        if ($page->stop && $page->stop < $now) {
            return false;
        }

        // Check page type
        $indexableTypes = ['regular', 'root'];
        if (!in_array($page->type, $indexableTypes, true)) {
            return false;
        }

        // Check noSearch flag
        if ($page->noSearch) {
            return false;
        }

        // Check robots setting
        if (str_contains($page->robots ?? '', 'noindex')) {
            return false;
        }

        // Check protected pages
        if ($page->protected) {
            return false;
        }

        return true;
    }

    /**
     * Log message
     */
    protected function log(string $message): void
    {
        $this->logger?->debug("[GenAI IndexQueue] {$message}");
    }
}
