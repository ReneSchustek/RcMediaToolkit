<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use Generator;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;

/**
 * Findet Vorschaubilder, deren Datei fehlt, obwohl der Eintrag steht.
 *
 * Wirkt sich anders aus als ein fehlendes Original: Die Produktseite liefert weiter ein
 * Bild — nur eben keins in der passenden Größe, sondern gar keins an dieser Stelle des
 * `srcset`. Je nach Bildschirmbreite sehen also manche Besucher etwas und andere nicht.
 *
 * Der Block ist mit 500 größer als bei den anderen Prüfungen: Zu einem Medium gehören
 * mehrere Vorschaubilder, die Tabelle ist entsprechend länger.
 */
final class BrokenThumbnailCheck implements MediaHealthCheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemAdapterInterface $filesystem,
    ) {
    }

    public function type(): string
    {
        return 'broken_thumbnail';
    }

    public function configKey(): string
    {
        return 'RcMediaToolkit.config.healthCheckBrokenThumbnails';
    }

    public function run(): Generator
    {
        $offset = 0;
        $batchSize = 500;

        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(mt.id)) AS thumbnail_id, LOWER(HEX(mt.media_id)) AS media_id, '
                . 'mt.width, mt.height, mt.path '
                . 'FROM media_thumbnail mt ORDER BY mt.id LIMIT :limit OFFSET :offset',
                ['limit' => $batchSize, 'offset' => $offset],
                ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $path = is_string($row['path'] ?? null) && $row['path'] !== '' ? $row['path'] : null;
                if ($path === null || !$this->filesystem->exists($path)) {
                    yield new Finding(
                        checkType: $this->type(),
                        severity: Finding::SEVERITY_WARNING,
                        sourceType: 'media_thumbnail',
                        mediaId: (string) $row['media_id'],
                        sourceReference: 'thumbnail:' . $row['thumbnail_id'],
                        urlPath: $path,
                        errorDetail: [
                            'width' => (int) ($row['width'] ?? 0),
                            'height' => (int) ($row['height'] ?? 0),
                            'reason' => $path === null ? 'no_path_in_db' : 'file_not_found',
                        ],
                    );
                }
            }

            $offset += $batchSize;
        } while (count($rows) === $batchSize);
    }
}
