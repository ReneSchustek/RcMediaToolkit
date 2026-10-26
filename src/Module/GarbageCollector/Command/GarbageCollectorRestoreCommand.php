<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Command;

use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Holt Medien aus der Quarantäne zurück.
 *
 * Der Rückweg, der die Quarantäne überhaupt rechtfertigt: Solange ein Lauf nicht endgültig
 * gelöscht ist, ist jede Fehleinschätzung des Aufräumers umkehrbar. Deshalb fragt dieser
 * Befehl auch nichts nach — Wiederherstellen kann nichts kaputt machen.
 */
#[AsCommand(name: 'rc:media:gc:restore', description: 'Stellt Medien aus der Quarantäne wieder her')]
final class GarbageCollectorRestoreCommand extends Command
{
    public function __construct(
        private readonly MediaQuarantineService $quarantineService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('runId', InputArgument::OPTIONAL, 'Quarantäne-Run-ID')
            ->addOption('media-id', null, InputOption::VALUE_REQUIRED, 'Nur ein einzelnes Medium wiederherstellen')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Verfügbare Run-IDs auflisten');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            $runs = $this->quarantineService->listRuns();
            if ($runs === []) {
                $io->note('Keine Quarantäne-Runs vorhanden.');
                return self::SUCCESS;
            }
            $io->section('Quarantäne-Runs');
            foreach ($runs as $runId) {
                $count = count($this->quarantineService->loadManifest($runId));
                $io->writeln(sprintf(' • %s — %d Items', $runId, $count));
            }
            return self::SUCCESS;
        }

        $runId = $input->getArgument('runId');
        if (!is_string($runId) || $runId === '') {
            $io->error('Bitte runId angeben (oder --list nutzen).');
            return self::INVALID;
        }

        $mediaId = $input->getOption('media-id');
        $mediaIdString = is_string($mediaId) && $mediaId !== '' ? $mediaId : null;

        $restored = $this->quarantineService->restore($runId, $mediaIdString);
        $io->success(sprintf('%d Medium/Medien wiederhergestellt.', $restored));
        return self::SUCCESS;
    }
}
