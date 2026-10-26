<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;

/**
 * Phase 1 — Indexing. Sammelt alle Media-IDs, die irgendwo im Shop referenziert werden,
 * in eine UsageMap. Out-of-Scope: die `media`-Tabelle selbst (Bestandsliste).
 *
 * Welche Quellen gescannt werden, bestimmt `MediaReferenceSources` aus dem Schema:
 * jeder Fremdschlüssel auf `media.id` (Kern und Plugins), dazu die Text-/JSON-Spalten ohne
 * Fremdschlüssel und alle `custom_fields`-Spalten.
 *
 * Nicht abgedeckt: Medien-IDs, die weder per Fremdschlüssel noch in einer der bekannten
 * Text-/JSON-Spalten stehen — etwa in einer plugin-eigenen Tabelle mit eigener Spalte ohne
 * Fremdschlüssel.
 *
 * Performance:
 *  - Cursor-Iteration (LIMIT/OFFSET in Batches von 1000) statt Voll-Load pro Spalte.
 *  - Speicher-Warnung bei großen UsageMaps — dann auf TempTable-Spilling umstellen.
 */
final class MediaUsageIndexer
{
    private const UUID_HEX_PATTERN = '[0-9a-f]{32}';
    private const BATCH_SIZE = 1000;
    private const MEMORY_WARN_THRESHOLD = 1_000_000;

    public function __construct(
        private readonly Connection $connection,
        private readonly MediaToolkitLogger $logger,
        private readonly MediaReferenceSources $referenceSources,
    ) {
    }

    /**
     * @throws DbalException
     */
    public function buildUsageMap(?UsageMapInterface $map = null): UsageMapInterface
    {
        $usage = $map ?? new InMemoryUsageMap();
        $logger = $this->logger->withModule('GarbageCollector');

        foreach ($this->referenceSources->foreignKeyColumns() as $source) {
            $this->collectFromBinaryColumn($usage, $source['table'], $source['column']);
        }

        foreach ($this->referenceSources->textColumns() as $source) {
            $this->collectFromTextColumn($usage, $source['table'], $source['column']);
        }

        foreach ($this->referenceSources->customFieldsTables() as $table) {
            $this->collectFromTextColumn($usage, $table, 'custom_fields');
        }

        if ($usage->size() > self::MEMORY_WARN_THRESHOLD) {
            $logger->warning('UsageMap ist sehr groß — TempTable-Spilling empfohlen', [
                'usageSize' => $usage->size(),
                'threshold' => self::MEMORY_WARN_THRESHOLD,
            ]);
        }

        return $usage;
    }

    /**
     * @throws DbalException
     */
    private function collectFromBinaryColumn(UsageMapInterface $usage, string $table, string $column): void
    {
        $quotedTable = $this->connection->quoteIdentifier($table);
        $quotedColumn = $this->connection->quoteIdentifier($column);

        // Cursor-Iteration: nicht alle FK-Werte auf einmal laden, sondern in Batches.
        $offset = 0;
        do {
            $sql = sprintf(
                'SELECT LOWER(HEX(%s)) AS id FROM %s WHERE %s IS NOT NULL '
                . 'ORDER BY %s LIMIT :limit OFFSET :offset',
                $quotedColumn,
                $quotedTable,
                $quotedColumn,
                $quotedColumn,
            );
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative($sql, [
                'limit' => self::BATCH_SIZE,
                'offset' => $offset,
            ], [
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
                'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
            ]);

            foreach ($rows as $row) {
                $id = $row['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $usage->add($id);
                }
            }

            $offset += self::BATCH_SIZE;
        } while (count($rows) === self::BATCH_SIZE);
    }

    /**
     * @throws DbalException
     */
    private function collectFromTextColumn(UsageMapInterface $usage, string $table, string $column): void
    {
        $quotedTable = $this->connection->quoteIdentifier($table);
        $quotedColumn = $this->connection->quoteIdentifier($column);

        $offset = 0;
        do {
            $sql = sprintf(
                'SELECT %s AS payload FROM %s WHERE %s IS NOT NULL '
                . 'ORDER BY %s LIMIT :limit OFFSET :offset',
                $quotedColumn,
                $quotedTable,
                $quotedColumn,
                $quotedColumn,
            );
            /** @var list<array{payload: ?string}> $rows */
            $rows = $this->connection->fetchAllAssociative($sql, [
                'limit' => self::BATCH_SIZE,
                'offset' => $offset,
            ], [
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
                'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
            ]);

            foreach ($rows as $row) {
                $this->extractUuidsFromBlob($row['payload'] ?? null, $usage);
            }

            $offset += self::BATCH_SIZE;
        } while (count($rows) === self::BATCH_SIZE);
    }

    private function extractUuidsFromBlob(?string $blob, UsageMapInterface $usage): void
    {
        if ($blob === null || $blob === '') {
            return;
        }

        if (preg_match_all('/' . self::UUID_HEX_PATTERN . '/', $blob, $matches) === false) {
            return;
        }

        foreach ($matches[0] as $match) {
            $usage->add(strtolower($match));
        }
    }

}
