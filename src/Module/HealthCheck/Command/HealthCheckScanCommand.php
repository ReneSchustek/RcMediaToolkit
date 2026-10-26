<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Command;

use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Message\CheckMediaBatchMessage;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Service\HealthCheckRunner;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Startet die Medienprüfung — wahlweise sofort oder über die Warteschlange.
 *
 * `--async` ist bei großen Beständen der einzig praktikable Weg: Ein vollständiger Lauf
 * über alle Prüfungen kann länger dauern als jede vernünftige Zeitgrenze einer
 * Konsolensitzung. Ohne den Schalter läuft alles im aufrufenden Prozess, was für einen
 * Blick auf eine einzelne Prüfung (`--check`) die schnellere Wahl ist.
 */
#[AsCommand(name: 'rc:media:health:scan', description: 'Führt Health-Checks aus (synchron oder asynchron)')]
final class HealthCheckScanCommand extends Command
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly HealthCheckRunner $runner,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_REQUIRED, 'Nur diesen Check-Typ ausführen')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatched Messenger-Messages, kehrt sofort zurück');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->moduleRegistry->isActive(ModuleRegistry::MODULE_HEALTH_CHECK)) {
            $io->warning('Health-Check-Modul ist nicht aktiviert (Plugin-Config).');
            return self::FAILURE;
        }

        $check = $input->getOption('check');
        $async = (bool) $input->getOption('async');

        $types = is_string($check) && $check !== '' ? [$check] : $this->runner->availableTypes();

        if ($async) {
            foreach ($types as $type) {
                $this->messageBus->dispatch(new CheckMediaBatchMessage($type));
            }
            $io->success(sprintf('%d Check(s) dispatched.', count($types)));
            return self::SUCCESS;
        }

        $io->section('Health-Check – synchroner Lauf');
        if (is_string($check) && $check !== '') {
            $count = $this->runner->runSingle($check);
            $io->success(sprintf('Check %s: %d Finding(s) persistiert.', $check, $count));
        } else {
            $result = $this->runner->runAll();
            $rows = [];
            foreach ($result as $type => $count) {
                $rows[] = [$type, $count];
            }
            $io->table(['Check', 'Findings'], $rows);
            $io->success(sprintf('%d Check(s) abgeschlossen.', count($result)));
        }

        return self::SUCCESS;
    }
}
