<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Repräsentiert einen Verwaisten-Kandidaten — entweder ein DB-Eintrag ohne
 * Referenz oder eine Datei ohne DB-Eintrag (Filesystem-Waise).
 */
final class OrphanedMedia
{
    public const SOURCE_DB_ORPHAN = 'db_orphan';
    public const SOURCE_FS_ORPHAN = 'filesystem_orphan';

    public function __construct(
        public readonly ?string $mediaId,
        public readonly string $path,
        public readonly int $size,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $fileName,
        public readonly string $sourceType,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mediaId' => $this->mediaId,
            'path' => $this->path,
            'size' => $this->size,
            'createdAt' => $this->createdAt->format(DateTimeInterface::ATOM),
            'fileName' => $this->fileName,
            'sourceType' => $this->sourceType,
        ];
    }
}
