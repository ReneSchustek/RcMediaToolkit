<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\ScheduledTask;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Service\HealthCheckRunner;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: HealthCheckScanTask::class)]
/**
 * Führt die tägliche Medienprüfung aus.
 *
 * Ein Fehler in der Verdrahtung dieser Klasse trifft mehr als das Plugin: Ein Argument in
 * `services.xml`, das der Konstruktor nicht kennt, bringt beim Start der Konsole **jede**
 * geplante Aufgabe der Instanz zu Fall — auch die fremder Plugins. Deshalb vergleicht
 * `ServicesXmlWiringTest` beide Seiten.
 */
final class HealthCheckScanTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly HealthCheckRunner $runner,
        private readonly HealthReportRepository $repository,
        private readonly SystemConfigService $systemConfigService,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        if (!$this->moduleRegistry->isActive(ModuleRegistry::MODULE_HEALTH_CHECK)) {
            return;
        }
        if (!(bool) $this->systemConfigService->get('RcMediaToolkit.config.healthCronEnabled')) {
            return;
        }

        $this->runner->runAll(MediaToolkitLogger::generateCorrelationId());

        $retention = (int) ($this->systemConfigService->get('RcMediaToolkit.config.healthRetentionDays') ?? 90);
        if ($retention > 0) {
            $this->repository->purgeResolvedOlderThan($retention);
        }
    }
}
