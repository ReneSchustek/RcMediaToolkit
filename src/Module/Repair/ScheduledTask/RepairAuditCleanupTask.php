<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\Repair\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Der tägliche Termin, an dem alte Einträge der Reparatur-Prüfspur wegfallen.
 */
final class RepairAuditCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_media_toolkit.repair_audit_cleanup';
    }

    public static function getDefaultInterval(): int
    {
        // Default: täglich
        return 24 * 60 * 60;
    }
}
