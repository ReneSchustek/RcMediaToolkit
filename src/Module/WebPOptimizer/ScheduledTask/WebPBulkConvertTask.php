<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Der Viertelstunden-Termin der Umwandlung.
 *
 * Kurz getaktet und klein portioniert statt selten und groß: So arbeitet sich der Bestand
 * im Hintergrund ab, ohne dass ein einzelner Lauf die Instanz belegt.
 */
final class WebPBulkConvertTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_media_toolkit.webp_bulk_convert';
    }

    public static function getDefaultInterval(): int
    {
        // Default: alle 15 Minuten
        return 15 * 60;
    }
}
