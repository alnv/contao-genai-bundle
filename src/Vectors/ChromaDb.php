<?php

namespace Alnv\GenaiBundle\Vectors;

use Alnv\GenaiBundle\Embeddings\OllamaEmbeddings;
use Contao\Config;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Database;
use Contao\StringUtil;
use Contao\System;
use HelgeSverre\Chromadb\Chromadb as ChromaDbClient;
use HelgeSverre\Chromadb\Embeddings\EmbeddingFunction;
use Psr\Log\LogLevel;

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

    /**
     * Upsert a single document with extended metadata
     */
    public function upsertDocument(object $objDocument, ?string $contentHash = null): void
    {
        if (!$this->embeddingFunction) {
            throw new \RuntimeException('Embedding function not set. Call setOllamaEmbedding() first.');
        }

        $data = $this->prepareDocumentData($objDocument, $contentHash);

        if (empty($data['ids'])) {
            $this->markAsSynced($objDocument->id);
            return;
        }

        // Generate embeddings for all documents
        $embeddings = $this->generateEmbeddings($data['documents']);

        $this->retry(function () use ($data, $embeddings) {
            // Use upsert with pre-computed embeddings
            $this->chromaDB->items()->upsert(
                collectionId: $this->collectionId,
                ids: $data['ids'],
                embeddings: $embeddings,
                metadatas: $data['metadatas'],
                documents: $data['documents']
            );
        });

        $this->markAsSynced($objDocument->id, $contentHash);
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

        $arrContents = StringUtil::deserialize($objDocument->content, true);
        $now = time();

        foreach ($arrContents as $index => $arrContent) {
            $chunkNumber = $arrContent['chunkNumber'] ?? ($index + 1);
            $content = $arrContent['formattedContent'] ?? '';

            if (empty($content)) {
                continue;
            }

            // Deterministic ID format: {type}-{sourceId}-chunk-{n}
            $sourceType = $this->determineSourceType($objDocument);
            $sourceId = $objDocument->pageId ?: $objDocument->id;
            $data['ids'][] = "{$sourceType}-{$sourceId}-chunk-{$chunkNumber}";
            
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
    protected function markAsSynced(int $documentId, ?string $contentHash = null): void
    {
        $set = [
            'tstamp' => time(),
            'synced_at' => time()
        ];

        if ($contentHash) {
            $set['content_hash'] = $contentHash;
        }

        Database::getInstance()
            ->prepare('UPDATE tl_genai_documents %s WHERE id=?')
            ->set($set)
            ->limit(1)
            ->execute($documentId);
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
        $this->upsertDocument($objDocument);
    }

    /**
     * Delete chunks by document ID
     */
    public function deleteByDocumentId(int $documentId): void
    {
        $objDocument = Database::getInstance()
            ->prepare('SELECT * FROM tl_genai_documents WHERE id=?')
            ->limit(1)
            ->execute($documentId);

        if (!$objDocument->numRows) {
            return;
        }

        $ids = $this->getChunkIdsForDocument($objDocument);

        if (empty($ids)) {
            return;
        }

        $this->retry(function () use ($ids) {
            $this->chromaDB->items()->delete(
                collectionId: $this->collectionId,
                ids: $ids
            );
        });
    }

    /**
     * Get all chunk IDs for a document
     */
    protected function getChunkIdsForDocument(object $objDocument): array
    {
        $arrContents = StringUtil::deserialize($objDocument->content, true);
        $ids = [];

        $sourceType = $this->determineSourceType($objDocument);
        $sourceId = $objDocument->pageId ?: $objDocument->id;

        foreach ($arrContents as $index => $arrContent) {
            $chunkNumber = $arrContent['chunkNumber'] ?? ($index + 1);
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
