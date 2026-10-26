<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Service;

use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Decorator über PSR-3-Logger. Reichert jeden Log-Eintrag mit Modul-Kontext an
 * und maskiert media-spezifische IDs/Pfade (DSGVO — Pfade können Klarnamen enthalten).
 *
 * Nutzung: pro Sub-Modul eine eigene Instanz mit "module"-Kontext.
 */
final class MediaToolkitLogger implements LoggerInterface
{
    private const MASKED_ID_LENGTH = 8;
    private const MASKED_PATH_LENGTH = 32;

    /**
     * @param array<string, mixed> $defaultContext
     */
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly array $defaultContext = [],
    ) {
    }

    public function withModule(string $module): self
    {
        return new self($this->inner, [...$this->defaultContext, 'module' => $module]);
    }

    /**
     * Setzt eine Korrelations-ID für alle nachfolgenden Log-Records — durchgängig über
     * Cron-Lauf, Messenger-Job oder CLI-Aufruf nachverfolgbar.
     */
    public function withCorrelationId(string $correlationId): self
    {
        return new self($this->inner, [...$this->defaultContext, 'correlationId' => $correlationId]);
    }

    /**
     * Kurze Hilfsmethode für neue Korrelations-IDs (8-Hex-Zeichen reichen für Cron-Logs).
     */
    public static function generateCorrelationId(): string
    {
        return bin2hex(random_bytes(4));
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->inner->emergency($message, $this->enrich($context));
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->inner->alert($message, $this->enrich($context));
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->inner->critical($message, $this->enrich($context));
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->inner->error($message, $this->enrich($context));
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->inner->warning($message, $this->enrich($context));
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->inner->notice($message, $this->enrich($context));
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->inner->info($message, $this->enrich($context));
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->inner->debug($message, $this->enrich($context));
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $this->enrich($context));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function enrich(array $context): array
    {
        $merged = [...$this->defaultContext, ...$context];

        if (isset($merged['mediaId']) && is_string($merged['mediaId'])) {
            $merged['mediaId'] = self::maskId($merged['mediaId']);
        }

        if (isset($merged['mediaIds']) && is_array($merged['mediaIds'])) {
            $merged['mediaIds'] = array_map(
                static fn ($id): string => is_string($id) ? self::maskId($id) : (string) $id,
                $merged['mediaIds'],
            );
        }

        if (isset($merged['path']) && is_string($merged['path'])) {
            $merged['path'] = self::maskPath($merged['path']);
        }

        return $merged;
    }

    private static function maskId(string $id): string
    {
        if (strlen($id) <= self::MASKED_ID_LENGTH) {
            return $id;
        }
        return substr($id, 0, self::MASKED_ID_LENGTH) . '…';
    }

    private static function maskPath(string $path): string
    {
        if (mb_strlen($path) <= self::MASKED_PATH_LENGTH) {
            return $path;
        }
        return mb_substr($path, 0, self::MASKED_PATH_LENGTH) . '…';
    }
}
