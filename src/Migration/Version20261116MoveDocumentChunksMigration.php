<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Migration;

use Alnv\GenaiBundle\Helpers\DocumentChunks;
use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Moves the serialized chunks from tl_genai_documents.content (LONGBLOB)
 * into tl_genai_documents_chunks (one row per chunk) and drops the column.
 *
 * - Processes one document at a time (max. one LONGBLOB in memory).
 * - Resumable: each document is moved in its own transaction and its content
 *   is set to NULL afterwards; a re-run continues with the remaining documents.
 * - The column is only dropped when no document with content is left.
 */
class Version20261116MoveDocumentChunksMigration extends AbstractMigration
{
    private const DOCUMENTS = 'tl_genai_documents';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function getName(): string
    {
        return 'GenAI: move tl_genai_documents.content into tl_genai_documents_chunks';
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist([self::DOCUMENTS])) {
            return false;
        }

        return isset($schemaManager->listTableColumns(self::DOCUMENTS)['content']);
    }

    public function run(): MigrationResult
    {
        $this->createChunksTable();

        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM ' . self::DOCUMENTS . " WHERE content IS NOT NULL AND content != '' ORDER BY id"
        );

        $documents = 0;
        $chunks = 0;
        $failed = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            $raw = $this->connection->fetchOne('SELECT content FROM ' . self::DOCUMENTS . ' WHERE id=?', [$id]);
            $data = \is_string($raw) ? @\unserialize($raw, ['allowed_classes' => false]) : false;

            if (!\is_array($data)) {
                // Keep the raw content, do not drop the column
                $failed[] = $id;
                continue;
            }

            $this->connection->beginTransaction();

            try {
                $chunks += DocumentChunks::save($id, $data, $this->connection);
                $this->connection->executeStatement('UPDATE ' . self::DOCUMENTS . ' SET content=NULL WHERE id=?', [$id]);
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();

                throw $e;
            }

            ++$documents;
            unset($raw, $data);
        }

        if ($failed) {
            return $this->createResult(
                false,
                \sprintf(
                    'Moved %d chunks of %d documents. Content of document(s) %s could not be unserialized; column tl_genai_documents.content was kept.',
                    $chunks,
                    $documents,
                    \implode(', ', $failed)
                )
            );
        }

        $this->connection->executeStatement('ALTER TABLE ' . self::DOCUMENTS . ' DROP COLUMN content');

        return $this->createResult(
            true,
            \sprintf('Moved %d chunks of %d documents into tl_genai_documents_chunks and dropped tl_genai_documents.content.', $chunks, $documents)
        );
    }

    /**
     * Contao runs migrations before the schema update, so the table has to be created here.
     * The definition matches dca/tl_genai_documents_chunks.php.
     */
    private function createChunksTable(): void
    {
        if ($this->connection->createSchemaManager()->tablesExist([DocumentChunks::TABLE])) {
            return;
        }

        $this->connection->executeStatement('
            CREATE TABLE ' . DocumentChunks::TABLE . ' (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                pid INT UNSIGNED DEFAULT 0 NOT NULL,
                tstamp INT UNSIGNED DEFAULT 0 NOT NULL,
                sorting INT UNSIGNED DEFAULT 0 NOT NULL,
                chunkNumber INT UNSIGNED DEFAULT 0 NOT NULL,
                content MEDIUMTEXT DEFAULT NULL,
                INDEX pid_sorting (pid, sorting),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ');
    }
}
