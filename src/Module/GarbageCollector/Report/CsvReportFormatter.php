<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report;

use DateTimeInterface;
use RuntimeException;

/**
 * Der Waisenbericht als Tabelle — die Form, in der ihn jemand prüfen kann, bevor gelöscht wird.
 *
 * Geschrieben wird über `php://temp`: Das reicht die Zeilen an die PHP-eigene
 * CSV-Ausgabe weiter, die Anführungszeichen und Trennzeichen in Dateinamen korrekt
 * behandelt. Selbst zusammengesetzte Zeilen scheitern genau daran — und ein Bericht, der
 * bei einem Komma im Dateinamen verrutscht, führt beim Löschen zur falschen Datei.
 */
final class CsvReportFormatter implements ReportFormatterInterface
{
    public function format(): string
    {
        return 'csv';
    }

    public function render(iterable $candidates): string
    {
        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            throw new RuntimeException('Konnte CSV-Buffer nicht öffnen');
        }

        fputcsv($buffer, ['media_id', 'path', 'size_bytes', 'created_at', 'file_name', 'source_type'], ',', '"', '\\');

        foreach ($candidates as $candidate) {
            fputcsv($buffer, [
                $candidate->mediaId ?? '',
                $candidate->path,
                (string) $candidate->size,
                $candidate->createdAt->format(DateTimeInterface::ATOM),
                $candidate->fileName,
                $candidate->sourceType,
            ], ',', '"', '\\');
        }

        rewind($buffer);
        $content = stream_get_contents($buffer);
        fclose($buffer);

        return $content === false ? '' : $content;
    }
}
