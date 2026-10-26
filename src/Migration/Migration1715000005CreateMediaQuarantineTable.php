<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Merkt sich, welche Medien gerade in Quarantäne liegen.
 *
 * **Warum diese Tabelle nötig ist:** Die Quarantäne lässt die `media`-Zeile beim Einlagern
 * stehen und vermerkt das Medium hier. Löschte sie die Zeilen mit, käme beim Wiederherstellen
 * nur die Datei zurück — das Medium wäre für Shopware verschwunden, und die Zählung meldete
 * trotzdem Erfolg.
 *
 * **Warum eine eigene Tabelle und kein Zusatzfeld am Medium:** `custom_fields` liegt bei
 * Shopware auf `media_translation`, also je Sprache. Eine Einlagerung ist aber keine
 * übersetzbare Eigenschaft — sie gälte sonst in einer Sprache und in der anderen nicht.
 *
 * **Verhältnis zum Manifest:** Das Manifest auf der Platte bleibt das Tagebuch des Laufs — es
 * überlebt einen Absturz mitten im Verschieben und weiß, welche Datei wohin gehört. Diese
 * Tabelle ist die andere Hälfte: Sie sagt der Datenbank, dass dieses Medium eingelagert ist,
 * damit der nächste Durchlauf es nicht erneut aufgreift und die Oberfläche es benennen kann.
 */
final class Migration1715000005CreateMediaQuarantineTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1715000005;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `rc_media_quarantine` (
                `media_id` BINARY(16) NOT NULL,
                `run_id` VARCHAR(64) NOT NULL,
                `original_path` VARCHAR(1024) NOT NULL,
                `quarantined_path` VARCHAR(1024) NULL,
                `quarantined_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`media_id`),
                KEY `idx_run` (`run_id`),
                KEY `idx_quarantined_at` (`quarantined_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Die Tabelle hält den Rückweg für eingelagerte Medien. Sie zu leeren hieße, ihn zu
        // verlieren — auch wenn das Plugin deinstalliert wird.
    }
}
