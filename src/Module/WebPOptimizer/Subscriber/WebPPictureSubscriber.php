<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Subscriber;

use Psr\Cache\CacheItemPoolInterface;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPUrl;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Opt-in Subscriber: tauscht `<img>`-Tags durch `<picture>`-Wrapper mit WebP-Source.
 *
 * Aktivierung über `webpSubscriberEnabled`. Per Default deaktiviert — der Twig-Filter
 * `|rc_webp_url` ist der saubere Weg für eigene Templates.
 *
 * Sicherheits- und Performance-Grenzen:
 *  - Nur HTML-Responses (Content-Type startet mit `text/html`).
 *  - Nur 2xx-Responses.
 *  - Nur Master-Request (nicht in Sub-Requests / ESI / Fragment).
 *  - Nur Storefront-Routen (`/`, `/account`, etc.) — nicht `/api/`.
 *  - Existenz-Check je Pfad im gemeinsamen Objekt-Speicher, davor ein Dienst-Cache
 *    für denselben Aufruf.
 *  - Nur Bilder aus `/media/` und `/thumbnail/` mit JPEG/PNG; im `srcset` behält eine Größe ohne
 *    Fassung ihre Originaladresse. Vorhandene `<picture>`-Blöcke werden vorher ausgeblendet.
 *  - Vorlade-Links auf solche Bilder zeigen danach auf die Fassung.
 */
final class WebPPictureSubscriber implements EventSubscriberInterface
{
    /**
     * Ein Bild-Tag als Ganzes; die Attribute liest `attribute()` einzeln. Ein einziger Ausdruck
     * für Tag, Adresse und Endung griff an allem vorbei, was ein `srcset` trägt, und das sind
     * Galerie, Kacheln und Schieber.
     */
    private const IMG_TAG_REGEX = '~<img\b[^>]*>~i';

    private const LINK_TAG_REGEX = '~<link\b[^>]*>~i';

    /** Ablageorte des öffentlichen Dateisystems, deren Bilder eine Fassung haben können. */
    private const PUBLIC_ROOTS = ['/media/', '/thumbnail/'];

    private const CONVERTIBLE_EXTENSIONS = ['jpeg', 'jpg', 'png'];

    /** Response-Size-Cap — skip Subscriber bei sehr großen HTMLs (3 MB Default). */
    private const MAX_RESPONSE_BYTES = 3 * 1024 * 1024;

    /** Cache-Größen-Limit für `webpExistenceCache` — schützt Long-Running-Worker vor Leak. */
    private const MAX_CACHE_ENTRIES = 5000;

    /**
     * Wie lange die Antwort im gemeinsamen Speicher gilt.
     *
     * Kurz gehalten mit Absicht: Ein frisch konvertiertes Bild soll nicht stundenlang als
     * „gibt es nicht" gelten, nur weil jemand die Seite aufgerufen hat, bevor der Konverter
     * fertig war.
     */
    private const CACHE_TTL_SECONDS = 300;

    /** @var array<string, bool> */
    private array $webpExistenceCache = [];

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly SystemConfigService $systemConfigService,
        private readonly FilesystemAdapterInterface $filesystem,
        private readonly MediaToolkitLogger $logger,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (!$this->moduleRegistry->isActive(ModuleRegistry::MODULE_WEBP_OPTIMIZER)) {
            return;
        }
        if (!(bool) $this->systemConfigService->get('RcMediaToolkit.config.webpSubscriberEnabled')) {
            return;
        }

        $response = $event->getResponse();
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (!str_starts_with($contentType, 'text/html')) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if ($this->isExcludedPath($path)) {
            return;
        }

        $body = (string) $response->getContent();
        if ($body === '' || !str_contains($body, '<img')) {
            return;
        }
        // Response-Size-Cap: bei sehr großen HTML-Bodies (z.B. exportierte Sitemaps, Listing
        // mit hunderten Produkten) wird der RegEx-Rewrite zum Performance-Killer. Skip + Log,
        // damit der Operator das im Monitoring sieht.
        $bodySize = strlen($body);
        if ($bodySize > self::MAX_RESPONSE_BYTES) {
            $this->logger->withModule('WebPOptimizer')->warning(
                'Response zu groß für RegEx-Rewrite — übersprungen',
                ['bodySize' => $bodySize, 'cap' => self::MAX_RESPONSE_BYTES],
            );
            return;
        }

        $rewritten = $this->rewriteImgTags($body);
        if ($rewritten !== $body) {
            $response->setContent($rewritten);
        }
    }

    /**
     * Schreibt die Bilder um — aber nur die, die noch frei stehen.
     *
     * Ein `<img>`, das bereits in einem `<picture>` sitzt, darf nicht erneut umschlossen
     * werden: Verschachtelte `<picture>` sind ungültiges HTML, und der Browser nimmt dann
     * die Quelle des äußeren — also genau die Datei, die der innere gerade vermeiden
     * wollte. Im Standard-Theme betrifft das das Kopf-Logo, das Shopware selbst in ein
     * `<picture>` setzt.
     *
     * Statt den Bereich um jeden Treffer herum zu untersuchen, werden die vorhandenen
     * `<picture>`-Blöcke vorher ausgeblendet und hinterher unverändert zurückgelegt. Das
     * ist die einzige Variante, die auch bei mehreren Bildern in einem Block trägt.
     */
    private function rewriteImgTags(string $body): string
    {
        $vorhandene = [];
        $body = (string) preg_replace_callback(
            '~<picture\b.*?</picture>~is',
            static function (array $treffer) use (&$vorhandene): string {
                $marke = "\x00rc-picture-" . \count($vorhandene) . "\x00";
                $vorhandene[$marke] = $treffer[0];

                return $marke;
            },
            $body,
        );

        $body = $this->rewriteFreeImgTags($body);
        $body = $this->rewritePreloadLinks($body);

        return $vorhandene === [] ? $body : strtr($body, $vorhandene);
    }

    private function rewriteFreeImgTags(string $body): string
    {
        return (string) preg_replace_callback(
            self::IMG_TAG_REGEX,
            fn (array $treffer): string => $this->rewriteImgTag($treffer[0]),
            $body,
        );
    }

    /**
     * Setzt ein Bild in ein `<picture>` mit WebP-Quelle, sobald eine seiner Dateien eine Fassung hat.
     *
     * Eine Größe ohne Fassung behält in der Quelle ihre Originaladresse. Der Browser wählt aus der
     * Quelle frei; zeigte sie auf eine fehlende Datei, bliebe das Bild leer. Mit der Originaladresse
     * lädt er das PNG und zeigt es, denn das Format liest er aus der Datei, nicht aus `type`. Das
     * betrifft vor allem die kleinste Stufe, die oft unter der Mindestgröße bleibt und nie eine
     * Fassung bekommt. Die Quelle übernimmt `sizes`, sonst rechnete der Browser mit voller
     * Bildschirmbreite und lüde eine größere Datei als vorher.
     *
     * Die Werte stammen aus gerendertem HTML und sind schon maskiert; sie werden unverändert
     * übernommen, ein zweites Maskieren machte aus `&amp;` ein `&amp;amp;`.
     */
    private function rewriteImgTag(string $imgTag): string
    {
        $srcset = self::attribute($imgTag, 'srcset');
        if ($srcset !== null) {
            $webpSrcset = $this->webpSrcset($srcset);
            if ($webpSrcset === null) {
                return $imgTag;
            }
            $sizes = self::attribute($imgTag, 'sizes');

            return '<picture>'
                . '<source type="image/webp" srcset="' . $webpSrcset . '"'
                . ($sizes !== null ? ' sizes="' . $sizes . '"' : '')
                . '>'
                . $imgTag
                . '</picture>';
        }

        $src = self::attribute($imgTag, 'src');
        if ($src === null) {
            return $imgTag;
        }
        $webpUrl = $this->webpUrl($src);
        if ($webpUrl === null) {
            return $imgTag;
        }

        return '<picture>'
            . '<source srcset="' . $webpUrl . '" type="image/webp">'
            . $imgTag
            . '</picture>';
    }

    /**
     * Stellt das Vorladen eines Bildes auf seine WebP-Fassung um.
     *
     * Lädt der Kopf das PNG vor und wählt das Bild danach die WebP-Quelle, holt der Browser beide
     * Dateien, und das Vorladen verzögert genau das Bild, das es beschleunigen sollte. Ein Link
     * mit eigenem `type` bleibt, wie er ist; er hat seine Wahl schon getroffen.
     */
    private function rewritePreloadLinks(string $body): string
    {
        return (string) preg_replace_callback(
            self::LINK_TAG_REGEX,
            function (array $treffer): string {
                $linkTag = $treffer[0];
                $rel = preg_split('~\s+~', strtolower((string) self::attribute($linkTag, 'rel'))) ?: [];
                if (!in_array('preload', $rel, true)
                    || strtolower((string) self::attribute($linkTag, 'as')) !== 'image'
                    || self::attribute($linkTag, 'type') !== null
                ) {
                    return $linkTag;
                }

                $href = self::attribute($linkTag, 'href');
                $imageSrcset = self::attribute($linkTag, 'imagesrcset');
                if ($href === null && $imageSrcset === null) {
                    return $linkTag;
                }

                $webpHref = $href !== null ? $this->webpUrl($href) : null;
                $webpSrcset = $imageSrcset !== null ? $this->webpSrcset($imageSrcset) : null;
                if (($href !== null && $webpHref === null) || ($imageSrcset !== null && $webpSrcset === null)) {
                    return $linkTag;
                }

                if ($webpHref !== null) {
                    $linkTag = self::replaceAttribute($linkTag, 'href', $webpHref);
                }
                if ($webpSrcset !== null) {
                    $linkTag = self::replaceAttribute($linkTag, 'imagesrcset', $webpSrcset);
                }

                return (string) preg_replace('~\s*/?>$~', ' type="image/webp">', $linkTag, 1);
            },
            $body,
        );
    }

    /**
     * Das `srcset` mit den WebP-Fassungen, wo es sie gibt, sonst mit der Originaladresse. Null,
     * wenn keine einzige Größe eine Fassung hat; dann ändert sich nichts.
     */
    private function webpSrcset(string $srcset): ?string
    {
        $candidates = preg_split('~,\s+~', trim($srcset)) ?: [];
        if ($candidates === [] || $candidates === ['']) {
            return null;
        }

        $webpCandidates = [];
        $converted = 0;
        foreach ($candidates as $candidate) {
            if (preg_match('~^(\S+)(\s+\S.*)?$~s', trim($candidate), $teile) !== 1) {
                return null;
            }
            $webpUrl = $this->webpUrl($teile[1]);
            if ($webpUrl !== null) {
                ++$converted;
            }
            $webpCandidates[] = ($webpUrl ?? $teile[1]) . ($teile[2] ?? '');
        }

        return $converted === 0 ? null : implode(', ', $webpCandidates);
    }

    /**
     * Die Adresse der WebP-Fassung, oder null, wenn das Bild keine hat oder keine haben kann.
     */
    private function webpUrl(string $url): ?string
    {
        $relativePath = $this->urlToFilesystemPath($url);
        if ($relativePath === null || !$this->hasWebpVariant($relativePath)) {
            return null;
        }

        return WebPUrl::variantOf($url);
    }

    /**
     * Liest ein Attribut. `data-src` und `data-srcset` zählen nicht: Davor steht ein Bindestrich,
     * und genau dort sähe eine Wortgrenze einen Anfang.
     */
    private static function attribute(string $tag, string $name): ?string
    {
        $muster = '~(?<![\w-])' . preg_quote($name, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i';
        if (preg_match($muster, $tag, $treffer) !== 1) {
            return null;
        }

        return isset($treffer[2]) ? $treffer[2] : $treffer[1];
    }

    private static function replaceAttribute(string $tag, string $name, string $value): string
    {
        $muster = '~(?<![\w-])' . preg_quote($name, '~') . '\s*=\s*(?:"[^"]*"|\'[^\']*\')~i';

        return (string) preg_replace_callback($muster, static fn (): string => $name . '="' . $value . '"', $tag, 1);
    }

    /**
     * Schließt Admin-/API-/Profiler-Routen aus, damit der RegEx-Rewrite nur Storefront-HTMLs trifft.
     */
    private function isExcludedPath(string $path): bool
    {
        foreach (['/api/', '/_action/', '/admin/', '/_profiler', '/_wdt', '/_fragment'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return $path === '/admin';
    }

    private function urlToFilesystemPath(string $url): ?string
    {
        // NUL-Byte-Check direkt auf der Roh-URL — parse_url ersetzt \x00 mit `_`, sonst
        // würde ein Path-Traversal-Versuch über NUL-Bytes nicht erkannt.
        if (str_contains($url, "\x00")) {
            return null;
        }
        $parsed = parse_url($url);
        if ($parsed === false) {
            return null;
        }
        $rawPath = isset($parsed['path']) ? (string) $parsed['path'] : $url;

        $rootPos = null;
        foreach (self::PUBLIC_ROOTS as $root) {
            $pos = strpos($rawPath, $root);
            if ($pos !== false && ($rootPos === null || $pos < $rootPos)) {
                $rootPos = $pos;
            }
        }
        if ($rootPos === null) {
            return null;
        }
        $relative = ltrim(substr($rawPath, $rootPos), '/');
        if (str_contains($relative, '..')) {
            return null;
        }
        $extension = strtolower((string) pathinfo($relative, \PATHINFO_EXTENSION));
        if (!in_array($extension, self::CONVERTIBLE_EXTENSIONS, true)) {
            return null;
        }

        return $relative;
    }

    /**
     * Gibt es zu diesem Bild eine WebP-Fassung?
     *
     * Die Antwort hält länger als einen Request. Läge sie nur im Dienst, lebte sie bei
     * klassischem PHP-FPM genau einen Aufruf lang: 80 Bilder auf einer Seite bedeuteten 80
     * Dateisystem-Abfragen — bei externem Speicher 80 Netz-Wege, jedes Mal aufs Neue. Deshalb
     * liegt sie im gemeinsamen Objekt-Speicher; der Dienst-Cache davor spart innerhalb
     * desselben Aufrufs den Weg dorthin.
     *
     * Die Frist ist bewusst kurz: Ein frisch konvertiertes Bild soll nicht stundenlang als
     * „gibt es nicht" gelten, nur weil einmal jemand die Seite aufgerufen hat, bevor der
     * Konverter durch war.
     */
    private function hasWebpVariant(string $relativePath): bool
    {
        $webpPath = $relativePath . '.webp';
        if (array_key_exists($webpPath, $this->webpExistenceCache)) {
            return $this->webpExistenceCache[$webpPath];
        }

        $cacheKey = 'rc_media_webp_' . hash('xxh128', $webpPath);

        try {
            $cacheItem = $this->cache->getItem($cacheKey);
            if ($cacheItem->isHit()) {
                return $this->webpExistenceCache[$webpPath] = (bool) $cacheItem->get();
            }
        } catch (Throwable $e) {
            // Ein kaputter Zwischenspeicher darf die Seite nicht kosten — dann eben fragen.
            $this->logger->withModule('WebPOptimizer')->warning(
                'WebP-Zwischenspeicher nicht lesbar',
                ['path' => $webpPath, 'exception' => $e],
            );
            $cacheItem = null;
        }

        try {
            $exists = $this->filesystem->exists($webpPath);
        } catch (Throwable $e) {
            $this->logger->withModule('WebPOptimizer')->warning(
                'WebP-Existenz-Check fehlgeschlagen',
                ['path' => $webpPath, 'exception' => $e],
            );
            $exists = false;
        }
        // Cache-Limit: bei Long-Running-Workern (Frankenphp, Roadrunner) kann der Service
        // mehrere Requests bedienen. Ohne Limit würde der Cache unbegrenzt wachsen. Wenn
        // die Schwelle erreicht ist, werfen wir die Hälfte raus — einfacher Approximate-LRU.
        if ($cacheItem !== null) {
            try {
                $this->cache->save($cacheItem->set($exists)->expiresAfter(self::CACHE_TTL_SECONDS));
            } catch (Throwable $e) {
                $this->logger->withModule('WebPOptimizer')->warning(
                    'WebP-Zwischenspeicher nicht schreibbar',
                    ['path' => $webpPath, 'exception' => $e],
                );
            }
        }

        if (count($this->webpExistenceCache) >= self::MAX_CACHE_ENTRIES) {
            $this->webpExistenceCache = array_slice(
                $this->webpExistenceCache,
                (int) (self::MAX_CACHE_ENTRIES / 2),
                preserve_keys: true,
            );
        }
        return $this->webpExistenceCache[$webpPath] = $exists;
    }
}
