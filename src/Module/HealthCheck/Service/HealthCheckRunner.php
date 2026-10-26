<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\MediaHealthCheckInterface;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

/**
 * Führt die Prüfungen aus und schreibt ihre Befunde weg.
 *
 * Die Prüfungen kommen über die Dienst-Verdrahtung herein und tragen ihren Typ selbst; eine
 * neue braucht deshalb nur einen Eintrag in `services.xml`. Jede ist außerdem einzeln
 * abschaltbar — über den Schlüssel, den sie selbst nennt (`configKey()`). Die Zuordnung von
 * Schalter zu Prüfung steht damit an genau einer Stelle: in der Prüfung.
 */
final class HealthCheckRunner
{
    /** @var array<string, MediaHealthCheckInterface> */
    private array $checksByType = [];

    /**
     * @param iterable<MediaHealthCheckInterface> $checks
     */
    public function __construct(
        iterable $checks,
        private readonly HealthReportRepository $repository,
        private readonly SystemConfigService $systemConfigService,
        private readonly MediaToolkitLogger $logger,
    ) {
        foreach ($checks as $check) {
            $this->checksByType[$check->type()] = $check;
        }
    }

    /**
     * @return array<string, int> [type => persistedFindings]
     */
    public function runAll(?string $correlationId = null): array
    {
        $logger = $this->logger
            ->withModule('HealthCheck')
            ->withCorrelationId($correlationId ?? MediaToolkitLogger::generateCorrelationId());
        $result = [];

        foreach ($this->checksByType as $type => $check) {
            if (!$this->isCheckEnabled($check)) {
                continue;
            }

            $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $startedAtMicrotime = microtime(true);
            $persisted = 0;

            try {
                foreach ($check->run() as $finding) {
                    $this->repository->upsert($finding, new DateTimeImmutable('now', new DateTimeZone('UTC')));
                    $persisted++;
                }
            } catch (Throwable $e) {
                $logger->error('Check abgebrochen', ['checkType' => $type, 'exception' => $e]);
                continue;
            }

            $resolved = $this->repository->autoResolveStaleFindings($type, $startedAt);
            $logger->info('Check abgeschlossen', [
                'checkType' => $type,
                'persisted' => $persisted,
                'autoResolved' => $resolved,
                'durationMs' => (int) ((microtime(true) - $startedAtMicrotime) * 1000),
            ]);
            $result[$type] = $persisted;
        }

        return $result;
    }

    public function runSingle(string $type, ?string $correlationId = null): int
    {
        if (!isset($this->checksByType[$type])) {
            throw new InvalidArgumentException(sprintf('Unbekannter Check-Typ: %s', $type));
        }

        $logger = $this->logger
            ->withModule('HealthCheck')
            ->withCorrelationId($correlationId ?? MediaToolkitLogger::generateCorrelationId());

        $check = $this->checksByType[$type];
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $startedAtMicrotime = microtime(true);
        $persisted = 0;

        foreach ($check->run() as $finding) {
            $this->repository->upsert($finding, new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $persisted++;
        }

        $this->repository->autoResolveStaleFindings($type, $startedAt);

        $logger->info('Single-Check abgeschlossen', [
            'checkType' => $type,
            'persisted' => $persisted,
            'durationMs' => (int) ((microtime(true) - $startedAtMicrotime) * 1000),
        ]);

        return $persisted;
    }

    /**
     * @return list<string>
     */
    public function availableTypes(): array
    {
        return array_keys($this->checksByType);
    }

    private function isCheckEnabled(MediaHealthCheckInterface $check): bool
    {
        return (bool) $this->systemConfigService->get($check->configKey());
    }
}
