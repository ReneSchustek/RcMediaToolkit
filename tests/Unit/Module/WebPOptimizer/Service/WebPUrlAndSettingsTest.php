<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Service;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Dto\WebPSettings;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPUrl;

/**
 * Die Adressbildung und die Einstellungen einer Umwandlung.
 *
 * Die Adressbildung ist eine reine Funktion von vier Zeilen — und trotzdem eine Stelle, an der
 * ein Fehler still bleibt: Landet die Endung hinter Shopwares Zeitstempel im Abfrageteil, zeigt
 * der Pfad weiter auf das Original, und der Server liefert ein JPEG als WebP aus. Sichtbar ist
 * nichts, das Bild erscheint ja.
 */
#[CoversClass(WebPUrl::class)]
#[CoversClass(WebPSettings::class)]
final class WebPUrlAndSettingsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function urlCases(): array
    {
        return [
            'ohne alles' => ['media/ab/bild.jpg', 'media/ab/bild.jpg.webp'],
            'mit Zeitstempel' => ['media/ab/bild.jpg?ts=1784098309', 'media/ab/bild.jpg.webp?ts=1784098309'],
            'mit mehreren Angaben' => ['media/ab/bild.jpg?ts=1&w=200', 'media/ab/bild.jpg.webp?ts=1&w=200'],
            'mit Sprungmarke' => ['media/ab/bild.jpg#oben', 'media/ab/bild.jpg.webp#oben'],
            'Angabe und Sprungmarke' => ['media/ab/bild.jpg?ts=1#oben', 'media/ab/bild.jpg.webp?ts=1#oben'],
            'vollständige Adresse' => ['https://shop.example/media/x.png?ts=9', 'https://shop.example/media/x.png.webp?ts=9'],
            'Punkt im Verzeichnis' => ['media/v1.2/bild.jpeg', 'media/v1.2/bild.jpeg.webp'],
        ];
    }

    /**
     * Was: Die Endung gehört ans Ende des **Pfades**, nicht der Adresse.
     * Warum: Genau daran ist es zweimal gescheitert. Der Fall mit dem Zeitstempel ist der
     *        eigentliche — im Bestand trägt **jedes** Medium einen.
     */
    #[Test]
    #[DataProvider('urlCases')]
    public function theSuffixGoesIntoThePath(string $url, string $expected): void
    {
        self::assertSame($expected, WebPUrl::variantOf($url));
    }

    /**
     * Was: Eine Güte außerhalb von 0 bis 100.
     * Warum: Sie geht als Befehlszeilenangabe an ein fremdes Programm. Ein unsinniger Wert
     *        führte dort zu einem Abbruch, dessen Meldung niemand mit der Einstellung in
     *        Verbindung bringt — die Grenze gehört an die Stelle, an der der Wert entsteht.
     */
    #[Test]
    public function anImpossibleQualityIsRefusedAtTheSource(): void
    {
        foreach ([-1, 101, 1000] as $quality) {
            try {
                new WebPSettings(quality: $quality, lossless: false, stripMetadata: true);
                self::fail(\sprintf('Güte %d hätte abgelehnt werden müssen.', $quality));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1); // Der erwartete Abbruch ist eingetreten — das ist die Prüfung.
            }
        }
    }

    /**
     * Die Gegenprobe: Die Ränder selbst sind gültig. Null bedeutet stärkste Verkleinerung,
     * hundert die beste Güte — beides sind sinnvolle Einstellungen.
     */
    #[Test]
    public function bothEdgesOfTheQualityScaleAreAllowed(): void
    {
        self::assertSame(0, (new WebPSettings(0, false, true))->quality);
        self::assertSame(100, (new WebPSettings(100, false, true))->quality);
    }

    /**
     * Ohne Angabe gilt der Sammelschlüssel. Er ist der Rückfall, wenn für einen Medienordner
     * nichts eingestellt ist.
     */
    #[Test]
    public function withoutAFolderTheDefaultKeyApplies(): void
    {
        self::assertSame('default', (new WebPSettings(80, false, true))->folderKey);
    }
}
