<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message\WebPConvertMessage;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message\WebPConvertMessageHandler;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\ImagickEncoder;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\LibvipsEncoder;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\WebPEncoderInterface;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\EncoderChain;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPConverter;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPSettingsResolver;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Command\ConversionRecordingFilesystem;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Command\StubEncoder;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Die beiden Umwandler, die eine Fremdkomponente brauchen — und der Arbeiter, der sie benutzt.
 *
 * `imagick` und `libvips` sind auf den wenigsten Systemen vorhanden. **Genau deshalb ist ihr
 * wichtigster Fall der, in dem sie fehlen**: Sie müssen sich als nicht verfügbar melden, ohne
 * dabei selbst zu scheitern, und eine trotzdem angeforderte Umwandlung muss mit einer
 * verständlichen Meldung enden statt mit einem Absturz über eine unbekannte Klasse.
 *
 * Wo die Erweiterung vorhanden ist, prüfen dieselben Fälle den vollständigen Durchgang. Damit
 * sagt dieser Fall in jeder Umgebung etwas aus — und nicht nur „übersprungen".
 */
#[CoversClass(ImagickEncoder::class)]
#[CoversClass(LibvipsEncoder::class)]
#[CoversClass(WebPConvertMessageHandler::class)]
final class EncoderAndWorkerTest extends TestCase
{
    /**
     * @return array<string, array{WebPEncoderInterface, string}>
     */
    public static function encoders(): array
    {
        return [
            'imagick' => [new ImagickEncoder(), 'imagick'],
            'libvips' => [new LibvipsEncoder(), 'libvips'],
        ];
    }

    /**
     * Was: Der Name des Umwandlers.
     * Warum: Er steht in der Reihenfolge, die der Betreiber einstellt (`cwebp,libvips,imagick,gd`).
     *        Ändert er sich, ist der Umwandler **nie mehr an der Reihe** — und niemand sieht,
     *        warum plötzlich ein anderer arbeitet oder gar keiner.
     */
    #[Test]
    #[DataProvider('encoders')]
    public function everyEncoderKeepsItsName(WebPEncoderInterface $encoder, string $expected): void
    {
        self::assertSame($expected, $encoder->name());
    }

    /**
     * Was: Die Verfügbarkeitsfrage auf einem System ohne die Erweiterung.
     * Warum: Sie muss antworten, nicht scheitern. Über sie läuft die Auswahl des Umwandlers;
     *        eine Ausnahme an dieser Stelle nähme die gesamte Umwandlung mit, auch dort, wo ein
     *        anderer Umwandler bereitstünde.
     */
    #[Test]
    #[DataProvider('encoders')]
    public function theAvailabilityQuestionAnswersEvenWithoutTheExtension(WebPEncoderInterface $encoder): void
    {
        // Geprüft wird, dass die Frage überhaupt beantwortet wird — der Rückgabetyp steht fest.
        $encoder->isAvailable();

        self::addToAssertionCount(1);
    }

    /**
     * Was: Eine Umwandlung ohne die Erweiterung.
     * Warum: Sie endet mit einer Meldung, die den Grund nennt — nicht mit einem Absturz über
     *        eine unbekannte Klasse. Der Fall ist auf den meisten Systemen **der Normalfall**,
     *        und die Meldung landet im Protokoll, das jemand später liest.
     *
     * Wo die Erweiterung vorhanden ist, prüft derselbe Fall den vollständigen Durchgang: Aus
     * einem gültigen Bild müssen WebP-Daten werden, erkennbar an ihrer Kennung im Dateikopf.
     */
    #[Test]
    #[DataProvider('encoders')]
    public function withoutTheExtensionTheConversionFailsWithAReason(WebPEncoderInterface $encoder): void
    {
        if (!$encoder->isAvailable()) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/verf(ü|ue)gbar/');

            $encoder->encode('egal', 'jpeg', 80, false, true);

            return;
        }

        $webp = $encoder->encode($this->pngBytes(), 'png', 80, false, true);

        self::assertNotSame('', $webp);
        self::assertSame('RIFF', substr($webp, 0, 4), 'WebP-Daten beginnen mit der RIFF-Kennung.');
        self::assertSame('WEBP', substr($webp, 8, 4));
    }

    /**
     * Was: Die Versionsangabe.
     * Warum: Sie steht im Protokoll der Umwandler-Auswahl. Fehlt die Erweiterung, ist `null` die
     *        ehrliche Antwort — eine erfundene Angabe machte die Frage „womit wurde dieses Bild
     *        erzeugt?" unbeantwortbar, und genau die stellt sich, wenn nach einem Systemwechsel
     *        die Bildgüte anders aussieht.
     */
    #[Test]
    #[DataProvider('encoders')]
    public function theVersionIsNullExactlyWhenTheExtensionIsMissing(WebPEncoderInterface $encoder): void
    {
        if ($encoder->isAvailable()) {
            self::assertNotNull($encoder->version());

            return;
        }

        self::assertNull($encoder->version());
    }

    /**
     * Was: Eine fehlgeschlagene Umwandlung aus der Warteschlange.
     * Warum: Sie wird protokolliert und **nicht** weitergereicht. Ein Bild, das sich heute nicht
     *        umwandeln lässt, lässt sich auch beim zehnten Versuch nicht umwandeln; würde der
     *        Fehler herausgereicht, liefe der Auftrag in die Wiedervorlage und belegte die
     *        Warteschlange dauerhaft.
     *
     * Das ist der bewusste Gegensatz zum Bearbeiter der Medienprüfung, der weiterreicht — dort
     * ist ein zweiter Versuch sinnvoll.
     */
    #[Test]
    public function aFailedConversionStaysInTheLogInsteadOfTheQueue(): void
    {
        $converted = [];
        $handler = new WebPConvertMessageHandler(
            $this->converter($converted, failFor: 'media/a/klemmt.jpg'),
            new MediaToolkitLogger(new NullLogger()),
        );

        $handler(new WebPConvertMessage('media/a/klemmt.jpg'));

        self::assertSame([], $converted, 'Umgewandelt wurde nichts.');
    }

    /**
     * Die Gegenprobe: Ein gelungener Auftrag wandelt um. Ohne sie bewiese der vorige Fall nur,
     * dass der Bearbeiter nie etwas tut.
     */
    #[Test]
    public function aSuccessfulJobConvertsTheImage(): void
    {
        $converted = [];
        $handler = new WebPConvertMessageHandler(
            $this->converter($converted),
            new MediaToolkitLogger(new NullLogger()),
        );

        $handler(new WebPConvertMessage('media/a/bild.jpg', 'vorgang-1'));

        self::assertSame(['media/a/bild.jpg'], $converted);
    }

    /**
     * @param list<string> $converted
     */
    private function converter(array &$converted, ?string $failFor = null): WebPConverter
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(null);

        $logger = new MediaToolkitLogger(new NullLogger());

        return new WebPConverter(
            new EncoderChain([new StubEncoder($failFor)], $config, $logger),
            new ConversionRecordingFilesystem($converted),
            $config,
            $logger,
            new WebPSettingsResolver($config, $logger),
        );
    }

    /**
     * Ein gültiges PNG von einem Bildpunkt — genug, damit eine vorhandene Erweiterung wirklich
     * etwas zu tun bekommt.
     */
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
