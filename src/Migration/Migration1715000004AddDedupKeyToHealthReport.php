<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Fügt eine berechnete `dedup_key`-Spalte zur Health-Report-Tabelle hinzu, um
 * INSERT … ON DUPLICATE KEY UPDATE atomar zu unterstützen.
 *
 * Hintergrund: der ursprüngliche UNIQUE-Index `(check_type, media_id, source_reference)`
 * leidet daran, dass MySQL NULL-Werte als „verschieden" behandelt. Bei async Workers mit
 * Concurrency > 1 entstehen Duplikate für `media_id = NULL`-Findings. Die neue
 * `dedup_key`-Spalte ist NOT NULL und kombiniert die drei Bestandteile mit Sentinel-
 * Strings für NULL — damit greift die UNIQUE-Constraint zuverlässig.
 */
final class Migration1715000004AddDedupKeyToHealthReport extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1715000004;
    }

    public function update(Connection $connection): void
    {
        // 1. Spalte hinzufügen (NULL erlauben für Migration bestehender Rows).
        $connection->executeStatement(
            'ALTER TABLE `rc_media_health_report` '
            . "ADD COLUMN IF NOT EXISTS `dedup_key` VARCHAR(320) NULL AFTER `source_reference`",
        );

        // 2. Bestehende Rows befüllen — berechnet aus check_type, media_id-Hex, source_reference.
        $connection->executeStatement(
            'UPDATE `rc_media_health_report` '
            . "SET `dedup_key` = CONCAT("
            . "    `check_type`, '|', "
            . "    IFNULL(LOWER(HEX(`media_id`)), '_NULL_'), '|', "
            . "    IFNULL(`source_reference`, '_NULL_')"
            . ") "
            . 'WHERE `dedup_key` IS NULL',
        );

        // 3. Auf NOT NULL umstellen, alten Unique-Index droppen, neuen darauf legen.
        $connection->executeStatement(
            'ALTER TABLE `rc_media_health_report` '
            . 'MODIFY COLUMN `dedup_key` VARCHAR(320) NOT NULL',
        );

        // Alter Unique-Index droppen (idempotent über IF EXISTS-Workaround).
        $this->dropPluginIndexIfExists($connection, 'rc_media_health_report', 'uniq_finding');

        // Neuen Unique-Index auf dedup_key anlegen — idempotent.
        $exists = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index',
            ['table' => 'rc_media_health_report', 'index' => 'uniq_dedup_key'],
        );
        if ($exists === 0) {
            $connection->executeStatement(
                'ALTER TABLE `rc_media_health_report` ADD UNIQUE KEY `uniq_dedup_key` (`dedup_key`)',
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // dedup_key ist Compute-Spalte aus existierenden Daten — kein destruktiver Schritt nötig.
        unset($connection);
    }

    private function dropPluginIndexIfExists(Connection $connection, string $table, string $index): void
    {
        $exists = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index',
            ['table' => $table, 'index' => $index],
        );
        if ($exists > 0) {
            $connection->executeStatement(sprintf(
                'ALTER TABLE %s DROP INDEX %s',
                $connection->quoteIdentifier($table),
                $connection->quoteIdentifier($index),
            ));
        }
    }
}
