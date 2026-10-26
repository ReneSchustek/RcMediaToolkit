<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report;

/**
 * Der Waisenbericht für den Blick auf der Konsole.
 *
 * Bewusst gekürzt: Die Kennung steht mit ihren ersten acht Zeichen da, das Datum ohne
 * Uhrzeit, und nur die Gesamtgröße wird in lesbare Einheiten gebracht — die Zeilen selbst
 * behalten die genaue Bytezahl, damit sie untereinander vergleichbar bleiben.
 *
 * Wer den vollständigen Datensatz braucht, nimmt CSV oder JSON. Diese Ausgabe ist zum
 * Überfliegen da, nicht zum Auswerten.
 */
final class ConsoleReportFormatter implements ReportFormatterInterface
{
    public function format(): string
    {
        return 'console';
    }

    public function render(iterable $candidates): string
    {
        $lines = [];
        $count = 0;
        $totalBytes = 0;

        foreach ($candidates as $candidate) {
            $count++;
            $totalBytes += $candidate->size;
            $lines[] = sprintf(
                '  %s  %10d B  %s  %s',
                substr((string) $candidate->mediaId, 0, 8),
                $candidate->size,
                $candidate->createdAt->format('Y-m-d'),
                $candidate->fileName,
            );
        }

        if ($count === 0) {
            return 'Keine Kandidaten gefunden.';
        }

        $header = sprintf('%d Kandidat(en), insgesamt %s', $count, self::humanBytes($totalBytes));
        return $header . "\n" . implode("\n", $lines);
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024.0 && $i < count($units) - 1) {
            $value /= 1024.0;
            $i++;
        }
        return sprintf('%.1f %s', $value, $units[$i]);
    }
}
