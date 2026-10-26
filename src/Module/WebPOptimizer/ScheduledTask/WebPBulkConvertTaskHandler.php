<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\ScheduledTask;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message\WebPConvertMessage;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPRegistry;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(handles: WebPBulkConvertTask::class)]
/**
 * Reiht im Hintergrund die nächste Portion Bilder in die Warteschlange ein.
 *
 * Er wandelt nicht selbst um, sondern schickt je Bild eine Nachricht los — deshalb kann der
 * Viertelstunden-Takt so kurz sein: Die Aufgabe ist in Millisekunden zurück, die Arbeit
 * macht der Worker.
 *
 * Modul aus oder Cron nicht erlaubt heißt: Es passiert nichts. **Anders als beim Aufräumer
 * ohne Protokolleintrag** — hier bleibt „warum passiert nichts?" unbeantwortet.
 */
final class WebPBulkConvertTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly WebPRegistry $registry,
        private readonly MessageBusInterface $messageBus,
        private readonly SystemConfigService $systemConfigService,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        if (!$this->moduleRegistry->isActive(ModuleRegistry::MODULE_WEBP_OPTIMIZER)) {
            return;
        }
        if (!(bool) $this->systemConfigService->get('RcMediaToolkit.config.webpBulkCronEnabled')) {
            return;
        }

        $correlationId = MediaToolkitLogger::generateCorrelationId();
        foreach ($this->registry->pathsMissingWebp(100) as $path) {
            $this->messageBus->dispatch(new WebPConvertMessage($path, $correlationId));
        }
    }
}
