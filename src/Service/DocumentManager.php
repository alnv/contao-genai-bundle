<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Service;

use Contao\Database;
use Contao\StringUtil;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DocumentManager
{
    public const SOURCE_PAGE = 'page';
    public const SOURCE_EXTERNAL = 'external';
    public const SOURCE_FILE = 'file';
    public const SOURCE_API = 'api';

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function createDocument(array $data): int
    {
        $this->validateData($data);

        $db = Database::getInstance();
        $now = time();

        // Prepare content (convert to chunk format if string)
        $content = $this->prepareContent($data['content']);
        $contentHash = md5(serialize($content));

        // Get collection ID
        $collectionId = $data['collectionId'] ?? $this->getDefaultCollectionId();
        if (!$collectionId) {
            throw new \InvalidArgumentException('No collection available. Create a collection first.');
        }

        // Check if document already exists
        $existing = $db->prepare('SELECT id, content_hash FROM tl_genai_documents WHERE url=?')
            ->limit(1)
            ->execute($data['url']);

        $documentData = [
            'pid' => $collectionId,
            'tstamp' => $now,
            'title' => $data['title'],
            'content' => serialize($content),
            'url' => $data['url'],
            'language' => $data['language'] ?? '',
            'source_type' => $data['sourceType'] ?? self::SOURCE_EXTERNAL,
            'source_id' => $data['sourceId'] ?? '',
            'metadata' => !empty($data['metadata']) ? serialize($data['metadata']) : '',
            'content_hash' => $contentHash,
            'updated_at' => $now,
        ];

        if ($existing->numRows) {
            // Update existing
            if ($existing->content_hash !== $contentHash) {
                // Content changed, reset sync status
                $documentData['synced_at'] = 0;
            }

            $db->prepare('UPDATE tl_genai_documents %s WHERE id=?')
                ->set($documentData)
                ->execute($existing->id);

            $this->logger->info("Updated external document: {$data['url']} (ID: {$existing->id})");
            return (int) $existing->id;
        }

        // Create new
        $documentData['created_at'] = $now;
        $documentData['synced_at'] = 0;

        $db->prepare('INSERT INTO tl_genai_documents %s')
            ->set($documentData)
            ->execute();

        $id = (int) $db->insertId;
        $this->logger->info("Created external document: {$data['url']} (ID: {$id})");

        // Dispatch hook
        $this->dispatchHook('onDocumentCreated', $id, $data);

        return $id;
    }

    /**
     * Update an existing document by ID.
     */
    public function updateDocument(int $id, array $data): bool
    {
        $db = Database::getInstance();
        $now = time();

        $existing = $db->prepare('SELECT * FROM tl_genai_documents WHERE id=?')
            ->limit(1)
            ->execute($id);

        if (!$existing->numRows) {
            return false;
        }

        $updateData = ['tstamp' => $now, 'updated_at' => $now];

        if (isset($data['title'])) {
            $updateData['title'] = $data['title'];
        }

        if (isset($data['content'])) {
            $content = $this->prepareContent($data['content']);
            $updateData['content'] = serialize($content);
            $updateData['content_hash'] = md5(serialize($content));
            $updateData['synced_at'] = 0; // Reset sync status
        }

        if (isset($data['url'])) {
            $updateData['url'] = $data['url'];
        }

        if (isset($data['language'])) {
            $updateData['language'] = $data['language'];
        }

        if (isset($data['metadata'])) {
            $updateData['metadata'] = serialize($data['metadata']);
        }

        $db->prepare('UPDATE tl_genai_documents %s WHERE id=?')
            ->set($updateData)
            ->execute($id);

        $this->logger->info("Updated document ID: {$id}");
        $this->dispatchHook('onDocumentUpdated', $id, $data);

        return true;
    }

    /**
     * Delete a document by ID.
     */
    public function deleteDocument(int $id): bool
    {
        $db = Database::getInstance();

        $existing = $db->prepare('SELECT url FROM tl_genai_documents WHERE id=?')
            ->limit(1)
            ->execute($id);

        if (!$existing->numRows) {
            return false;
        }

        $this->dispatchHook('onDocumentDeleting', $id, ['url' => $existing->url]);

        $db->prepare('DELETE FROM tl_genai_documents WHERE id=?')
            ->execute($id);

        $this->logger->info("Deleted document ID: {$id}");

        return true;
    }

    /**
     * Delete a document by URL.
     */
    public function deleteDocumentByUrl(string $url): bool
    {
        $db = Database::getInstance();

        $existing = $db->prepare('SELECT id FROM tl_genai_documents WHERE url=?')
            ->limit(1)
            ->execute($url);

        if (!$existing->numRows) {
            return false;
        }

        return $this->deleteDocument((int) $existing->id);
    }

    /**
     * Mark a document for re-sync (embedding will be regenerated).
     */
    public function markForSync(int $id): bool
    {
        $db = Database::getInstance();

        $result = $db->prepare('UPDATE tl_genai_documents SET synced_at=0, tstamp=? WHERE id=?')
            ->execute(time(), $id);

        return $result->affectedRows > 0;
    }

    /**
     * Mark all documents of a source type for re-sync.
     */
    public function markSourceTypeForSync(string $sourceType): int
    {
        $db = Database::getInstance();

        $result = $db->prepare('UPDATE tl_genai_documents SET synced_at=0, tstamp=? WHERE source_type=?')
            ->execute(time(), $sourceType);

        return (int) $result->affectedRows;
    }

    /**
     * Get document by ID.
     */
    public function getDocument(int $id): ?array
    {
        $db = Database::getInstance();

        $result = $db->prepare('SELECT * FROM tl_genai_documents WHERE id=?')
            ->limit(1)
            ->execute($id);

        if (!$result->numRows) {
            return null;
        }

        return $this->hydrateDocument($result->row());
    }

    /**
     * Get document by URL.
     */
    public function getDocumentByUrl(string $url): ?array
    {
        $db = Database::getInstance();

        $result = $db->prepare('SELECT * FROM tl_genai_documents WHERE url=?')
            ->limit(1)
            ->execute($url);

        if (!$result->numRows) {
            return null;
        }

        return $this->hydrateDocument($result->row());
    }

    /**
     * Find documents by source type.
     */
    public function findBySourceType(string $sourceType, int $limit = 100, int $offset = 0): array
    {
        $db = Database::getInstance();

        $result = $db->prepare('SELECT * FROM tl_genai_documents WHERE source_type=? ORDER BY updated_at DESC')
            ->limit($limit, $offset)
            ->execute($sourceType);

        $documents = [];
        while ($result->next()) {
            $documents[] = $this->hydrateDocument($result->row());
        }

        return $documents;
    }

    /**
     * Find documents by source ID (external reference).
     */
    public function findBySourceId(string $sourceId): array
    {
        $db = Database::getInstance();

        $result = $db->prepare('SELECT * FROM tl_genai_documents WHERE source_id=? ORDER BY updated_at DESC')
            ->execute($sourceId);

        $documents = [];
        while ($result->next()) {
            $documents[] = $this->hydrateDocument($result->row());
        }

        return $documents;
    }

    /**
     * Bulk create documents.
     * 
     * @param array $documents Array of document data arrays
     * @return array Array of created document IDs
     */
    public function bulkCreate(array $documents): array
    {
        $ids = [];
        foreach ($documents as $data) {
            try {
                $ids[] = $this->createDocument($data);
            } catch (\Exception $e) {
                $this->logger->warning("Failed to create document: {$e->getMessage()}", $data);
            }
        }
        return $ids;
    }

    /**
     * Prepare content for storage (convert to chunk format).
     */
    private function prepareContent($content): array
    {
        if (is_array($content)) {
            // Already in chunk format or array of strings
            if (isset($content[0]['chunkNumber'])) {
                return $content;
            }

            // Array of strings - convert to chunks
            $chunks = [];
            foreach ($content as $i => $text) {
                $chunks[] = [
                    'chunkNumber' => $i + 1,
                    'formattedContent' => $text,
                ];
            }
            return $chunks;
        }

        // Single string - create single chunk
        return [
            [
                'chunkNumber' => 1,
                'formattedContent' => (string) $content,
            ]
        ];
    }

    /**
     * Validate required document data.
     */
    private function validateData(array $data): void
    {
        if (empty($data['title'])) {
            throw new \InvalidArgumentException('Document title is required.');
        }
        if (empty($data['content'])) {
            throw new \InvalidArgumentException('Document content is required.');
        }
        if (empty($data['url'])) {
            throw new \InvalidArgumentException('Document URL is required.');
        }
    }

    /**
     * Get default collection ID.
     */
    private function getDefaultCollectionId(): ?int
    {
        $db = Database::getInstance();
        $result = $db->execute('SELECT id FROM tl_genai_documents_collections ORDER BY id ASC LIMIT 1');

        return $result->numRows ? (int) $result->id : null;
    }

    /**
     * Hydrate document row with unserialized data.
     */
    private function hydrateDocument(array $row): array
    {
        $row['content'] = StringUtil::deserialize($row['content'], true);
        $row['metadata'] = !empty($row['metadata']) ? StringUtil::deserialize($row['metadata'], true) : [];
        return $row;
    }

    /**
     * Dispatch a hook for document events.
     */
    private function dispatchHook(string $hook, int $documentId, array $data): void
    {
        $hookName = 'genai' . ucfirst($hook);

        if (!empty($GLOBALS['TL_HOOKS'][$hookName])) {
            foreach ($GLOBALS['TL_HOOKS'][$hookName] as $callback) {
                if (is_array($callback)) {
                    $class = new $callback[0]();
                    $class->{$callback[1]}($documentId, $data);
                } elseif (is_callable($callback)) {
                    $callback($documentId, $data);
                }
            }
        }
    }
}
