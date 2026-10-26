<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report;

use JsonException;
use RuntimeException;

/**
 * Der Waisenbericht als JSON — für Weiterverarbeitung statt für Menschen.
 *
 * Als einziger der drei Formatierer sammelt er alle Kandidaten, bevor er etwas ausgibt. Das
 * liegt am Format: Ein gültiges JSON-Dokument lässt sich nicht stückweise schreiben, ohne
 * die Klammern von Hand zu setzen. Bei sehr großen Beständen ist deshalb CSV die
 * sparsamere Wahl.
 */
final class JsonReportFormatter implements ReportFormatterInterface
{
    public function format(): string
    {
        return 'json';
    }

    public function render(iterable $candidates): string
    {
        $items = [];
        foreach ($candidates as $candidate) {
            $items[] = $candidate->toArray();
        }

        try {
            return json_encode($items, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('JSON-Encoding fehlgeschlagen', 0, $e);
        }
    }
}
