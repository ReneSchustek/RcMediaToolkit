<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Command;

use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sagt, welche Module eingeschaltet sind.
 *
 * Klingt nebensächlich und ist der erste Griff bei jeder Störungssuche: Alle vier Module
 * sind einzeln abschaltbar und im Auslieferungszustand **aus**. „Das Plugin tut nichts" hat
 * deshalb weit öfter hier seine Antwort als im Programmtext.
 */
#[AsCommand(name: 'rc:media:status', description: 'Zeigt geladene Sub-Module und ihren Aktivierungsstatus')]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('RcMediaToolkit – Modul-Status');

        $rows = [];
        foreach ($this->moduleRegistry->statusMap() as $module => $active) {
            $rows[] = [$module, $active ? 'aktiv' : 'inaktiv'];
        }

        $io->table(['Modul', 'Status'], $rows);
        $io->note('Aktivierung erfolgt über die Plugin-Konfiguration im Admin oder per CLI.');

        return self::SUCCESS;
    }
}
