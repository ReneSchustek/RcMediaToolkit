<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\Repair\ScheduledTask;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairAuditRepository;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(handles: RepairAuditCleanupTask::class)]
/**
 * Räumt die Prüfspur der Reparaturen auf.
 *
 * Die Spur ist der Rückweg jeder Reparatur — sie darf nicht ewig wachsen, aber auch nicht
 * zu früh verschwinden. Solange ein Eintrag steht, ist der Eingriff umkehrbar; danach nicht
 * mehr. Deshalb ist die Aufbewahrungsdauer einstellbar, und der Auslieferungszustand ist
 * **0 = nie löschen**: Wer nichts einstellt, verliert keinen Rückweg.
 */
final class RepairAuditCleanupTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly RepairAuditRepository $auditRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly MediaToolkitLogger $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $retentionDays = (int) ($this->systemConfigService->get('RcMediaToolkit.config.repairAuditRetentionDays') ?? 0);
        if ($retentionDays <= 0) {
            // 0 = nie löschen (Insert-only-Default). Skip mit Log auf debug-Level.
            return;
        }

        $logger = $this->logger
            ->withModule('Repair')
            ->withCorrelationId(MediaToolkitLogger::generateCorrelationId());

        try {
            $purged = $this->auditRepository->purgeOlderThan($retentionDays);
            if ($purged > 0) {
                $logger->info('Repair-Audit aufgeräumt', [
                    'retentionDays' => $retentionDays,
                    'purged' => $purged,
                ]);
            }
        } catch (Throwable $e) {
            $logger->error('Repair-Audit-Cleanup fehlgeschlagen', ['exception' => $e]);
        }
    }
}
