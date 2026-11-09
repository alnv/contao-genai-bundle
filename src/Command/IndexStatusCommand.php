<?php

namespace Alnv\GenaiBundle\Command;

use Alnv\GenaiBundle\Service\IndexQueue;
use Alnv\GenaiBundle\Vectors\ChromaDb;
use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'genai:index:status',
    description: 'Show the status of the GenAI document index and queue'
)]
class IndexStatusCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);
        $io->title('GenAI Index Status');

        $db = Database::getInstance();

        // Queue statistics
        $io->section('Index Queue (tl_genai_index)');
        
        try {
            $queue = new IndexQueue();
            $stats = $queue->getStats();

            $io->table(
                ['Status', 'Count'],
                [
                    ['Pending', $stats['pending']],
                    ['Processing', $stats['processing']],
                    ['Completed', $stats['completed']],
                    ['Failed', $stats['failed']],
                    ['Marked for deletion', $stats['deleted']],
                    ['─────', '─────'],
                    ['Total', $stats['total']],
                ]
            );

            // Show failed items if any
            if ($stats['failed'] > 0) {
                $failed = $db->prepare("
                    SELECT source_type, source_id, title, error_message, retry_count 
                    FROM tl_genai_index 
                    WHERE status = 'failed' 
                    ORDER BY tstamp DESC
                ")->limit(5)->execute();

                $io->warning("Recent failures:");
                while ($failed->next()) {
                    $io->writeln(sprintf(
                        "  • [%s:%d] %s (retries: %d)",
                        $failed->source_type,
                        $failed->source_id,
                        $failed->title ?: '(no title)',
                        $failed->retry_count
                    ));
                    if ($failed->error_message) {
                        $io->writeln("    Error: " . substr($failed->error_message, 0, 100));
                    }
                }
            }
        } catch (\Exception $e) {
            $io->note('Queue table not available (run migrations first)');
        }

        // Document statistics
        $io->section('Documents (tl_genai_documents)');
        
        $totalDocs = $db->execute('SELECT COUNT(*) as cnt FROM tl_genai_documents')->cnt;
        $syncedDocs = $db->execute('SELECT COUNT(*) as cnt FROM tl_genai_documents WHERE synced_at > 0')->cnt;
        $pendingDocs = $db->execute('SELECT COUNT(*) as cnt FROM tl_genai_documents WHERE synced_at = 0 AND tstamp > 0')->cnt;
        
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total documents', $totalDocs],
                ['Synced to ChromaDB', $syncedDocs],
                ['Pending sync', $pendingDocs],
            ]
        );

        // By language
        $byLang = $db->execute("SELECT language, COUNT(*) as cnt FROM tl_genai_documents WHERE synced_at > 0 GROUP BY language ORDER BY cnt DESC");
        if ($byLang->numRows) {
            $langData = [];
            while ($byLang->next()) {
                $langData[] = [$byLang->language ?: '(none)', $byLang->cnt];
            }
            $io->writeln("By language:");
            $io->table(['Language', 'Documents'], $langData);
        }

        // ChromaDB statistics
        $io->section('ChromaDB');
        
        $ollamaUrl = Config::get('genAIChromaDBOllamaUrl');
        $ollamaModel = Config::get('genAIChromaDBOllamaModel');

        if (!$ollamaUrl || !$ollamaModel) {
            $io->warning('Embedding configuration missing (genAIChromaDBOllamaUrl / genAIChromaDBOllamaModel)');
        } else {
            try {
                $objChroma = new ChromaDb();
                $objChroma->setOllamaEmbedding($ollamaUrl, $ollamaModel);
                
                $info = $objChroma->getCollectionInfo();
                $health = $objChroma->healthCheck();
                
                $io->table(
                    ['Metric', 'Value'],
                    [
                        ['Status', $health['status'] === 'healthy' ? '✓ Healthy' : '✗ Unhealthy'],
                        ['Collection', $info['name']],
                        ['Embedding Model', $info['embedding_model']],
                        ['Total chunks', $health['chunks'] ?? 'N/A'],
                        ['Latency', ($health['latency_ms'] ?? 'N/A') . ' ms'],
                        ['Tenant', $info['tenant']],
                        ['Database', $info['database']],
                    ]
                );

                if ($health['status'] !== 'healthy' && isset($health['error'])) {
                    $io->error("ChromaDB error: " . $health['error']);
                }
            } catch (\Exception $e) {
                $io->error('Could not connect to ChromaDB: ' . $e->getMessage());
            }
        }

        // Configuration summary
        $io->section('Configuration');
        $io->table(
            ['Setting', 'Value'],
            [
                ['ChromaDB URL', Config::get('genAIChromaDBUrl') ?: '(not set)'],
                ['ChromaDB Port', Config::get('genAIChromaDBPort') ?: '(not set)'],
                ['Embedding Model', $ollamaModel ?: '(not set)'],
                ['Ollama URL', $ollamaUrl ?: '(not set)'],
                ['Collection Name', Config::get('genAIChromaDBCollectionName') ?: '(not set)'],
                ['Max Context Length', Config::get('genAIMaxContextLength') ?: '8000 (default)'],
                ['Top-K Results', Config::get('genAITopK') ?: '10 (default)'],
            ]
        );

        return Command::SUCCESS;
    }
}
