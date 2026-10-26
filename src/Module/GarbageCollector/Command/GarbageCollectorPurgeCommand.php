<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Command;

use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Löscht Quarantäne-Läufe endgültig — der einzige Vorgang im Plugin ohne Rückweg.
 *
 * Entsprechend eng geführt: Jeder Aufruf fragt nach, die Vorgabe der Rückfrage ist „nein",
 * und der Text nennt die Unumkehrbarkeit beim Namen. Auch die Sammellöschung über
 * `--older-than` fragt einzeln — gerade sie trifft im Zweifel am meisten.
 */
#[AsCommand(name: 'rc:media:gc:purge', description: 'Löscht Quarantäne-Runs endgültig (irreversibel)')]
final class GarbageCollectorPurgeCommand extends Command
{
    private const RETENTION_CONFIG_KEY = 'RcMediaToolkit.config.gcQuarantineRetentionDays';
    private const DEFAULT_RETENTION_DAYS = 14;

    public function __construct(
        private readonly MediaQuarantineService $quarantineService,
        private readonly SystemConfigService $systemConfigService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('runId', InputArgument::OPTIONAL, 'Spezifischer Run zum Löschen')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Alle Runs älter als X Tage löschen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $runId = $input->getArgument('runId');
        $olderThan = $input->getOption('older-than');

        if ((!is_string($runId) || $runId === '') && $olderThan === null) {
            $days = (int) ($this->systemConfigService->get(self::RETENTION_CONFIG_KEY) ?? self::DEFAULT_RETENTION_DAYS);
            $olderThan = $days;
            $io->note(sprintf('Kein Filter angegeben — nutze Default-Retention aus Config: %d Tage.', $days));
        }

        if (is_string($runId) && $runId !== '') {
            if (!$io->confirm(sprintf('Run %s endgültig löschen? Dies ist nicht rückgängig zu machen.', $runId), false)) {
                $io->note('Abgebrochen.');
                return self::SUCCESS;
            }
            $count = $this->quarantineService->purge($runId);
            $io->success(sprintf('Run %s gelöscht (%d Items).', $runId, $count));
            return self::SUCCESS;
        }

        $days = is_numeric($olderThan) ? (int) $olderThan : self::DEFAULT_RETENTION_DAYS;
        if ($days < 0) {
            $io->error('older-than muss >= 0 sein.');
            return self::INVALID;
        }

        if (!$io->confirm(sprintf('Alle Quarantäne-Runs älter als %d Tag(e) endgültig löschen?', $days), false)) {
            $io->note('Abgebrochen.');
            return self::SUCCESS;
        }

        $purged = $this->quarantineService->purgeOlderThan($days);
        if ($purged === []) {
            $io->note('Keine zu löschenden Runs gefunden.');
            return self::SUCCESS;
        }

        $totalItems = array_sum($purged);
        $io->success(sprintf('%d Run(s) mit insgesamt %d Items gelöscht.', count($purged), $totalItems));
        return self::SUCCESS;
    }
}
