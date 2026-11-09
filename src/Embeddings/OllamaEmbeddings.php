<?php

namespace Alnv\GenaiBundle\Embeddings;

use Contao\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use HelgeSverre\Chromadb\Embeddings\EmbeddingFunction;
use HelgeSverre\Chromadb\Exceptions\EmbeddingException;

/**
 * Ollama Embeddings provider with query/document prefix support.
 *
 * Supports any Ollama embedding model running locally, including:
 * - bge-m3 (multilingual, very good for German, 1024 dim)
 * - qwen3-embedding (0.6B/4B/8B, multilingual, strong)
 * - nomic-embed-text (fast, needs prefixes: search_query:/search_document:)
 * - mxbai-embed-large (fast, English-focused)
 * - snowflake-arctic-embed2 (multilingual)
 * - multilingual-e5-large (needs prefixes: query:/passage:)
 *
 * @see https://docs.ollama.com/api#generate-embeddings
 */
class OllamaEmbeddings implements EmbeddingFunction
{
    private Client $client;

    // Model-specific prefix configurations
    private const MODEL_PREFIXES = [
        // Nomic models use search_query/search_document prefixes
        'nomic-embed-text' => [
            'query' => 'search_query: ',
            'document' => 'search_document: '
        ],
        // E5 models use query/passage prefixes
        'multilingual-e5' => [
            'query' => 'query: ',
            'document' => 'passage: '
        ],
        'e5-large' => [
            'query' => 'query: ',
            'document' => 'passage: '
        ],
        'e5-small' => [
            'query' => 'query: ',
            'document' => 'passage: '
        ],
        // BGE models can use instruction prefixes (optional)
        'bge-' => [
            'query' => '',
            'document' => ''
        ],
    ];

    private bool $isQueryMode = true;

    /**
     * Create a new Ollama embeddings instance.
     *
     * @param string $model The embedding model to use
     * @param string $baseUrl The Ollama API base URL
     * @param int $timeout Request timeout in seconds
     */
    public function __construct(
        private readonly string $model = 'bge-m3',
        private readonly string $baseUrl = 'http://localhost:11434',
        private readonly int $timeout = 120,
    ) {
        $username = Config::get('aiServerUser');
        $password = Config::get('aiServerPassword');

        $base64Credentials = base64_encode("{$username}:{$password}");
        
        $this->client = new Client([
            'base_uri' => rtrim($this->baseUrl, '/'),
            'headers' => [
                'Authorization' => 'Basic ' . $base64Credentials,
                'Connection' => 'keep-alive',
            ],
            'timeout' => $this->timeout,
            'connect_timeout' => min($this->timeout, 30),
        ]);
    }

    /**
     * Set mode for query embedding (vs document embedding)
     * This affects which prefix is used for models that require them.
     */
    public function setQueryMode(bool $isQuery = true): self
    {
        $this->isQueryMode = $isQuery;
        return $this;
    }

    /**
     * Generate embeddings for the given texts using Ollama's API.
     *
     * @param array<string> $texts The texts to embed
     * @return array<array<float>> The embedding vectors
     *
     * @throws EmbeddingException
     */
    public function generate(array $texts): array
    {
        if (empty($texts)) {
            throw EmbeddingException::emptyInput();
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($hostIp = Config::get('aiServerHostIP')) {
            $headers['Host'] = $hostIp;
        }

        // Get prefix configuration for this model
        $prefix = $this->getPrefix();

        // Maximum text length to avoid 413 errors (default: 8000 chars)
        $maxTextLength = (int) (Config::get('genAIEmbeddingMaxTextLength') ?: 8000);

        // Truncate texts that are too long
        $texts = array_map(function($text) use ($maxTextLength) {
            if (mb_strlen($text) > $maxTextLength) {
                return mb_substr($text, 0, $maxTextLength);
            }
            return $text;
        }, $texts);

        try {
            $embeddings = [];

            // Check if Ollama supports batch embedding
            $useBatch = count($texts) > 1 && $this->supportsBatchEmbedding();

            if ($useBatch) {
                // Try batch embedding (Ollama 0.5+)
                $embeddings = $this->generateBatch($texts, $headers, $prefix);
            } else {
                // Fall back to sequential embedding
                foreach ($texts as $text) {
                    $prefixedText = $prefix . $text;
                    
                    $response = $this->client->post('/api/embed', [
                        'headers' => $headers,
                        'json' => [
                            'model' => $this->model,
                            'input' => $prefixedText,
                        ],
                    ]);

                    $result = json_decode($response->getBody()->getContents(), true);

                    if (!isset($result['embeddings']) || !is_array($result['embeddings'])) {
                        throw EmbeddingException::invalidResponse('Ollama', 'Missing or invalid "embeddings" field');
                    }

                    $embeddings[] = $result['embeddings'][0] ?? [];
                }
            }

            return $embeddings;
        } catch (GuzzleException $e) {
            throw EmbeddingException::apiError('Ollama', $e->getMessage(), $e);
        }
    }

    /**
     * Generate embeddings in batch (Ollama 0.5+)
     * Splits large batches to avoid 413 errors from proxies
     */
    protected function generateBatch(array $texts, array $headers, string $prefix): array
    {
        // Add prefix to all texts
        $prefixedTexts = array_map(fn($t) => $prefix . $t, $texts);

        // Configurable batch size to avoid 413 errors (default: 5 texts per request)
        $batchSize = (int) (Config::get('genAIEmbeddingBatchSize') ?: 5);
        
        // If small enough, send all at once
        if (count($prefixedTexts) <= $batchSize) {
            return $this->sendBatchRequest($prefixedTexts, $headers);
        }

        // Split into smaller batches
        $allEmbeddings = [];
        $chunks = array_chunk($prefixedTexts, $batchSize);
        
        foreach ($chunks as $chunk) {
            $batchEmbeddings = $this->sendBatchRequest($chunk, $headers);
            $allEmbeddings = array_merge($allEmbeddings, $batchEmbeddings);
        }

        return $allEmbeddings;
    }

    /**
     * Send a single batch embedding request
     */
    protected function sendBatchRequest(array $texts, array $headers): array
    {
        $response = $this->client->post('/api/embed', [
            'headers' => $headers,
            'json' => [
                'model' => $this->model,
                'input' => $texts,
            ],
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        if (!isset($result['embeddings']) || !is_array($result['embeddings'])) {
            throw EmbeddingException::invalidResponse('Ollama', 'Missing or invalid "embeddings" field');
        }

        return $result['embeddings'];
    }

    /**
     * Get the appropriate prefix for the current model and mode
     */
    protected function getPrefix(): string
    {
        // Check for custom prefix in config
        $customQueryPrefix = Config::get('genAIEmbeddingQueryPrefix');
        $customDocPrefix = Config::get('genAIEmbeddingDocumentPrefix');
        
        if ($this->isQueryMode && $customQueryPrefix !== null) {
            return $customQueryPrefix;
        }
        if (!$this->isQueryMode && $customDocPrefix !== null) {
            return $customDocPrefix;
        }

        // Check model-specific prefixes
        foreach (self::MODEL_PREFIXES as $modelPattern => $prefixes) {
            if (str_contains(strtolower($this->model), strtolower($modelPattern))) {
                return $this->isQueryMode ? $prefixes['query'] : $prefixes['document'];
            }
        }

        // No prefix needed for most models
        return '';
    }

    /**
     * Check if the Ollama version supports batch embedding
     */
    protected function supportsBatchEmbedding(): bool
    {
        // Batch embedding is supported in Ollama 0.5+
        // For now, assume it's supported and handle errors gracefully
        return true;
    }

    /**
     * Get model information
     */
    public function getModelInfo(): array
    {
        return [
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'timeout' => $this->timeout,
            'prefix' => $this->getPrefix(),
            'mode' => $this->isQueryMode ? 'query' : 'document'
        ];
    }

    /**
     * Embed a single query text (uses query prefix)
     */
    public function embedQuery(string $text): array
    {
        $this->setQueryMode(true);
        $result = $this->generate([$text]);
        return $result[0] ?? [];
    }

    /**
     * Embed a single document text (uses document prefix)
     */
    public function embedDocument(string $text): array
    {
        $this->setQueryMode(false);
        $result = $this->generate([$text]);
        return $result[0] ?? [];
    }

    /**
     * Embed multiple documents (uses document prefix)
     */
    public function embedDocuments(array $texts): array
    {
        $this->setQueryMode(false);
        return $this->generate($texts);
    }
}
