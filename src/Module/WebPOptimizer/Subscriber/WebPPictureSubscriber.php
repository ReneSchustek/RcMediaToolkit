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
 *  - Regex matched konservativ: nur `<img>` mit `/media/...jpg|jpeg|png` ohne bestehendes
 *    `srcset`; vorhandene `<picture>`-Blöcke werden vorher ausgeblendet.
 */
final class WebPPictureSubscriber implements EventSubscriberInterface
{
    // Zwei Pattern-Varianten, weil Backreferences in `[^...]` von PCRE nicht aufgelöst werden.
    // Ein einzelnes Pattern mit `([\\'"])...[^\\1...]...\\1` würde `[^\\1...]` als „nicht-1, nicht-…"
    // interpretieren, nicht als „nicht-Match-für-Backreference-1" — subtiler Greedy-Bug.
    // Der Abfrageteil am Ende ist nicht schmückendes Beiwerk: Shopware hängt an jede
    // Medien-Adresse `?ts=…` an. Ein Ausdruck, der die Endung unmittelbar vor dem
    // Anführungszeichen verlangt, greift deshalb an allem vorbei, was der Shop selbst rendert —
    // gemessen 80 Bilder auf vier Seiten, kein einziges umgeschrieben.
    private const URL_REGEX_DOUBLE_QUOTE = '~<img\\b(?![^>]*\\bsrcset=)[^>]*\\bsrc="((?:https?://[^"\\s]+)?/media/[^"\\s]+\\.(?:jpe?g|png)(?:[?#][^"\\s]*)?)"[^>]*>~i';
    private const URL_REGEX_SINGLE_QUOTE = "~<img\\b(?![^>]*\\bsrcset=)[^>]*\\bsrc='((?:https?://[^'\\s]+)?/media/[^'\\s]+\\.(?:jpe?g|png)(?:[?#][^'\\s]*)?)'[^>]*>~i";

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

        return $vorhandene === [] ? $body : strtr($body, $vorhandene);
    }

    private function rewriteFreeImgTags(string $body): string
    {
        $callback = function (array $matches): string {
            /** @var array{0: string, 1: string} $matches */
            $imgTag = $matches[0];
            $url = $matches[1];

            $relativePath = $this->urlToFilesystemPath($url);
            if ($relativePath === null) {
                return $imgTag;
            }

            if (!$this->hasWebpVariant($relativePath)) {
                return $imgTag;
            }

            // $url ist bereits HTML-escaped (kommt aus gerendertem Twig).
            // Nicht erneut htmlspecialchars, sonst Double-Encoding (&amp; → &amp;amp;).
            $webpUrl = WebPUrl::variantOf($url);
            return '<picture>'
                . '<source srcset="' . $webpUrl . '" type="image/webp">'
                . $imgTag
                . '</picture>';
        };

        $body = (string) preg_replace_callback(self::URL_REGEX_DOUBLE_QUOTE, $callback, $body);
        $body = (string) preg_replace_callback(self::URL_REGEX_SINGLE_QUOTE, $callback, $body);
        return $body;
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

        $mediaPos = strpos($rawPath, '/media/');
        if ($mediaPos === false) {
            return null;
        }
        $relative = ltrim(substr($rawPath, $mediaPos), '/');
        if (str_contains($relative, '..')) {
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
