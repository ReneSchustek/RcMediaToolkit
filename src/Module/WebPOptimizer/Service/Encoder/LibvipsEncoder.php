<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder;

use RuntimeException;
use Throwable;

/**
 * libvips über die vips-PHP-Extension. Schnell und speichersparend für
 * sehr große Bilder. Bei fehlender Extension überspringt der Chain automatisch.
 *
 * Die Klasse nutzt Jcupitt\Vips\Image und vips_version(), beides ist nur verfügbar,
 * wenn die optionale PHP-Extension vips geladen ist. PHPStan kennt diese Symbole nicht
 * — die wenigen Calls werden über phpstan-Ignore-Anweisungen ausgeklammert.
 */
final class LibvipsEncoder implements WebPEncoderInterface
{
    public function name(): string
    {
        return 'libvips';
    }

    public function isAvailable(): bool
    {
        return extension_loaded('vips') && class_exists('\Jcupitt\Vips\Image');
    }

    public function version(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }
        if (!function_exists('vips_version')) {
            return 'libvips';
        }
        try {
            /**  */
            $v = \call_user_func('vips_version'); // @phpstan-ignore-line
            return is_string($v) ? 'libvips ' . $v : 'libvips';
        } catch (Throwable) {
            return null;
        }
    }

    public function encode(string $sourceBytes, string $sourceFormat, int $quality, bool $lossless, bool $stripMetadata): string
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('libvips ist nicht verfügbar');
        }

        unset($sourceFormat);

        try {
            /** @phpstan-ignore-next-line — Jcupitt\Vips\Image ist eine optionale Extension */
            $image = \Jcupitt\Vips\Image::newFromBuffer($sourceBytes);
            $options = [
                'Q' => max(0, min(100, $quality)),
                'lossless' => $lossless,
                // strip=true entfernt ALLE Metadata; bei strip=false hält libvips das ICC-Profil.
                // Mix (strip + keep ICC) ist im 0.x nicht unterstützt.
                'strip' => $stripMetadata,
            ];
            /**  */
            $bytes = $image->writeToBuffer('.webp', $options);

            return is_string($bytes) ? $bytes : '';
        } catch (Throwable $e) {
            throw new RuntimeException('libvips-Encoding fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }
}
