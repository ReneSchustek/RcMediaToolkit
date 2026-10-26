<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\Repair\Command;

use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Nimmt eine frühere Reparatur zurück.
 *
 * Der Grund, warum jede Reparatur überhaupt eine Prüfspur schreibt. Ohne diesen Weg wäre
 * das Reparaturmodul ein einseitiger Eingriff in fremde Daten — mit ihm ist es eine
 * Änderung, die jemand rückgängig machen kann, der den Fehler bemerkt.
 */
#[AsCommand(name: 'rc:media:repair:rollback', description: 'Rollback eines früheren Repair-Vorgangs')]
final class RepairRollbackCommand extends Command
{
    public function __construct(
        private readonly RepairExecutor $executor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('auditId', InputArgument::REQUIRED, 'Hex-ID aus rc_media_repair_audit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $auditId = (string) $input->getArgument('auditId');

        if (!$io->confirm('Rollback wirklich ausführen?', false)) {
            $io->note('Abgebrochen.');
            return self::SUCCESS;
        }

        try {
            $this->executor->rollback($auditId);
        } catch (Throwable $e) {
            $io->error('Rollback fehlgeschlagen: ' . $e->getMessage());
            return self::FAILURE;
        }

        $io->success('Rollback abgeschlossen.');
        return self::SUCCESS;
    }
}
