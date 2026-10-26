<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Exception\InvalidExclusionPatternException;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\CsvReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\JsonReportFormatter;

/**
 * Was den Aufräumer verlässt: der einzelne Fund und die Meldung über ein unbrauchbares Muster.
 *
 * Beides geht an Menschen oder an nachgelagerte Auswertungen — und beides ist die Grundlage für
 * eine Entscheidung über das Löschen. Ein Fund ohne Zeitangabe oder eine Fehlermeldung ohne
 * Hinweis auf die Stelle wäre für diese Entscheidung wertlos.
 */
#[CoversClass(OrphanedMedia::class)]
#[CoversClass(InvalidExclusionPatternException::class)]
#[CoversClass(JsonReportFormatter::class)]
#[CoversClass(CsvReportFormatter::class)]
final class ReportedDataTest extends TestCase
{
    /**
     * Was: Die maschinenlesbare Form eines Fundes.
     * Warum: Sie geht unverändert in die Schnittstelle des Verwaltungsfensters. **Jede
     *        Umbenennung eines Feldes bricht die Oberfläche**, ohne dass hier etwas auffiele —
     *        deshalb steht die Form hier fest.
     */
    #[Test]
    public function theMachineFormOfAFindingIsFixed(): void
    {
        $fund = new OrphanedMedia(
            mediaId: 'aabbccddeeff00112233445566778899',
            path: 'media/ab/verwaist.jpg',
            size: 2048,
            createdAt: new DateTimeImmutable('2026-02-03T10:11:12+00:00'),
            fileName: 'verwaist.jpg',
            sourceType: OrphanedMedia::SOURCE_DB_ORPHAN,
        );

        self::assertSame([
            'mediaId' => 'aabbccddeeff00112233445566778899',
            'path' => 'media/ab/verwaist.jpg',
            'size' => 2048,
            'createdAt' => '2026-02-03T10:11:12+00:00',
            'fileName' => 'verwaist.jpg',
            'sourceType' => 'db_orphan',
        ], $fund->toArray());
    }

    /**
     * Was: Die Zeitangabe trägt ihre Zeitzone mit.
     * Warum: Der Bericht wird auf einem Server erzeugt und anderswo gelesen. Ohne Zeitzone
     *        verschöbe sich das Anlagedatum um Stunden — und die Schonfrist, die sich daran
     *        bemisst, wäre eine andere als gedacht.
     */
    #[Test]
    public function theTimestampCarriesItsZone(): void
    {
        $fund = new OrphanedMedia(
            mediaId: null,
            path: 'media/ab/datei.jpg',
            size: 0,
            createdAt: new DateTimeImmutable('2026-02-03 10:11:12', new DateTimeZone('UTC')),
            fileName: 'datei.jpg',
            sourceType: OrphanedMedia::SOURCE_FS_ORPHAN,
        );

        $form = $fund->toArray();

        self::assertSame(
            (new DateTimeImmutable('2026-02-03 10:11:12', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            $form['createdAt'],
        );
        self::assertNull($form['mediaId'], 'Ein Fund aus dem Dateisystem hat keine Kennung.');
    }

    /**
     * Was: Die Meldung über ein unbrauchbares Ausschlussmuster.
     * Warum: **Sie muss den Betreiber zur richtigen Stelle führen.** Das Muster steht in der
     *        Plugin-Einstellung; ein regulärer Ausdruck mit einem Tippfehler hält den ganzen
     *        Aufräumlauf an, und die Meldung ist das Einzige, was jemand davon sieht.
     */
    #[Test]
    public function theMessageAboutABrokenPatternPointsToTheSetting(): void
    {
        $ausnahme = InvalidExclusionPatternException::forPattern('/(unvollständig/');

        self::assertStringContainsString('/(unvollständig/', $ausnahme->getMessage());
        self::assertStringContainsString('Ausschluss-Muster', $ausnahme->getMessage());
        self::assertStringContainsString('abgebrochen', $ausnahme->getMessage());
    }

    /**
     * Die Begründung aus der Mustererkennung wird mitgenommen, wenn es eine gibt. Sie sagt am
     * genauesten, was am Muster nicht stimmt — ohne sie bliebe nur „ist ungültig".
     */
    #[Test]
    public function theUnderlyingReasonIsCarriedAlong(): void
    {
        $mit = InvalidExclusionPatternException::forPattern('/(/', 'missing closing parenthesis');
        $ohne = InvalidExclusionPatternException::forPattern('/(/');

        self::assertStringContainsString('missing closing parenthesis', $mit->getMessage());
        self::assertStringNotContainsString('()', $ohne->getMessage());
    }

    /**
     * Was: Ein Fund ohne Kennung in der Tabellenform.
     * Warum: Er kommt aus dem Dateisystem und hat keinen Datensatz. Die Spalte bleibt leer,
     *        statt dass die Zeile verrutscht — eine verrutschte Zeile macht die ganze Datei
     *        unbrauchbar.
     */
    #[Test]
    public function aFindingWithoutAnIdKeepsTheColumnsAligned(): void
    {
        $zeilen = array_values(array_filter(explode("\n", (new CsvReportFormatter())->render([
            new OrphanedMedia(
                mediaId: null,
                path: 'media/ab/verwaist.jpg',
                size: 100,
                createdAt: new DateTimeImmutable('2026-02-03T10:11:12+00:00'),
                fileName: 'verwaist.jpg',
                sourceType: OrphanedMedia::SOURCE_FS_ORPHAN,
            ),
        ]))));

        self::assertCount(2, $zeilen, 'Kopfzeile und ein Datensatz.');
        self::assertSame(
            substr_count($zeilen[0], ','),
            substr_count($zeilen[1], ','),
            'Beide Zeilen haben gleich viele Spalten.',
        );
    }

    /**
     * Die JSON-Form eines leeren Berichts ist eine leere Liste — nicht `null` und kein leerer
     * Text. Die nachgelagerte Auswertung erwartet eine Liste, immer.
     */
    #[Test]
    public function theEmptyMachineReportIsAnEmptyList(): void
    {
        self::assertSame('[]', trim((new JsonReportFormatter())->render([])));
    }
}
