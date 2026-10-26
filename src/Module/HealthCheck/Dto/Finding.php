<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto;

use InvalidArgumentException;

/**
 * Repräsentiert ein einzelnes Health-Check-Finding. Wird vom Runner persistent
 * über (checkType, mediaId, sourceReference) als Unique-Tupel dedupliziert.
 */
final class Finding
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    /**
     * @param array<string, mixed> $errorDetail
     */
    public function __construct(
        public readonly string $checkType,
        public readonly string $severity,
        public readonly string $sourceType,
        public readonly ?string $mediaId = null,
        public readonly ?string $sourceReference = null,
        public readonly ?string $urlPath = null,
        public readonly array $errorDetail = [],
    ) {
        if (!in_array($severity, [self::SEVERITY_CRITICAL, self::SEVERITY_WARNING, self::SEVERITY_INFO], true)) {
            throw new InvalidArgumentException(sprintf('Unbekannte Severity: %s', $severity));
        }
    }
}
