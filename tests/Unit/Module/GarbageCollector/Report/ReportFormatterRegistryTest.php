<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Report;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\ConsoleReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\CsvReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\JsonReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\ReportFormatterRegistry;

/**
 * Die Zuordnung vom angeforderten Format zum Formatierer.
 *
 * Der Bericht wird meist aus einem zeitgesteuerten Aufruf heraus erzeugt, dessen Ausgabe niemand
 * ansieht. **Ein Tippfehler im Format darf deshalb nicht stillschweigend etwas anderes liefern**,
 * sondern muss den Aufruf anhalten — mit einer Meldung, die die möglichen Formate nennt.
 */
#[CoversClass(ReportFormatterRegistry::class)]
final class ReportFormatterRegistryTest extends TestCase
{
    /**
     * Was: Ein bekanntes Format.
     * Warum: Der Normalfall — und zugleich der Nachweis, dass die Formatierer sich selbst
     *        eintragen. Ein neues Format braucht dann nur einen Eintrag in der Verdrahtung.
     */
    #[Test]
    public function aKnownFormatReturnsItsFormatter(): void
    {
        $registry = $this->registry();

        self::assertInstanceOf(JsonReportFormatter::class, $registry->get('json'));
        self::assertInstanceOf(CsvReportFormatter::class, $registry->get('csv'));
        self::assertInstanceOf(ConsoleReportFormatter::class, $registry->get('console'));
    }

    /**
     * Was: Ein unbekanntes Format.
     * Warum: **Kein stiller Rückfall.** Wer `--format=jsno` schreibt, bekäme sonst die
     *        Konsolenform und einen Bericht, den die nachgelagerte Auswertung nicht lesen kann —
     *        und der Fehler fiele erst Wochen später auf.
     */
    #[Test]
    public function anUnknownFormatIsRefusedInsteadOfSubstituted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->get('jsno');
    }

    /**
     * Was: Die Meldung zählt auf, was es gibt.
     * Warum: Sie ist die einzige Hilfe, die jemand an dieser Stelle bekommt. Ohne die Aufzählung
     *        bliebe nur der Blick in die Verdrahtung.
     */
    #[Test]
    public function theErrorMessageListsWhatIsAvailable(): void
    {
        try {
            $this->registry()->get('pdf');
            self::fail('Ein unbekanntes Format hätte abgelehnt werden müssen.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('pdf', $exception->getMessage());
            self::assertStringContainsString('json', $exception->getMessage());
            self::assertStringContainsString('csv', $exception->getMessage());
        }
    }

    /**
     * Was: Die Liste der Formate.
     * Warum: Sie steht in der Hilfe des Aufrufs. Wäre sie fest verdrahtet statt aus den
     *        eingetragenen Formatierern gebildet, nennte sie irgendwann ein Format, das es nicht
     *        mehr gibt.
     */
    #[Test]
    public function theAvailableFormatsComeFromTheRegisteredFormatters(): void
    {
        self::assertSame(['json', 'csv', 'console'], $this->registry()->availableFormats());
    }

    /**
     * Ohne eingetragene Formatierer bleibt die Liste leer, statt eine Vorgabe zu erfinden. Der
     * Fall kann nur bei kaputter Verdrahtung eintreten — und dann ist eine leere Aufzählung in
     * der Fehlermeldung der ehrlichere Hinweis.
     */
    #[Test]
    public function anEmptyRegistryStaysEmpty(): void
    {
        $registry = new ReportFormatterRegistry([]);

        self::assertSame([], $registry->availableFormats());

        $this->expectException(InvalidArgumentException::class);
        $registry->get('json');
    }

    private function registry(): ReportFormatterRegistry
    {
        return new ReportFormatterRegistry([
            new JsonReportFormatter(),
            new CsvReportFormatter(),
            new ConsoleReportFormatter(),
        ]);
    }
}
