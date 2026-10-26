<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Health-Check-Findings werden idempotent über (check_type, media_id, source_reference) deduped.
 * Auto-Resolve setzt resolved_at, sodass historische Reports erhalten bleiben.
 */
final class Migration1715000001CreateMediaHealthReportTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1715000001;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `rc_media_health_report` (
                `id` BINARY(16) NOT NULL,
                `media_id` BINARY(16) NULL,
                `check_type` VARCHAR(64) NOT NULL,
                `severity` VARCHAR(16) NOT NULL DEFAULT "warning",
                `source_type` VARCHAR(32) NOT NULL,
                `source_reference` VARCHAR(255) NULL,
                `error_detail` JSON NULL,
                `url_path` VARCHAR(1024) NULL,
                `first_seen_at` DATETIME(3) NOT NULL,
                `last_seen_at` DATETIME(3) NOT NULL,
                `resolved_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_finding` (`check_type`, `media_id`, `source_reference`),
                KEY `idx_open_findings` (`severity`, `resolved_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Destructive nicht implementiert — Findings sind historisch wertvoll.
    }
}
