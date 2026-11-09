<?php

namespace Alnv\GenaiBundle\Command;

use Alnv\GenaiBundle\Vectors\ChromaDb;
use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[AsCommand(
    name: 'genai:index:full',
    description: 'Full reindex of all documents to ChromaDB'
)]
class IndexFullCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force reindex even if documents are already synced')
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Documents per batch', 10)
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum documents to process', 0)
            ->addOption('collection', 'c', InputOption::VALUE_OPTIONAL, 'Only index documents from this collection ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);
        $io->title('GenAI Full Index');

        // Check global configuration
        $ollamaUrl = Config::get('genAIChromaDBOllamaUrl');
        $ollamaModel = Config::get('genAIChromaDBOllamaModel');

        if (!$ollamaUrl || !$ollamaModel) {
            $io->error([
                'Ollama configuration missing.',
                'Set genAIChromaDBOllamaUrl and genAIChromaDBOllamaModel in localconfig.php or system settings.'
            ]);
            return Command::FAILURE;
        }

        // Acquire lock to prevent concurrent runs
        $lockFactory = new LockFactory(new FlockStore());
        $lock = $lockFactory->createLock('genai-index-full', 3600);

        if (!$lock->acquire()) {
            $io->error('Another index process is running. Try again later.');
            return Command::FAILURE;
        }

        try {
            return $this->doIndex($input, $io, $ollamaUrl, $ollamaModel);
        } finally {
            $lock->release();
        }
    }

    protected function doIndex(InputInterface $input, SymfonyStyle $io, string $ollamaUrl, string $ollamaModel): int
    {
        $db = Database::getInstance();
        $force = $input->getOption('force');
        $batchSize = (int) $input->getOption('batch-size');
        $limit = (int) $input->getOption('limit');
        $collectionId = $input->getOption('collection');

        // Initialize ChromaDB with global config
        $objChroma = new ChromaDb();
        $objChroma->setOllamaEmbedding($ollamaUrl, $ollamaModel);

        // Show configuration
        $info = $objChroma->getCollectionInfo();
        $io->section('Configuration');
        $io->writeln("Ollama URL: {$ollamaUrl}");
        $io->writeln("Embedding Model: {$ollamaModel}");
        $io->writeln("ChromaDB Collection: {$info['name']}");
        $io->newLine();

        // Build query
        $where = ['tstamp > 0'];
        $params = [];

        if (!$force) {
            $where[] = 'synced_at = 0';
        }

        if ($collectionId) {
            $where[] = 'pid = ?';
            $params[] = $collectionId;
        }

        $whereClause = implode(' AND ', $where);

        // Count documents
        $countQuery = "SELECT COUNT(*) as cnt FROM tl_genai_documents WHERE {$whereClause}";
        $total = $db->prepare($countQuery)->execute(...$params)->cnt;

        if ($total === 0) {
            $io->success('No documents to index.');
            return Command::SUCCESS;
        }

        if ($limit > 0) {
            $total = min($total, $limit);
        }

        $io->writeln("Found {$total} documents to index" . ($force ? ' (force mode)' : ''));

        // Mark all as unsynced if force mode
        if ($force) {
            $io->writeln('Marking all documents as unsynced...');
            $db->prepare("UPDATE tl_genai_documents SET synced_at = 0 WHERE {$whereClause}")
                ->execute(...$params);
        }

        // Process documents
        $progressBar = new ProgressBar($io, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
        $progressBar->start();

        $processed = 0;
        $failed = 0;
        $startTime = microtime(true);

        $fetchWhere = 'synced_at = 0 AND tstamp > 0';
        $fetchParams = [];
        
        if ($collectionId) {
            $fetchWhere = 'pid = ? AND ' . $fetchWhere;
            $fetchParams[] = $collectionId;
        }

        while ($processed < $total) {
            $documents = $db->prepare(
                "SELECT * FROM tl_genai_documents WHERE {$fetchWhere} ORDER BY tstamp ASC"
            )
            ->limit($batchSize)
            ->execute(...$fetchParams);

            if (!$documents->numRows) {
                break;
            }

            while ($documents->next()) {
                $progressBar->setMessage("Processing: " . mb_substr($documents->title, 0, 40));

                try {
                    $objChroma->upsertDocument($documents);
                } catch (\Exception $e) {
                    $failed++;
                    $io->warning("Failed to index document {$documents->id}: " . $e->getMessage());
                }

                $processed++;
                $progressBar->advance();

                if ($limit > 0 && $processed >= $limit) {
                    break 2;
                }
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        $duration = round(microtime(true) - $startTime, 2);
        $perDoc = $processed > 0 ? round($duration / $processed, 2) : 0;

        $io->success([
            "Indexed {$processed} documents in {$duration}s ({$perDoc}s/doc)",
            "Failed: {$failed}",
            "Chunks in ChromaDB: " . $objChroma->count()
        ]);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
