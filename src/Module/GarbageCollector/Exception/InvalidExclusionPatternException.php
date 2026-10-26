<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Exception;

use RuntimeException;

/**
 * Ein Ausschlussmuster aus der Plugin-Konfiguration ist kein gültiger regulärer Ausdruck.
 *
 * Der Lauf bricht deshalb ab: Ein Werkzeug, das Medien aussortiert, darf nicht mit einer
 * Ausschlussliste weiterarbeiten, von der ein Teil nicht greift.
 */
final class InvalidExclusionPatternException extends RuntimeException
{
    public static function forPattern(string $pattern, ?string $reason = null): self
    {
        return new self(sprintf(
            'Das Ausschlussmuster "%s" ist kein gültiger regulärer Ausdruck%s. '
            . 'Der Lauf wurde abgebrochen, damit keine Datei versehentlich aussortiert wird. '
            . 'Bitte das Muster in der Plugin-Konfiguration unter „Ausschluss-Muster" korrigieren.',
            $pattern,
            $reason !== null && $reason !== '' ? ' (' . $reason . ')' : '',
        ));
    }
}
