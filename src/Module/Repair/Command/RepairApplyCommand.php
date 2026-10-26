<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\Repair\Command;

use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairExecutor;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairProposalService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Wendet einen Reparaturvorschlag an — der bewusste Bruch des Lesevertrags der Medienprüfung.
 *
 * Zwei Vorkehrungen tragen diesen Bruch: Der Vorschlag muss über seinen Index aus
 * `rc:media:repair:list` benannt werden (man muss ihn also gesehen haben), und jeder
 * Eingriff landet in der Prüfspur, aus der ihn `rc:media:repair:rollback` wieder
 * herausholen kann. Dazu die Rückfrage mit Vorgabe „nein".
 */
#[AsCommand(name: 'rc:media:repair:apply', description: 'Wendet einen Reparatur-Vorschlag an (mit Bestätigung)')]
final class RepairApplyCommand extends Command
{
    public function __construct(
        private readonly RepairProposalService $proposalService,
        private readonly RepairExecutor $executor,
        private readonly HealthReportRepository $reportRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('findingId', InputArgument::REQUIRED, 'Hex-ID des Findings')
            ->addArgument('proposalIndex', InputArgument::REQUIRED, 'Index aus rc:media:repair:list');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $findingId = (string) $input->getArgument('findingId');
        $proposalIndex = (int) $input->getArgument('proposalIndex');

        $finding = $this->reportRepository->findById($findingId);
        if ($finding === null) {
            $io->error('Finding nicht gefunden: ' . $findingId);
            return self::FAILURE;
        }

        $proposals = $this->proposalService->proposalsFor($finding);
        if (!isset($proposals[$proposalIndex])) {
            $io->error('Vorschlag mit Index ' . $proposalIndex . ' nicht vorhanden.');
            return self::INVALID;
        }

        $proposal = $proposals[$proposalIndex];
        $io->section('Anstehender Repair');
        $io->writeln(' Typ:           ' . $proposal->proposalType);
        $io->writeln(' Confidence:    ' . sprintf('%.0f %%', $proposal->confidence * 100));
        $io->writeln(' Beschreibung:  ' . $proposal->description);

        if (!$io->confirm('Reparatur wirklich anwenden?', false)) {
            $io->note('Abgebrochen.');
            return self::SUCCESS;
        }

        try {
            $auditId = $this->executor->apply(
                $finding,
                $proposal,
                'cli:' . (get_current_user() ?: 'unknown'),
            );
        } catch (Throwable $e) {
            $io->error('Repair fehlgeschlagen: ' . $e->getMessage());
            return self::FAILURE;
        }

        $io->success('Repair angewendet. Audit-ID: ' . $auditId);
        $io->note('Rollback via: bin/console rc:media:repair:rollback ' . $auditId);
        return self::SUCCESS;
    }
}
