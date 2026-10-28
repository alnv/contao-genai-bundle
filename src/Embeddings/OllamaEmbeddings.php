<?php

namespace Alnv\GenaiBundle\Embeddings;

use Contao\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use HelgeSverre\Chromadb\Embeddings\EmbeddingFunction;
use HelgeSverre\Chromadb\Embeddings\HttpClient;
use HelgeSverre\Chromadb\Exceptions\EmbeddingException;

/**
 * Ollama Embeddings provider (local).
 *
 * Supports any Ollama embedding model running locally, including:
 * - all-minilm (lightweight, default)
 * - nomic-embed-text (high quality)
 * - mxbai-embed-large (larger model)
 * - snowflake-arctic-embed (specialized)
 *
 * @see https://docs.ollama.com/api#generate-embeddings
 */
class OllamaEmbeddings implements EmbeddingFunction
{
    private Client $client;

    /**
     * Create a new Ollama embeddings instance.
     *
     * @param string $model The embedding model to use (default: all-minilm)
     * @param string $baseUrl The Ollama API base URL (default: http://localhost:11434)
     */
    public function __construct(
        private readonly string $model = 'all-minilm',
        private readonly string $baseUrl = 'http://localhost:11434',
        private readonly int    $timeout = 90,
    )
    {
        $username = Config::get('aiServerUser');
        $password = Config::get('aiServerPassword');

        $base64Credentials = base64_encode("$username:$password");
        $this->client = new Client([
            'base_uri' => rtrim($this->baseUrl, '/'),
            'headers' => [
                'Authorization' => 'Basic ' . $base64Credentials,
            ],
            'timeout' => $this->timeout,
        ]);
    }

    /**
     * Generate embeddings for the given texts using Ollama's API.
     *
     * Note: Ollama's API processes one text at a time, so this method
     * makes multiple API calls for batch requests.
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

        try {
            $embeddings = [];

            foreach ($texts as $text) {
                $response = $this->client->post('/api/embed', [
                    'headers' => $headers,
                    'json' => [
                        'model' => $this->model,
                        'input' => $text,
                    ],
                ]);

                $result = json_decode($response->getBody()->getContents(), true);

                if (!isset($result['embeddings']) || !is_array($result['embeddings'])) {
                    throw EmbeddingException::invalidResponse('Ollama', 'Missing or invalid "embeddings" field');
                }

                $embeddings[] = $result['embeddings'][0] ?? [];
            }

            return $embeddings;
        } catch (GuzzleException $e) {
            throw EmbeddingException::apiError('Ollama', $e->getMessage(), $e);
        }
    }
}