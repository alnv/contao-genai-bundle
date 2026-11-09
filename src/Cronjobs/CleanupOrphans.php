<?php

namespace Alnv\GenaiBundle\Cronjobs;

use Alnv\GenaiBundle\Vectors\ChromaDb;
use Contao\Config;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Database;
use Contao\System;
use Psr\Log\LogLevel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Daily cronjob for orphan cleanup
 * 
 * Removes chunks from ChromaDB that no longer have corresponding documents.
 * Runs daily to keep the index clean.
 */
class CleanupOrphans
{
    private const LOCK_NAME = 'genai-cleanup-orphans';

    public function __invoke(): void
    {
        // Check configuration
        if (!Config::get('genAIChromaDBOllamaUrl') || !Config::get('genAIChromaDBOllamaModel')) {
            return;
        }

        // Acquire lock
        $lockFactory = new LockFactory(new FlockStore());
        $lock = $lockFactory->createLock(self::LOCK_NAME, 3600);

        if (!$lock->acquire()) {
            $this->log('Another cleanup process is running, skipping');
            return;
        }

        try {
            $this->cleanupOrphanedDocuments();
            $this->cleanupExpiredPages();
            $this->cleanupQueueEntries();
        } catch (\Exception $e) {
            $this->log('Cleanup error: ' . $e->getMessage(), LogLevel::ERROR);
        } finally {
            $lock->release();
        }
    }

    /**
     * Remove documents that are no longer synced or have been deleted
     */
    private function cleanupOrphanedDocuments(): void
    {
        $db = Database::getInstance();
        $chromaDb = $this->getChromaDb();

        // Find documents in tl_genai_documents that no longer have a valid source
        $orphans = $db->execute("
            SELECT d.id, d.url, d.pageId 
            FROM tl_genai_documents d
            LEFT JOIN tl_page p ON d.pageId = p.id
            WHERE d.pageId > 0 AND p.id IS NULL
        ");

        $deleted = 0;
        while ($orphans->next()) {
            try {
                // Delete from ChromaDB
                $chromaDb->deleteByDocumentId((int) $orphans->id);

                // Delete from database
                $db->prepare('DELETE FROM tl_genai_documents WHERE id=?')
                    ->execute($orphans->id);

                $deleted++;
            } catch (\Exception $e) {
                $this->log("Failed to delete orphan document {$orphans->id}: {$e->getMessage()}", LogLevel::WARNING);
            }
        }

        if ($deleted > 0) {
            $this->log("Deleted {$deleted} orphaned documents");
        }
    }

    /**
     * Remove pages that are now unpublished or have time restrictions
     */
    private function cleanupExpiredPages(): void
    {
        $db = Database::getInstance();
        $chromaDb = $this->getChromaDb();
        $now = time();

        // Find documents for unpublished or expired pages
        $expired = $db->prepare("
            SELECT d.id, d.url, d.pageId 
            FROM tl_genai_documents d
            INNER JOIN tl_page p ON d.pageId = p.id
            WHERE p.published = '' 
               OR p.published = '0'
               OR (p.start > 0 AND p.start > ?)
               OR (p.stop > 0 AND p.stop < ?)
               OR p.noSearch = '1'
        ")->execute($now, $now);

        $deleted = 0;
        while ($expired->next()) {
            try {
                $chromaDb->deleteByDocumentId((int) $expired->id);
                $db->prepare('DELETE FROM tl_genai_documents WHERE id=?')
                    ->execute($expired->id);
                $deleted++;
            } catch (\Exception $e) {
                $this->log("Failed to delete expired document {$expired->id}: {$e->getMessage()}", LogLevel::WARNING);
            }
        }

        if ($deleted > 0) {
            $this->log("Deleted {$deleted} documents for unpublished/expired pages");
        }
    }

    /**
     * Cleanup old queue entries
     */
    private function cleanupQueueEntries(): void
    {
        $db = Database::getInstance();
        
        // Remove completed entries older than 7 days
        $weekAgo = time() - (7 * 24 * 60 * 60);
        $db->prepare("DELETE FROM tl_genai_index WHERE status = 'completed' AND processed_at < ?")
            ->execute($weekAgo);

        // Remove failed entries that exceeded max retries and are older than 30 days
        $monthAgo = time() - (30 * 24 * 60 * 60);
        $db->prepare("DELETE FROM tl_genai_index WHERE status = 'failed' AND retry_count >= max_retries AND tstamp < ?")
            ->execute($monthAgo);

        $this->log("Cleaned up old queue entries");
    }

    /**
     * Get ChromaDB instance
     */
    private function getChromaDb(): ChromaDb
    {
        $chromaDb = new ChromaDb();
        $chromaDb->setOllamaEmbedding(
            Config::get('genAIChromaDBOllamaUrl'),
            Config::get('genAIChromaDBOllamaModel')
        );
        return $chromaDb;
    }

    /**
     * Log message
     */
    private function log(string $message, string $level = LogLevel::INFO): void
    {
        try {
            System::getContainer()
                ->get('monolog.logger.contao')
                ->log($level, "[GenAI Cleanup] {$message}", ['contao' => new ContaoContext(__CLASS__, ContaoContext::CRON)]);
        } catch (\Exception $e) {
            // Logger not available
        }
    }
}
