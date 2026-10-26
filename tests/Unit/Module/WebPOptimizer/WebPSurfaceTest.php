<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\GdEncoder;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\EncoderChain;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Twig\RcWebpExtension;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Command\StubEncoder;
use RuntimeException;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Die Berührungsflächen der Umwandlung nach außen: die Vorlagenfunktion, die Auswahl der
 * Umwandler und der Umwandler, der immer da ist.
 *
 * Die Vorlagenfunktion ist der einzige Teil des Moduls, den ein Themenbauer direkt aufruft. Sie
 * muss deshalb dieselbe Zusage einhalten wie der Rest: **Ist das Modul aus, verhält sie sich, als
 * gäbe es sie nicht** — sie liefert dann die gewöhnliche Adresse und nicht etwa eine, die ins
 * Leere zeigt.
 */
#[CoversClass(RcWebpExtension::class)]
#[CoversClass(GdEncoder::class)]
#[CoversClass(EncoderChain::class)]
final class WebPSurfaceTest extends TestCase
{
    /**
     * Was: Die Vorlagenfunktion trägt einen festen Namen.
     * Warum: Sie wird aus Themenvorlagen aufgerufen. Ein neuer Name macht jede Vorlage kaputt,
     *        die sie benutzt — und zwar erst beim Aufruf der Seite, nicht beim Aktualisieren.
     */
    #[Test]
    public function theTemplateFilterKeepsItsName(): void
    {
        $filter = (new RcWebpExtension(new ModuleRegistry($this->config(true))))->getFilters();

        self::assertCount(1, $filter);
        self::assertSame('rc_webp_url', $filter[0]->getName());
    }

    /**
     * Was: Die Vorlagenfunktion bei abgeschaltetem Modul.
     * Warum: Sie gibt die **gewöhnliche** Adresse zurück. Gäbe sie die umgewandelte, zeigte die
     *        Seite auf Dateien, die es nicht gibt — und das Abschalten des Moduls machte den
     *        Laden kaputt, statt ihn in den Ausgangszustand zu bringen.
     */
    #[Test]
    public function theTemplateFilterFallsBackToTheOrdinaryAddress(): void
    {
        $medium = new MediaEntity();
        $medium->setId('aabbccddeeff00112233445566778899');
        $medium->setUniqueIdentifier('aabbccddeeff00112233445566778899');
        $medium->setUrl('https://shop.example/media/ab/bild.jpg');

        $aus = new RcWebpExtension(new ModuleRegistry($this->config(false)));
        $an = new RcWebpExtension(new ModuleRegistry($this->config(true)));

        self::assertSame('https://shop.example/media/ab/bild.jpg', $aus->webpUrl($medium));
        self::assertSame('https://shop.example/media/ab/bild.jpg.webp', $an->webpUrl($medium));
    }

    /**
     * Ohne Medium und ohne Adresse kommt nichts heraus — die Vorlage prüft das Ergebnis
     * ohnehin, und eine erfundene Adresse wäre schlimmer als keine.
     */
    #[Test]
    public function theTemplateFilterAnswersNothingForNothing(): void
    {
        $ohneAdresse = new MediaEntity();
        $ohneAdresse->setId('aabbccddeeff00112233445566778899');
        $ohneAdresse->setUniqueIdentifier('aabbccddeeff00112233445566778899');
        $ohneAdresse->setUrl('');

        $erweiterung = new RcWebpExtension(new ModuleRegistry($this->config(true)));

        self::assertNull($erweiterung->webpUrl(null));
        self::assertNull($erweiterung->webpUrl($ohneAdresse));
    }

    /**
     * Was: Der Umwandler, der überall vorhanden ist.
     * Warum: GD gehört zu praktisch jeder PHP-Installation und ist damit die letzte Reihe der
     *        Auswahl. Wo es WebP beherrscht, muss der Durchgang klappen; wo nicht, muss die
     *        Absage verständlich sein — sonst sucht jemand den Fehler in seinen Bildern.
     */
    #[Test]
    public function theAlwaysPresentEncoderWorksOrSaysWhyNot(): void
    {
        $encoder = new GdEncoder();

        self::assertSame('gd', $encoder->name());

        if (!$encoder->isAvailable()) {
            self::assertNull($encoder->version());

            $this->expectException(RuntimeException::class);
            $encoder->encode('egal', 'png', 80, false, true);

            return;
        }

        self::assertStringStartsWith('GD ', (string) $encoder->version());

        $webp = $encoder->encode($this->pngBytes(), 'png', 80, false, true);

        self::assertSame('RIFF', substr($webp, 0, 4));
        self::assertSame('WEBP', substr($webp, 8, 4));
    }

    /**
     * Was: Daten, die kein Bild sind.
     * Warum: Sie führen zu einer Ausnahme mit klarer Begründung statt zu einer leeren Datei.
     *        Eine leere WebP-Datei neben dem Original wäre der schlechteste Ausgang: Der
     *        Bestandsüberblick hielte das Bild für umgewandelt.
     */
    #[Test]
    public function contentThatIsNoImageIsRefusedByTheEncoder(): void
    {
        $encoder = new GdEncoder();

        if (!$encoder->isAvailable()) {
            self::markTestSkipped('Ohne GD mit WebP-Unterstützung sagt dieser Fall nichts aus.');
        }

        $this->expectException(RuntimeException::class);

        $encoder->encode('das ist kein Bild', 'png', 80, false, true);
    }

    /**
     * Was: Die Auswahl ohne einen einzigen verfügbaren Umwandler.
     * Warum: Sie endet mit einer Ausnahme, die sagt, was zu tun ist. Ohne diese Meldung stünde
     *        im Protokoll nur „Umwandlung fehlgeschlagen", und niemand käme darauf, dass schlicht
     *        kein Programm installiert ist.
     */
    #[Test]
    public function anEmptyChainSaysWhatIsMissing(): void
    {
        $chain = new EncoderChain([], $this->config(true), new MediaToolkitLogger(new NullLogger()));

        self::assertSame([], $chain->availabilityMap());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cwebp|GD/');

        $chain->selectEncoder();
    }

    /**
     * Was: Die eingestellte Reihenfolge.
     * Warum: Sie entscheidet, welches Programm die Bilder erzeugt — und damit, wie sie
     *        aussehen. Ein Umwandler, der nicht in der Reihenfolge steht, kommt nie zum Zug,
     *        auch wenn er verfügbar ist.
     */
    #[Test]
    public function theConfiguredOrderDecidesWhoWorks(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'RcMediaToolkit.config.webpEncoderPreference' ? 'gd,cwebp' : null,
        );

        $chain = new EncoderChain([new StubEncoder()], $config, new MediaToolkitLogger(new NullLogger()));

        self::assertSame(['gd' => true], $chain->availabilityMap());
        self::assertSame('gd', $chain->selectEncoder()->name());
        self::assertSame(['gd' => 'gd (Doppel)'], $chain->versionMap());
    }

    /**
     * @return SystemConfigService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function config(bool $moduleActive): SystemConfigService
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'RcMediaToolkit.config.webPOptimizerActive' ? $moduleActive : null,
        );

        return $config;
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
