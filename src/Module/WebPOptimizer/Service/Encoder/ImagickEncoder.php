<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder;

use Imagick;
use ImagickException;
use RuntimeException;
use Throwable;

/**
 * Umwandlung über die Imagick-Erweiterung — das dritte Glied der Kette.
 *
 * Die Verfügbarkeitsprüfung fragt nicht nur, ob die Erweiterung geladen ist, sondern ob sie
 * WebP überhaupt kann: Imagick ist gegen eine Systembibliothek gebaut, und ohne deren
 * WebP-Unterstützung ist die Erweiterung zwar da, das Format aber nicht. Ohne diese zweite
 * Frage würde die Kette bei ihm stehen bleiben und dann bei jedem Bild scheitern.
 */
final class ImagickEncoder implements WebPEncoderInterface
{
    public function name(): string
    {
        return 'imagick';
    }

    public function isAvailable(): bool
    {
        if (!extension_loaded('imagick') || !class_exists(Imagick::class)) {
            return false;
        }
        try {
            $formats = Imagick::queryFormats('WEBP');
            return in_array('WEBP', $formats, true);
        } catch (Throwable) {
            return false;
        }
    }

    public function version(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }
        try {
            $version = Imagick::getVersion();
            return 'Imagick ' . $version['versionString'];
        } catch (Throwable) {
            return 'Imagick';
        }
    }

    public function encode(string $sourceBytes, string $sourceFormat, int $quality, bool $lossless, bool $stripMetadata): string
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Imagick mit WebP-Support ist nicht verfügbar');
        }

        unset($sourceFormat);

        $imagick = new Imagick();
        try {
            $imagick->readImageBlob($sourceBytes);

            // ICC-Profil sichern, bevor stripImage es entfernt — Color-Profile-Erhaltung
            // ist Pflicht für AdobeRGB/CMYK-Quellen (sonst „washed-out"-Farben in WebP).
            $iccProfile = null;
            if (!$stripMetadata) {
                try {
                    $iccProfile = $imagick->getImageProfile('icc');
                } catch (ImagickException) {
                    // Kein ICC-Profil im Bild — OK.
                }
            }

            if ($stripMetadata) {
                $imagick->stripImage();
            }

            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality(max(0, min(100, $quality)));
            if ($lossless) {
                $imagick->setOption('webp:lossless', 'true');
            }

            if ($iccProfile !== null && $iccProfile !== '') {
                $imagick->profileImage('icc', $iccProfile);
            }

            return $imagick->getImageBlob();
        } catch (Throwable $e) {
            throw new RuntimeException('Imagick-Encoding fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        } finally {
            // Pflicht: auch im Exception-Pfad Imagick-Memory freigeben.
            $imagick->clear();
        }
    }
}
