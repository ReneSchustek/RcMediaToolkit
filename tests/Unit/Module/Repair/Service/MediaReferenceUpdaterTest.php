<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\Repair\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\MediaReferenceUpdater;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;

/**
 * Der Aktualisierer schreibt Verweise in fremden Tabellen um.
 *
 * **Zwei Dinge müssen deshalb halten, bevor irgendetwas geschrieben wird:** Die Kennungen sind
 * echte Hex-UUIDs — sie gehen unmaskiert in die Anweisung —, und die Tabellen- und Spaltennamen
 * kommen maskiert aus der Verbindung statt roh aus einer Liste. Fällt eines davon weg, wird aus
 * einer Reparatur eine Einschleusung.
 */
#[CoversClass(MediaReferenceUpdater::class)]
final class MediaReferenceUpdaterTest extends TestCase
{
    private const OLD_ID = '0123456789abcdef0123456789abcdef';
    private const NEW_ID = 'fedcba9876543210fedcba9876543210';

    /**
     * @return array<string, array{string}>
     */
    public static function badIdCases(): array
    {
        return [
            'zu kurz' => ['abc'],
            'Großbuchstaben' => ['0123456789ABCDEF0123456789ABCDEF'],
            'Bindestriche' => ['01234567-89ab-cdef-0123-456789abcdef'],
            'Anführungszeichen' => ["0123456789abcdef0123456789abcde'"],
            'Anweisungsfragment' => ['0 OR 1=1 -- '],
            'leer' => [''],
        ];
    }

    /**
     * Was: Kennungen, die keine Hex-UUID sind.
     * Warum: Sie landen in `UNHEX(...)` und in einem Muster für `LIKE`. Ohne Prüfung stünde dort
     *        beliebiger Text — an einer Stelle, die Massenänderungen über fremde Tabellen fährt.
     */
    #[Test]
    #[DataProvider('badIdCases')]
    public function aBadIdStopsTheRewrite(string $badId): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(InvalidArgumentException::class);

        $this->updater($connection)->rewriteReferences($badId, self::NEW_ID);
    }

    #[Test]
    #[DataProvider('badIdCases')]
    public function aBadIdStopsTheCmsRewrite(string $badId): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(InvalidArgumentException::class);

        $this->updater($connection)->rewriteCmsSlotReference(self::OLD_ID, $badId, self::NEW_ID);
    }

    /**
     * Was: Die Zielkennung wird ebenso geprüft wie die Quelle.
     * Warum: Eine Prüfung, die nur die halbe Anweisung absichert, sichert gar nichts.
     */
    #[Test]
    public function theTargetIdIsCheckedAsWell(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(InvalidArgumentException::class);

        $this->updater($connection)->rewriteReferences(self::OLD_ID, 'kein-uuid');
    }

    /**
     * Was: Tabellen- und Spaltennamen gehen maskiert in die Anweisung.
     * Warum: Sie stammen aus einer Liste im Code, nicht aus einer Eingabe — aber die Maskierung
     *        ist das, was diese Aussage auch dann noch trägt, wenn die Liste eines Tages aus der
     *        Datenbank kommt.
     */
    #[Test]
    public function tableAndColumnNamesAreQuoted(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        $this->updater($connection)->rewriteReferences(self::OLD_ID, self::NEW_ID);

        self::assertNotSame([], $statements, 'Ohne eine einzige Anweisung prüft dieser Fall nichts.');

        foreach ($statements as $sql) {
            self::assertMatchesRegularExpression(
                '/UPDATE `[a-z_]+` SET `[a-z_]+` = UNHEX\(:newId\) WHERE `[a-z_]+` = UNHEX\(:oldId\)/',
                $sql,
            );
        }
    }

    /**
     * Was: Zurückgegeben wird, **wo** wie viele Zeilen betroffen waren.
     * Warum: Genau daraus baut der Reparaturlauf seinen Protokolleintrag — und aus dem wiederum
     *        entsteht die Gegenbewegung. Eine bloße Gesamtzahl ließe sich nicht zurückdrehen.
     */
    #[Test]
    public function theResultNamesTableAndColumnOfEveryChange(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('executeStatement')->willReturn(3);

        $touched = $this->updater($connection)->rewriteReferences(self::OLD_ID, self::NEW_ID);

        self::assertNotSame([], $touched);

        foreach ($touched as $key => $rows) {
            self::assertStringContainsString('.', $key, 'Der Schlüssel nennt Tabelle und Spalte.');
            self::assertSame(3, $rows);
        }
    }

    /**
     * Die Gegenprobe: Wo nichts geändert wurde, steht auch nichts im Ergebnis. Sonst meldete der
     * Protokolleintrag Tabellen, die der Lauf nie berührt hat.
     */
    #[Test]
    public function untouchedTablesAreLeftOutOfTheResult(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('executeStatement')->willReturn(0);

        self::assertSame([], $this->updater($connection)->rewriteReferences(self::OLD_ID, self::NEW_ID));
    }

    /**
     * Die Quelle der Spaltenliste fragt das Schema der Datenbank. Für die Fälle hier antwortet
     * eine Doublette mit zwei Spalten — mehr braucht es nicht, und weniger machte die beiden
     * Fälle zur leeren Behauptung.
     */
    private function updater(Connection $connection): MediaReferenceUpdater
    {
        $schemaConnection = $this->createMock(Connection::class);
        $schemaConnection->method('getDatabase')->willReturn('shopware');
        $schemaConnection->method('fetchAllAssociative')->willReturn([
            ['TABLE_NAME' => 'product_media', 'COLUMN_NAME' => 'media_id'],
            ['TABLE_NAME' => 'category', 'COLUMN_NAME' => 'media_id'],
        ]);

        return new MediaReferenceUpdater(
            $connection,
            new MediaReferenceSources($schemaConnection),
        );
    }
}
