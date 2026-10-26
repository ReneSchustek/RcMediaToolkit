<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\CwebpBinaryEncoder;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\EncoderChain;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPConverter;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPSettingsResolver;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Command\StubEncoder;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Wann eine Umwandlung stattfindet — und wann bewusst nicht.
 *
 * Über diese Entscheidungen läuft der gesamte Bilderbestand, oft mehrmals täglich. **Jede
 * Umwandlung, die überflüssig stattfindet, kostet Rechenzeit auf einem Produktivsystem**, und
 * jede, die zu Unrecht ausbleibt, hinterlässt ein Bild in alter Größe. Beides fällt an der
 * Oberfläche nicht auf: Die Seite sieht in jedem Fall gleich aus.
 *
 * Der Unterschied zwischen „übergangen" und „fehlgeschlagen" ist deshalb keine Formsache. Was
 * übergangen wird, ist in Ordnung; was fehlschlägt, gehört angesehen — und färbt am Ende des
 * Laufs den Rückgabewert.
 */
#[CoversClass(WebPConverter::class)]
#[CoversClass(CwebpBinaryEncoder::class)]
final class ConversionDecisionsTest extends TestCase
{
    /**
     * Was: Eine Datei, die kein umwandelbares Bild ist.
     * Warum: Sie wird übergangen, nicht als Fehler gemeldet. Im Medienbestand liegen PDF-Dateien
     *        und Zeichnungen; jede davon als Fehlschlag zu zählen, färbte jeden Lauf rot und
     *        machte den Rückgabewert wertlos.
     */
    #[Test]
    public function aNonImageIsSkippedNotFailed(): void
    {
        $ergebnis = $this->converter(['media/ab/anleitung.pdf' => 'daten'])->convert('media/ab/anleitung.pdf');

        self::assertSame('skipped', $ergebnis['status']);
        self::assertSame('format_not_whitelisted', $ergebnis['reason'] ?? null);
    }

    /**
     * Eine Datei, die es nicht gibt, wird ebenfalls übergangen. Der Fall entsteht, wenn zwischen
     * Auftrag und Bearbeitung jemand aufgeräumt hat — ein Fehlschlag wäre die falsche Auskunft.
     */
    #[Test]
    public function aVanishedSourceIsSkipped(): void
    {
        $ergebnis = $this->converter([])->convert('media/ab/weg.jpg');

        self::assertSame('skipped', $ergebnis['status']);
        self::assertSame('source_missing', $ergebnis['reason'] ?? null);
    }

    /**
     * Was: Ein sehr kleines Bild.
     * Warum: Unterhalb einer Mindestgröße lohnt die Umwandlung nicht — das Ergebnis wäre kaum
     *        kleiner, die Rechenzeit aber dieselbe. Die Grenze ist einstellbar; sie muss greifen,
     *        sonst arbeitet der Lauf sich an Symbolbildern ab.
     */
    #[Test]
    public function aTinyImageIsNotWorthConverting(): void
    {
        $ergebnis = $this->converter(['media/ab/symbol.png' => 'x'], size: 200)
            ->convert('media/ab/symbol.png');

        self::assertSame('skipped', $ergebnis['status']);
        self::assertSame('below_min_size', $ergebnis['reason'] ?? null);
    }

    /**
     * Was: Eine Umwandlung, die schon vorliegt und aktuell ist.
     * Warum: **Der häufigste Fall im Betrieb.** Ohne diese Prüfung wandelte jeder nächtliche Lauf
     *        den gesamten Bestand erneut um — die Bilder blieben gleich, die Last nicht.
     */
    #[Test]
    public function anUpToDateVariantIsLeftAlone(): void
    {
        $converter = $this->converter(
            ['media/ab/bild.jpg' => 'inhalt', 'media/ab/bild.jpg.webp' => 'fertig'],
            zeitstempel: ['media/ab/bild.jpg' => 100, 'media/ab/bild.jpg.webp' => 200],
        );

        $ergebnis = $converter->convert('media/ab/bild.jpg');

        self::assertSame('skipped', $ergebnis['status']);
        self::assertSame('already_up_to_date', $ergebnis['reason'] ?? null);
    }

    /**
     * Die Gegenprobe: Ist das Original neuer als die Umwandlung, wird neu umgewandelt. Sonst
     * bliebe nach jeder Bildbearbeitung die alte Fassung ausgeliefert — sichtbar für jeden
     * Besucher, unsichtbar für jede Prüfung.
     */
    #[Test]
    public function aNewerOriginalTriggersAFreshConversion(): void
    {
        $converter = $this->converter(
            ['media/ab/bild.jpg' => 'inhalt', 'media/ab/bild.jpg.webp' => 'veraltet'],
            zeitstempel: ['media/ab/bild.jpg' => 500, 'media/ab/bild.jpg.webp' => 100],
        );

        self::assertSame('converted', $converter->convert('media/ab/bild.jpg')['status']);
    }

    /**
     * Die erzeugte Datei liegt unter dem Pfad des Originals mit angehängter Endung. Ein Versatz
     * fiele hier nicht auf: Der Shop lieferte still weiter das Original aus.
     */
    #[Test]
    public function theResultIsWrittenNextToTheOriginal(): void
    {
        $converter = $this->converter(['media/ab/bild.jpg' => 'inhalt']);

        $ergebnis = $converter->convert('media/ab/bild.jpg');

        self::assertSame('converted', $ergebnis['status']);
        self::assertSame('media/ab/bild.jpg.webp', $ergebnis['targetPath'] ?? null);
        self::assertGreaterThan(0, $ergebnis['sizeBytes'] ?? 0);
    }

    /**
     * Was: Der Umwandler scheitert.
     * Warum: **Das ist ein Fehlschlag und keine Übergehung.** Nur so färbt sich der Rückgabewert
     *        des Laufs, und nur so bemerkt jemand ein Bild, das sich dauerhaft nicht umwandeln
     *        lässt.
     */
    #[Test]
    public function aFailingEncoderIsReportedAsFailure(): void
    {
        $converter = $this->converter(['media/ab/bild.jpg' => 'inhalt'], scheiternFuer: 'inhalt');

        self::assertSame('failed', $converter->convert('media/ab/bild.jpg')['status']);
    }

    /**
     * Ein Speicher, der das Schreiben verweigert, ist ebenfalls ein Fehlschlag — und zwar mit
     * eigener Begründung. Ein voller Datenträger ist etwas anderes als ein kaputtes Bild.
     */
    #[Test]
    public function aRefusedWriteIsItsOwnKindOfFailure(): void
    {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturnCallback(
            static fn (string $path): bool => !str_ends_with($path, '.webp'),
        );
        $filesystem->method('size')->willReturn(5_000_000);
        $filesystem->method('read')->willReturn('inhalt');
        $filesystem->method('write')->willThrowException(new RuntimeException('Datenträger voll'));

        $ergebnis = $this->converterWith($filesystem)->convert('media/ab/bild.jpg');

        self::assertSame('failed', $ergebnis['status']);
        self::assertSame('write_error', $ergebnis['reason'] ?? null);
    }

    /**
     * Was: Das Programm cwebp.
     * Warum: Es ist der bevorzugte Umwandler — dort, wo es installiert ist. Wo nicht, muss die
     *        Verfügbarkeitsfrage mit „nein" antworten, ohne selbst zu scheitern, und eine
     *        trotzdem angeforderte Umwandlung mit einer verständlichen Meldung enden.
     *
     * Wo das Programm vorhanden ist, prüft derselbe Fall den vollständigen Durchgang: Aus einem
     * gültigen PNG müssen WebP-Daten werden.
     */
    #[Test]
    public function theExternalProgramConvertsWhereItExists(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(null);

        $encoder = new CwebpBinaryEncoder($config, new MediaToolkitLogger(new NullLogger()));

        self::assertSame('cwebp', $encoder->name());

        if (!$encoder->isAvailable()) {
            self::assertNull($encoder->version());

            $this->expectException(RuntimeException::class);
            $encoder->encode('egal', 'png', 80, false, true);

            return;
        }

        self::assertNotNull($encoder->version());

        $webp = $encoder->encode($this->pngBytes(), 'png', 80, false, true);

        self::assertSame('RIFF', substr($webp, 0, 4), 'WebP-Daten beginnen mit der RIFF-Kennung.');
        self::assertSame('WEBP', substr($webp, 8, 4));
    }

    /**
     * Was: Ein eingestellter Programmpfad, unter dem nichts liegt.
     * Warum: Der Betreiber kann den Pfad zu `cwebp` selbst setzen. Zeigt er ins Leere, muss die
     *        Verfügbarkeitsfrage mit „nein" antworten — **nicht** mit einer Ausnahme. Sonst
     *        nähme ein Tippfehler in einem Einstellungsfeld die gesamte Bildauslieferung mit.
     */
    #[Test]
    public function aConfiguredPathThatLeadsNowhereMeansUnavailable(): void
    {
        $encoder = $this->cwebpWithPath('/gibt/es/nicht/cwebp');

        self::assertFalse($encoder->isAvailable());
        self::assertNull($encoder->version());
    }

    /**
     * Was: Ein Pfad, der auf etwas zeigt, das kein ausführbares Programm ist.
     * Warum: Derselbe Fall aus einer anderen Richtung — jemand trägt versehentlich ein
     *        Verzeichnis oder eine Textdatei ein. Auch das ist ein „nein", kein Absturz.
     */
    #[Test]
    public function aPathToSomethingUnexecutableMeansUnavailableToo(): void
    {
        self::assertFalse($this->cwebpWithPath(sys_get_temp_dir())->isAvailable());
    }

    /**
     * Was: Die Antwort auf die Verfügbarkeitsfrage wird gemerkt.
     * Warum: Sie startet im schlechtesten Fall ein fremdes Programm. Bei achtzig Bildern in
     *        einem Lauf wären das achtzig Programmstarts, nur um jedes Mal dasselbe zu
     *        erfahren.
     */
    #[Test]
    public function theAvailabilityAnswerIsRemembered(): void
    {
        $encoder = $this->cwebpWithPath('/gibt/es/nicht/cwebp');

        $erste = $encoder->isAvailable();
        $zweite = $encoder->isAvailable();

        self::assertSame($erste, $zweite);
    }

    /**
     * Was: Eine Umwandlung mit einem Programm, das es nicht gibt.
     * Warum: Sie endet mit einer verständlichen Meldung — und **ohne Rückstände**. Das Programm
     *        arbeitet über Zwischendateien; blieben die liegen, füllte sich der Ablageordner
     *        bei jedem Fehlschlag weiter.
     */
    #[Test]
    public function aFailingRunLeavesNoTemporaryFilesBehind(): void
    {
        $encoder = $this->cwebpWithPath('/gibt/es/nicht/cwebp');

        $vorher = $this->countTemporaryFiles();

        try {
            $encoder->encode($this->pngBytes(), 'png', 80, false, true);
            self::fail('Ohne Programm darf keine Umwandlung gelingen.');
        } catch (RuntimeException) {
            self::assertSame($vorher, $this->countTemporaryFiles(), 'Keine Zwischendatei bleibt liegen.');
        }
    }

    private function cwebpWithPath(string $path): CwebpBinaryEncoder
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'RcMediaToolkit.config.webpCwebpPath' ? $path : null,
        );

        return new CwebpBinaryEncoder($config, new MediaToolkitLogger(new NullLogger()));
    }

    private function countTemporaryFiles(): int
    {
        return count(glob(sys_get_temp_dir() . '/rcwebp-*') ?: []);
    }

    /**
     * Was: Das Alter der vorhandenen Umwandlung lässt sich nicht ermitteln.
     * Warum: Dann wird **neu umgewandelt**. Im Zweifel lieber einmal zu viel gerechnet als ein
     *        veraltetes Bild dauerhaft ausgeliefert — der Fehler wäre für Besucher sichtbar,
     *        die zusätzliche Rechenzeit nicht.
     */
    #[Test]
    public function anUnknownAgeLeadsToAFreshConversion(): void
    {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturn(true);
        $filesystem->method('size')->willReturn(5_000_000);
        $filesystem->method('read')->willReturn('inhalt');
        $filesystem->method('lastModified')->willThrowException(new RuntimeException('Zeitangabe fehlt'));

        self::assertSame('converted', $this->converterWith($filesystem)->convert('media/ab/bild.jpg')['status']);
    }

    /**
     * Was: Die Quelldatei lässt sich nicht lesen.
     * Warum: Das ist ein Fehlschlag mit eigener Begründung. Ein voller Datenträger, ein
     *        klemmender Fremdspeicher oder eine kaputte Datei — alle drei brauchen einen Blick,
     *        und alle drei sind etwas anderes als „übergangen".
     */
    #[Test]
    public function anUnreadableSourceIsItsOwnKindOfFailure(): void
    {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturnCallback(
            static fn (string $path): bool => !str_ends_with($path, '.webp'),
        );
        $filesystem->method('size')->willReturn(5_000_000);
        $filesystem->method('read')->willThrowException(new RuntimeException('Speicher antwortet nicht'));

        $ergebnis = $this->converterWith($filesystem)->convert('media/ab/bild.jpg');

        self::assertSame('failed', $ergebnis['status']);
        self::assertSame('read_error', $ergebnis['reason'] ?? null);
    }

    /**
     * @param array<string, string> $dateien
     * @param array<string, int>    $zeitstempel
     */
    private function converter(
        array $dateien,
        int $size = 5_000_000,
        array $zeitstempel = [],
        ?string $scheiternFuer = null,
    ): WebPConverter {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturnCallback(
            static fn (string $path): bool => isset($dateien[$path]),
        );
        $filesystem->method('size')->willReturn($size);
        $filesystem->method('read')->willReturnCallback(
            static fn (string $path): string => $dateien[$path] ?? '',
        );
        $filesystem->method('lastModified')->willReturnCallback(
            static fn (string $path): int => $zeitstempel[$path] ?? 0,
        );

        return $this->converterWith($filesystem, $scheiternFuer);
    }

    private function converterWith(FilesystemAdapterInterface $filesystem, ?string $scheiternFuer = null): WebPConverter
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(null);

        $logger = new MediaToolkitLogger(new NullLogger());

        return new WebPConverter(
            new EncoderChain([new StubEncoder($scheiternFuer)], $config, $logger),
            $filesystem,
            $config,
            $logger,
            new WebPSettingsResolver($config, $logger),
        );
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($bytes);

        return $bytes;
    }
}
