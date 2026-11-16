<?php

namespace Alnv\GenaiBundle\Vectors;

use Alnv\GenaiBundle\Embeddings\OllamaEmbeddings;
use Alnv\GenaiBundle\Helpers\DocumentChunks;
use Contao\Config;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Database;
use Contao\StringUtil;
use Contao\System;
use HelgeSverre\Chromadb\Chromadb as ChromaDbClient;
use HelgeSverre\Chromadb\Embeddings\EmbeddingFunction;
use Psr\Log\LogLevel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * ChromaDB Vector Store Integration
 * 
 * Supports ChromaDB v2 API with tenant/database concepts.
 * Uses deterministic IDs and extended metadata for better RAG.
 */
class ChromaDb
{
    protected string $collectionName;
    protected string $collectionId;
    protected string $url;
    protected ?int $port;
    protected string $tenant;
    protected string $database;
    protected string $embeddingModel = '';
    protected ?EmbeddingFunction $embeddingFunction = null;
    protected ChromaDbClient $chromaDB;

    // Retry configuration
    protected int $maxRetries = 3;
    protected int $retryDelayMs = 500;

    /** Max IDs per delete/$in request */
    protected const BATCH_SIZE = 100;

    /**
     * Instance with the global embedding configuration, or null if GenAI is not configured.
     * Throws if ChromaDB itself is unreachable.
     */
    public static function fromConfig(): ?self
    {
        $ollamaUrl = Config::get('genAIChromaDBOllamaUrl');
        $ollamaModel = Config::get('genAIChromaDBOllamaModel');

        if (!$ollamaUrl || !$ollamaModel) {
            return null;
        }

        $chroma = new self();
        $chroma->setOllamaEmbedding($ollamaUrl, $ollamaModel);

        return $chroma;
    }

    public function __construct(array $arrConfig = [])
    {
        $this->url = $arrConfig['url'] ?? Config::get('genAIChromaDBUrl') ?: '';
        $this->port = $arrConfig['port'] ?? Config::get('genAIChromaDBPort') ?: null;
        $this->tenant = $arrConfig['tenant'] ?? Config::get('genAIChromaDBTenant') ?: 'default_tenant';
        $this->database = $arrConfig['database'] ?? Config::get('genAIChromaDBDatabase') ?: 'default_database';
        
        $baseCollectionName = $arrConfig['collection'] ?? Config::get('genAIChromaDBCollectionName') ?: 'genai';
        $this->collectionName = $baseCollectionName;

        $this->chromaDB = new ChromaDbClient(
            token: $arrConfig['token'] ?? Config::get('genAIChromaDBToken') ?: null,
            host: $this->url,
            port: $this->port,
            tenant: $this->tenant,
            database: $this->database
        );

        $this->initCollection();
    }

    /**
     * Set Ollama embedding function with optional query prefix support
     */
    public function setOllamaEmbedding(string $strBaseUrl, string $strModel): void
    {
        $this->embeddingModel = $strModel;
        
        // Update collection name to include model (prevents dimension mismatches)
        $this->updateCollectionForModel($strModel);

        $this->embeddingFunction = new OllamaEmbeddings(
            model: $strModel,
            baseUrl: $strBaseUrl,
            timeout: (int) (Config::get('genAIOllamaEmbeddingTimeout') ?: 120)
        );
    }

    /**
     * Update collection name to be model-specific
     * This prevents dimension mismatches when switching embedding models
     */
    protected function updateCollectionForModel(string $model): void
    {
        // Sanitize model name for collection name
        $modelSuffix = preg_replace('/[^a-z0-9-]/', '-', strtolower($model));
        $modelSuffix = preg_replace('/-+/', '-', $modelSuffix);
        $modelSuffix = trim($modelSuffix, '-');

        $baseCollection = Config::get('genAIChromaDBCollectionName') ?: 'genai';
        $this->collectionName = $baseCollection . '-' . $modelSuffix;

        $this->initCollection();
    }

    /**
     * Initialize or get collection with proper settings
     */
    protected function initCollection(): void
    {
        try {
            // Create collection with cosine distance metric
            $response = $this->chromaDB->collections()->create(
                name: $this->collectionName,
                getOrCreate: true,
                metadata: [
                    'hnsw:space' => 'cosine',
                    'created_by' => 'contao-genai-bundle',
                    'embedding_model' => $this->embeddingModel ?: 'unknown'
                ]
            );
            
            if ($response->failed() || !$response->json('id')) {
                throw new \RuntimeException('ChromaDB collection "' . $this->collectionName . '" not available: HTTP ' . $response->status() . ' ' . \mb_substr((string) $response->body(), 0, 200));
            }

            $this->collectionId = $response->json('id');
        } catch (\Exception $e) {
            $this->logError('Failed to initialize collection', $e);
            throw $e;
        }
    }

    /**
     * Health check - verify ChromaDB connection
     */
    public function healthCheck(): array
    {
        try {
            $startTime = microtime(true);
            $count = $this->count();
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'healthy',
                'collection' => $this->collectionName,
                'chunks' => $count,
                'latency_ms' => $latency,
                'tenant' => $this->tenant,
                'database' => $this->database
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'collection' => $this->collectionName
            ];
        }
    }

    /**
     * Query with optional filters for root page and collections
     */
    public function get(string $query, int $topK = 10, ?array $filters = null): array
    {
        if (!$this->embeddingFunction) {
            throw new \RuntimeException('Embedding function not set. Call setOllamaEmbedding() first.');
        }

        // Build where clause for filtering
        $where = null;
        if ($filters) {
            $conditions = [];
            
            if (!empty($filters['root_page'])) {
                $conditions[] = ['root_page' => (string) $filters['root_page']];
            }

            // Collection filter - supports multiple collections with $in operator
            if (!empty($filters['collection_ids'])) {
                $collectionIds = array_map('strval', (array) $filters['collection_ids']);
                if (count($collectionIds) === 1) {
                    $conditions[] = ['collection_id' => $collectionIds[0]];
                } else {
                    $conditions[] = ['collection_id' => ['$in' => $collectionIds]];
                }
            }

            if (!empty($conditions)) {
                $where = count($conditions) === 1 
                    ? $conditions[0]
                    : ['$and' => $conditions];
            }
        }

        // Generate query embedding (with query prefix)
        if (!$this->embeddingFunction instanceof OllamaEmbeddings) {
            throw new \RuntimeException('Embedding function must be OllamaEmbeddings instance.');
        }
        
        $queryEmbedding = $this->embeddingFunction->embedQuery($query);

        return $this->retry(function () use ($queryEmbedding, $topK, $where) {
            $results = $this->chromaDB->items()->query(
                collectionId: $this->collectionId,
                queryEmbeddings: [$queryEmbedding],
                nResults: $topK,
                include: ['documents', 'metadatas', 'distances'],
                where: $where
            );

            return $results->array();
        });
    }

    /** Chunks per embedding + upsert step (progress is persisted after each step) */
    protected const UPSERT_STEP = 50;

    /**
     * Upsert a single document with extended metadata.
     *
     * Incremental: only chunks that are missing in the collection or whose text changed
     * (metadata chunk_hash) are embedded. Upserts happen in steps; with a $deadline the
     * method stops after the step that crossed it and throws SyncIncompleteException -
     * the next call continues with the remaining chunks.
     *
     * @throws SyncIncompleteException
     */
    public function upsertDocument(object $objDocument, ?string $contentHash = null, ?float $deadline = null): void
    {
        if (!$this->embeddingFunction) {
            throw new \RuntimeException('Embedding function not set. Call setOllamaEmbedding() first.');
        }

        $documentId = (int) $objDocument->id;

        // Hash at read time: if the content changes while we embed, the document stays pending
        $expectedHash = (string) ($objDocument->content_hash ?? '');

        $data = $this->prepareDocumentData($objDocument, $contentHash);

        // Which chunks are already embedded with identical text?
        $existing = $this->getVectorChunkHashesByDocumentId($documentId);
        $todo = [];
        foreach ($data['ids'] as $i => $id) {
            if (($existing[$id] ?? null) !== $data['metadatas'][$i]['chunk_hash']) {
                $todo[] = $i;
            }
        }

        $steps = \array_chunk($todo, self::UPSERT_STEP);
        $alreadyEmbedded = \count($data['ids']) - \count($todo);

        foreach ($steps as $stepIndex => $step) {
            $ids = $documents = $metadatas = [];
            foreach ($step as $i) {
                $ids[] = $data['ids'][$i];
                $documents[] = $data['documents'][$i];
                $metadatas[] = $data['metadatas'][$i];
            }

            $embeddings = $this->generateEmbeddings($documents);

            $this->retry(function () use ($ids, $documents, $metadatas, $embeddings) {
                // Use upsert with pre-computed embeddings
                $response = $this->chromaDB->items()->upsert(
                    collectionId: $this->collectionId,
                    ids: $ids,
                    embeddings: $embeddings,
                    metadatas: $metadatas,
                    documents: $documents
                );

                // Saloon does not throw on HTTP errors -> never mark a failed upsert as synced
                if ($response->failed()) {
                    throw new \RuntimeException('ChromaDB upsert failed: HTTP ' . $response->status() . ' ' . \mb_substr((string) $response->body(), 0, 200));
                }
            });

            $isLastStep = $stepIndex === \count($steps) - 1;

            if (!$isLastStep && $deadline !== null && \microtime(true) >= $deadline) {
                $done = $alreadyEmbedded + ($stepIndex + 1) * self::UPSERT_STEP;
                throw new SyncIncompleteException($done, \count($data['ids']));
            }
        }

        // Remove vectors of this document that are not part of the current chunk set
        // (fewer chunks than before, old ID formats) -> no stale or duplicate embeddings
        $stale = \array_values(\array_diff($this->getVectorIdsByDocumentId($documentId), $data['ids']));
        $this->deleteVectorIds($stale);

        $this->markAsSynced($documentId, $contentHash, $expectedHash);
    }

    /**
     * Sync exactly one document (manual backend action, cronjob, CLI).
     *
     * - per-document lock: the same document is never synced twice in parallel
     * - errors are stored in sync_error/sync_failed_at and re-thrown
     *
     * @return array{chunks: int, synced: bool} synced=false if the content changed during the sync
     *
     * @throws DocumentLockedException|\Throwable
     */
    public function syncDocument(int $documentId, bool $enable = false, ?float $deadline = null): array
    {
        $lock = $this->acquireDocumentLock($documentId);

        try {
            $document = Database::getInstance()
                ->prepare('SELECT * FROM tl_genai_documents WHERE id=?')
                ->limit(1)
                ->execute($documentId);

            if (!$document->numRows) {
                throw new \InvalidArgumentException(\sprintf('Document ID %d does not exist.', $documentId));
            }

            if ($enable) {
                Database::getInstance()
                    ->prepare("UPDATE tl_genai_documents SET sync_disabled='' WHERE id=?")
                    ->execute($documentId);
            }

            // Attempt marker: if PHP dies (time limit) the document moves to the end of the queue
            // instead of blocking it; cleared by markAsSynced() / reset on incomplete
            Database::getInstance()
                ->prepare('UPDATE tl_genai_documents SET sync_failed_at=?, sync_error=? WHERE id=?')
                ->execute(time(), 'Synchronisierung gestartet, aber nicht abgeschlossen (Zeitlimit?)', $documentId);

            try {
                $this->upsertDocument($document, null, $deadline);
            } catch (SyncIncompleteException $e) {
                // Regular progress: stay at the front of the queue, continue next run
                Database::getInstance()
                    ->prepare("UPDATE tl_genai_documents SET sync_failed_at=0, sync_error='' WHERE id=?")
                    ->execute($documentId);
                throw $e;
            } catch (\Throwable $e) {
                $this->markAsFailed($documentId, $e);
                throw $e;
            }

            $synced = (int) Database::getInstance()
                ->prepare('SELECT synced_at FROM tl_genai_documents WHERE id=?')
                ->execute($documentId)
                ->synced_at > 0;

            return ['chunks' => \count($this->getVectorIdsByDocumentId($documentId)), 'synced' => $synced];
        } finally {
            $lock->release();
        }
    }

    /**
     * Remove all vectors of one document from the collection; the DB record is kept.
     * synced_at is reset to 0 and sync_disabled set, so the cronjob does not re-add it.
     *
     * @return int number of removed vectors
     */
    public function removeDocument(int $documentId): int
    {
        $lock = $this->acquireDocumentLock($documentId);

        try {
            $removed = $this->deleteByDocumentId($documentId);

            Database::getInstance()
                ->prepare("UPDATE tl_genai_documents SET synced_at=0, sync_disabled='1', sync_error='', sync_failed_at=0, tstamp=? WHERE id=?")
                ->execute(time(), $documentId);

            return $removed;
        } finally {
            $lock->release();
        }
    }

    protected function acquireDocumentLock(int $documentId): \Symfony\Component\Lock\LockInterface
    {
        $lock = \Alnv\GenaiBundle\Helpers\Locks::factory()->createLock('genai-document-' . $documentId, 900);

        if (!$lock->acquire()) {
            throw new DocumentLockedException(\sprintf('Document ID %d is currently being synchronized.', $documentId));
        }

        return $lock;
    }

    protected function markAsFailed(int $documentId, \Throwable $e): void
    {
        Database::getInstance()
            ->prepare('UPDATE tl_genai_documents SET sync_error=?, sync_failed_at=? WHERE id=?')
            ->execute(\mb_substr($e->getMessage(), 0, 255), time(), $documentId);
    }

    /**
     * Generate embeddings for documents using the embedding function
     */
    protected function generateEmbeddings(array $documents): array
    {
        if (!$this->embeddingFunction instanceof OllamaEmbeddings) {
            throw new \RuntimeException('Embedding function must be OllamaEmbeddings instance.');
        }

        // Use batch embedding for efficiency
        return $this->embeddingFunction->embedDocuments($documents);
    }

    /**
     * Prepare document data with deterministic IDs and extended metadata
     */
    protected function prepareDocumentData(object $objDocument, ?string $contentHash = null): array
    {
        $data = [
            'ids' => [],
            'documents' => [],
            'metadatas' => []
        ];

        $arrContents = DocumentChunks::load((int) $objDocument->id);
        $now = time();

        foreach ($arrContents as $index => $arrContent) {
            $chunkNumber = $arrContent['chunkNumber'] ?? ($index + 1);
            $content = $arrContent['formattedContent'] ?? '';

            if (empty($content)) {
                continue;
            }

            // Deterministic, unique ID: {type}-{documentId}-chunk-{n}
            // (pageId is not unique: the same page/PDF can exist under several URLs)
            $sourceType = $this->determineSourceType($objDocument);
            $sourceId = $objDocument->pageId ?: $objDocument->id;
            $data['ids'][] = "{$sourceType}-{$objDocument->id}-chunk-{$chunkNumber}";
            
            $data['documents'][] = $content;
            
            // Extended metadata
            $data['metadatas'][] = [
                // Core identifiers
                'source_type' => $sourceType,
                'source_id' => (string) $sourceId,
                'document_id' => (string) $objDocument->id,
                'chunk_number' => (int) $chunkNumber,
                'collection_id' => (string) ($objDocument->pid ?? ''),
                
                // Display info
                'url' => $objDocument->url ?? '',
                'title' => $objDocument->title ?? '',
                
                // Filtering (root_page determines site/language)
                'root_page' => (string) ($objDocument->rootPageId ?? ''),
                
                // Tracking
                'content_hash' => $contentHash ?: hash('xxh3', $content),
                // Per chunk: unchanged chunks are not embedded again
                'chunk_hash' => hash('xxh3', $content . "\0" . ($objDocument->title ?? '') . "\0" . ($objDocument->url ?? '') . "\0" . ($objDocument->pid ?? '') . "\0" . ($objDocument->rootPageId ?? '')),
                'indexed_at' => $now,
                'embedding_model' => $this->embeddingModel
            ];
        }

        return $data;
    }

    /**
     * Determine source type from document
     */
    protected function determineSourceType(object $objDocument): string
    {
        $pageId = $objDocument->pageId ?? '';
        
        // UUID format indicates file
        if (preg_match('/^[a-f0-9-]{36}$/i', $pageId)) {
            return 'file';
        }
        
        // Check URL for hints
        $url = $objDocument->url ?? '';
        if (str_contains($url, '.pdf')) {
            return 'file';
        }
        
        return 'page';
    }

    /**
     * Mark document as synced in database
     */
    protected function markAsSynced(int $documentId, ?string $contentHash = null, ?string $expectedHash = null): bool
    {
        $set = [
            'tstamp' => time(),
            'synced_at' => time(),
            'sync_error' => '',
            'sync_failed_at' => 0
        ];

        if ($contentHash) {
            $set['content_hash'] = $contentHash;
        }

        $query = 'UPDATE tl_genai_documents %s WHERE id=?';
        $params = [$documentId];

        // Only mark as synced if nobody changed the content meanwhile
        if ($expectedHash !== null) {
            $query .= ' AND content_hash=?';
            $params[] = $expectedHash;
        }

        $result = Database::getInstance()
            ->prepare($query)
            ->set($set)
            ->limit(1)
            ->execute(...$params);

        return $result->affectedRows > 0;
    }

    /**
     * Add documents from queue (used by cronjob)
     */
    public function add(): void
    {
        $objDocuments = Database::getInstance()
            ->prepare('SELECT * FROM tl_genai_documents WHERE synced_at=0 AND tstamp>0 ORDER BY tstamp ASC')
            ->limit(1)
            ->execute();

        if (!$objDocuments->numRows) {
            return;
        }

        try {
            $this->upsertDocument($objDocuments);
        } catch (\Exception $error) {
            $this->logError('Failed to add document ID: ' . $objDocuments->id, $error);
        }
    }

    /**
     * Sync a single document (called from CLI command)
     */
    public function addSingleDocument(object $objDocument): void
    {
        $this->syncDocument((int) $objDocument->id);
    }

    /**
     * Delete chunks by document ID
     */
    /**
     * Delete all vectors of a document. Matches by the metadata document_id (unique), so it
     * also works after the DB record is gone and never touches other documents. Legacy IDs
     * without document_id metadata ("ID-{id}-{n}") are removed as well while chunks exist.
     *
     * @return int number of removed vectors
     */
    public function deleteByDocumentId(int $documentId): int
    {
        $ids = $this->getVectorIdsByDocumentId($documentId);

        $legacy = [];
        foreach (DocumentChunks::loadChunkNumbers($documentId) as $chunkNumber) {
            $legacy[] = 'ID-' . $documentId . '-' . $chunkNumber;
        }

        // Legacy IDs are deleted blindly (Chroma ignores unknown IDs) and not counted
        $this->deleteVectorIds(\array_values(\array_diff($legacy, $ids)));

        return $this->deleteVectorIds($ids);
    }

    /**
     * Delete the vectors of several documents (by metadata document_id).
     *
     * @return int number of removed vectors
     */
    public function deleteByDocumentIds(array $documentIds): int
    {
        $removed = 0;

        foreach (\array_chunk(\array_values(\array_unique(\array_map('intval', $documentIds))), self::BATCH_SIZE) as $batch) {
            $where = ['document_id' => ['$in' => \array_map('strval', $batch)]];
            $removed += $this->deleteVectorIds($this->getVectorIds($where));
        }

        return $removed;
    }

    /**
     * Delete all vectors of a document collection (tl_genai_documents_collections).
     */
    public function deleteByCollectionId(int $collectionId): int
    {
        return $this->deleteVectorIds($this->getVectorIds(['collection_id' => (string) $collectionId]));
    }

    /**
     * @return array<string, string|null> vector id => chunk_hash (null for vectors without it)
     */
    protected function getVectorChunkHashesByDocumentId(int $documentId): array
    {
        $response = $this->retry(fn () => $this->chromaDB->items()->get(
            collectionId: $this->collectionId,
            ids: [],
            include: ['metadatas'],
            where: ['document_id' => (string) $documentId]
        ));

        if ($response->failed()) {
            throw new \RuntimeException('ChromaDB get failed: HTTP ' . $response->status() . ' ' . \mb_substr((string) $response->body(), 0, 200));
        }

        $hashes = [];
        foreach ($response->json('ids') ?? [] as $i => $id) {
            $hashes[(string) $id] = $response->json('metadatas')[$i]['chunk_hash'] ?? null;
        }

        return $hashes;
    }

    /**
     * Vector IDs stored for a document (by metadata document_id).
     *
     * @return array<string>
     */
    public function getVectorIdsByDocumentId(int $documentId): array
    {
        return $this->getVectorIds(['document_id' => (string) $documentId]);
    }

    /**
     * Find vectors whose document no longer exists in tl_genai_documents and delete them.
     *
     * @return array{vectors: int, documents: array<string>} what was (or would be) removed
     */
    public function deleteOrphanedVectors(bool $dryRun = false): array
    {
        $valid = \array_flip(\array_map(
            'strval',
            Database::getInstance()->execute('SELECT id FROM tl_genai_documents')->fetchEach('id')
        ));

        $orphanIds = [];
        $orphanDocuments = [];
        $offset = 0;
        $limit = 500;

        do {
            $result = $this->retry(fn () => $this->chromaDB->items()->get(
                collectionId: $this->collectionId,
                ids: [],
                include: ['metadatas'],
                limit: $limit,
                offset: $offset
            )->json());

            $ids = $result['ids'] ?? [];

            foreach ($ids as $i => $id) {
                $documentId = (string) ($result['metadatas'][$i]['document_id'] ?? '');

                // Vectors without document_id cannot be assigned -> left alone
                if ($documentId !== '' && !isset($valid[$documentId])) {
                    $orphanIds[] = $id;
                    $orphanDocuments[$documentId] = true;
                }
            }

            $offset += $limit;
        } while (\count($ids) === $limit);

        if (!$dryRun) {
            $this->deleteVectorIds($orphanIds);
        }

        return ['vectors' => \count($orphanIds), 'documents' => \array_map('strval', \array_keys($orphanDocuments))];
    }

    /**
     * @return array<string>
     */
    protected function getVectorIds(array $where): array
    {
        $response = $this->retry(fn () => $this->chromaDB->items()->get(
            collectionId: $this->collectionId,
            ids: [],
            include: [],
            where: $where
        ));

        if ($response->failed()) {
            throw new \RuntimeException('ChromaDB get failed: HTTP ' . $response->status() . ' ' . \mb_substr((string) $response->body(), 0, 200));
        }

        return \array_map('strval', $response->json('ids') ?? []);
    }

    /**
     * @return int number of IDs sent for deletion
     */
    protected function deleteVectorIds(array $ids): int
    {
        foreach (\array_chunk($ids, self::BATCH_SIZE) as $batch) {
            $this->retry(function () use ($batch) {
                $response = $this->chromaDB->items()->delete(
                    collectionId: $this->collectionId,
                    ids: $batch
                );

                if ($response->failed()) {
                    throw new \RuntimeException('ChromaDB delete failed: HTTP ' . $response->status() . ' ' . \mb_substr((string) $response->body(), 0, 200));
                }
            });
        }

        return \count($ids);
    }

    /**
     * Get all chunk IDs for a document
     */
    protected function getChunkIdsForDocument(object $objDocument): array
    {
        $ids = [];

        $sourceType = $this->determineSourceType($objDocument);
        $sourceId = $objDocument->pageId ?: $objDocument->id;

        foreach (DocumentChunks::loadChunkNumbers((int) $objDocument->id) as $chunkNumber) {
            $ids[] = "{$sourceType}-{$sourceId}-chunk-{$chunkNumber}";
            
            // Also try old ID format for backwards compatibility
            $ids[] = 'ID-' . $objDocument->id . '-' . $chunkNumber;
        }

        return array_unique($ids);
    }

    /**
     * Delete chunks by URL
     */
    public function deleteByUrl(string $url): void
    {
        $objDocument = Database::getInstance()
            ->prepare('SELECT id FROM tl_genai_documents WHERE url=?')
            ->limit(1)
            ->execute($url);

        if ($objDocument->numRows) {
            $this->deleteByDocumentId((int) $objDocument->id);
        }
    }

    /**
     * Delete all documents and recreate collection
     */
    public function deleteAll(): void
    {
        DocumentChunks::deleteAll();

        Database::getInstance()
            ->prepare('DELETE FROM tl_genai_documents')
            ->execute();

        try {
            $this->chromaDB->collections()->delete($this->collectionName);
        } catch (\Exception $e) {
            // Collection might not exist
        }

        $this->initCollection();
    }

    /**
     * Get chunk count in collection
     */
    public function count(): int
    {
        return $this->retry(function () {
            return $this->chromaDB->items()->count(collectionId: $this->collectionId);
        });
    }

    /**
     * Get collection info
     */
    public function getCollectionInfo(): array
    {
        return [
            'name' => $this->collectionName,
            'id' => $this->collectionId,
            'embedding_model' => $this->embeddingModel,
            'tenant' => $this->tenant,
            'database' => $this->database
        ];
    }

    /**
     * Retry wrapper for ChromaDB operations
     */
    protected function retry(callable $operation, ?int $maxRetries = null): mixed
    {
        $maxRetries = $maxRetries ?? $this->maxRetries;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $operation();
            } catch (\Exception $e) {
                $lastException = $e;

                if ($attempt < $maxRetries) {
                    // Exponential backoff
                    usleep($this->retryDelayMs * 1000 * $attempt);
                }
            }
        }

        throw $lastException;
    }

    /**
     * Log error to Contao logger
     */
    protected function logError(string $message, \Exception $error): void
    {
        try {
            System::getContainer()
                ->get('monolog.logger.contao')
                ->log(
                    LogLevel::ERROR,
                    "[GenAI ChromaDB] {$message}: {$error->getMessage()}",
                    ['contao' => new ContaoContext(__CLASS__, ContaoContext::ERROR)]
                );
        } catch (\Exception $e) {
            // Logger not available
        }
    }

    // =========================================================================
    // Legacy methods for backwards compatibility
    // =========================================================================

    /**
     * @deprecated Use initCollection() instead
     */
    public function create(): void
    {
        $this->initCollection();
    }

    /**
     * @deprecated Use setOllamaEmbedding() instead
     */
    public function setOpenAIEmbedding($strApiToken, $strOrganization, $strModel): void
    {
        // TODO: Implement OpenAI embeddings if needed
        throw new \RuntimeException('OpenAI embeddings not yet implemented. Use Ollama.');
    }

    /**
     * @deprecated Legacy sync method
     */
    public function sync(): void
    {
        // No longer needed - use upsert workflow
    }
}
