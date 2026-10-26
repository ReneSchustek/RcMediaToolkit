<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\Repair\Dto;

use InvalidArgumentException;

/**
 * Vorschlag zur Reparatur eines Findings. Mehrere Vorschläge pro Finding sind möglich;
 * die manuelle Bestätigung wählt einen aus. Confidence (0.0–1.0) hilft beim Sortieren.
 */
final class RepairProposal
{
    public const TYPE_RELINK_BY_NAME = 'relink_by_name';
    public const TYPE_REGENERATE_THUMBNAIL = 'regenerate_thumbnail';
    public const TYPE_REWRITE_CMS_REFERENCE = 'rewrite_cms_reference';

    /**
     * @param array<string, mixed> $payload Implementations-spezifische Daten
     *                                      (z. B. Ziel-Media-ID, Distanz, Score).
     */
    public function __construct(
        public readonly string $proposalType,
        public readonly string $description,
        public readonly float $confidence,
        public readonly array $payload,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('Confidence muss zwischen 0.0 und 1.0 liegen');
        }
    }
}
