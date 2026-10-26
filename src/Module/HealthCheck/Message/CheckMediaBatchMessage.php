<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Message;

/**
 * Async-Auftrag: führe einen einzelnen Check-Typ aus (optional gefiltert nach
 * Media-IDs für gezielte Batches). `correlationId` verkettet UI-Trigger → Queue → Handler-Log.
 */
final class CheckMediaBatchMessage
{
    /**
     * @param list<string> $mediaIds Leer = alle Medien iterieren
     */
    public function __construct(
        public readonly string $checkType,
        public readonly array $mediaIds = [],
        public readonly ?string $correlationId = null,
    ) {
    }
}
