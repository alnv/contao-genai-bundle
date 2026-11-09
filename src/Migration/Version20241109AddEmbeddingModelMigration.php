<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Removes obsolete embedding model columns from tl_genai_documents_collections
 * 
 * Embedding configuration is now centralized via global config:
 * - genAIChromaDBOllamaUrl
 * - genAIChromaDBOllamaModel
 */
class Version20241109AddEmbeddingModelMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_genai_documents_collections'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_genai_documents_collections');

        // Check if obsolete columns exist
        return isset($columns['embeddingmodel']) || isset($columns['embeddingurl']);
    }

    public function run(): MigrationResult
    {
        $schemaManager = $this->connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('tl_genai_documents_collections');

        $dropped = [];

        // Drop obsolete columns
        $obsoleteColumns = ['embeddingmodel', 'embeddingurl', 'filterlanguage', 'previousembeddingmodel', 'reindexrequired'];

        foreach ($obsoleteColumns as $column) {
            if (isset($columns[$column])) {
                try {
                    $this->connection->executeStatement("ALTER TABLE tl_genai_documents_collections DROP COLUMN {$column}");
                    $dropped[] = $column;
                } catch (\Exception $e) {
                    // Column might not exist, ignore
                }
            }
        }

        // Ensure filterRootPage exists
        if (!isset($columns['filterrootpage'])) {
            $this->connection->executeStatement("
                ALTER TABLE tl_genai_documents_collections
                ADD COLUMN filterRootPage INT UNSIGNED NOT NULL DEFAULT 0
            ");
        }

        $droppedList = implode(', ', $dropped);
        return new MigrationResult(true, "Cleaned up tl_genai_documents_collections: dropped [{$droppedList}]");
    }
}
