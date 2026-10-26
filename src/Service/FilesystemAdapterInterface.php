<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Service;

/**
 * Abstraktion über Filesystem-Operationen — kapselt sowohl lokale Pfade als auch
 * Shopware-Flysystem-Adapter (S3/CDN). Sub-Module benutzen ausschließlich dieses Interface,
 * damit Storage-Wechsel ohne Code-Änderungen in den Modulen möglich sind.
 */
interface FilesystemAdapterInterface
{
    public function exists(string $path): bool;

    public function read(string $path): string;

    public function write(string $path, string $contents): void;

    public function delete(string $path): void;

    public function size(string $path): int;

    public function lastModified(string $path): int;

    /**
     * Atomarer Move auf gleichem Storage. Fällt auf copy+delete zurück, wenn
     * der Adapter kein atomares Rename unterstützt.
     */
    public function move(string $source, string $destination): void;

    public function makeDirectory(string $path): void;

    /**
     * @return list<string>
     */
    public function listContents(string $path, bool $recursive = false): array;
}
