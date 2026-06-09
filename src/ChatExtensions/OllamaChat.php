<?php

namespace Alnv\GenaiBundle\ChatExtensions;

use Contao\Config;
use GuzzleHttp\Client;
use LLPhant\Chat\OllamaChat as OllamaChatOrigin;
use LLPhant\OllamaConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class OllamaChat extends OllamaChatOrigin
{
    public function __construct(protected OllamaConfig $config, private readonly LoggerInterface $logger = new NullLogger())
    {
        $username = Config::get('aiServerUser');
        $password = Config::get('aiServerPassword');
        $base64Credentials = \base64_encode("$username:$password");

        $config->apiKey = '';

        try {
            parent::__construct($config, $logger);
        } catch (\Exception $e) {}

        $options = [
            'base_uri' => $config->url,
            'timeout' => $config->timeout,
            'connect_timeout' => $config->timeout,
            'read_timeout' => $config->timeout,
            'headers' => ['Authorization' => 'Basic ' . $base64Credentials]
        ];

        $this->client = new Client($options);
    }
}