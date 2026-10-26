<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Der wöchentliche Termin des Aufräumers.
 *
 * Sieben Tage, nicht täglich: Der Lauf geht über den gesamten Medienbestand und ist die
 * teuerste Aufgabe des Plugins. Häufiger zu suchen brächte nichts — Waisen entstehen
 * langsam.
 */
final class MediaGarbageCollectorTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_media_toolkit.garbage_collector';
    }

    public static function getDefaultInterval(): int
    {
        // Default: wöchentlich
        return 7 * 24 * 60 * 60;
    }
}
