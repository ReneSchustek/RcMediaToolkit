<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Subscriber;

use DateInterval;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Subscriber\WebPPictureSubscriber;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Was passiert, wenn unter dem Bildumschreiber etwas nicht funktioniert.
 *
 * Dieser Dienst hängt in **jeder** Storefront-Antwort. Er ist damit der einzige Teil des
 * Plugins, dessen Fehler Besucher unmittelbar treffen: Was hier eine Ausnahme wirft, ist keine
 * fehlende Optimierung mehr, sondern eine weiße Seite.
 *
 * Daraus folgt eine Zusage, die über allem steht: **Keine Störung unter ihm darf die Seite
 * kosten.** Nicht der Zwischenspeicher, nicht der Medienspeicher, nicht eine Adresse, mit der
 * niemand gerechnet hat. Im schlimmsten Fall wird nicht umgeschrieben — dann ist das Bild
 * größer als nötig, und mehr ist nicht passiert.
 *
 * Die zweite Zusage betrifft etwas, das nur auf lang laufenden Arbeitern auffällt: Die
 * Merkliste im Dienst wächst nicht unbegrenzt. Bei klassischem PHP-FPM lebt der Dienst einen
 * Aufruf lang; unter Frankenphp oder Roadrunner bedient dieselbe Instanz Tausende.
 */
#[CoversClass(WebPPictureSubscriber::class)]
final class WebPPictureSubscriberResilienceTest extends TestCase
{
    /**
     * Was: Der Zeitpunkt, zu dem sich der Dienst einklinkt.
     * Warum: Er arbeitet auf **fertigem** HTML. Liefe er früher, hätte das Seitenlayout die
     *        Bilder noch nicht eingesetzt, und er schriebe an einer Antwort, die es so nicht
     *        gibt. Die späte Einordnung ist deshalb keine Feinheit, sondern Voraussetzung.
     */
    #[Test]
    public function theSubscriberRunsAfterEverythingElse(): void
    {
        $angemeldet = WebPPictureSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::RESPONSE, $angemeldet);
        self::assertSame('onKernelResponse', $angemeldet[KernelEvents::RESPONSE][0]);
        self::assertLessThan(0, $angemeldet[KernelEvents::RESPONSE][1], 'Der Dienst arbeitet zuletzt.');
    }

    /**
     * Was: Der Zwischenspeicher lässt sich nicht lesen.
     * Warum: **Die Seite muss trotzdem stehen.** Ein Redis, der gerade nicht antwortet, darf
     *        nicht dazu führen, dass der Shop nicht ausliefert — dann wird eben der Speicher
     *        gefragt, so wie vor der Einführung des Zwischenspeichers.
     */
    #[Test]
    public function aBrokenCacheDoesNotCostThePage(): void
    {
        $ergebnis = $this->rewrite(
            '<img src="/media/a.jpg">',
            new StubbornFilesystem(['media/a.jpg.webp' => true]),
            new BrokenCache(beimLesen: true),
        );

        self::assertStringContainsString('<source srcset="/media/a.jpg.webp"', $ergebnis);
    }

    /**
     * Dasselbe für das Schreiben: Lässt sich die Antwort nicht ablegen, wird sie eben beim
     * nächsten Mal wieder ermittelt. Der Besucher merkt davon nichts.
     */
    #[Test]
    public function anUnwritableCacheDoesNotCostThePageEither(): void
    {
        $ergebnis = $this->rewrite(
            '<img src="/media/a.jpg">',
            new StubbornFilesystem(['media/a.jpg.webp' => true]),
            new BrokenCache(beimSchreiben: true),
        );

        self::assertStringContainsString('<source srcset="/media/a.jpg.webp"', $ergebnis);
    }

    /**
     * Was: Der Medienspeicher antwortet nicht.
     * Warum: Bei angebundenem Fremdspeicher reicht dafür eine Zeitüberschreitung. Dann gilt das
     *        Bild als nicht umgewandelt — die Seite zeigt das Original und bleibt vollständig.
     *        **Eine Ausnahme an dieser Stelle wäre ein Ausfall des ganzen Shops.**
     */
    #[Test]
    public function anUnreachableStorageLeavesThePageIntact(): void
    {
        $ergebnis = $this->rewrite(
            '<img src="/media/a.jpg">',
            new StubbornFilesystem([], klemmt: true),
            new ArrayAdapter(),
        );

        self::assertSame('<img src="/media/a.jpg">', $ergebnis, 'Unverändert, aber vollständig.');
    }

    /**
     * Was: Dieselbe Adresse mehrfach auf einer Seite.
     * Warum: Der Regelfall — ein Logo steht in Kopf und Fuß. Gefragt wird der Speicher **einmal**;
     *        bei achtzig Bildern je Seite und angebundenem Fremdspeicher ist das der Unterschied
     *        zwischen einer und achtzig Anfragen über das Netz.
     */
    #[Test]
    public function theSameImageIsLookedUpOnlyOnce(): void
    {
        $speicher = new StubbornFilesystem(['media/a.jpg.webp' => true]);

        $this->rewrite(
            '<img src="/media/a.jpg"><img src="/media/a.jpg"><img src="/media/a.jpg">',
            $speicher,
            new ArrayAdapter(),
        );

        self::assertSame(1, $speicher->existsCalls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableAddresses(): array
    {
        return [
            'ohne Medienpfad' => ['<img src="/theme/logo.png">'],
            'Verzeichniswechsel' => ['<img src="/media/../../etc/passwd.jpg">'],
            'nur Fragezeichen' => ['<img src="/media/.jpg?">'],
        ];
    }

    /**
     * Was: Adressen, aus denen sich kein Speicherpfad ableiten lässt.
     * Warum: Sie bleiben, wie sie sind. Der Umschreiber fasst nur an, was er sicher zuordnen
     *        kann — ein Pfad mit Verzeichniswechsel wird gar nicht erst nachgeschlagen.
     */
    #[Test]
    #[DataProvider('unusableAddresses')]
    public function anAddressThatCannotBeResolvedStaysUntouched(string $markup): void
    {
        $speicher = new StubbornFilesystem([]);

        self::assertSame($markup, $this->rewrite($markup, $speicher, new ArrayAdapter()));
    }

    /**
     * Was: Die Merkliste im Dienst wächst nicht unbegrenzt.
     * Warum: Bei klassischem PHP-FPM lebt der Dienst einen Aufruf lang, bei einem lang laufenden
     *        Arbeiter Tausende. **Ohne Grenze wäre das ein Speicherleck**, das erst nach Stunden
     *        auffällt — und dann als Absturz des Arbeiters, nicht als Fehler dieses Dienstes.
     */
    #[Test]
    public function theInMemoryListDoesNotGrowWithoutBound(): void
    {
        $subscriber = $this->subscriber(new StubbornFilesystem([]), new ArrayAdapter());

        $bilder = '';
        for ($i = 0; $i < 5_200; ++$i) {
            $bilder .= sprintf('<img src="/media/bild%d.jpg">', $i);
        }

        $this->callRewrite($subscriber, $bilder);

        $eigenschaft = (new ReflectionClass($subscriber))->getProperty('webpExistenceCache');
        $eigenschaft->setAccessible(true);
        /** @var array<string, bool> $merkliste */
        $merkliste = $eigenschaft->getValue($subscriber);

        self::assertLessThan(5_200, count($merkliste), 'Die Merkliste muss zwischendurch abgeräumt werden.');
        self::assertGreaterThan(0, count($merkliste), 'Ganz leer wäre sie nutzlos.');
    }

    /**
     * Was: Eine Adresse, die sich nicht zerlegen lässt.
     * Warum: In Inhalten steht alles Mögliche — auch Zeichenfolgen, die nur wie eine Adresse
     *        aussehen. Sie bleiben unangetastet, statt den Umschreiber über eine Zerlegung
     *        stolpern zu lassen, die es nicht gibt.
     */
    #[Test]
    public function anUnparsableAddressIsLeftAlone(): void
    {
        $markup = '<img src="/media/bild.jpg?p=' . chr(0) . '">';

        self::assertSame(
            $markup,
            $this->rewrite($markup, new StubbornFilesystem([]), new ArrayAdapter()),
        );
    }

    private function rewrite(string $body, FilesystemAdapterInterface $filesystem, CacheItemPoolInterface $cache): string
    {
        return $this->callRewrite($this->subscriber($filesystem, $cache), $body);
    }

    private function subscriber(FilesystemAdapterInterface $filesystem, CacheItemPoolInterface $cache): WebPPictureSubscriber
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(true);

        return new WebPPictureSubscriber(
            new ModuleRegistry($config),
            $config,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $cache,
        );
    }

    private function callRewrite(WebPPictureSubscriber $subscriber, string $body): string
    {
        $methode = (new ReflectionClass($subscriber))->getMethod('rewriteImgTags');
        $methode->setAccessible(true);

        return (string) $methode->invoke($subscriber, $body);
    }
}

/**
 * Ein Speicher, der auf Wunsch klemmt und mitzählt, wie oft er gefragt wurde.
 */
final class StubbornFilesystem implements FilesystemAdapterInterface
{
    public int $existsCalls = 0;

    /**
     * @param array<string, bool> $vorhanden
     */
    public function __construct(
        private readonly array $vorhanden,
        private readonly bool $klemmt = false,
    ) {
    }

    public function exists(string $path): bool
    {
        ++$this->existsCalls;

        if ($this->klemmt) {
            throw new RuntimeException('Speicher antwortet nicht');
        }

        return $this->vorhanden[$path] ?? false;
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

/**
 * Ein Zwischenspeicher, der beim Lesen oder beim Schreiben scheitert — so wie ein Redis, der
 * gerade nicht erreichbar ist.
 */
final class BrokenCache implements CacheItemPoolInterface
{
    public function __construct(
        private readonly bool $beimLesen = false,
        private readonly bool $beimSchreiben = false,
    ) {
    }

    public function getItem(string $key): CacheItemInterface
    {
        if ($this->beimLesen) {
            throw new RuntimeException('Zwischenspeicher nicht erreichbar');
        }

        return new PassiveCacheItem($key);
    }

    /**
     * @param list<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        return [];
    }

    public function hasItem(string $key): bool
    {
        return false;
    }

    public function clear(): bool
    {
        return true;
    }

    public function deleteItem(string $key): bool
    {
        return true;
    }

    public function deleteItems(array $keys): bool
    {
        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        if ($this->beimSchreiben) {
            throw new RuntimeException('Zwischenspeicher nicht schreibbar');
        }

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }
}

/**
 * Ein Eintrag, der nichts behält — er wird nur gebraucht, damit der Zwischenspeicher überhaupt
 * einen herausgeben kann.
 */
final class PassiveCacheItem implements CacheItemInterface
{
    private mixed $wert = null;

    public function __construct(private readonly string $key)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->wert;
    }

    public function isHit(): bool
    {
        return false;
    }

    public function set(mixed $value): static
    {
        $this->wert = $value;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(DateInterval|int|null $time): static
    {
        return $this;
    }
}
