<?php

namespace Alnv\GenaiBundle\Command;

use Alnv\GenaiBundle\Embeddings\OllamaEmbeddings;
use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'genai:warmup',
    description: 'Warm up Ollama models to avoid cold-start latency for the first user'
)]
class WarmupCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('chat-model', 'c', InputOption::VALUE_OPTIONAL, 'Chat model to warm up')
            ->addOption('embedding-model', 'em', InputOption::VALUE_OPTIONAL, 'Embedding model to warm up')
            ->addOption('keep-alive', 'k', InputOption::VALUE_OPTIONAL, 'Keep-alive duration (e.g., "30m", "1h")', '30m');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);
        $io->title('GenAI Model Warmup');

        $ollamaUrl = Config::get('genAIChromaDBOllamaUrl');
        if (!$ollamaUrl) {
            $io->error('Ollama URL not configured (genAIChromaDBOllamaUrl)');
            return Command::FAILURE;
        }

        $keepAlive = $input->getOption('keep-alive');

        // Warm up embedding model
        $embeddingModel = $input->getOption('embedding-model') ?: Config::get('genAIChromaDBOllamaModel');
        if ($embeddingModel) {
            $io->section("Warming up embedding model: {$embeddingModel}");
            
            try {
                $startTime = microtime(true);
                
                $embeddings = new OllamaEmbeddings(
                    model: $embeddingModel,
                    baseUrl: $ollamaUrl,
                    timeout: 120
                );
                
                // Generate a test embedding to load the model
                $embeddings->generate(['Warmup test query']);
                
                $duration = round(microtime(true) - $startTime, 2);
                $io->success("Embedding model loaded in {$duration}s");
            } catch (\Exception $e) {
                $io->error("Failed to warm up embedding model: " . $e->getMessage());
            }
        }

        // Warm up chat model
        $chatModel = $input->getOption('chat-model');
        if ($chatModel) {
            $io->section("Warming up chat model: {$chatModel}");
            
            try {
                $startTime = microtime(true);
                
                $this->warmupChatModel($ollamaUrl, $chatModel, $keepAlive);
                
                $duration = round(microtime(true) - $startTime, 2);
                $io->success("Chat model loaded in {$duration}s (keep_alive: {$keepAlive})");
            } catch (\Exception $e) {
                $io->error("Failed to warm up chat model: " . $e->getMessage());
            }
        }

        if (!$embeddingModel && !$chatModel) {
            $io->warning('No models specified. Use --chat-model and/or --embedding-model options.');
            return Command::FAILURE;
        }

        $io->success('Warmup complete. Models are now loaded in memory.');
        
        return Command::SUCCESS;
    }

    private function warmupChatModel(string $ollamaUrl, string $model, string $keepAlive): void
    {
        $username = Config::get('aiServerUser');
        $password = Config::get('aiServerPassword');
        $base64Credentials = base64_encode("{$username}:{$password}");

        $client = new Client([
            'base_uri' => rtrim($ollamaUrl, '/'),
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Basic ' . $base64Credentials,
                'Content-Type' => 'application/json'
            ]
        ]);

        // Send a minimal chat request to load the model
        $response = $client->post('/api/chat', [
            'json' => [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => 'Hi']
                ],
                'stream' => false,
                'keep_alive' => $keepAlive,
                'options' => [
                    'num_predict' => 1 // Only generate 1 token (minimal work)
                ]
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("Ollama returned status " . $response->getStatusCode());
        }
    }
}
