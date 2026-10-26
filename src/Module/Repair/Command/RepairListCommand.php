<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\Repair\Command;

use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairProposalService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Zeigt, was zu einem Befund der Medienprüfung repariert werden könnte.
 *
 * Nur lesend, und bewusst ein eigener Schritt vor dem Anwenden: Die Vorschläge tragen einen
 * Index, und genau dieser Index geht in `rc:media:repair:apply`. Wer repariert, hat den
 * Vorschlag also gesehen — versehentlich anwenden lässt sich hier nichts.
 */
#[AsCommand(name: 'rc:media:repair:list', description: 'Listet Reparatur-Vorschläge für ein Health-Check-Finding')]
final class RepairListCommand extends Command
{
    public function __construct(
        private readonly RepairProposalService $proposalService,
        private readonly HealthReportRepository $reportRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('findingId', InputArgument::REQUIRED, 'Hex-ID des Findings aus rc_media_health_report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $findingId = (string) $input->getArgument('findingId');

        $finding = $this->reportRepository->findById($findingId);
        if ($finding === null) {
            $io->error('Finding nicht gefunden: ' . $findingId);
            return self::FAILURE;
        }

        $proposals = $this->proposalService->proposalsFor($finding);
        if ($proposals === []) {
            $io->note('Keine Vorschläge für diesen Finding-Typ.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($proposals as $index => $proposal) {
            $rows[] = [
                (string) $index,
                $proposal->proposalType,
                sprintf('%.0f %%', $proposal->confidence * 100),
                $proposal->description,
            ];
        }

        $io->section(sprintf('Vorschläge für Finding %s (%s)', substr($findingId, 0, 8), $finding['check_type']));
        $io->table(['Index', 'Typ', 'Confidence', 'Beschreibung'], $rows);
        $io->note('Anwenden via: bin/console rc:media:repair:apply ' . $findingId . ' <Index>');
        return self::SUCCESS;
    }
}
