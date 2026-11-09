<?php

namespace Alnv\GenaiBundle\Command;

use Alnv\GenaiBundle\Vectors\ChromaDb;
use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'genai:index:purge',
    description: 'Purge orphaned chunks from ChromaDB and/or reset the index'
)]
class IndexPurgeCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Delete ALL documents and chunks (full reset)')
            ->addOption('orphans', 'o', InputOption::VALUE_NONE, 'Delete only orphaned chunks in ChromaDB')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without actually deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);
        $io->title('GenAI Index Purge');

        $ollamaUrl = Config::get('genAIChromaDBOllamaUrl');
        $ollamaModel = Config::get('genAIChromaDBOllamaModel');

        if (!$ollamaUrl || !$ollamaModel) {
            $io->error('Ollama configuration missing. Set genAIChromaDBOllamaUrl and genAIChromaDBOllamaModel.');
            return Command::FAILURE;
        }

        $purgeAll = $input->getOption('all');
        $purgeOrphans = $input->getOption('orphans');
        $dryRun = $input->getOption('dry-run');

        if (!$purgeAll && !$purgeOrphans) {
            $io->warning('Please specify --all or --orphans');
            $io->writeln('  --all      Delete ALL documents and chunks');
            $io->writeln('  --orphans  Delete only orphaned chunks');
            return Command::INVALID;
        }

        $objChroma = new ChromaDb();
        $objChroma->setOllamaEmbedding($ollamaUrl, $ollamaModel);

        if ($purgeAll) {
            return $this->purgeAll($objChroma, $io, $dryRun);
        }

        if ($purgeOrphans) {
            return $this->purgeOrphans($objChroma, $io, $dryRun);
        }

        return Command::SUCCESS;
    }

    protected function purgeAll(ChromaDb $chroma, SymfonyStyle $io, bool $dryRun): int
    {
        $db = Database::getInstance();
        
        $docCount = $db->execute('SELECT COUNT(*) as cnt FROM tl_genai_documents')->cnt;
        $chunkCount = $chroma->count();

        $io->writeln("Found {$docCount} documents in database");
        $io->writeln("Found {$chunkCount} chunks in ChromaDB");

        if ($dryRun) {
            $io->note('Dry run - would delete all documents and chunks');
            return Command::SUCCESS;
        }

        if (!$io->confirm('Are you sure you want to delete ALL documents and chunks?', false)) {
            $io->writeln('Aborted.');
            return Command::SUCCESS;
        }

        $chroma->deleteAll();

        $io->success('All documents and chunks deleted.');
        return Command::SUCCESS;
    }

    protected function purgeOrphans(ChromaDb $chroma, SymfonyStyle $io, bool $dryRun): int
    {
        $db = Database::getInstance();

        // Get all document IDs from database
        $documents = $db->execute('SELECT id, pageId FROM tl_genai_documents WHERE synced_at > 0');
        
        $validPrefixes = [];
        while ($documents->next()) {
            // Both old and new ID formats
            $validPrefixes[] = 'ID-' . $documents->id . '-';
            $validPrefixes[] = 'page-' . ($documents->pageId ?: $documents->id) . '-chunk-';
            $validPrefixes[] = 'file-' . ($documents->pageId ?: $documents->id) . '-chunk-';
        }

        $io->writeln('Found ' . count($validPrefixes) . ' valid document prefixes');
        
        // Note: ChromaDB doesn't have a native way to list all IDs efficiently
        // This would require a custom implementation based on the ChromaDB version
        
        $io->note('Orphan detection requires ChromaDB metadata query support.');
        $io->writeln('Consider running genai:index:full --force for a complete reindex.');

        return Command::SUCCESS;
    }
}
