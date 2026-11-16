<?php

declare(strict_types=1);

namespace Alnv\GenaiBundle\Helpers;

use Contao\System;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Storage for document chunks (tl_genai_documents_chunks).
 *
 * Chunks used to live serialized in tl_genai_documents.content (LONGBLOB). That made every
 * "SELECT * FROM tl_genai_documents" (e.g. DC_Table::parentView) load all chunk texts.
 * Chunks are now stored one row per chunk; the public array format stays the same:
 *
 *     [['chunkNumber' => 1, 'formattedContent' => '...'], ...]
 */
class DocumentChunks
{
    public const TABLE = 'tl_genai_documents_chunks';

    /** Max rows per multi-row INSERT */
    private const INSERT_ROWS = 250;

    /** Max payload bytes per multi-row INSERT (stays well below max_allowed_packet) */
    private const INSERT_BYTES = 1048576;

    public static function save(int $documentId, array $chunks, ?Connection $connection = null): int
    {
        $connection ??= self::connection();
        $rows = self::normalize($chunks);
        $now = time();

        $connection->beginTransaction();

        try {
            $connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE pid=?', [$documentId]);

            $values = [];
            $params = [];
            $bytes = 0;

            foreach ($rows as $row) {
                $values[] = '(?,?,?,?,?)';
                \array_push($params, $documentId, $now, $row['sorting'], $row['chunkNumber'], $row['content']);
                $bytes += \strlen($row['content']);

                if (\count($values) >= self::INSERT_ROWS || $bytes >= self::INSERT_BYTES) {
                    self::insert($connection, $values, $params);
                    $values = $params = [];
                    $bytes = 0;
                }
            }

            if ($values) {
                self::insert($connection, $values, $params);
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        return \count($rows);
    }

    public static function load(int $documentId): array
    {
        $result = self::connection()->executeQuery(
            'SELECT chunkNumber, content FROM ' . self::TABLE . ' WHERE pid=? ORDER BY sorting, id',
            [$documentId]
        );

        $chunks = [];

        while (false !== ($row = $result->fetchAssociative())) {
            $chunks[] = [
                'chunkNumber' => (int) $row['chunkNumber'],
                'formattedContent' => (string) $row['content'],
            ];
        }

        return $chunks;
    }

    public static function loadChunkNumbers(int $documentId): array
    {
        return \array_map(
            'intval',
            self::connection()->fetchFirstColumn(
                'SELECT chunkNumber FROM ' . self::TABLE . ' WHERE pid=? ORDER BY sorting, id',
                [$documentId]
            )
        );
    }

    public static function count(int $documentId): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE pid=?', [$documentId]);
    }

    public static function delete(int ...$documentIds): int
    {
        $documentIds = \array_values(\array_unique(\array_filter($documentIds)));

        if (!$documentIds) {
            return 0;
        }

        return (int) self::connection()->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE pid IN (?)',
            [$documentIds],
            [ArrayParameterType::INTEGER]
        );
    }

    public static function deleteByDocumentUrl(string $url): int
    {
        return (int) self::connection()->executeStatement(
            'DELETE c FROM ' . self::TABLE . ' c INNER JOIN tl_genai_documents d ON d.id=c.pid WHERE d.url=?',
            [$url]
        );
    }

    public static function deleteOrphans(): int
    {
        return (int) self::connection()->executeStatement(
            'DELETE c FROM ' . self::TABLE . ' c LEFT JOIN tl_genai_documents d ON d.id=c.pid WHERE d.id IS NULL'
        );
    }

    public static function deleteAll(): void
    {
        self::connection()->executeStatement('DELETE FROM ' . self::TABLE);
    }

    public static function markDocumentForSync(int $documentId): void
    {
        if ($documentId < 1) {
            return;
        }

        self::connection()->executeStatement(
            'UPDATE tl_genai_documents SET synced_at=0, tstamp=?, updated_at=? WHERE id=?',
            [time(), time(), $documentId]
        );
    }

    public static function normalize(array $chunks): array
    {
        $rows = [];
        $position = 0;

        foreach (\array_values($chunks) as $index => $chunk) {
            if (\is_object($chunk)) {
                $chunk = (array) $chunk;
            }

            if (\is_array($chunk)) {
                $content = (string) ($chunk['formattedContent'] ?? ($chunk['content'] ?? ''));
                $number = $chunk['chunkNumber'] ?? ($index + 1);
            } elseif (\is_scalar($chunk)) {
                $content = (string) $chunk;
                $number = $index + 1;
            } else {
                continue;
            }

            $rows[] = [
                'sorting' => ++$position,
                'chunkNumber' => \is_numeric($number) ? (int) $number : ($index + 1),
                'content' => $content,
            ];
        }

        return $rows;
    }

    private static function insert(Connection $connection, array $values, array $params): void
    {
        $connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (pid, tstamp, sorting, chunkNumber, content) VALUES ' . \implode(',', $values),
            $params
        );
    }

    private static function connection(): Connection
    {
        return System::getContainer()->get('database_connection');
    }
}
