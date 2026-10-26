<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use Generator;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Throwable;

/**
 * Findet Dateien im Media-Verzeichnis, die keinen passenden DB-Eintrag haben.
 * Read-only — Reparatur erfolgt manuell (siehe GarbageCollector oder Repair-Modul).
 *
 * Memory-Strategie: statt alle bekannten Pfade im Vorab in einen Set zu laden, werden
 * Filesystem-Pfade in Batches gegen die DB geprüft. Pro Batch eine IN-Query.
 * Dadurch konstanter Memory-Footprint auch bei Stores mit Millionen Medien.
 */
final class OrphanedFileCheck implements MediaHealthCheckInterface
{
    private const SCAN_ROOT = 'media';
    private const MAX_FILES_PER_RUN = 5000;
    private const LOOKUP_BATCH_SIZE = 200;

    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemAdapterInterface $filesystem,
    ) {
    }

    public function type(): string
    {
        return 'orphaned_file';
    }

    public function configKey(): string
    {
        return 'RcMediaToolkit.config.healthCheckOrphanedFiles';
    }

    public function run(): Generator
    {
        try {
            $contents = $this->filesystem->listContents(self::SCAN_ROOT, true);
        } catch (Throwable) {
            return;
        }

        $batch = [];
        $scanned = 0;

        foreach ($contents as $path) {
            if ($scanned >= self::MAX_FILES_PER_RUN) {
                break;
            }
            // Thumbnails liegen unter media/.../thumbnail/... — separat im BrokenThumbnailCheck.
            if (str_contains($path, '/thumbnail/')) {
                continue;
            }
            $scanned++;
            $batch[] = $path;

            if (count($batch) >= self::LOOKUP_BATCH_SIZE) {
                yield from $this->yieldOrphansForBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield from $this->yieldOrphansForBatch($batch);
        }
    }

    /**
     * @param list<string> $paths
     * @return Generator<int, Finding>
     */
    private function yieldOrphansForBatch(array $paths): Generator
    {
        $known = $this->lookupKnownPaths($paths);

        foreach ($paths as $path) {
            if (isset($known[$path])) {
                continue;
            }
            yield new Finding(
                checkType: $this->type(),
                severity: Finding::SEVERITY_WARNING,
                sourceType: 'filesystem',
                sourceReference: 'fs:' . $path,
                urlPath: $path,
                errorDetail: ['reason' => 'no_db_entry'],
            );
        }
    }

    /**
     * Pro Batch zwei IN-Queries: erst direkte `path`-Spalte, dann Builder-Fallback
     * über `file_name`+`file_extension`. Konstanter Memory-Footprint.
     *
     * @param list<string> $paths
     * @return array<string, true>
     */
    private function lookupKnownPaths(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $known = [];

        $placeholders = [];
        $params = [];
        foreach (array_values(array_unique($paths)) as $i => $path) {
            $key = 'p_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $path;
        }

        $sql = 'SELECT path FROM media WHERE path IN (' . implode(',', $placeholders) . ')';
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        foreach ($rows as $row) {
            $rowPath = $row['path'] ?? null;
            if (is_string($rowPath)) {
                $known[$rowPath] = true;
            }
        }

        $missing = array_values(array_filter($paths, static fn (string $p): bool => !isset($known[$p])));
        if ($missing !== []) {
            $known = array_merge($known, $this->lookupByFileName($missing));
        }

        return $known;
    }

    /**
     * Fallback: Shopware berechnet Pfade aus `file_name`+`file_extension`.
     *
     * @param list<string> $paths
     * @return array<string, true>
     */
    private function lookupByFileName(array $paths): array
    {
        $candidates = [];
        foreach ($paths as $path) {
            if (!str_starts_with($path, 'media/')) {
                continue;
            }
            $remainder = substr($path, strlen('media/'));
            $dotPos = strrpos($remainder, '.');
            $fileName = $dotPos !== false ? substr($remainder, 0, $dotPos) : $remainder;
            $candidates[$fileName] = $path;
        }
        if ($candidates === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_keys($candidates) as $i => $name) {
            $key = 'n_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $name;
        }

        $sql = 'SELECT file_name, file_extension FROM media WHERE file_name IN ('
            . implode(',', $placeholders) . ')';
        /** @var list<array{file_name: string, file_extension: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $known = [];
        foreach ($rows as $row) {
            $rebuilt = 'media/' . $row['file_name']
                . (isset($row['file_extension']) ? '.' . $row['file_extension'] : '');
            $known[$rebuilt] = true;
        }
        return $known;
    }
}
