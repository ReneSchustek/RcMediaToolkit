<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Command;

use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message\WebPConvertMessage;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPConverter;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPRegistry;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Wandelt JPEG und PNG in WebP um — Originale bleiben unberührt.
 *
 * Die Obergrenze je Lauf ist Absicht und steht auf 100: Der Umwandler ist rechenintensiv,
 * und ein Lauf über zehntausend Bilder in einem Zug macht die Instanz für die Dauer
 * unbrauchbar. Wer den ganzen Bestand ziehen will, ruft mehrfach auf oder überlässt es der
 * geplanten Aufgabe.
 *
 * `--dry-run` listet nur auf. Das ist die ehrliche Vorschau: Sie zeigt genau die Medien,
 * die ein echter Lauf anfassen würde.
 */
#[AsCommand(name: 'rc:media:webp:convert', description: 'Konvertiert JPEG/PNG nach WebP (synchron oder via Queue)')]
final class WebPConvertCommand extends Command
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly WebPRegistry $registry,
        private readonly WebPConverter $converter,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl Medien pro Lauf', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur listen, keine Konvertierung')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatched WebPConvertMessages an Queue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->moduleRegistry->isActive(ModuleRegistry::MODULE_WEBP_OPTIMIZER)) {
            $io->warning('WebP-Optimizer-Modul ist nicht aktiviert (Plugin-Config).');
            return self::FAILURE;
        }

        $limit = (int) ($input->getOption('limit') ?? 100);
        $dryRun = (bool) $input->getOption('dry-run');
        $async = (bool) $input->getOption('async');

        $paths = [];
        foreach ($this->registry->pathsMissingWebp($limit) as $path) {
            $paths[] = $path;
        }

        if ($paths === []) {
            $io->success('Keine zu konvertierenden Medien gefunden.');
            return self::SUCCESS;
        }

        $io->section(sprintf('Gefundene Medien ohne WebP: %d', count($paths)));

        if ($dryRun) {
            foreach ($paths as $path) {
                $io->writeln('  ' . $path);
            }
            return self::SUCCESS;
        }

        if ($async) {
            foreach ($paths as $path) {
                $this->messageBus->dispatch(new WebPConvertMessage($path));
            }
            $io->success(sprintf('%d Konvertierungs-Aufträge dispatched.', count($paths)));
            return self::SUCCESS;
        }

        $converted = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($paths as $path) {
            $result = $this->converter->convert($path);
            match ($result['status']) {
                'converted' => $converted++,
                'skipped' => $skipped++,
                'failed' => $failed++,
            };
        }

        $io->success(sprintf('Fertig: %d konvertiert, %d übersprungen, %d Fehler.', $converted, $skipped, $failed));
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
