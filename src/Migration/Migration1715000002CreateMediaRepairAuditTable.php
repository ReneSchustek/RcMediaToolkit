<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Audit-Log für Smart-Fix-Reparaturen. Insert-only — jede Reparatur erzeugt eine
 * unveränderliche Zeile mit before/after-State für späteren Rollback.
 */
final class Migration1715000002CreateMediaRepairAuditTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1715000002;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `rc_media_repair_audit` (
                `id` BINARY(16) NOT NULL,
                `finding_id` BINARY(16) NULL,
                `proposal_type` VARCHAR(64) NOT NULL,
                `before_state` JSON NOT NULL,
                `after_state` JSON NOT NULL,
                `applied_by` VARCHAR(255) NOT NULL,
                `applied_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_finding` (`finding_id`),
                KEY `idx_applied_at` (`applied_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Audit ist unveränderlich — kein destruktives Update.
    }
}
