<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\Repair\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Der Zugang zur Prüfspur der Reparaturen.
 *
 * Das Löschen weist eine Aufbewahrungsdauer von 0 oder weniger ab und gibt 0 zurück, statt
 * alles zu entfernen. Der Unterschied ist erheblich: Eine nicht gesetzte Einstellung liest
 * sich als 0, und ein `DELETE` mit einem Stichtag von „jetzt" hätte die gesamte Spur
 * genommen — also jeden Rückweg jeder je durchgeführten Reparatur.
 */
final class RepairAuditRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Löscht Audit-Einträge, die älter sind als der angegebene Schwellwert.
     * Retention <= 0 = nie löschen (Insert-only-Default).
     *
     * @throws DbalException
     */
    public function purgeOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days');
        return (int) $this->connection->executeStatement(
            'DELETE FROM rc_media_repair_audit WHERE applied_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s.v')],
        );
    }

    /**
     * @return int Anzahl Einträge insgesamt
     * @throws DbalException
     */
    public function count(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rc_media_repair_audit');
    }
}
