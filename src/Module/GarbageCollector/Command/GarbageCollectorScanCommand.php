<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Command;

use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\ReportFormatterRegistry;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\OrphanedMediaFinder;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Sucht verwaiste Medien — und rührt von sich aus nichts an.
 *
 * Ohne `--quarantine` schreibt der Befehl nichts, er berichtet nur. Das ist keine
 * Bequemlichkeit, sondern die Reihenfolge, auf der das ganze Modul beruht: erst ansehen,
 * dann verschieben, erst viel später löschen. Wer den Schalter setzt, wird zusätzlich
 * gefragt, und die Vorgabe der Rückfrage ist „nein".
 */
#[AsCommand(name: 'rc:media:gc:scan', description: 'Scan: findet verwaiste Medien (Default Read-only)')]
final class GarbageCollectorScanCommand extends Command
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly OrphanedMediaFinder $finder,
        private readonly MediaQuarantineService $quarantineService,
        private readonly ReportFormatterRegistry $formatters,
        private readonly MediaToolkitLogger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Report-Format: console, json, csv', 'console')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl Kandidaten')
            ->addOption('quarantine', null, InputOption::VALUE_NONE, 'Kandidaten in Quarantäne verschieben (nicht Dry-Run!)')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Pfad für Report-Datei (statt STDOUT)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $logger = $this->logger->withModule('GarbageCollector');

        if (!$this->moduleRegistry->isActive(ModuleRegistry::MODULE_GARBAGE_COLLECTOR)) {
            $io->warning('Garbage-Collector-Modul ist nicht aktiviert (Plugin-Config).');
            return self::FAILURE;
        }

        $format = (string) $input->getOption('format');
        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;
        $doQuarantine = (bool) $input->getOption('quarantine');
        $outputPath = $input->getOption('output');

        $io->section($doQuarantine ? 'Garbage-Collector – Quarantine-Lauf' : 'Garbage-Collector – Read-only-Scan');

        try {
            /** @var list<OrphanedMedia> $candidates */
            $candidates = [];
            foreach ($this->finder->findOrphans(500, $limit) as $candidate) {
                $candidates[] = $candidate;
            }
        } catch (Throwable $e) {
            $logger->error('Scan abgebrochen', ['exception' => $e]);
            $io->error('Scan fehlgeschlagen: ' . $e->getMessage());
            return self::FAILURE;
        }

        $rendered = $this->formatters->get($format)->render($candidates);

        if (is_string($outputPath) && $outputPath !== '') {
            if (file_put_contents($outputPath, $rendered) === false) {
                $io->error('Konnte Report-Datei nicht schreiben: ' . $outputPath);
                return self::FAILURE;
            }
            $io->success(sprintf('Report nach "%s" geschrieben (%d Kandidaten).', $outputPath, count($candidates)));
        } else {
            $output->writeln($rendered);
        }

        if (!$doQuarantine) {
            return self::SUCCESS;
        }

        if ($candidates === []) {
            $io->note('Keine Kandidaten — nichts zu verschieben.');
            return self::SUCCESS;
        }

        if (!$io->confirm(sprintf('%d Kandidaten in Quarantäne verschieben?', count($candidates)), false)) {
            $io->note('Abgebrochen.');
            return self::SUCCESS;
        }

        try {
            $runId = $this->quarantineService->createRun();
            $moved = 0;
            foreach ($candidates as $candidate) {
                try {
                    $this->quarantineService->quarantine($runId, $candidate);
                    $moved++;
                } catch (Throwable $e) {
                    $logger->error('Quarantine eines Kandidaten fehlgeschlagen', [
                        'mediaId' => $candidate->mediaId,
                        'exception' => $e,
                    ]);
                }
            }
            $io->success(sprintf('Run %s: %d/%d Medien in Quarantäne verschoben.', $runId, $moved, count($candidates)));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $logger->error('Quarantine-Lauf abgebrochen', ['exception' => $e]);
            $io->error('Quarantine fehlgeschlagen: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
