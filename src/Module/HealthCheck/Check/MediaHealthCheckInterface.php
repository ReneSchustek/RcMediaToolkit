<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check;

use Generator;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;

/**
 * Der Vertrag, den jede Prüfung erfüllt.
 *
 * Drei Zusagen, und die dritte ist die wichtige: `run()` gibt einen Generator zurück, keine
 * Liste. Ein Shop mit sechsstelliger Medienzahl passt nicht in den Speicher — die Befunde
 * müssen einzeln herausfallen, während der Lauf noch läuft. Wer hier eine Sammlung
 * zurückgibt, bringt den Lauf genau bei den Beständen um, für die er gedacht ist.
 */
interface MediaHealthCheckInterface
{
    /**
     * Eindeutige Identifikation des Check-Typs (z. B. "missing_file"). Wird in
     * `check_type` der Report-Tabelle gespeichert und als Filter im CLI verwendet.
     */
    public function type(): string;

    /**
     * Plugin-Config-Key, mit dem dieser Check (de)aktiviert wird (z. B.
     * `RcMediaToolkit.config.healthCheckMissingFiles`).
     */
    public function configKey(): string;

    /**
     * @return Generator<int, Finding>
     */
    public function run(): Generator;
}
