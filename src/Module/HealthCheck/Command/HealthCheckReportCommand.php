<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Command;

use JsonException;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Zeigt die offenen Befunde der Medienprüfung.
 *
 * Rein lesend und vom Lauf getrennt: Die Befunde liegen in einer eigenen Tabelle, ein
 * Bericht braucht also keinen erneuten Durchgang über den Medienbestand. Ohne diese
 * Trennung wäre jeder Blick auf die Liste so teuer wie die Prüfung selbst.
 */
#[AsCommand(name: 'rc:media:health:report', description: 'Zeigt offene Health-Check-Findings')]
final class HealthCheckReportCommand extends Command
{
    public function __construct(
        private readonly HealthReportRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_REQUIRED, 'Auf einen Check-Typ filtern')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format: console, json, csv, md', 'console')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl Findings', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $check = $input->getOption('check');
        $format = (string) $input->getOption('format');
        $limit = (int) ($input->getOption('limit') ?? 100);

        $findings = $this->repository->listOpenFindings(
            is_string($check) && $check !== '' ? $check : null,
            $limit,
        );

        $rendered = match ($format) {
            'json' => $this->asJson($findings),
            'csv' => $this->asCsv($findings),
            'md' => $this->asMarkdown($findings),
            default => null,
        };

        if ($rendered !== null) {
            $output->writeln($rendered);
            return self::SUCCESS;
        }

        // Console-Format
        $countMap = $this->repository->countOpenBySeverity();
        $io->section('Offene Findings (gruppiert nach Severity)');
        $io->table(['Severity', 'Anzahl'], array_map(
            static fn (string $sev, int $c): array => [$sev, $c],
            array_keys($countMap),
            array_values($countMap),
        ));

        if ($findings === []) {
            $io->success('Keine offenen Findings.');
            return self::SUCCESS;
        }

        $io->section(sprintf('Letzte %d Findings', count($findings)));
        $rows = [];
        foreach ($findings as $f) {
            $rows[] = [
                substr((string) $f['id'], 0, 8),
                $f['severity'],
                $f['check_type'],
                substr((string) ($f['media_id'] ?? '-'), 0, 8) ?: '-',
                substr((string) ($f['url_path'] ?? '-'), 0, 40),
                (string) $f['last_seen_at'],
            ];
        }
        $io->table(['ID', 'Severity', 'Check', 'Media', 'URL/Path', 'Last Seen'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function asJson(array $findings): string
    {
        try {
            return json_encode($findings, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('JSON-Encoding fehlgeschlagen', 0, $e);
        }
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function asCsv(array $findings): string
    {
        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            return '';
        }
        fputcsv($buffer, ['id', 'check_type', 'severity', 'media_id', 'source_type', 'source_reference', 'url_path', 'last_seen_at'], ',', '"', '\\');
        foreach ($findings as $f) {
            fputcsv($buffer, [
                (string) $f['id'],
                (string) $f['check_type'],
                (string) $f['severity'],
                (string) ($f['media_id'] ?? ''),
                (string) $f['source_type'],
                (string) ($f['source_reference'] ?? ''),
                (string) ($f['url_path'] ?? ''),
                (string) $f['last_seen_at'],
            ], ',', '"', '\\');
        }
        rewind($buffer);
        $content = stream_get_contents($buffer);
        fclose($buffer);
        return $content === false ? '' : $content;
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function asMarkdown(array $findings): string
    {
        if ($findings === []) {
            return "# Health-Check-Findings\n\nKeine offenen Findings.\n";
        }
        $lines = ['# Health-Check-Findings', '', '| Check | Severity | Media-ID | URL/Path |', '|-------|----------|----------|----------|'];
        foreach ($findings as $f) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $f['check_type'],
                $f['severity'],
                $f['media_id'] ?? '-',
                str_replace('|', '\\|', (string) ($f['url_path'] ?? '-')),
            );
        }
        return implode("\n", $lines) . "\n";
    }
}
