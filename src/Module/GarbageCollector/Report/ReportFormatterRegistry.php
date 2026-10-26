<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report;

use InvalidArgumentException;

/**
 * Findet zum angeforderten Ausgabeformat den passenden Formatierer.
 *
 * Die Formatierer kommen über die Dienst-Verdrahtung herein und tragen ihren Namen selbst
 * (`format()`). Ein neues Format braucht deshalb einen Eintrag in `services.xml` und sonst
 * nichts — keine Liste, die jemand nachzupflegen vergisst.
 *
 * Ein unbekanntes Format führt zur Ausnahme, die die verfügbaren aufzählt. Ein stiller
 * Rückfall auf die Konsolenausgabe hätte bei einem Tippfehler im Cron-Aufruf einen Bericht
 * erzeugt, den niemand weiterverarbeiten kann.
 */
final class ReportFormatterRegistry
{
    /** @var array<string, ReportFormatterInterface> */
    private array $formatters = [];

    /**
     * @param iterable<ReportFormatterInterface> $formatters
     */
    public function __construct(iterable $formatters)
    {
        foreach ($formatters as $formatter) {
            $this->formatters[$formatter->format()] = $formatter;
        }
    }

    public function get(string $format): ReportFormatterInterface
    {
        if (!isset($this->formatters[$format])) {
            throw new InvalidArgumentException(sprintf(
                'Unbekanntes Report-Format "%s". Verfügbar: %s',
                $format,
                implode(', ', array_keys($this->formatters)),
            ));
        }
        return $this->formatters[$format];
    }

    /**
     * @return list<string>
     */
    public function availableFormats(): array
    {
        return array_keys($this->formatters);
    }
}
