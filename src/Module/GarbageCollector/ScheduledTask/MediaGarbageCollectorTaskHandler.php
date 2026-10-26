<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\ScheduledTask;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\OrphanedMediaFinder;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(handles: MediaGarbageCollectorTask::class)]
/**
 * Führt den wöchentlichen Aufräumlauf aus — im Regelfall, ohne etwas zu verschieben.
 *
 * Zwei Schalter müssen stehen, bevor überhaupt gesucht wird (Modul aktiv, Cron erlaubt), und
 * ein dritter, bevor etwas in die Quarantäne wandert. Die Vorgabe ist berichten, nicht
 * handeln: Ein unbeaufsichtigter Lauf, der Medien wegräumt, ist genau das, was man bei
 * einem solchen Werkzeug nicht als Standard haben will.
 *
 * Übersprungene Läufe werden protokolliert statt stillschweigend beendet — sonst ist
 * „warum passiert nichts?" nicht zu beantworten.
 */
final class MediaGarbageCollectorTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly OrphanedMediaFinder $finder,
        private readonly MediaQuarantineService $quarantineService,
        private readonly SystemConfigService $systemConfigService,
        private readonly MediaToolkitLogger $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $logger = $this->logger
            ->withModule('GarbageCollector')
            ->withCorrelationId(\Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger::generateCorrelationId());

        if (!$this->moduleRegistry->isActive(ModuleRegistry::MODULE_GARBAGE_COLLECTOR)) {
            $logger->info('Cron-Lauf übersprungen — Modul ist deaktiviert');
            return;
        }

        if (!(bool) $this->systemConfigService->get('RcMediaToolkit.config.gcCronEnabled')) {
            $logger->info('Cron-Lauf übersprungen — gcCronEnabled = false');
            return;
        }

        $autoQuarantine = (bool) $this->systemConfigService->get('RcMediaToolkit.config.gcCronAutoQuarantine');

        $candidates = 0;
        $moved = 0;
        $runId = null;

        try {
            $iterator = $this->finder->findOrphans();

            foreach ($iterator as $candidate) {
                $candidates++;

                if ($autoQuarantine) {
                    $runId ??= $this->quarantineService->createRun();
                    try {
                        $this->quarantineService->quarantine($runId, $candidate);
                        $moved++;
                    } catch (Throwable $e) {
                        $logger->error('Quarantine eines Cron-Kandidaten fehlgeschlagen', [
                            'mediaId' => $candidate->mediaId,
                            'exception' => $e,
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            $logger->error('Cron-Lauf abgebrochen', ['exception' => $e]);
            return;
        }

        $logger->info('Garbage-Collector-Cron abgeschlossen', [
            'candidates' => $candidates,
            'moved' => $moved,
            'runId' => $runId,
            'autoQuarantine' => $autoQuarantine,
        ]);

        if ((int) $this->systemConfigService->get('RcMediaToolkit.config.gcQuarantineRetentionDays') > 0) {
            $purged = $this->quarantineService->purgeOlderThan(
                (int) $this->systemConfigService->get('RcMediaToolkit.config.gcQuarantineRetentionDays'),
            );
            if ($purged !== []) {
                $logger->info('Alte Quarantäne-Runs aufgeräumt', [
                    'purgedRuns' => count($purged),
                    'purgedItems' => array_sum($purged),
                ]);
            }
        }
    }
}
