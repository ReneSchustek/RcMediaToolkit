<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service;

use Doctrine\DBAL\Connection;
use Generator;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

/**
 * Aggregiert Bestand: welche Medien haben WebP-Varianten, welche nicht.
 * Zustand wird zur Laufzeit aus dem Filesystem ermittelt — keine eigene Tabelle nötig.
 */
final class WebPRegistry
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemAdapterInterface $filesystem,
        private readonly ?SystemConfigService $systemConfigService = null,
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
     * Bestand der Vorschaubilder öffentlicher Medien.
     *
     * Die Seiten zeigen fast nur Vorschaubilder: Galerie, Kacheln und Schieber holen sich ihre
     * Größe aus dem `srcset`. Ohne diese Zahl sähe der Bestand fertig aus, obwohl kein einziges
     * Bild auf der Seite als WebP ankommt.
     *
     * @return array{total: int, withWebp: int, withoutWebp: int, eligible: int}
     */
    public function thumbnailStats(int $sampleLimit = 1000): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM media_thumbnail mt INNER JOIN media m ON m.id = mt.media_id WHERE m.private = 0',
        );

        $eligible = 0;
        $withWebp = 0;
        foreach ($this->thumbnailPaths($sampleLimit, 0) as $path) {
            if (!$this->isConvertible($path)) {
                continue;
            }
            $eligible++;
            if ($this->filesystem->exists($path . '.webp')) {
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
     * Was noch umzuwandeln ist, zuerst die Vorschaubilder, dann die Originale.
     *
     * Die Vorschaubilder kommen zuerst, weil nur sie auf den Seiten ankommen; ein Massenlauf in
     * Portionen bringt so von der ersten Portion an etwas.
     *
     * In die Liste kommt nur, was die Umwandlung auch bearbeitet: Die Quelldatei muss da sein und
     * die Mindestgröße erreichen. Stünde ein übergangener Pfad darin, bekäme jede Portion dieselben
     * Pfade wieder, und ein Massenlauf in Blöcken käme nie über den ersten Block hinaus.
     *
     * @return Generator<int, string> Quell-Pfade ohne WebP-Variante
     */
    public function pathsMissingWebp(int $limit = 100): Generator
    {
        $yielded = 0;
        $offset = 0;
        $batch = 200;
        do {
            $paths = $this->thumbnailPaths($batch, $offset);
            foreach ($paths as $path) {
                if (!$this->isConvertible($path) || !$this->needsConversion($path)) {
                    continue;
                }
                yield $path;
                if (++$yielded >= $limit) {
                    return;
                }
            }
            $offset += $batch;
        } while (count($paths) === $batch);

        foreach ($this->originalPathsMissingWebp($limit - $yielded) as $path) {
            yield $path;
        }
    }

    /**
     * @return list<string>
     */
    private function thumbnailPaths(int $limit, int $offset): array
    {
        /** @var list<string> $paths */
        $paths = array_values(array_filter(
            $this->connection->fetchFirstColumn(
                'SELECT mt.path FROM media_thumbnail mt INNER JOIN media m ON m.id = mt.media_id '
                . 'WHERE m.private = 0 AND mt.path IS NOT NULL ORDER BY mt.id LIMIT :limit OFFSET :offset',
                ['limit' => $limit, 'offset' => $offset],
                ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
            ),
            static fn (mixed $path): bool => is_string($path) && $path !== '',
        ));

        return $paths;
    }

    private function needsConversion(string $path): bool
    {
        if ($this->filesystem->exists($path . '.webp') || !$this->filesystem->exists($path)) {
            return false;
        }

        $minKb = (int) ($this->systemConfigService?->get('RcMediaToolkit.config.webpMinFileSizeKb') ?? 0);
        if ($minKb <= 0) {
            return true;
        }

        try {
            return $this->filesystem->size($path) >= $minKb * 1024;
        } catch (Throwable) {
            return false;
        }
    }

    private function isConvertible(string $path): bool
    {
        return in_array(strtolower((string) pathinfo($path, \PATHINFO_EXTENSION)), ['jpeg', 'jpg', 'png'], true);
    }

    /**
     * @return Generator<int, string>
     */
    private function originalPathsMissingWebp(int $limit): Generator
    {
        if ($limit <= 0) {
            return;
        }
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
                if ($path === null || !$this->needsConversion($path)) {
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
