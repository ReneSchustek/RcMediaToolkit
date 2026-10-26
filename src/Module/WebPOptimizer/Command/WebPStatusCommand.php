<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Command;

use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\EncoderChain;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Zeigt, wie viel des Bestands umgewandelt ist und welche Umwandler zur Verfügung stehen.
 *
 * Die Umwandler-Übersicht ist der wichtigere Teil: Die Kette fällt der Reihe nach zurück
 * (cwebp → libvips → Imagick → GD), und welches Glied greift, entscheidet über Bildqualität
 * und Laufzeit. Ohne diese Auskunft lässt sich nicht erklären, warum dieselbe Datei auf
 * zwei Servern unterschiedlich groß herauskommt.
 *
 * **Sie zählt Dateien, keine Auslieferungen.** Dass eine `.webp` existiert, heißt nicht,
 * dass sie je an einen Browser geht. Wer beides verwechselt, hält eine Auslieferung für
 * gesichert, die nie stattfindet.
 */
#[AsCommand(name: 'rc:media:webp:status', description: 'Zeigt WebP-Bestand und verfügbare Encoder')]
final class WebPStatusCommand extends Command
{
    public function __construct(
        private readonly WebPRegistry $registry,
        private readonly EncoderChain $encoderChain,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('WebP-Optimizer – Status');

        $stats = $this->registry->stats();
        $io->section('Bestand (Sample-basiert)');
        $io->table(
            ['Metrik', 'Wert'],
            [
                ['Medien gesamt', $stats['total']],
                ['Konvertierbar (JPEG/PNG)', $stats['eligible']],
                ['mit WebP', $stats['withWebp']],
                ['ohne WebP', $stats['withoutWebp']],
            ],
        );

        $io->section('Encoder-Verfügbarkeit');
        $rows = [];
        foreach ($this->encoderChain->availabilityMap() as $name => $available) {
            $rows[] = [$name, $available ? 'verfügbar' : 'nicht verfügbar'];
        }
        $io->table(['Encoder', 'Status'], $rows);

        return self::SUCCESS;
    }
}
