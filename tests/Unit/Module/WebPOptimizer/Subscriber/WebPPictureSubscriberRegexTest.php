<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Subscriber\WebPPictureSubscriber;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Die Stellen, an denen ein Umschreiber per regulärem Ausdruck leicht scheitert:
 *  - ein Rückverweis in einer Zeichenklasse wie `[^\1\s]`, der dort keiner ist und den
 *    Ausdruck über Tag-Grenzen greifen lässt
 *  - Double-HTML-Encoding bei `&amp;`
 *  - Excluded-Path-Filter
 *  - Path-Traversal-Schutz in urlToFilesystemPath
 */
#[CoversClass(WebPPictureSubscriber::class)]
final class WebPPictureSubscriberRegexTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function excludedPathCases(): array
    {
        return [
            'api'         => ['/api/_action/foo', true],
            'action'      => ['/_action/bar', true],
            'admin-root'  => ['/admin', true],
            'admin-sub'   => ['/admin/login', true],
            'profiler'    => ['/_profiler/abcd', true],
            'wdt'         => ['/_wdt/abc', true],
            'fragment'    => ['/_fragment/foo', true],
            'storefront-home'    => ['/', false],
            'storefront-listing' => ['/Bekleidung', false],
            'storefront-account' => ['/account/orders', false],
        ];
    }

    #[Test]
    #[DataProvider('excludedPathCases')]
    public function isExcludedPathHandlesAdminAndStorefront(string $path, bool $expected): void
    {
        $subscriber = $this->buildSubscriber(new FakeFilesystem());

        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('isExcludedPath');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invoke($subscriber, $path));
    }

    #[Test]
    public function rewriteWrapsDoubleQuotedSrcAttribute(): void
    {
        $fs = new FakeFilesystem(['media/products/foo.jpg.webp' => true]);
        $subscriber = $this->buildSubscriber($fs);

        $body = '<p><img src="/media/products/foo.jpg" alt="Test"></p>';
        $result = $this->callRewrite($subscriber, $body);

        self::assertStringContainsString('<picture>', $result);
        self::assertStringContainsString('<source srcset="/media/products/foo.jpg.webp" type="image/webp">', $result);
        self::assertStringContainsString('<img src="/media/products/foo.jpg" alt="Test">', $result);
    }

    #[Test]
    public function rewriteWrapsSingleQuotedSrcAttribute(): void
    {
        $fs = new FakeFilesystem(['media/products/foo.jpg.webp' => true]);
        $subscriber = $this->buildSubscriber($fs);

        $body = "<img src='/media/products/foo.jpg' alt='Test'>";
        $result = $this->callRewrite($subscriber, $body);

        self::assertStringContainsString('<picture>', $result);
        self::assertStringContainsString("<source srcset=\"/media/products/foo.jpg.webp\"", $result);
    }

    #[Test]
    public function rewriteDoesNotDoubleEncodeAttributesInTag(): void
    {
        // Doppelte Kodierung: htmlspecialchars auf eine URL aus dem Twig-Output machte aus
        // `&amp;` ein `&amp;amp;`.
        // Hier ist die Adresse selbst ohne Abfrageteil, das Alt-Attribut enthält ein `&amp;`.
        // Der Test stellt sicher, dass der Subscriber den ursprünglichen `<img>`-Tag unverändert
        // in das `<picture>`-Element weiterreicht.
        //
        // Die Adresse ohne Abfrageteil ist eine Vereinfachung für diesen Fall, nicht der
        // Normalfall: Shopware hängt an jede Medien-Adresse einen Zeitstempel. Den Fall mit
        // `?ts=…` prüft ein eigener Test weiter unten.
        $fs = new FakeFilesystem(['media/products/foo.jpg.webp' => true]);
        $subscriber = $this->buildSubscriber($fs);

        $body = '<img src="/media/products/foo.jpg" alt="A &amp; B">';
        $result = $this->callRewrite($subscriber, $body);

        self::assertStringContainsString('alt="A &amp; B"', $result);
        self::assertStringNotContainsString('&amp;amp;', $result, 'Double-Encoding-Regression!');
        self::assertStringContainsString('srcset="/media/products/foo.jpg.webp"', $result);
    }

    #[Test]
    public function rewriteDoesNotMatchAcrossTagBoundary(): void
    {
        // Ein zu locker gefasster Ausdruck griffe über mehrere Tags hinweg. Hier ein böser Mix mit zwei <img>-Tags in einem String.
        $fs = new FakeFilesystem(['media/a.jpg.webp' => true, 'media/b.jpg.webp' => true]);
        $subscriber = $this->buildSubscriber($fs);

        $body = '<img src="/media/a.jpg"> Lorem <img src="/media/b.jpg">';
        $result = $this->callRewrite($subscriber, $body);

        // Beide werden einzeln gewrappt, das Lorem bleibt unverändert dazwischen.
        self::assertSame(2, substr_count($result, '<picture>'));
        self::assertStringContainsString('Lorem', $result);
    }

    #[Test]
    public function rewriteSkipsImgWithExistingSrcset(): void
    {
        $fs = new FakeFilesystem(['media/x.jpg.webp' => true]);
        $subscriber = $this->buildSubscriber($fs);

        $body = '<img src="/media/x.jpg" srcset="/media/x@2x.jpg 2x">';
        $result = $this->callRewrite($subscriber, $body);

        self::assertStringNotContainsString('<picture>', $result, 'srcset-Tags sollen unangetastet bleiben');
    }

    #[Test]
    public function rewriteSkipsWhenWebpDoesNotExist(): void
    {
        $fs = new FakeFilesystem([]); // Keine .webp existiert
        $subscriber = $this->buildSubscriber($fs);

        $body = '<img src="/media/missing.jpg">';
        $result = $this->callRewrite($subscriber, $body);

        self::assertSame($body, $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePathCases(): array
    {
        return [
            'traversal' => ['/media/../etc/passwd'],
            'nul-byte'  => ["/media/x\x00.jpg"],
        ];
    }

    #[Test]
    #[DataProvider('unsafePathCases')]
    public function urlToFilesystemPathRejectsUnsafeInput(string $url): void
    {
        $subscriber = $this->buildSubscriber(new FakeFilesystem());

        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('urlToFilesystemPath');
        $method->setAccessible(true);

        self::assertNull($method->invoke($subscriber, $url));
    }


    /**
     * Was: Ein Bild, das bereits in einem `<picture>` steckt.
     * Warum: **Der Hauptfall.** Verschachtelte `<picture>` sind ungültiges HTML, und der
     *        Browser nimmt dann die Quelle des äußeren — also genau die Datei, die der innere
     *        vermeiden wollte. Im Standard-Theme betrifft das das Kopf-Logo, das Shopware
     *        selbst in ein `<picture>` setzt.
     * Erwartet: unverändert.
     */
    #[Test]
    public function anImageInsideAnExistingPictureIsLeftAlone(): void
    {
        $subscriber = $this->buildSubscriber(new FakeFilesystem(['media/logo.png.webp' => true]));

        $body = '<picture><source srcset="/media/logo.avif" type="image/avif">'
            . '<img src="/media/logo.png" alt="Logo"></picture>';

        self::assertSame($body, $this->callRewrite($subscriber, $body));
    }

    /**
     * Was: Ein freies Bild neben einem, das schon in einem `<picture>` sitzt.
     * Warum: Die Gegenprobe. Ein Schutz, der auch das freie Bild verschont, hätte das Modul
     *        wirkungslos gemacht — und wäre in einem Test, der nur den ersten Fall prüft,
     *        nicht aufgefallen.
     * Erwartet: Das freie Bild wird umschlossen, das andere bleibt, wie es war.
     */
    #[Test]
    public function aFreeImageNextToAPictureIsStillRewritten(): void
    {
        $subscriber = $this->buildSubscriber(new FakeFilesystem([
            'media/logo.png.webp' => true,
            'media/frei.jpg.webp' => true,
        ]));

        $body = '<picture><img src="/media/logo.png"></picture><img src="/media/frei.jpg">';

        $ergebnis = $this->callRewrite($subscriber, $body);

        self::assertStringContainsString('<picture><img src="/media/logo.png"></picture>', $ergebnis);
        self::assertStringContainsString('<source srcset="/media/frei.jpg.webp"', $ergebnis);
    }

    /**
     * Was: Die Adresse, die Shopware im Betrieb wirklich ausliefert — mit Zeitstempel.
     * Warum: **Der Normalfall im Betrieb.** `MediaUrlGenerator` hängt an jede Medien-Adresse `?ts=…`, sobald
     *        das Medium ein `updated_at` hat — auf beiden geprüften Instanzen sind das 100 %
     *        (5614 von 5614 bzw. 303 von 303). Jeder andere Fall in dieser Datei arbeitet mit
     *        einer sauberen Adresse, also mit einer Eingabe, die es im Betrieb nicht gibt.
     *        Verlangte der Ausdruck die Endung unmittelbar vor dem Anführungszeichen, griffe
     *        er an allem vorbei, was der Shop selbst rendert.
     * Erwartet: umschlossen — und die Endung sitzt im Pfad, nicht im Abfrageteil.
     */
    #[Test]
    public function anAddressCarryingShopwaresTimestampIsRewrittenAsWell(): void
    {
        $subscriber = $this->buildSubscriber(new FakeFilesystem(['media/products/foo.jpg.webp' => true]));

        $body = '<img src="/media/products/foo.jpg?ts=1784098309" alt="Test">';

        self::assertStringContainsString(
            '<source srcset="/media/products/foo.jpg.webp?ts=1784098309" type="image/webp">',
            $this->callRewrite($subscriber, $body),
        );
    }

    /**
     * Was: Eine Adresse mit Zeitstempel, zu der es keine umgewandelte Fassung gibt.
     * Warum: Die Gegenprobe zum vorigen Fall. Ein Ausdruck, der den Abfrageteil zwar erkennt,
     *        ihn aber bei der Existenzprüfung stehen lässt, würde `foo.jpg?ts=1.webp` im
     *        Dateisystem suchen — nie finden und deshalb nie umschreiben. Der Fehler sähe von
     *        außen aus wie „es gibt keine WebP-Fassung" und wäre damit unsichtbar.
     * Erwartet: unverändert, weil die Datei wirklich fehlt — nicht, weil der Pfad falsch war.
     */
    #[Test]
    public function aTimestampedAddressWithoutAConvertedFileStaysUntouched(): void
    {
        $subscriber = $this->buildSubscriber(new FakeFilesystem([]));

        $body = '<img src="/media/products/foo.jpg?ts=1784098309" alt="Test">';

        self::assertSame($body, $this->callRewrite($subscriber, $body));
    }

    /**
     * Was: Dasselbe Bild in **zwei getrennten Aufrufen**.
     * Warum: Läge die Antwort nur im Dienst, lebte sie bei klassischem PHP-FPM genau einen
     *        Aufruf lang — 80 Bilder bedeuteten 80 Dateisystem-Abfragen je Seitenaufruf, bei
     *        externem Speicher 80 Netzwege.
     *
     *        Bewusst mit zwei Instanzen am selben Speicher: Ein Test mit **einer** Instanz
     *        wäre auch mit einem reinen Dienst-Cache grün und belegte nichts.
     * Erwartet: eine einzige Abfrage.
     */
    #[Test]
    public function theExistenceSurvivesTheRequest(): void
    {
        $fs = new FakeFilesystem(['media/a.jpg.webp' => true]);
        $cache = new ArrayAdapter();

        $this->callRewrite($this->buildSubscriber($fs, $cache), '<img src="/media/a.jpg">');
        $this->callRewrite($this->buildSubscriber($fs, $cache), '<img src="/media/a.jpg">');

        self::assertSame(1, $fs->existsCalls);
    }

    private function buildSubscriber(FakeFilesystem $fs, ?ArrayAdapter $cache = null): WebPPictureSubscriber
    {
        // ModuleRegistry ist `final` — kein Mock möglich. Echter Service mit Mock-Config.
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturn(true);

        $moduleRegistry = new ModuleRegistry($systemConfig);

        return new WebPPictureSubscriber(
            $moduleRegistry,
            $systemConfig,
            $fs,
            new MediaToolkitLogger(new NullLogger()),
            $cache ?? new ArrayAdapter(),
        );
    }

    private function callRewrite(WebPPictureSubscriber $subscriber, string $body): string
    {
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('rewriteImgTags');
        $method->setAccessible(true);
        return (string) $method->invoke($subscriber, $body);
    }
}

/**
 * Minimal-Filesystem-Adapter für die Tests — `exists()` ist hardcoded.
 */
final class FakeFilesystem implements FilesystemAdapterInterface
{
    /** Wie oft nach einer Datei gefragt wurde — der Beleg für die Zwischenspeicherung. */
    public int $existsCalls = 0;

    /** @param array<string, bool> $existsMap */
    public function __construct(private readonly array $existsMap = [])
    {
    }

    public function exists(string $path): bool
    {
        ++$this->existsCalls;

        return $this->existsMap[$path] ?? false;
    }

    public function read(string $path): string
    {
        return '';
    }

    public function write(string $path, string $contents): void
    {
    }

    public function delete(string $path): void
    {
    }

    public function size(string $path): int
    {
        return 0;
    }

    public function lastModified(string $path): int
    {
        return 0;
    }

    public function move(string $source, string $destination): void
    {
    }

    public function makeDirectory(string $path): void
    {
    }

    public function listContents(string $path, bool $recursive = false): array
    {
        return [];
    }
}
