<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service;

use Doctrine\DBAL\Connection;
use Generator;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;

/**
 * Aggregiert Bestand: welche Medien haben WebP-Varianten, welche nicht.
 * Zustand wird zur Laufzeit aus dem Filesystem ermittelt — keine eigene Tabelle nötig.
 */
final class WebPRegistry
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemAdapterInterface $filesystem,
    ) {
    }

    /**
     * @return array{total: int, withWebp: int, withoutWebp: int, eligible: int}
     */
    public function stats(int $sampleLimit = 1000): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM media WHERE file_name IS NOT NULL',
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, file_extension, path '
            . 'FROM media WHERE file_name IS NOT NULL AND file_extension IS NOT NULL '
            . 'ORDER BY id LIMIT :limit',
            ['limit' => $sampleLimit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        $withWebp = 0;
        $eligible = 0;
        foreach ($rows as $row) {
            $extension = strtolower((string) $row['file_extension']);
            if (!in_array($extension, ['jpeg', 'jpg', 'png'], true)) {
                continue;
            }
            $eligible++;
            $path = is_string($row['path'] ?? null) && $row['path'] !== '' ? (string) $row['path'] : null;
            if ($path !== null && $this->filesystem->exists($path . '.webp')) {
                $withWebp++;
            }
        }

        return [
            'total' => $total,
            'withWebp' => $withWebp,
            'withoutWebp' => max(0, $eligible - $withWebp),
            'eligible' => $eligible,
        ];
    }

    /**
     * @return Generator<int, string> Quell-Pfade ohne WebP-Variante
     */
    public function pathsMissingWebp(int $limit = 100): Generator
    {
        $yielded = 0;
        $offset = 0;
        $batch = 200;

        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(id)) AS id, file_extension, path '
                . 'FROM media WHERE file_name IS NOT NULL AND file_extension IS NOT NULL '
                . 'ORDER BY id LIMIT :limit OFFSET :offset',
                ['limit' => $batch, 'offset' => $offset],
                ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $extension = strtolower((string) $row['file_extension']);
                if (!in_array($extension, ['jpeg', 'jpg', 'png'], true)) {
                    continue;
                }
                $path = is_string($row['path'] ?? null) && $row['path'] !== '' ? (string) $row['path'] : null;
                if ($path === null) {
                    continue;
                }
                if ($this->filesystem->exists($path . '.webp')) {
                    continue;
                }
                yield $path;
                if (++$yielded >= $limit) {
                    return;
                }
            }

            $offset += $batch;
        } while (count($rows) === $batch);
    }
}
