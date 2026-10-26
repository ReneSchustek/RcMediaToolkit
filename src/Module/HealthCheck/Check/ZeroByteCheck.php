<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use Generator;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Throwable;

/**
 * Findet Dateien, die zwar da sind, aber nichts enthalten.
 *
 * Der tückischere Verwandte des fehlenden Bildes: Die Datei existiert, jede
 * Existenzprüfung ist zufrieden, und der Browser bekommt trotzdem nichts. Entsteht bei
 * abgebrochenen Übertragungen und vollgelaufenen Datenträgern.
 *
 * Geprüft wird gegen das Dateisystem, nicht gegen `media.file_size` — der Eintrag in der
 * Datenbank stammt vom Zeitpunkt des Hochladens und weiß von einem späteren Abbruch nichts.
 */
final class ZeroByteCheck implements MediaHealthCheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemAdapterInterface $filesystem,
    ) {
    }

    public function type(): string
    {
        return 'zero_byte';
    }

    public function configKey(): string
    {
        return 'RcMediaToolkit.config.healthCheckZeroByte';
    }

    public function run(): Generator
    {
        $offset = 0;
        $batchSize = 200;

        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(id)) AS id, file_name, file_extension, file_size, path '
                . 'FROM media WHERE file_name IS NOT NULL ORDER BY id LIMIT :limit OFFSET :offset',
                ['limit' => $batchSize, 'offset' => $offset],
                ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $path = $this->resolvePath($row);
                if ($path === null || !$this->filesystem->exists($path)) {
                    continue;
                }
                try {
                    $size = $this->filesystem->size($path);
                } catch (Throwable) {
                    continue;
                }

                if ($size !== 0) {
                    continue;
                }

                yield new Finding(
                    checkType: $this->type(),
                    severity: Finding::SEVERITY_CRITICAL,
                    sourceType: 'media_table',
                    mediaId: (string) $row['id'],
                    sourceReference: 'media:' . $row['id'],
                    urlPath: $path,
                    errorDetail: ['actualSize' => 0, 'declaredSize' => (int) ($row['file_size'] ?? 0)],
                );
            }

            $offset += $batchSize;
        } while (count($rows) === $batchSize);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolvePath(array $row): ?string
    {
        if (isset($row['path']) && is_string($row['path']) && $row['path'] !== '') {
            return $row['path'];
        }
        $name = is_string($row['file_name'] ?? null) ? $row['file_name'] : null;
        if ($name === null) {
            return null;
        }
        $ext = isset($row['file_extension']) && is_string($row['file_extension'])
            ? '.' . $row['file_extension']
            : '';
        return 'media/' . $name . $ext;
    }
}
