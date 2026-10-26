<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\Repair\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;

/**
 * Aktualisiert alle FK-Verweise von einer Media-ID auf eine andere. Wird vom RepairExecutor
 * im Rahmen eines `relink_by_name`-Apply genutzt.
 *
 * Welche Spalten das sind, sagt `MediaReferenceSources` — dieselbe Quelle, aus der auch der
 * Garbage Collector liest. Was als Referenz erkannt wird, kann damit auch umgebogen werden.
 *
 * Wichtig: Operationen sind Set-basiert (UPDATE WHERE old_id = ...), das macht Reverse
 * trivial: einfach old/new tauschen und nochmal updaten.
 */
final class MediaReferenceUpdater
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MediaReferenceSources $referenceSources,
    ) {
    }

    /**
     * Biegt alle FK-Verweise von `$oldHexId` auf `$newHexId` um.
     *
     * @return array<string, int> [table.column => betroffeneRows]
     */
    public function rewriteReferences(string $oldHexId, string $newHexId): array
    {
        $this->assertHexUuid($oldHexId);
        $this->assertHexUuid($newHexId);

        $touched = [];

        foreach ($this->referenceSources->foreignKeyColumns() as $fk) {
            $sql = sprintf(
                'UPDATE %s SET %s = UNHEX(:newId) WHERE %s = UNHEX(:oldId)',
                $this->connection->quoteIdentifier($fk['table']),
                $this->connection->quoteIdentifier($fk['column']),
                $this->connection->quoteIdentifier($fk['column']),
            );
            $rows = (int) $this->connection->executeStatement($sql, [
                'newId' => $newHexId,
                'oldId' => $oldHexId,
            ]);
            if ($rows > 0) {
                $touched[$fk['table'] . '.' . $fk['column']] = $rows;
            }
        }

        return $touched;
    }

    /**
     * Tauscht in einer einzelnen `cms_slot_translation.config`-Spalte alle Vorkommen einer alten
     * Media-ID gegen eine neue. Nutzt SQL-`REPLACE` — sicher, weil Hex-UUIDs keine
     * Sub-String-Kollisionen produzieren.
     */
    public function rewriteCmsSlotReference(string $slotHexId, string $oldHexId, string $newHexId): int
    {
        $this->assertHexUuid($slotHexId);
        $this->assertHexUuid($oldHexId);
        $this->assertHexUuid($newHexId);

        return (int) $this->connection->executeStatement(
            'UPDATE cms_slot_translation '
            . 'SET config = REPLACE(config, :oldId, :newId) '
            . 'WHERE cms_slot_id = UNHEX(:slotId) AND config LIKE :pattern',
            [
                'oldId' => $oldHexId,
                'newId' => $newHexId,
                'slotId' => $slotHexId,
                'pattern' => '%' . $oldHexId . '%',
            ],
        );
    }

    /**
     * @return list<array{table: string, column: string}>
     */
    public function knownFkColumns(): array
    {
        return $this->referenceSources->foreignKeyColumns();
    }

    private function assertHexUuid(string $hex): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $hex) !== 1) {
            throw new InvalidArgumentException(sprintf('Ungültige Hex-UUID: "%s"', $hex));
        }
    }

}
