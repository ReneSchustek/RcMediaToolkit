<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Twig;

use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPUrl;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\Content\Media\MediaEntity;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Liefert WebP-URL einer Media-Entity, mit Fallback auf das Original.
 *
 * Twig-Verwendung:
 *   <picture>
 *     <source srcset="{{ media|rc_webp_url }}" type="image/webp">
 *     <img src="{{ media.url }}" alt="…">
 *   </picture>
 *
 * Der Filter prüft die Existenz der `.webp`-Fassung bewusst NICHT — das wäre pro
 * Render-Aufruf zu teuer. Fehlt sie, antwortet der Server mit 404, und der Browser nimmt
 * über das `<picture>`-Konstrukt das Original. **Umsonst ist dieser Rückweg nicht:** Jedes
 * fehlende Bild kostet den Besucher einen zusätzlichen Aufruf. Wer den Filter benutzt,
 * sollte `rc:media:webp:convert` vorher durchlaufen lassen.
 *
 * Die Adresse bildet {@see WebPUrl}, und das mit Grund: Hängte der Filter die Endung stumpf
 * an `media.url`, landete sie hinter Shopwares Zeitstempel im Abfrageteil
 * (`bild.jpg?ts=1.webp`). Der Pfad zeigte dann unverändert auf das Original, der Server
 * antwortete mit **200** statt 404, und der Browser bekäme ein JPEG in voller Größe — als
 * `image/webp` deklariert, bei einem gemessenen Bild 15.470 Byte statt 4.090. Der Rückweg
 * über 404 griffe nie.
 */
final class RcWebpExtension extends AbstractExtension
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('rc_webp_url', [$this, 'webpUrl']),
        ];
    }

    public function webpUrl(?MediaEntity $media): ?string
    {
        if ($media === null) {
            return null;
        }
        if (!$this->moduleRegistry->isActive(ModuleRegistry::MODULE_WEBP_OPTIMIZER)) {
            return $media->getUrl();
        }
        $url = $media->getUrl();
        if ($url === '') {
            return null;
        }
        return WebPUrl::variantOf($url);
    }
}
