<?php

namespace Alnv\GenaiBundle\ChatExtensions;

use Contao\Config;
use GuzzleHttp\Client;
use LLPhant\Chat\OllamaChat as OllamaChatOrigin;
use LLPhant\Exception\HttpException;
use LLPhant\OllamaConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class OllamaChat extends OllamaChatOrigin
{
    private array $modelOptions = [];

    public function __construct(protected OllamaConfig $config, private readonly LoggerInterface $logger = new NullLogger())
    {
        $config->apiKey = '';

        try {
            parent::__construct($config, $logger);
        } catch (\Exception $e) {
        }

        $username = Config::get('aiServerUser');
        $password = Config::get('aiServerPassword');
        $base64Credentials = \base64_encode("$username:$password");

        $options = [
            'base_uri' => $config->url,
            'timeout' => $config->timeout,
            'connect_timeout' => min($config->timeout, 30),
            'read_timeout' => $config->timeout,
            'headers' => [
                'Authorization' => 'Basic ' . $base64Credentials,
                'Connection' => 'keep-alive'
            ]
        ];

        $this->client = new Client($options);
    }

    /**
     * Set Ollama-specific model options (keep_alive, num_ctx, etc.)
     */
    public function setModelOptions(array $options): void
    {
        $this->modelOptions = $options;
    }

    protected function sendRequest(string $method, string $path, array $json): ResponseInterface
    {
        $this->logger->debug('Calling ' . $method . ' ' . $path, [
            'chat' => self::class,
            'params' => $json,
        ]);

        $params = ['json' => $json, 'headers' => [], 'stream' => $json['stream'] ?? false];
        if ($hostIp = Config::get('aiServerHostIP')) {
            $params['headers']['Host'] = $hostIp;
        }

        $response = $this->client->request($method, $path, $params);

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new HttpException(sprintf(
                'HTTP error from Ollama (%d): %s',
                $status,
                $response->getBody()->getContents()
            ));
        }

        return $response;
    }

    public function generateChatStreamV2($messages): ResponseInterface
    {
        $params = [
            ...$this->modelOptions,
            'model' => $this->config->model,
            'messages' => $this->prepareMessages($messages),
            'stream' => true,
            'think' => false
        ];

        return $this->sendRequest(
            'POST',
            'chat',
            $params
        );
    }
}