<?php

namespace Alnv\GenaiBundle\ChatExtensions;

use Contao\Config;
use GuzzleHttp\Client;
use LLPhant\Chat\OpenAIChat as OpenAIChatOrigin;
use LLPhant\Exception\HttpException;
use LLPhant\OpenAIConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Extended OpenAI Chat with streaming support
 * 
 * Adds SSE streaming capability and optional Basic Auth for proxy servers.
 */
class OpenAIChat extends OpenAIChatOrigin
{
    private array $modelOptions = [];
    protected Client $streamClient;

    public function __construct(
        protected OpenAIConfig $config,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        parent::__construct($config, $logger);

        // Ensure base URL ends with slash for proper path resolution
        $baseUrl = $config->url ?: 'https://api.openai.com/v1/';
        if (!str_ends_with($baseUrl, '/')) {
            $baseUrl .= '/';
        }

        // Build client options
        $options = [
            'base_uri' => $baseUrl,
            'timeout' => $config->timeout ?? 120,
            'connect_timeout' => min($config->timeout ?? 120, 30),
            'read_timeout' => $config->timeout ?? 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'Connection' => 'keep-alive'
            ]
        ];

        // Add Authorization header only if apiKey is set (local APIs often don't need it)
        if (!empty($config->apiKey)) {
            $options['headers']['Authorization'] = 'Bearer ' . $config->apiKey;
        }

        // Add OpenAI organization header if set
        if (!empty($config->organization)) {
            $options['headers']['OpenAI-Organization'] = $config->organization;
        }

        // Optional: Add Basic Auth for proxy servers
        $username = Config::get('aiServerUser');
        $password = Config::get('aiServerPassword');
        if ($username && $password) {
            $options['headers']['X-Proxy-Authorization'] = 'Basic ' . \base64_encode("$username:$password");
        }

        $this->streamClient = new Client($options);
    }

    /**
     * Set model-specific options (temperature, max_tokens, etc.)
     */
    public function setModelOptions(array $options): void
    {
        $this->modelOptions = $options;
    }

    /**
     * Send streaming request to OpenAI API
     */
    protected function sendStreamRequest(string $method, string $path, array $json): ResponseInterface
    {
        $this->logger->debug('Calling ' . $method . ' ' . $path, [
            'chat' => self::class,
            'params' => $json,
        ]);

        $params = [
            'json' => $json,
            'stream' => true,
            'headers' => []
        ];

        // Add custom host header if configured
        if ($hostIp = Config::get('aiServerHostIP')) {
            $params['headers']['Host'] = $hostIp;
        }

        $response = $this->streamClient->request($method, $path, $params);

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new HttpException(sprintf(
                'HTTP error from OpenAI (%d): %s',
                $status,
                $response->getBody()->getContents()
            ));
        }

        return $response;
    }

    /**
     * Generate chat completion with SSE streaming
     * 
     * Returns a ResponseInterface that can be read as a stream.
     * Each chunk is a JSON object with delta content.
     * 
     * @param array $messages LLPhant Message objects
     * @return ResponseInterface Streaming response body
     */
    public function generateChatStreamV2(array $messages): ResponseInterface
    {
        $params = [
            'model' => $this->config->model,
            'messages' => $this->prepareMessages($messages),
            'stream' => true,
            ...$this->modelOptions
        ];

        // Add temperature if set in config
        if (isset($this->config->temperature)) {
            $params['temperature'] = $this->config->temperature;
        }

        // Add max_tokens if set in config
        if (isset($this->config->maxTokens)) {
            $params['max_tokens'] = $this->config->maxTokens;
        }

        return $this->sendStreamRequest(
            'POST',
            'chat/completions',
            $params
        );
    }

    /**
     * Prepare messages for OpenAI API format
     */
    protected function prepareMessages(array $messages): array
    {
        $prepared = [];

        foreach ($messages as $message) {
            $prepared[] = [
                'role' => $message->role->value,
                'content' => $message->content
            ];
        }

        return $prepared;
    }
}
