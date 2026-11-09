<?php

namespace Alnv\GenaiBundle\Cronjobs;

use Alnv\GenaiBundle\Service\IndexQueue;
use Alnv\GenaiBundle\Vectors\ChromaDb;
use Contao\Config;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Database;
use Contao\System;
use Psr\Log\LogLevel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Cronjob for processing the index queue
 * 
 * Processes pending items from tl_genai_index in batches.
 * Uses file-based locking to prevent concurrent runs.
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

    public function __invoke(): void
    {
        // Check global configuration
        $ollamaUrl = Config::get('genAIChromaDBOllamaUrl');
        $ollamaModel = Config::get('genAIChromaDBOllamaModel');

        if (!$ollamaUrl || !$ollamaModel) {
            $this->log('Ollama configuration missing (genAIChromaDBOllamaUrl, genAIChromaDBOllamaModel)', LogLevel::WARNING);
            return;
        }

        // Acquire lock
        $lockFactory = new LockFactory(new FlockStore());
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

                // Delete from tl_genai_documents
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
        $db = Database::getInstance();

        switch ($item['source_type']) {
            case IndexQueue::TYPE_PAGE:
                return null; // Handled by PageCrawler

            case IndexQueue::TYPE_ARTICLE:
                $article = $db->prepare('SELECT * FROM tl_article WHERE id=?')
                    ->limit(1)
                    ->execute($item['source_id']);
                    
                if (!$article->numRows) {
                    return null;
                }

                $contents = $db->prepare('SELECT * FROM tl_content WHERE pid=? AND ptable=? ORDER BY sorting')
                    ->execute($item['source_id'], 'tl_article');

                $text = $article->title . "\n\n";
                while ($contents->next()) {
                    $text .= $this->extractTextFromContent($contents) . "\n";
                }

                return $text;

            case IndexQueue::TYPE_NEWS:
                $news = $db->prepare('SELECT * FROM tl_news WHERE id=?')
                    ->limit(1)
                    ->execute($item['source_id']);
                    
                if (!$news->numRows) {
                    return null;
                }

                return $news->headline . "\n\n" . strip_tags($news->teaser ?? '') . "\n\n" . strip_tags($news->text ?? '');

            case IndexQueue::TYPE_EVENT:
                $event = $db->prepare('SELECT * FROM tl_calendar_events WHERE id=?')
                    ->limit(1)
                    ->execute($item['source_id']);
                    
                if (!$event->numRows) {
                    return null;
                }

                return $event->title . "\n\n" . strip_tags($event->teaser ?? '') . "\n\n" . strip_tags($event->description ?? '');

            default:
                return null;
        }
    }

    /**
     * Extract text from content element
     */
    private function extractTextFromContent($content): string
    {
        $text = '';

        if (!empty($content->headline)) {
            $headline = \Contao\StringUtil::deserialize($content->headline, true);
            $text .= ($headline['value'] ?? '') . "\n";
        }

        if (!empty($content->text)) {
            $text .= strip_tags($content->text) . "\n";
        }

        return trim($text);
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
            'content' => serialize($chunks),
            'content_hash' => $contentHash,
            'synced_at' => 0,
        ];

        if ($existing->numRows) {
            $db->prepare('UPDATE tl_genai_documents %s WHERE id=?')
                ->set($data)
                ->execute($existing->id);
            return (int) $existing->id;
        }

        $db->prepare('INSERT INTO tl_genai_documents %s')
            ->set($data)
            ->execute();
        return (int) $db->insertId;
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
        return (microtime(true) - $this->startTime) >= self::TIME_LIMIT_SECONDS;
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
