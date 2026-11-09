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

#[AsCommand(
    name: 'genai:index:sync',
    description: 'Sync pending documents to ChromaDB'
)]
class IndexSyncCommand extends Command
{
    public function __construct(
        private readonly ContaoFramework $framework
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum documents to sync', 100)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be synced without actually syncing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);
        $io->title('GenAI Index Sync');

        $ollamaUrl = Config::get('genAIChromaDBOllamaUrl');
        $ollamaModel = Config::get('genAIChromaDBOllamaModel');

        if (!$ollamaUrl || !$ollamaModel) {
            $io->error('Ollama configuration missing. Set genAIChromaDBOllamaUrl and genAIChromaDBOllamaModel.');
            return Command::FAILURE;
        }

        $limit = (int) $input->getOption('limit');
        $dryRun = $input->getOption('dry-run');

        $db = Database::getInstance();
        $pending = $db->prepare('SELECT * FROM tl_genai_documents WHERE synced_at = 0 AND tstamp > 0 ORDER BY tstamp ASC')
            ->limit($limit)
            ->execute();

        if (!$pending->numRows) {
            $io->success('No documents pending sync.');
            return Command::SUCCESS;
        }

        $io->writeln("Found {$pending->numRows} documents to sync.");

        if ($dryRun) {
            $io->note('Dry run mode - no changes will be made.');
            while ($pending->next()) {
                $io->writeln(" - [{$pending->id}] {$pending->title}");
            }
            return Command::SUCCESS;
        }

        $objChroma = new ChromaDb();
        $objChroma->setOllamaEmbedding($ollamaUrl, $ollamaModel);

        $progressBar = new ProgressBar($output, $pending->numRows);
        $progressBar->start();

        $synced = 0;
        $failed = 0;

        $pending->reset();
        while ($pending->next()) {
            try {
                // The add() method in ChromaDb processes one document at a time
                // We need to manually trigger sync for each document
                $objChroma->addSingleDocument($pending);
                $synced++;
            } catch (\Exception $e) {
                $failed++;
                $io->warning("Failed to sync document {$pending->id}: " . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success("Synced {$synced} documents. Failed: {$failed}");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
