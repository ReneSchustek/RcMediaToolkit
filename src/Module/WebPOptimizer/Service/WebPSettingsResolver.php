<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service;

use JsonException;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Dto\WebPSettings;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Resolves WebP-Encoder-Settings für einen Source-Pfad.
 *
 * Strategie:
 *  1. Folder-Name aus Pfad extrahieren (`media/<folder>/...` → `<folder>`).
 *  2. Wenn Plugin-Config `webpFolderSettingsJson` einen Eintrag dafür hat — nutzen.
 *  3. Sonst „default"-Eintrag aus dem JSON.
 *  4. Sonst Plugin-Config-Defaults (`webpDefaultQuality`, etc.).
 *
 * Beispiel-JSON:
 *   {
 *     "default": {"quality": 82, "lossless": false, "stripMetadata": true},
 *     "products": {"quality": 80, "lossless": false, "stripMetadata": true},
 *     "icons":    {"quality": 100, "lossless": true, "stripMetadata": false}
 *   }
 */
final class WebPSettingsResolver
{
    private const CONFIG_KEY_JSON = 'RcMediaToolkit.config.webpFolderSettingsJson';
    private const CONFIG_KEY_QUALITY = 'RcMediaToolkit.config.webpDefaultQuality';
    private const CONFIG_KEY_LOSSLESS = 'RcMediaToolkit.config.webpDefaultLossless';
    private const CONFIG_KEY_STRIP = 'RcMediaToolkit.config.webpDefaultStripMetadata';

    /** @var array<string, array{quality?: int, lossless?: bool, stripMetadata?: bool}>|null */
    private ?array $cachedJson = null;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly MediaToolkitLogger $logger,
    ) {
    }

    public function resolveForPath(string $sourcePath): WebPSettings
    {
        $folderKey = $this->extractFolderKey($sourcePath);
        $jsonMap = $this->loadJsonMap();

        $folderSettings = $jsonMap[$folderKey] ?? $jsonMap['default'] ?? null;

        $quality = $folderSettings['quality'] ?? $this->defaultQuality();
        $lossless = $folderSettings['lossless'] ?? $this->defaultLossless();
        $strip = $folderSettings['stripMetadata'] ?? $this->defaultStripMetadata();

        return new WebPSettings(
            quality: max(0, min(100, (int) $quality)),
            lossless: (bool) $lossless,
            stripMetadata: (bool) $strip,
            folderKey: $folderKey,
        );
    }

    public function refresh(): void
    {
        $this->cachedJson = null;
    }

    private function extractFolderKey(string $sourcePath): string
    {
        if (!str_starts_with($sourcePath, 'media/')) {
            return 'default';
        }
        $remainder = substr($sourcePath, strlen('media/'));
        $slashPos = strpos($remainder, '/');
        if ($slashPos === false) {
            return 'default';
        }
        $folder = substr($remainder, 0, $slashPos);
        // Sanitization: nur a-z, 0-9, _, -
        if (preg_match('/^[a-z0-9_\-]+$/i', $folder) !== 1) {
            return 'default';
        }
        return strtolower($folder);
    }

    /**
     * @return array<string, array{quality?: int, lossless?: bool, stripMetadata?: bool}>
     */
    private function loadJsonMap(): array
    {
        if ($this->cachedJson !== null) {
            return $this->cachedJson;
        }
        $raw = $this->systemConfigService->get(self::CONFIG_KEY_JSON);
        if (!is_string($raw) || trim($raw) === '') {
            return $this->cachedJson = [];
        }
        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new JsonException('Top-Level ist kein Object');
            }
            /** @var array<string, array{quality?: int, lossless?: bool, stripMetadata?: bool}> $decoded */
            return $this->cachedJson = $decoded;
        } catch (JsonException $e) {
            $this->logger->withModule('WebPOptimizer')->warning(
                'webpFolderSettingsJson konnte nicht geparst werden — nutze Defaults',
                ['exception' => $e],
            );
            return $this->cachedJson = [];
        }
    }

    private function defaultQuality(): int
    {
        $q = $this->systemConfigService->get(self::CONFIG_KEY_QUALITY);
        return is_numeric($q) ? (int) $q : 82;
    }

    private function defaultLossless(): bool
    {
        return (bool) $this->systemConfigService->get(self::CONFIG_KEY_LOSSLESS);
    }

    private function defaultStripMetadata(): bool
    {
        $raw = $this->systemConfigService->get(self::CONFIG_KEY_STRIP);
        // Default = true (DSGVO-Strip), nur explizit auf false setzbar
        return $raw === null ? true : (bool) $raw;
    }
}
