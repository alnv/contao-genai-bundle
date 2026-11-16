<?php

namespace Alnv\GenaiBundle\Cronjobs;

use Alnv\GenaiBundle\Helpers\DocumentChunks;
use Alnv\GenaiBundle\Service\IndexQueue;
use Alnv\GenaiBundle\Vectors\ChromaDb;
use Alnv\GenaiBundle\Vectors\DocumentLockedException;
use Alnv\GenaiBundle\Vectors\SyncIncompleteException;
use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Database;
use Contao\System;
use Psr\Log\LogLevel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Cronjob: embeds pending documents and processes the index queue
 *
 * 1. Deletions from tl_genai_index
 * 2. Queue items from tl_genai_index
 * 3. Pending documents in tl_genai_documents (synced_at=0, not sync_disabled)
 *
 * Uses file-based locking to prevent concurrent runs (plus a per-document lock in ChromaDb).
 * Uses global embedding configuration.
 */
class SyncVectors
{
    private const BATCH_SIZE = 5;
    private const TIME_LIMIT_SECONDS = 55;
    private const LOCK_NAME = 'genai-sync-vectors';

    private ?IndexQueue $indexQueue = null;
    private ?ChromaDb $chromaDb = null;
    private float $startTime;

    public function __construct(private readonly ?ContaoFramework $framework = null)
    {
    }

    public function __invoke(): void
    {
        // CLI cron (contao:cron) does not initialize the framework -> Config/Database unavailable
        $this->framework?->initialize();

        // Check global configuration
        $ollamaUrl = Config::get('genAIChromaDBOllamaUrl');
        $ollamaModel = Config::get('genAIChromaDBOllamaModel');

        if (!$ollamaUrl || !$ollamaModel) {
            $this->log('Ollama configuration missing (genAIChromaDBOllamaUrl, genAIChromaDBOllamaModel)', LogLevel::WARNING);
            return;
        }

        // Acquire lock
        $lockFactory = \Alnv\GenaiBundle\Helpers\Locks::factory();
        $lock = $lockFactory->createLock(self::LOCK_NAME, 300);

        if (!$lock->acquire()) {
            $this->log('Another sync process is running, skipping');
            return;
        }

        $this->startTime = microtime(true);

        try {
            $this->initServices($ollamaUrl, $ollamaModel);
            $this->processDeletions();
            $this->processPending();
            $this->processPendingDocuments();
        } catch (\Exception $e) {
            $this->log('Sync error: ' . $e->getMessage(), LogLevel::ERROR);
        } finally {
            $lock->release();
        }
    }

    /**
     * Initialize services with global config
     */
    private function initServices(string $ollamaUrl, string $ollamaModel): void
    {
        $this->indexQueue = new IndexQueue();
        $this->chromaDb = new ChromaDb();
        $this->chromaDb->setOllamaEmbedding($ollamaUrl, $ollamaModel);
    }

    /**
     * Process items marked for deletion
     */
    private function processDeletions(): void
    {
        $deletions = $this->indexQueue->getDeletedItems(20);

        foreach ($deletions as $item) {
            if ($this->isTimeExceeded()) {
                break;
            }

            try {
                // Delete from ChromaDB
                if (!empty($item['source_url'])) {
                    $this->chromaDb->deleteByUrl($item['source_url']);
                }

                // Delete chunks + document from tl_genai_documents
                DocumentChunks::deleteByDocumentUrl((string) $item['source_url']);

                Database::getInstance()
                    ->prepare('DELETE FROM tl_genai_documents WHERE url=?')
                    ->execute($item['source_url']);

                // Remove queue entry
                $this->indexQueue->removeDeletedEntry((int) $item['id']);

                $this->log("Deleted: {$item['source_type']} {$item['source_id']}");
            } catch (\Exception $e) {
                $this->indexQueue->markFailed((int) $item['id'], $e->getMessage());
            }
        }
    }

    /**
     * Process pending items
     */
    private function processPending(): void
    {
        $processed = 0;

        while (!$this->isTimeExceeded()) {
            $batch = $this->indexQueue->getNextBatch(self::BATCH_SIZE);

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $item) {
                if ($this->isTimeExceeded()) {
                    break 2;
                }

                $this->processItem($item);
                $processed++;
            }
        }

        if ($processed > 0) {
            $this->log("Processed {$processed} items");
        }
    }

    /**
     * Embed documents that are waiting for synchronization (synced_at=0).
     *
     * Failed documents are moved to the end of the queue (sync_failed_at) so that one broken
     * document does not block all others; each document is tried at most once per run.
     *
     * @return array{synced: int, failed: int, skipped: int}
     */
    public function processPendingDocuments(?int $limit = null): array
    {
        $stats = ['synced' => 0, 'failed' => 0, 'skipped' => 0, 'partial' => 0];
        $tried = [0];
        $db = Database::getInstance();

        while (!$this->isTimeExceeded() && ($limit === null || \array_sum($stats) < $limit)) {
            $ids = $db->prepare(
                "SELECT id FROM tl_genai_documents
                 WHERE synced_at=0 AND tstamp>0 AND sync_disabled='' AND id NOT IN (" . \implode(',', $tried) . ")
                 ORDER BY sync_failed_at ASC, tstamp ASC"
            )->limit(self::BATCH_SIZE)->execute()->fetchEach('id');

            if (!$ids) {
                break;
            }

            foreach ($ids as $id) {
                if ($this->isTimeExceeded() || ($limit !== null && \array_sum($stats) >= $limit)) {
                    break 2;
                }

                $tried[] = (int) $id;

                try {
                    $result = $this->chromaDb->syncDocument((int) $id, false, $this->getDeadline());
                    $result['synced'] ? $stats['synced']++ : $stats['skipped']++;
                } catch (SyncIncompleteException $e) {
                    // Large document: progress is stored, the next run continues
                    $stats['partial']++;
                    $this->log("Document {$id}: {$e->getMessage()}");
                    break 2;
                } catch (DocumentLockedException $e) {
                    $stats['skipped']++;
                } catch (\Throwable $e) {
                    // Error is stored on the document by ChromaDb::syncDocument()
                    $stats['failed']++;
                    $this->log("Failed to sync document {$id}: {$e->getMessage()}", LogLevel::WARNING);
                }
            }
        }

        if ($stats['synced'] || $stats['failed']) {
            $this->log("Documents synced: {$stats['synced']}, failed: {$stats['failed']}, skipped: {$stats['skipped']}, partial: {$stats['partial']}");
        }

        return $stats;
    }

    /**
     * Process a single queue item
     */
    private function processItem(array $item): void
    {
        $this->indexQueue->markProcessing((int) $item['id']);

        try {
            // Get content from source
            $content = $this->getContentFromSource($item);

            if ($content === null) {
                $this->indexQueue->markCompleted((int) $item['id']);
                return;
            }

            // Calculate content hash
            $contentHash = hash('xxh3', $content);

            // Check if content changed
            if ($contentHash === $item['content_hash'] && $item['status'] !== IndexQueue::STATUS_PENDING) {
                $this->indexQueue->markCompleted((int) $item['id'], $contentHash);
                return;
            }

            // Create or update document in tl_genai_documents
            $collectionId = (int) ($item['collection_id'] ?? 0);
            $documentId = $this->upsertDocument($item, $content, $contentHash, $collectionId);

            // Get the document and send to ChromaDB
            $document = Database::getInstance()
                ->prepare('SELECT * FROM tl_genai_documents WHERE id=?')
                ->limit(1)
                ->execute($documentId);

            if ($document->numRows) {
                $this->chromaDb->upsertDocument($document, $contentHash);
            }

            $this->indexQueue->markCompleted((int) $item['id'], $contentHash);
            
        } catch (\Exception $e) {
            $this->indexQueue->markFailed((int) $item['id'], $e->getMessage());
            $this->log("Failed to process {$item['source_type']} {$item['source_id']}: {$e->getMessage()}", LogLevel::WARNING);
        }
    }

    /**
     * Get content from source based on type
     */
    private function getContentFromSource(array $item): ?string
    {
        // Only pages are handled - they are processed by PageCrawler via SiteIndexer
        // This method returns null because page content is crawled externally
        return null;
    }

    /**
     * Create or update document in tl_genai_documents
     */
    private function upsertDocument(array $item, string $content, string $contentHash, int $collectionId): int
    {
        $db = Database::getInstance();

        $existing = $db->prepare('SELECT id FROM tl_genai_documents WHERE url=?')
            ->limit(1)
            ->execute($item['source_url']);

        $chunks = $this->chunkContent($content);

        $data = [
            'pid' => $collectionId,
            'tstamp' => time(),
            'title' => $item['title'],
            'url' => $item['source_url'],
            'pageId' => $item['source_id'],
            'rootPageId' => $item['root_page'],
            'content_hash' => $contentHash,
            'synced_at' => 0,
        ];

        if ($existing->numRows) {
            $db->prepare('UPDATE tl_genai_documents %s WHERE id=?')
                ->set($data)
                ->execute($existing->id);
            DocumentChunks::save((int) $existing->id, $chunks);

            return (int) $existing->id;
        }

        $id = (int) $db->prepare('INSERT INTO tl_genai_documents %s')
            ->set($data)
            ->execute()
            ->insertId;
        DocumentChunks::save($id, $chunks);

        return $id;
    }

    /**
     * Chunk content into smaller pieces
     */
    private function chunkContent(string $content, int $chunkSize = 1000, int $overlap = 200): array
    {
        $chunks = [];
        $content = trim($content);
        
        if (strlen($content) <= $chunkSize) {
            return [
                ['chunkNumber' => 1, 'formattedContent' => $content]
            ];
        }

        $position = 0;
        $chunkNumber = 1;

        while ($position < strlen($content)) {
            $chunk = substr($content, $position, $chunkSize);
            
            if (strlen($chunk) === $chunkSize && $position + $chunkSize < strlen($content)) {
                $lastBreak = max(
                    strrpos($chunk, ".\n"),
                    strrpos($chunk, ". "),
                    strrpos($chunk, "\n\n")
                );
                
                if ($lastBreak !== false && $lastBreak > $chunkSize * 0.5) {
                    $chunk = substr($chunk, 0, $lastBreak + 1);
                }
            }

            $chunks[] = [
                'chunkNumber' => $chunkNumber++,
                'formattedContent' => trim($chunk)
            ];

            $position += strlen($chunk) - $overlap;
            
            if (strlen($chunk) <= $overlap) {
                break;
            }
        }

        return $chunks;
    }

    /**
     * Check if time limit exceeded
     */
    private function isTimeExceeded(): bool
    {
        return microtime(true) >= $this->getDeadline();
    }

    /**
     * Own budget (TIME_LIMIT_SECONDS), but never closer than 45s to PHP's max_execution_time
     * of the current request: the web cron shares the request with other cronjobs.
     */
    private function getDeadline(): float
    {
        $deadline = $this->startTime + self::TIME_LIMIT_SECONDS;
        $maxExecution = (int) \ini_get('max_execution_time');

        if ($maxExecution > 0) {
            $requestStart = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? $this->startTime);
            $deadline = \min($deadline, $requestStart + $maxExecution - 45);
        }

        return $deadline;
    }

    /**
     * Log message
     */
    private function log(string $message, string $level = LogLevel::INFO): void
    {
        try {
            System::getContainer()
                ->get('monolog.logger.contao')
                ->log($level, "[GenAI Sync] {$message}", ['contao' => new ContaoContext(__CLASS__, ContaoContext::CRON)]);
        } catch (\Exception $e) {
            // Logger not available
        }
    }
}
