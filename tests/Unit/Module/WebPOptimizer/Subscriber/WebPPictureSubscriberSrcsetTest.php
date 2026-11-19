<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Subscriber\WebPPictureSubscriber;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Bilder mit `srcset`, Vorschaubilder und Vorlade-Links.
 *
 * Galerie, Kacheln und Schieber holen ihre Größe aus einem `srcset` aus Vorschaubildern unter
 * `/thumbnail/`. An genau diesen Bildern hängt die Ladezeit einer Produktseite; ein Umbau, der sie
 * auslässt, wandelt Dateien um, die niemand sieht.
 */
#[CoversClass(WebPPictureSubscriber::class)]
final class WebPPictureSubscriberSrcsetTest extends TestCase
{
    private const GALLERY_IMG = '<img src="https://shop.test/media/64/ec/BGB-IA.png?ts=1713529363" '
        . 'srcset="https://shop.test/thumbnail/64/ec/BGB-IA_400x225.png?ts=1765879516 400w, '
        . 'https://shop.test/thumbnail/64/ec/BGB-IA_600x338.png?ts=1765879516 600w, '
        . 'https://shop.test/thumbnail/64/ec/BGB-IA_800x450.png?ts=1765879516 800w" '
        . 'sizes="(min-width: 1200px) 600px, 100vw" alt="Geländer" fetchpriority="high">';

    private const GALLERY_WEBP = [
        'thumbnail/64/ec/BGB-IA_400x225.png.webp' => true,
        'thumbnail/64/ec/BGB-IA_600x338.png.webp' => true,
        'thumbnail/64/ec/BGB-IA_800x450.png.webp' => true,
    ];

    /**
     * Was: Ein Galeriebild mit drei Vorschaubildern, alle umgewandelt.
     * Warum: Die Quelle muss jede Größe mit ihrer Breite tragen und `sizes` übernehmen. Ohne
     *        `sizes` rechnete der Browser mit voller Bildschirmbreite und holte die 800er, wo
     *        vorher die 400er reichte; das WebP wäre dann größer als das PNG, das es ersetzt.
     *        Der Zeitstempel bleibt hinter der Endung, sonst zeigte die Adresse auf das PNG.
     */
    #[Test]
    public function aGalleryImageGetsAWebpSourceWithEverySizeAndItsSizes(): void
    {
        $result = $this->rewrite(self::GALLERY_WEBP, self::GALLERY_IMG);

        self::assertStringStartsWith('<picture><source type="image/webp" srcset="', $result);
        self::assertStringContainsString(
            'srcset="https://shop.test/thumbnail/64/ec/BGB-IA_400x225.png.webp?ts=1765879516 400w, '
            . 'https://shop.test/thumbnail/64/ec/BGB-IA_600x338.png.webp?ts=1765879516 600w, '
            . 'https://shop.test/thumbnail/64/ec/BGB-IA_800x450.png.webp?ts=1765879516 800w"',
            $result,
        );
        self::assertStringContainsString('sizes="(min-width: 1200px) 600px, 100vw">', $result);
        self::assertStringEndsWith(self::GALLERY_IMG . '</picture>', $result, 'Das Bild selbst bleibt unverändert.');
    }

    /**
     * Was: Eine Größe hat keine Fassung, etwa die kleinste Stufe unter der Mindestgröße.
     * Warum: Der Browser wählt aus der WebP-Quelle frei. Zeigte sie auf die fehlende Datei, bliebe
     *        das Bild leer. Mit der Originaladresse lädt er das PNG und zeigt es. Verlangte die
     *        Regel dagegen jede Größe, fiele fast jedes Bild durch: Die 200er Stufe liegt meist
     *        unter 10 KB und bekommt nie eine Fassung.
     */
    #[Test]
    public function aSizeWithoutWebpKeepsItsOriginalAddressInTheSource(): void
    {
        $webp = self::GALLERY_WEBP;
        unset($webp['thumbnail/64/ec/BGB-IA_400x225.png.webp']);

        $result = $this->rewrite($webp, self::GALLERY_IMG);

        self::assertStringContainsString(
            'srcset="https://shop.test/thumbnail/64/ec/BGB-IA_400x225.png?ts=1765879516 400w, '
            . 'https://shop.test/thumbnail/64/ec/BGB-IA_600x338.png.webp?ts=1765879516 600w, ',
            $result,
        );
        self::assertStringNotContainsString('BGB-IA_400x225.png.webp', $result);
    }

    /**
     * Was: Keine einzige Größe hat eine Fassung.
     * Warum: Dann brächte das `<picture>` nichts und kostete nur Platz im HTML.
     */
    #[Test]
    public function anImageWithoutAnyWebpStaysUntouched(): void
    {
        self::assertSame(self::GALLERY_IMG, $this->rewrite([], self::GALLERY_IMG));
    }

    /**
     * Was: Ein Vorschaubild nur im `src`.
     * Warum: `/thumbnail/` ist neben `/media/` der zweite Ablageort des öffentlichen
     *        Dateisystems; dort liegen die Bilder, die Seiten tatsächlich zeigen.
     */
    #[Test]
    public function aThumbnailInSrcIsServedAsWebp(): void
    {
        $result = $this->rewrite(
            ['thumbnail/a/b_280x280.jpg.webp' => true],
            '<img src="/thumbnail/a/b_280x280.jpg?ts=5" alt="">',
        );

        self::assertStringContainsString('<source srcset="/thumbnail/a/b_280x280.jpg.webp?ts=5" type="image/webp">', $result);
    }

    /**
     * Was: `data-src` und `data-srcset` stehen vor den echten Attributen.
     * Warum: Vor `src` in `data-src` sieht eine Wortgrenze einen Anfang. Wer dort liest, baut die
     *        Quelle aus einer Adresse, die ein Skript erst später setzt, oder gar nicht.
     */
    #[Test]
    public function dataAttributesAreNotReadAsSources(): void
    {
        $img = '<img data-srcset="/media/lazy.jpg 1x" data-src="/media/lazy.jpg" src="/media/echt.jpg" alt="">';
        $result = $this->rewrite(['media/echt.jpg.webp' => true, 'media/lazy.jpg.webp' => true], $img);

        self::assertStringContainsString('<source srcset="/media/echt.jpg.webp" type="image/webp">', $result);
        self::assertStringNotContainsString('lazy.jpg.webp', $result);
    }

    /**
     * Was: Ein schon maskiertes `&amp;` in der Adresse.
     * Warum: Die Werte kommen aus gerendertem HTML. Ein zweites Maskieren machte `&amp;amp;`
     *        daraus, und die Adresse träfe keine Datei mehr.
     */
    #[Test]
    public function escapedAmpersandsInSrcsetSurviveUnchanged(): void
    {
        $img = '<img src="/media/a.jpg" srcset="/media/a.jpg?ts=1&amp;w=2 1x" alt="">';
        $result = $this->rewrite(['media/a.jpg.webp' => true], $img);

        self::assertStringContainsString('srcset="/media/a.jpg.webp?ts=1&amp;w=2 1x"', $result);
        self::assertStringNotContainsString('&amp;amp;', $result);
    }

    /**
     * Was: Bilder, die schon WebP oder SVG sind.
     * Warum: Für sie gibt es keine Fassung; die Frage danach kostete nur einen Weg zur Platte.
     */
    #[Test]
    public function imagesThatAreNotJpegOrPngAreNotAskedFor(): void
    {
        $filesystem = new FakeFilesystem([]);
        $body = '<img src="/media/logo.svg" alt=""><img src="/thumbnail/x_800x400.webp" srcset="/thumbnail/x_800x400.webp 800w" alt="">';

        self::assertSame($body, $this->rewriteWith($filesystem, $body));
        self::assertSame(0, $filesystem->existsCalls);
    }

    /**
     * Was: Der Kopf lädt ein PNG vor, das Bild darunter bekommt eine WebP-Quelle.
     * Warum: Bliebe das Vorladen beim PNG, holte der Browser beide Dateien, und das Vorladen
     *        verzögerte genau das Bild, das es beschleunigen soll.
     */
    #[Test]
    public function aPreloadOfAPngPointsToItsWebp(): void
    {
        $link = '<link rel="preload" as="image" fetchpriority="high" href="/thumbnail/h_800x400.png?ts=3" media="(max-width: 767.98px)">';
        $result = $this->rewrite(['thumbnail/h_800x400.png.webp' => true], $link);

        self::assertSame(
            '<link rel="preload" as="image" fetchpriority="high" href="/thumbnail/h_800x400.png.webp?ts=3" media="(max-width: 767.98px)" type="image/webp">',
            $result,
        );
    }

    /**
     * Was: Vorladen mit `imagesrcset`, eine Größe ohne Fassung.
     * Warum: Dieselbe Regel wie beim Bild, damit Vorladen und Bild dieselbe Datei wählen; sonst
     *        lüde der Browser zwei.
     */
    #[Test]
    public function aPreloadWithImagesrcsetFollowsTheSameRuleAsTheImage(): void
    {
        $link = '<link rel="preload" as="image" imagesrcset="/media/a.png 1x, /media/b.png 2x" imagesizes="100vw">';

        self::assertSame($link, $this->rewrite([], $link));
        self::assertStringContainsString(
            'imagesrcset="/media/a.png.webp 1x, /media/b.png 2x" imagesizes="100vw" type="image/webp">',
            $this->rewrite(['media/a.png.webp' => true], $link),
        );
    }

    /**
     * Was: Links, die keine Bilder vorladen oder schon einen Typ tragen.
     * Warum: Ein Stilbogen oder eine Schrift hat keine Fassung, und ein Link mit `type` hat seine
     *        Wahl schon getroffen.
     */
    #[Test]
    public function otherLinksStayAsTheyAre(): void
    {
        $webp = ['media/a.png.webp' => true];
        $body = '<link rel="stylesheet" href="/media/a.png">'
            . '<link rel="preload" as="font" href="/media/a.png">'
            . '<link rel="preload" as="image" type="image/png" href="/media/a.png">';

        self::assertSame($body, $this->rewrite($webp, $body));
    }

    /**
     * @param array<string, bool> $webp
     */
    private function rewrite(array $webp, string $body): string
    {
        return $this->rewriteWith(new FakeFilesystem($webp), $body);
    }

    private function rewriteWith(FakeFilesystem $filesystem, string $body): string
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturn(true);

        $subscriber = new WebPPictureSubscriber(
            new ModuleRegistry($systemConfig),
            $systemConfig,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            new ArrayAdapter(),
        );

        $method = (new ReflectionClass($subscriber))->getMethod('rewriteImgTags');

        return (string) $method->invoke($subscriber, $body);
    }
}
