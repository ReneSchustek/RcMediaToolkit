<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\TempTableUsageMap;
use RuntimeException;

/**
 * Verhalten bei Verbindungsabriss: Die temporäre Tabelle hängt an der Verbindung. Ob ein
 * Neuanlegen harmlos ist, hängt allein davon ab, ob schon IDs darin standen.
 */
#[CoversClass(TempTableUsageMap::class)]
final class TempTableUsageMapReconnectTest extends TestCase
{
    #[Test]
    public function reconnectBeforeTheFirstIdRecreatesTheTableAndContinues(): void
    {
        $attempts = 0;
        $connection = $this->connection(function (string $sql) use (&$attempts): int {
            if (!str_starts_with($sql, 'INSERT')) {
                return 0;
            }

            $attempts++;
            if ($attempts === 1) {
                throw $this->tableIsGone();
            }

            return 1;
        });

        $map = new TempTableUsageMap($connection);
        $map->add('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $map->flush();

        self::assertSame(2, $attempts, 'Nach dem Neuanlegen muss der Einfüge-Versuch wiederholt werden.');
    }

    /**
     * Der eigentliche Schaden: ein stilles Neuanlegen ergäbe eine leere Nutzungsliste — der Vergleich
     * danach hielte jedes bereits erfasste Medium für eine Waise.
     */
    #[Test]
    public function reconnectAfterTheFirstIdAbortsTheRun(): void
    {
        $inserts = 0;
        $connection = $this->connection(function (string $sql) use (&$inserts): int {
            if (!str_starts_with($sql, 'INSERT')) {
                return 0;
            }

            $inserts++;
            if ($inserts === 1) {
                return 1;
            }

            throw $this->tableIsGone();
        });

        $map = new TempTableUsageMap($connection);
        $map->add('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $map->flush();

        $map->add('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Bestandsaufnahme.*abgebrochen/s');
        $map->flush();
    }

    #[Test]
    public function otherDatabaseErrorsAreNotMistakenForAReconnect(): void
    {
        $connection = $this->connection(function (string $sql): int {
            if (str_starts_with($sql, 'INSERT')) {
                throw $this->databaseError('Deadlock found when trying to get lock');
            }

            return 0;
        });

        $map = new TempTableUsageMap($connection);
        $map->add('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TempTable-Operation fehlgeschlagen');
        $map->flush();
    }

    private function connection(callable $onStatement): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $identifier): string => '`' . $identifier . '`',
        );
        $connection->method('executeStatement')->willReturnCallback($onStatement);

        return $connection;
    }

    private function tableIsGone(): DbalException
    {
        $pdoException = new PDOException("Base table or view not found: 1146 Table 'db.rc_media_toolkit_usage_temp' doesn't exist");
        $pdoException->errorInfo = ['42S02', 1146, "Table 'db.rc_media_toolkit_usage_temp' doesn't exist"];

        return $this->databaseError("Table 'db.rc_media_toolkit_usage_temp' doesn't exist", $pdoException);
    }

    private function databaseError(string $message, ?PDOException $previous = null): DbalException
    {
        return new class ($message, 0, $previous) extends RuntimeException implements DbalException {};
    }
}
