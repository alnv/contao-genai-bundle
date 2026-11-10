<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Creates tl_genai_index table for queue-based indexing
 */
class Version20241109CreateIndexQueueMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();
        return !$schemaManager->tablesExist(['tl_genai_index']);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement("
            CREATE TABLE tl_genai_index (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tstamp INT UNSIGNED NOT NULL DEFAULT 0,
                
                -- Source identification
                source_type VARCHAR(32) NOT NULL DEFAULT 'page' COMMENT 'Only page type supported',
                source_table VARCHAR(64) NOT NULL DEFAULT '',
                source_id INT UNSIGNED NOT NULL DEFAULT 0,
                source_url VARCHAR(500) NOT NULL DEFAULT '',
                
                -- Queue status
                status VARCHAR(16) NOT NULL DEFAULT 'pending' COMMENT 'pending, processing, completed, failed, deleted',
                priority TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1=highest, 10=lowest',
                
                -- Change detection
                content_hash VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'xxh3 or sha256 of cleaned content',
                previous_hash VARCHAR(64) NOT NULL DEFAULT '',
                
                -- Processing info
                processed_at INT UNSIGNED NOT NULL DEFAULT 0,
                retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_retries TINYINT UNSIGNED NOT NULL DEFAULT 3,
                error_message TEXT NULL,
                
                -- Collection reference
                collection_id INT UNSIGNED NOT NULL DEFAULT 0,
                
                -- Metadata
                language VARCHAR(5) NOT NULL DEFAULT '',
                root_page INT UNSIGNED NOT NULL DEFAULT 0,
                title VARCHAR(255) NOT NULL DEFAULT '',
                
                -- Indexes
                INDEX idx_status (status),
                INDEX idx_source (source_type, source_id),
                INDEX idx_url (source_url(191)),
                INDEX idx_collection (collection_id),
                INDEX idx_priority_status (priority, status),
                UNIQUE INDEX idx_unique_source (source_table, source_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        return $this->createResult(true, 'Created tl_genai_index queue table');
    }
}
