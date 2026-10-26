<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Report;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\ConsoleReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\CsvReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\JsonReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\ReportFormatterInterface;

/**
 * Was alle drei Berichtsformen gemeinsam einhalten müssen.
 *
 * Der Bericht ist die Grundlage dafür, dass jemand Dateien löschen lässt. Deshalb zählt hier
 * nicht die Form, sondern dass **jede Angabe erhalten bleibt** — und dass ein leerer Bestand
 * einen leeren Bericht ergibt und nicht etwa gar keinen.
 */
#[CoversClass(JsonReportFormatter::class)]
#[CoversClass(CsvReportFormatter::class)]
#[CoversClass(ConsoleReportFormatter::class)]
final class ReportFormatterContractTest extends TestCase
{
    /**
     * @return array<string, array{ReportFormatterInterface, string}>
     */
    public static function formatters(): array
    {
        return [
            'json' => [new JsonReportFormatter(), 'json'],
            'csv' => [new CsvReportFormatter(), 'csv'],
            'konsole' => [new ConsoleReportFormatter(), 'console'],
        ];
    }

    /**
     * Jede Form meldet ihren eigenen Namen — daran hängt die Auswahl im Aufruf.
     */
    #[Test]
    #[DataProvider('formatters')]
    public function everyFormatterNamesItself(ReportFormatterInterface $formatter, string $expected): void
    {
        self::assertSame($expected, $formatter->format());
    }

    /**
     * Was: Ein leerer Bestand.
     * Warum: Der Fall tritt nach jeder erfolgreichen Aufräumrunde ein — und dann darf der Bericht
     *        nicht leer im Sinne von „gar nichts" sein, sonst sieht es nach einem Fehlschlag aus.
     */
    #[Test]
    #[DataProvider('formatters')]
    public function anEmptyRunStillProducesAReport(ReportFormatterInterface $formatter, string $name): void
    {
        $rendered = $formatter->render([]);

        if ($name === 'json') {
            self::assertSame([], json_decode($rendered, true));

            return;
        }

        self::assertNotSame('', $rendered, 'Auch ohne Fund gehört eine Kopfzeile oder eine Meldung in den Bericht.');
    }

    /**
     * Was: Jede Form nennt die Datei wieder.
     * Warum: Ohne Wiedererkennung ist der Bericht nicht handlungsfähig — niemand findet eine
     *        Datei anhand einer Größenangabe.
     *
     * **Die Formen unterscheiden sich hier bewusst**, und das hält dieser Fall fest: JSON und
     * CSV tragen den vollständigen Pfad, die Konsolenform nur den Dateinamen. Sie ist zum
     * Überfliegen gedacht, nicht zum Auswerten; wer den ganzen Datensatz braucht, nimmt eine der
     * beiden anderen. Wer das ändern will, ändert zuerst diesen Fall.
     */
    #[Test]
    #[DataProvider('formatters')]
    public function everyFormatMakesTheFileRecognisable(ReportFormatterInterface $formatter, string $name): void
    {
        $rendered = $formatter->render([$this->candidate()]);

        self::assertStringContainsString('verwaist.jpg', $rendered, 'Der Dateiname gehört in jede Form.');

        if ($name === 'json') {
            // JSON entkommt die Schrägstriche (`media\/ab\/...`). Geprüft wird deshalb der
            // gelesene Wert, nicht die Zeichenfolge — sonst prüfte der Fall die Schreibweise
            // des Formats statt seinen Inhalt.
            $decoded = json_decode($rendered, true);
            self::assertSame('media/ab/cd/verwaist.jpg', $decoded[0]['path'] ?? null);

            return;
        }

        if ($name === 'csv') {
            self::assertStringContainsString('media/ab/cd/verwaist.jpg', $rendered, 'Auswertbare Formen tragen den Pfad.');
        }
    }

    /**
     * Was: Ein Datensatz ohne Kennung in der Datenbank.
     * Warum: **Der häufigste Fall überhaupt** — eine Datei, die im Speicher liegt und zu der es
     *        keinen Datensatz mehr gibt. Fiele die Form darüber, bliebe der Bericht genau dort
     *        stehen, wo er am nötigsten ist.
     */
    #[Test]
    #[DataProvider('formatters')]
    public function aCandidateWithoutADatabaseIdIsRendered(ReportFormatterInterface $formatter): void
    {
        $rendered = $formatter->render([$this->candidate(mediaId: null)]);

        self::assertStringContainsString('verwaist.jpg', $rendered);
    }

    private function candidate(?string $mediaId = 'aabbccddeeff00112233445566778899'): OrphanedMedia
    {
        return new OrphanedMedia(
            mediaId: $mediaId,
            path: 'media/ab/cd/verwaist.jpg',
            size: 2048,
            createdAt: new DateTimeImmutable('2026-02-03T10:11:12+00:00'),
            fileName: 'verwaist.jpg',
            sourceType: OrphanedMedia::SOURCE_DB_ORPHAN,
        );
    }
}
