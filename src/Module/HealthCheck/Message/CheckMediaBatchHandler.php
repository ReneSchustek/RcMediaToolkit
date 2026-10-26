<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Message;

use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Service\HealthCheckRunner;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Arbeitet eine Prüfung ab, die als Nachricht losgeschickt wurde.
 *
 * Er reicht die Vorgangskennung aus der Nachricht weiter, statt eine neue zu erzeugen. Nur
 * dadurch lassen sich die Protokollzeilen des Workers dem Konsolenaufruf zuordnen, der den
 * Lauf angestoßen hat — bei nebenläufig laufenden Prüfungen ist das der einzige Faden, an
 * dem man sie auseinanderhält.
 */
#[AsMessageHandler]
final class CheckMediaBatchHandler
{
    public function __construct(
        private readonly HealthCheckRunner $runner,
        private readonly MediaToolkitLogger $logger,
    ) {
    }

    public function __invoke(CheckMediaBatchMessage $message): void
    {
        $correlationId = $message->correlationId ?? MediaToolkitLogger::generateCorrelationId();
        $logger = $this->logger
            ->withModule('HealthCheck')
            ->withCorrelationId($correlationId);

        try {
            $count = $this->runner->runSingle($message->checkType, $correlationId);
            $logger->info('Async-Batch abgearbeitet', [
                'checkType' => $message->checkType,
                'persisted' => $count,
            ]);
        } catch (Throwable $e) {
            $logger->error('Async-Batch abgebrochen', [
                'checkType' => $message->checkType,
                'exception' => $e,
            ]);
            throw new RuntimeException(sprintf('Async-Health-Check "%s" abgebrochen', $message->checkType), 0, $e);
        }
    }
}
