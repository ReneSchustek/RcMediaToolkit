<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use Generator;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Throwable;

/**
 * Findet Dateien, deren tatsächlicher Inhalt nicht zu ihrem eingetragenen Typ passt.
 *
 * Entsteht beim Umbenennen von Endungen und bei Ausfuhren aus fremden Systemen. Die Folge
 * ist selten ein Fehler, meist etwas Unauffälligeres: Der Browser bekommt einen Typ genannt,
 * dem der Inhalt nicht entspricht, und entscheidet selbst, was er tut — je nach Browser
 * verschieden.
 *
 * Erkannt wird am Dateikopf, nicht an der Endung. Die Endung ist genau die Angabe, der hier
 * nicht zu trauen ist. Dafür muss jede Datei gelesen werden, was diese Prüfung zur
 * teuersten der vier macht.
 */
final class MimeMismatchCheck implements MediaHealthCheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemAdapterInterface $filesystem,
    ) {
    }

    public function type(): string
    {
        return 'mime_mismatch';
    }

    public function configKey(): string
    {
        return 'RcMediaToolkit.config.healthCheckMimeMismatch';
    }

    public function run(): Generator
    {
        $offset = 0;
        $batchSize = 200;

        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(id)) AS id, file_name, file_extension, mime_type, path '
                . 'FROM media WHERE file_name IS NOT NULL AND mime_type IS NOT NULL ORDER BY id LIMIT :limit OFFSET :offset',
                ['limit' => $batchSize, 'offset' => $offset],
                ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $path = $this->resolvePath($row);
                if ($path === null || !$this->filesystem->exists($path)) {
                    continue;
                }

                try {
                    $bytes = $this->filesystem->read($path);
                } catch (Throwable) {
                    continue;
                }

                // Eine Datei ohne Inhalt ist der Fall des ZeroByteCheck. Die Typerkennung liefert
                // dafür 'application/x-empty' — ein gültiger Wert, der hier zu einem zweiten
                // Befund führte und ein Typproblem behauptete, wo eine abgebrochene Übertragung
                // vorliegt.
                if ($bytes === '') {
                    continue;
                }

                $actual = $this->detectMime($bytes);
                $declared = strtolower(trim((string) $row['mime_type']));

                if ($actual !== null && $this->normalize($actual) !== $this->normalize($declared)) {
                    yield new Finding(
                        checkType: $this->type(),
                        severity: Finding::SEVERITY_WARNING,
                        sourceType: 'media_table',
                        mediaId: (string) $row['id'],
                        sourceReference: 'media:' . $row['id'],
                        urlPath: $path,
                        errorDetail: [
                            'declaredMime' => $declared,
                            'actualMime' => $actual,
                        ],
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

    private function detectMime(string $bytes): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(\FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $result = finfo_buffer($finfo, $bytes);
                finfo_close($finfo);
                if (is_string($result) && $result !== '') {
                    return strtolower($result);
                }
            }
        }
        return $this->detectByMagicNumber($bytes);
    }

    /**
     * Der Rückfall, wenn `fileinfo` fehlt.
     *
     * Er kennt nur die vier Formate, um die es hier geht — mehr braucht eine Bildprüfung nicht.
     * Eine eigene Methode ist er, weil er sich sonst nicht prüfen ließe: Solange die Erweiterung
     * geladen ist, kommt der Weg hierher nie zustande.
     */
    private function detectByMagicNumber(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'image/gif';
        }
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }

    private function normalize(string $mime): string
    {
        // image/jpg ↔ image/jpeg sind Synonyme aus Sicht der Browser
        if ($mime === 'image/jpg') {
            return 'image/jpeg';
        }
        return $mime;
    }
}
