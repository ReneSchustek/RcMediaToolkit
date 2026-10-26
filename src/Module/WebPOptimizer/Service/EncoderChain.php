<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service;

use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\WebPEncoderInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Wählt zur Laufzeit den bestmöglichen WebP-Encoder basierend auf der konfigurierten
 * Reihenfolge und tatsächlicher Verfügbarkeit (Binary/Extension).
 */
final class EncoderChain
{
    private const CONFIG_KEY = 'RcMediaToolkit.config.webpEncoderPreference';
    private const DEFAULT_ORDER = 'cwebp,libvips,imagick,gd';

    /** @var array<string, WebPEncoderInterface> */
    private array $encodersByName = [];

    /** @var array<string, true> Bereits geloggte (encoder, version)-Kombinationen — pro Request-Scope */
    private array $loggedVersions = [];

    /**
     * @param iterable<WebPEncoderInterface> $encoders
     */
    public function __construct(
        iterable $encoders,
        private readonly SystemConfigService $systemConfigService,
        private readonly MediaToolkitLogger $logger,
    ) {
        foreach ($encoders as $encoder) {
            $this->encodersByName[$encoder->name()] = $encoder;
        }
    }

    public function selectEncoder(): WebPEncoderInterface
    {
        foreach ($this->preferenceOrder() as $name) {
            if (!isset($this->encodersByName[$name])) {
                continue;
            }
            $encoder = $this->encodersByName[$name];
            if ($encoder->isAvailable()) {
                $this->logSelectedVersionOnce($encoder);
                return $encoder;
            }
        }

        $logger = $this->logger->withModule('WebPOptimizer');
        $logger->error('Kein WebP-Encoder verfügbar');
        throw new RuntimeException('Kein WebP-Encoder verfügbar. cwebp installieren oder GD-Extension aktivieren.');
    }

    /**
     * @return array<string, bool>
     */
    public function availabilityMap(): array
    {
        $map = [];
        foreach ($this->encodersByName as $name => $encoder) {
            $map[$name] = $encoder->isAvailable();
        }
        return $map;
    }

    /**
     * @return array<string, ?string>
     */
    public function versionMap(): array
    {
        $map = [];
        foreach ($this->encodersByName as $name => $encoder) {
            $map[$name] = $encoder->isAvailable() ? $encoder->version() : null;
        }
        return $map;
    }

    private function logSelectedVersionOnce(WebPEncoderInterface $encoder): void
    {
        $version = $encoder->version() ?? 'unbekannt';
        $key = $encoder->name() . '|' . $version;
        if (isset($this->loggedVersions[$key])) {
            return;
        }
        $this->loggedVersions[$key] = true;
        $this->logger->withModule('WebPOptimizer')->info('Encoder ausgewählt', [
            'encoder' => $encoder->name(),
            'version' => $version,
        ]);
    }

    /**
     * @return list<string>
     */
    private function preferenceOrder(): array
    {
        $configuredRaw = $this->systemConfigService->get(self::CONFIG_KEY);
        $configured = is_string($configuredRaw) && $configuredRaw !== '' ? $configuredRaw : self::DEFAULT_ORDER;
        $names = array_map('trim', explode(',', $configured));
        return array_values(array_filter($names, static fn (string $n): bool => $n !== ''));
    }
}
