<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Dto;

use InvalidArgumentException;

/**
 * Encoder-Settings für eine konkrete Konvertierung — pro Media-Folder konfigurierbar.
 */
final class WebPSettings
{
    public function __construct(
        public readonly int $quality,
        public readonly bool $lossless,
        public readonly bool $stripMetadata,
        public readonly string $folderKey = 'default',
    ) {
        if ($quality < 0 || $quality > 100) {
            throw new InvalidArgumentException('quality muss zwischen 0 und 100 liegen');
        }
    }
}
