<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use PDOException;
use RuntimeException;

/**
 * TempTable-basierte UsageMap für sehr große Stores (>5M Medien).
 *
 * Strategie: MySQL TEMPORARY TABLE als Connection-lokale Datenstruktur. INSERTS werden
 * gepuffert und in Batches geflusht, um SQL-Roundtrips zu reduzieren.
 *
 * Wichtig: `has()` pro Row ist hier teuer (1 Roundtrip). Für effizienten Bulk-Vergleich
 * sollte der `JOIN`-basierte Pfad in `OrphanedMediaFinder` genutzt werden, der die
 * Tabelle direkt in einem `LEFT JOIN` verwendet — siehe `tableName()`.
 *
 * Verbindungsabriss: TEMPORARY TABLEs sind an die Verbindung gebunden — nach einem Reconnect
 * (z. B. nach `wait_timeout`) ist die Tabelle weg. Was dann richtig ist, hängt davon ab, ob schon
 * etwas drinstand:
 *  - noch keine ID geschrieben: Tabelle still neu anlegen und weitermachen, es geht nichts verloren.
 *  - bereits IDs geschrieben: **abbrechen**. Ein stilles Neuanlegen ergäbe eine leere und damit
 *    unvollständige Nutzungsliste — der anschließende Vergleich hielte dann referenzierte Medien
 *    für Waisen. Ein abgebrochener Lauf ist billig, ein solcher Fehlschluss nicht.
 */
final class TempTableUsageMap implements UsageMapInterface
{
    private const TABLE_NAME = 'rc_media_toolkit_usage_temp';
    private const FLUSH_BATCH = 500;
    /** MySQL-Errorcode für "table doesn't exist". */
    private const ERR_TABLE_DOES_NOT_EXIST = 1146;

    /** @var list<string> */
    private array $pendingInserts = [];

    private int $count = 0;

    private bool $tableCreated = false;

    /** Sobald die erste ID geschrieben ist, kostet ein Verbindungsabriss Daten. */
    private bool $idsWritten = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function tableName(): string
    {
        $this->ensureTable();
        return self::TABLE_NAME;
    }

    public function add(string $hexId): void
    {
        $this->pendingInserts[] = $hexId;
        $this->count++;

        if (count($this->pendingInserts) >= self::FLUSH_BATCH) {
            $this->flush();
        }
    }

    public function has(string $hexId): bool
    {
        $this->flush();

        return $this->withReconnectGuard(function () use ($hexId): bool {
            $row = $this->connection->fetchOne(
                sprintf(
                    'SELECT 1 FROM %s WHERE id = UNHEX(:id) LIMIT 1',
                    $this->connection->quoteIdentifier(self::TABLE_NAME),
                ),
                ['id' => $hexId],
            );
            return $row !== false;
        });
    }

    public function size(): int
    {
        return $this->count;
    }

    /**
     * @throws DbalException
     */
    public function flush(): void
    {
        if ($this->pendingInserts === []) {
            return;
        }

        $batch = array_values(array_unique($this->pendingInserts));
        $this->pendingInserts = [];

        $this->withReconnectGuard(function () use ($batch): void {
            $this->ensureTable();

            $placeholders = [];
            $params = [];
            foreach ($batch as $i => $hex) {
                $key = 'h' . $i;
                $placeholders[] = '(UNHEX(:' . $key . '))';
                $params[$key] = $hex;
            }
            $sql = sprintf(
                'INSERT IGNORE INTO %s (id) VALUES %s',
                $this->connection->quoteIdentifier(self::TABLE_NAME),
                implode(',', $placeholders),
            );
            $this->connection->executeStatement($sql, $params);
            $this->idsWritten = true;
        });
    }

    private function ensureTable(): void
    {
        if ($this->tableCreated) {
            return;
        }
        // InnoDB statt Memory-Engine: Memory hätte max_heap_table_size-Limit (default 16–64 MB),
        // genau bei >5M Medien (~80 MB Set) würde Insert mit "table is full" brechen.
        // InnoDB-Temp-Tables spillen bei Bedarf auf Disk — passend für den Use-Case.
        $this->connection->executeStatement(sprintf(
            'CREATE TEMPORARY TABLE IF NOT EXISTS %s ('
            . ' id BINARY(16) PRIMARY KEY'
            . ') ENGINE=InnoDB',
            $this->connection->quoteIdentifier(self::TABLE_NAME),
        ));
        $this->tableCreated = true;
    }

    /**
     * Führt die Operation aus. Ist die Tabelle nach einem Reconnect verschwunden, entscheidet der
     * bisherige Füllstand: leer ⇒ neu anlegen und wiederholen, gefüllt ⇒ Abbruch.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withReconnectGuard(callable $operation)
    {
        try {
            return $operation();
        } catch (DbalException $e) {
            if (!$this->isTableDoesNotExistError($e)) {
                throw new RuntimeException('TempTable-Operation fehlgeschlagen', 0, $e);
            }

            if ($this->idsWritten) {
                throw new RuntimeException(
                    'Die Verbindung wurde während der Bestandsaufnahme neu aufgebaut, dabei ist die '
                    . 'Liste der benutzten Medien verloren gegangen. Der Lauf wird abgebrochen, damit '
                    . 'keine referenzierten Medien als Waisen gelten. Bitte den Lauf wiederholen.',
                    0,
                    $e,
                );
            }

            // Noch nichts geschrieben: Neuanlegen kostet keine Daten.
            $this->tableCreated = false;
            $this->ensureTable();

            return $operation();
        }
    }

    private function isTableDoesNotExistError(DbalException $e): bool
    {
        $previous = $e->getPrevious();
        if ($previous instanceof PDOException && isset($previous->errorInfo[1])) {
            return (int) $previous->errorInfo[1] === self::ERR_TABLE_DOES_NOT_EXIST;
        }
        // Fallback: Message-String-Check als zweite Bastion (z.B. wenn DBAL-Adapter wechselt).
        return str_contains((string) $e->getMessage(), "doesn't exist")
            || str_contains((string) $e->getMessage(), 'Table ' . self::TABLE_NAME);
    }
}
