<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Der tägliche Termin der Medienprüfung.
 *
 * Täglich, weil sie nur liest und ihre Befunde die Grundlage für alles Weitere sind. Ein
 * Bild, das heute verschwindet, soll nicht erst nächste Woche auffallen.
 */
final class HealthCheckScanTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_media_toolkit.health_check_scan';
    }

    public static function getDefaultInterval(): int
    {
        // Default: täglich
        return 24 * 60 * 60;
    }
}
