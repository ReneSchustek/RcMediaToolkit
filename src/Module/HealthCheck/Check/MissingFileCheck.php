<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use Generator;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;

/**
 * Findet Medien, die in der Datenbank stehen, deren Datei es aber nicht gibt.
 *
 * Der häufigste Fall aus der Praxis: eingespielte Datenbanksicherung ohne die zugehörigen
 * Dateien. Im Shop äußert sich das als leerer Bilderrahmen — ohne Fehlermeldung, ohne
 * Logeintrag.
 *
 * Gelesen wird in Blöcken zu 200 mit `LIMIT/OFFSET` statt in einem Zug: Die Abfrage liefert
 * sonst den ganzen Medienbestand auf einmal.
 */
final class MissingFileCheck implements MediaHealthCheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemAdapterInterface $filesystem,
    ) {
    }

    public function type(): string
    {
        return 'missing_file';
    }

    public function configKey(): string
    {
        return 'RcMediaToolkit.config.healthCheckMissingFiles';
    }

    public function run(): Generator
    {
        $offset = 0;
        $batchSize = 200;

        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(id)) AS id, file_name, file_extension, path '
                . 'FROM media WHERE file_name IS NOT NULL ORDER BY id LIMIT :limit OFFSET :offset',
                ['limit' => $batchSize, 'offset' => $offset],
                ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $path = $this->resolvePath($row);
                if ($path === null) {
                    continue;
                }
                if (!$this->filesystem->exists($path)) {
                    yield new Finding(
                        checkType: $this->type(),
                        severity: Finding::SEVERITY_CRITICAL,
                        sourceType: 'media_table',
                        mediaId: (string) $row['id'],
                        sourceReference: 'media:' . $row['id'],
                        urlPath: $path,
                        errorDetail: ['reason' => 'file_not_found'],
                    );
                }
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
