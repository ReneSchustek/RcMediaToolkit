<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder;

use RuntimeException;

/**
 * Fallback-Encoder über die GD-Extension. Immer verfügbar in Shopware-Installationen,
 * unterstützt aber kein Lossless-Encoding und kein ICC-Profile-Handling.
 */
final class GdEncoder implements WebPEncoderInterface
{
    public function name(): string
    {
        return 'gd';
    }

    public function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagewebp');
    }

    public function version(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $info = gd_info();
        $version = isset($info['GD Version']) && is_string($info['GD Version']) ? $info['GD Version'] : 'unknown';
        return 'GD ' . $version;
    }

    public function encode(string $sourceBytes, string $sourceFormat, int $quality, bool $lossless, bool $stripMetadata): string
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('GD mit WebP-Support ist nicht verfügbar');
        }

        unset($lossless, $stripMetadata); // GD unterstützt diese Optionen nicht — sauber ignorieren

        $image = @imagecreatefromstring($sourceBytes);
        if ($image === false) {
            throw new RuntimeException('GD konnte Bild nicht laden');
        }
        unset($sourceFormat);

        ob_start();
        try {
            $success = imagewebp($image, null, max(0, min(100, $quality)));
            if ($success === false) {
                throw new RuntimeException('GD-WebP-Encoding fehlgeschlagen');
            }
            $bytes = ob_get_clean();
            return $bytes === false ? '' : $bytes;
        } finally {
            imagedestroy($image);
        }
    }
}
