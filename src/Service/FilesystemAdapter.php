<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Service;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use RuntimeException;

/**
 * Default-Adapter auf Basis von Shopware-Flysystem (public-Filesystem für Medien).
 *
 * Shopware kennt mehrere Filesystem-Buckets (public, private, theme, asset, sitemap).
 * Hier wird zur Construction der relevante Operator injiziert — typischerweise
 * 'shopware.filesystem.public' für Media-Zugriff.
 */
final class FilesystemAdapter implements FilesystemAdapterInterface
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
    ) {
    }

    public function exists(string $path): bool
    {
        try {
            return $this->filesystem->fileExists($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    public function read(string $path): string
    {
        try {
            return $this->filesystem->read($path);
        } catch (FilesystemException $e) {
            throw new RuntimeException(sprintf('Konnte Datei "%s" nicht lesen', $path), 0, $e);
        }
    }

    public function write(string $path, string $contents): void
    {
        try {
            $this->filesystem->write($path, $contents);
        } catch (FilesystemException $e) {
            throw new RuntimeException(sprintf('Konnte Datei "%s" nicht schreiben', $path), 0, $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->filesystem->delete($path);
        } catch (FilesystemException $e) {
            throw new RuntimeException(sprintf('Konnte Datei "%s" nicht löschen', $path), 0, $e);
        }
    }

    public function size(string $path): int
    {
        try {
            return $this->filesystem->fileSize($path);
        } catch (FilesystemException $e) {
            throw new RuntimeException(sprintf('Konnte Größe von "%s" nicht ermitteln', $path), 0, $e);
        }
    }

    public function lastModified(string $path): int
    {
        try {
            return $this->filesystem->lastModified($path);
        } catch (FilesystemException $e) {
            throw new RuntimeException(sprintf('Konnte mtime von "%s" nicht ermitteln', $path), 0, $e);
        }
    }

    public function move(string $source, string $destination): void
    {
        try {
            $this->filesystem->move($source, $destination);
        } catch (FilesystemException $e) {
            throw new RuntimeException(
                sprintf('Konnte Datei "%s" nicht nach "%s" verschieben', $source, $destination),
                0,
                $e,
            );
        }
    }

    public function makeDirectory(string $path): void
    {
        try {
            $this->filesystem->createDirectory($path);
        } catch (FilesystemException $e) {
            throw new RuntimeException(sprintf('Konnte Verzeichnis "%s" nicht anlegen', $path), 0, $e);
        }
    }

    public function listContents(string $path, bool $recursive = false): array
    {
        try {
            $items = [];
            foreach ($this->filesystem->listContents($path, $recursive) as $item) {
                $items[] = $item->path();
            }
            return $items;
        } catch (FilesystemException $e) {
            throw new RuntimeException(sprintf('Konnte Verzeichnis "%s" nicht auflisten', $path), 0, $e);
        }
    }
}
