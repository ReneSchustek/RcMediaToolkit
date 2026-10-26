<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service;

use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

/**
 * Konvertiert eine einzelne Media-Datei zu WebP, ohne das Original zu überschreiben.
 *
 * Idempotenz: existiert <path>.webp und ist mindestens so neu wie das Original,
 * wird übersprungen.
 */
final class WebPConverter
{
    private const ALLOWED_FORMATS = ['jpeg', 'jpg', 'png'];

    public function __construct(
        private readonly EncoderChain $encoderChain,
        private readonly FilesystemAdapterInterface $filesystem,
        private readonly SystemConfigService $systemConfigService,
        private readonly MediaToolkitLogger $logger,
        private readonly WebPSettingsResolver $settingsResolver,
    ) {
    }

    /**
     * @return array{status: 'converted'|'skipped'|'failed', reason?: string, targetPath?: string, sizeBytes?: int}
     */
    public function convert(string $sourcePath): array
    {
        $logger = $this->logger->withModule('WebPOptimizer');

        $extension = strtolower((string) pathinfo($sourcePath, \PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedFormats(), true)) {
            return ['status' => 'skipped', 'reason' => 'format_not_whitelisted'];
        }

        if (!$this->filesystem->exists($sourcePath)) {
            return ['status' => 'skipped', 'reason' => 'source_missing'];
        }

        $sourceSize = $this->filesystem->size($sourcePath);
        $minBytes = $this->minFileSizeBytes();
        if ($sourceSize < $minBytes) {
            return ['status' => 'skipped', 'reason' => 'below_min_size'];
        }

        $targetPath = $sourcePath . '.webp';
        if ($this->filesystem->exists($targetPath)) {
            try {
                $sourceModified = $this->filesystem->lastModified($sourcePath);
                $targetModified = $this->filesystem->lastModified($targetPath);
                if ($targetModified >= $sourceModified) {
                    return ['status' => 'skipped', 'reason' => 'already_up_to_date', 'targetPath' => $targetPath];
                }
            } catch (Throwable) {
                // mtime nicht ermittelbar — wir encodieren neu
            }
        }

        try {
            $sourceBytes = $this->filesystem->read($sourcePath);
        } catch (Throwable $e) {
            $logger->error('Quell-Datei nicht lesbar', ['path' => $sourcePath, 'exception' => $e]);
            return ['status' => 'failed', 'reason' => 'read_error'];
        }

        $encoder = $this->encoderChain->selectEncoder();
        $settings = $this->settingsResolver->resolveForPath($sourcePath);

        $sourceFormat = $extension === 'png' ? 'png' : 'jpeg';

        try {
            $webpBytes = $encoder->encode(
                $sourceBytes,
                $sourceFormat,
                $settings->quality,
                $settings->lossless,
                $settings->stripMetadata,
            );
        } catch (Throwable $e) {
            $logger->error('Encoding fehlgeschlagen', [
                'path' => $sourcePath,
                'encoder' => $encoder->name(),
                'folderKey' => $settings->folderKey,
                'exception' => $e,
            ]);
            return ['status' => 'failed', 'reason' => 'encoding_error'];
        }

        try {
            $this->filesystem->write($targetPath, $webpBytes);
        } catch (Throwable $e) {
            $logger->error('WebP-Ziel-Datei konnte nicht geschrieben werden', [
                'path' => $targetPath,
                'exception' => $e,
            ]);
            return ['status' => 'failed', 'reason' => 'write_error'];
        }

        $logger->info('WebP erzeugt', [
            'path' => $sourcePath,
            'encoder' => $encoder->name(),
            'folderKey' => $settings->folderKey,
            'quality' => $settings->quality,
            'lossless' => $settings->lossless,
            'originalSize' => $sourceSize,
            'webpSize' => strlen($webpBytes),
        ]);

        return [
            'status' => 'converted',
            'targetPath' => $targetPath,
            'sizeBytes' => strlen($webpBytes),
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedFormats(): array
    {
        $configuredRaw = $this->systemConfigService->get('RcMediaToolkit.config.webpFormatWhitelist');
        $configured = is_string($configuredRaw) && $configuredRaw !== ''
            ? $configuredRaw
            : implode(',', self::ALLOWED_FORMATS);
        $list = array_map('strtolower', array_map('trim', explode(',', $configured)));
        return array_values(array_filter($list, static fn (string $f): bool => $f !== ''));
    }

    private function minFileSizeBytes(): int
    {
        $kb = (int) ($this->systemConfigService->get('RcMediaToolkit.config.webpMinFileSizeKb') ?? 10);
        return max(0, $kb) * 1024;
    }
}
