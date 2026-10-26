<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPSettingsResolver;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Welche Einstellungen für ein bestimmtes Bild gelten.
 *
 * Der Betreiber kann sie je Medienordner festlegen, als JSON in einem Textfeld. **Ein Textfeld
 * ist die unzuverlässigste Eingabe, die es gibt** — es kann leer sein, kaputtes JSON enthalten
 * oder eine Struktur, die niemand erwartet hat. Keiner dieser Fälle darf die Umwandlung
 * anhalten: Sie soll dann mit den Vorgaben weiterlaufen und die Sache ins Protokoll schreiben.
 */
#[CoversClass(WebPSettingsResolver::class)]
final class WebPSettingsResolverTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function folderCases(): array
    {
        return [
            'Medienordner' => ['media/produkte/bild.jpg', 'produkte'],
            'tiefer verschachtelt' => ['media/produkte/2026/bild.jpg', 'produkte'],
            'ohne Medien-Vorsilbe' => ['bilder/x.jpg', 'default'],
            'direkt unter media' => ['media/bild.jpg', 'default'],
            'leer' => ['', 'default'],
        ];
    }

    /**
     * Was: Aus welchem Teil des Pfades der Ordnerschlüssel kommt.
     * Warum: Er entscheidet, welche Einstellung greift. Läge er falsch, bekäme jedes Bild die
     *        Vorgabewerte — und die ordnerweise Feineinstellung wäre wirkungslos, ohne dass es
     *        auffiele.
     */
    #[Test]
    #[DataProvider('folderCases')]
    public function theFolderKeyComesFromTheFirstSegment(string $path, string $expectedKey): void
    {
        $resolver = $this->resolver([]);

        self::assertSame($expectedKey, $resolver->resolveForPath($path)->folderKey);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableJsonCases(): array
    {
        return [
            'nicht gesetzt' => [null],
            'leer' => [''],
            'nur Leerzeichen' => ['   '],
            'kaputtes JSON' => ['{produkte: 80}'],
            'abgeschnitten' => ['{"produkte": {"quality": 80}'],
            'kein Objekt' => ['"nur ein Text"'],
            'Zahl' => ['42'],
        ];
    }

    /**
     * Was: Unbrauchbare Angaben im Textfeld.
     * Warum: **Sie dürfen die Umwandlung nicht anhalten.** Ein Tippfehler im Feld würde sonst den
     *        gesamten Bilderbestand blockieren — und die Meldung stünde in einem Protokoll, in
     *        das niemand sieht, solange nichts fehlt.
     */
    #[Test]
    #[DataProvider('unusableJsonCases')]
    public function unusableSettingsFallBackInsteadOfBreaking(mixed $json): void
    {
        $settings = $this->resolver(['RcMediaToolkit.config.webpFolderSettingsJson' => $json])
            ->resolveForPath('media/produkte/bild.jpg');

        self::assertSame(82, $settings->quality, 'Ohne brauchbare Angabe gilt die Vorgabe.');
    }

    /**
     * Was: Eine Einstellung für genau diesen Ordner.
     * Warum: Der eigentliche Zweck der Klasse — Produktbilder dürfen stärker verkleinert werden
     *        als Bilder, bei denen es auf Details ankommt.
     */
    #[Test]
    public function aFolderSettingBeatsTheDefault(): void
    {
        $settings = $this->resolver([
            'RcMediaToolkit.config.webpFolderSettingsJson' => '{"produkte":{"quality":55,"lossless":true}}',
        ])->resolveForPath('media/produkte/bild.jpg');

        self::assertSame(55, $settings->quality);
        self::assertTrue($settings->lossless);
    }

    /**
     * Was: Ein Ordner ohne eigene Einstellung.
     * Warum: Dann gilt der Sammeleintrag — und erst wenn auch der fehlt, die Vorgabe. Ohne diese
     *        Stufe müsste jeder Ordner einzeln gepflegt werden.
     */
    #[Test]
    public function theCollectiveEntryCoversFoldersWithoutTheirOwn(): void
    {
        $settings = $this->resolver([
            'RcMediaToolkit.config.webpFolderSettingsJson' => '{"default":{"quality":61}}',
        ])->resolveForPath('media/irgendwas/bild.jpg');

        self::assertSame(61, $settings->quality);
    }

    /**
     * Was: Eine Güte außerhalb des Möglichen im Textfeld.
     * Warum: Sie wird eingefangen statt weitergereicht — sonst führte ein Tippfehler im Feld zu
     *        einer Ausnahme mitten in der Umwandlung.
     */
    #[Test]
    public function anImpossibleQualityFromTheFieldIsClamped(): void
    {
        $tooHigh = $this->resolver([
            'RcMediaToolkit.config.webpFolderSettingsJson' => '{"produkte":{"quality":900}}',
        ])->resolveForPath('media/produkte/bild.jpg');

        $tooLow = $this->resolver([
            'RcMediaToolkit.config.webpFolderSettingsJson' => '{"produkte":{"quality":-5}}',
        ])->resolveForPath('media/produkte/bild.jpg');

        self::assertSame(100, $tooHigh->quality);
        self::assertSame(0, $tooLow->quality);
    }

    /**
     * Was: Das Entfernen der Zusatzangaben ist an, solange niemand es abschaltet.
     * Warum: In Bildern stecken Aufnahmeort und Gerätekennung. Die Vorgabe muss deshalb
     *        „entfernen" lauten und nicht „behalten" — wer sie braucht, schaltet sie bewusst ein.
     */
    #[Test]
    public function metadataIsStrippedUnlessSomebodySaysOtherwise(): void
    {
        self::assertTrue($this->resolver([])->resolveForPath('media/x/b.jpg')->stripMetadata);

        $keep = $this->resolver(['RcMediaToolkit.config.webpDefaultStripMetadata' => false])
            ->resolveForPath('media/x/b.jpg');

        self::assertFalse($keep->stripMetadata);
    }

    /**
     * Die Angaben werden gemerkt — aber `refresh()` wirft sie weg. Ohne das läse ein lang
     * laufender Arbeitsprozess eine Einstellung, die längst geändert wurde.
     */
    #[Test]
    public function refreshForgetsTheRememberedSettings(): void
    {
        // Nur der JSON-Schlüssel wechselt; die übrigen Werte bleiben unbeantwortet, sonst
        // verbrauchte schon der erste Aufruf die vorbereiteten Antworten.
        $aufrufe = 0;
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static function (string $key) use (&$aufrufe) {
                if ($key !== 'RcMediaToolkit.config.webpFolderSettingsJson') {
                    return null;
                }

                return ++$aufrufe === 1
                    ? '{"produkte":{"quality":30}}'
                    : '{"produkte":{"quality":70}}';
            },
        );

        $resolver = new WebPSettingsResolver($config, new MediaToolkitLogger(new NullLogger()));

        self::assertSame(30, $resolver->resolveForPath('media/produkte/b.jpg')->quality);

        $resolver->refresh();

        self::assertSame(70, $resolver->resolveForPath('media/produkte/b.jpg')->quality);
    }

    /**
     * Was: Ein Medienordner mit ungewöhnlichen Zeichen im Namen.
     * Warum: Der Ordnername wird zum Schlüssel in der Einstellung. Zeichen außerhalb von
     *        Buchstaben, Ziffern, Strich und Unterstrich fallen auf den Sammeleintrag zurück —
     *        sonst hinge die Güte eines Bildes an einem Zeichen, das niemand eingetippt hat.
     */
    #[Test]
    public function anUnusualFolderNameFallsBackToTheCollectiveEntry(): void
    {
        $resolver = $this->resolver([
            'RcMediaToolkit.config.webpFolderSettingsJson' => '{"default":{"quality":61}}',
        ]);

        self::assertSame('default', $resolver->resolveForPath('media/ordner mit leerzeichen/bild.jpg')->folderKey);
        self::assertSame(61, $resolver->resolveForPath('media/ordner mit leerzeichen/bild.jpg')->quality);
    }

    /**
     * Die Angaben werden nur einmal gelesen. Bei einem Massenlauf über Zehntausende Bilder wäre
     * jede erneute Auswertung des Textfeldes verlorene Zeit.
     */
    #[Test]
    public function theSettingsAreReadOnlyOnce(): void
    {
        $aufrufe = 0;
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static function (string $key) use (&$aufrufe) {
                if ($key === 'RcMediaToolkit.config.webpFolderSettingsJson') {
                    ++$aufrufe;

                    return '{"produkte":{"quality":55}}';
                }

                return null;
            },
        );

        $resolver = new WebPSettingsResolver($config, new MediaToolkitLogger(new NullLogger()));
        $resolver->resolveForPath('media/produkte/eins.jpg');
        $resolver->resolveForPath('media/produkte/zwei.jpg');

        self::assertSame(1, $aufrufe);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolver(array $config): WebPSettingsResolver
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $config[$key] ?? null,
        );

        return new WebPSettingsResolver($systemConfig, new MediaToolkitLogger(new NullLogger()));
    }
}
