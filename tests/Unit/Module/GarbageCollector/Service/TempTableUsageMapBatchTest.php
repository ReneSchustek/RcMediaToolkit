<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\TempTableUsageMap;

/**
 * Die Merkliste der benutzten Medien — sie entscheidet, was als verwaist gilt.
 *
 * **Jede Kennung, die hier verlorengeht, wird zu einem Löschkandidaten.** Deshalb prüfen die
 * Fälle vor allem das Sammeln: dass gebündelt geschrieben wird statt einzeln, dass ein Bündel
 * vor jeder Abfrage tatsächlich geschrieben ist, und dass Doppelte zusammenfallen, ohne die
 * Zählung zu verfälschen.
 */
#[CoversClass(TempTableUsageMap::class)]
final class TempTableUsageMapBatchTest extends TestCase
{
    /**
     * Was: Einzelne Einträge werden gesammelt, nicht sofort geschrieben.
     * Warum: Der Aufräumlauf geht über den gesamten Medienbestand. Eine Anweisung je Medium
     *        wären bei 5.000 Bildern 5.000 Wege zur Datenbank.
     */
    #[Test]
    public function singleEntriesAreNotWrittenImmediately(): void
    {
        $statements = [];
        $map = new TempTableUsageMap($this->connection($statements));

        $map->add('0123456789abcdef0123456789abcdef');
        $map->add('fedcba9876543210fedcba9876543210');

        self::assertSame([], $statements, 'Zwei Einträge lösen noch kein Schreiben aus.');
        self::assertSame(2, $map->size(), 'Gezählt wird trotzdem sofort.');
    }

    /**
     * Was: Vor einer Abfrage wird geschrieben.
     * Warum: **Der gefährlichste Fall der Klasse.** Würde die Abfrage auf einem noch nicht
     *        geschriebenen Bündel laufen, meldete sie „nicht benutzt" — und ein Bild, das gerade
     *        eben als benutzt gemeldet wurde, landete auf der Löschliste.
     */
    #[Test]
    public function aQueryFlushesThePendingBatchFirst(): void
    {
        $statements = [];
        $map = new TempTableUsageMap($this->connection($statements));

        $map->add('0123456789abcdef0123456789abcdef');
        $map->has('0123456789abcdef0123456789abcdef');

        $written = implode(chr(10), array_column($statements, 'sql'));
        self::assertStringContainsString('INSERT IGNORE INTO', $written, 'Vor der Abfrage muss das Bündel stehen.');
    }

    /**
     * Was: Dieselbe Kennung zweimal.
     * Warum: Ein Medium kann an mehreren Stellen benutzt werden. Geschrieben gehört es einmal —
     *        gezählt wird jede Fundstelle, denn die Zahl beschreibt die Fundstellen, nicht die
     *        Medien.
     */
    #[Test]
    public function duplicatesAreWrittenOnceButCountedEachTime(): void
    {
        $statements = [];
        $map = new TempTableUsageMap($this->connection($statements));

        $map->add('0123456789abcdef0123456789abcdef');
        $map->add('0123456789abcdef0123456789abcdef');
        $map->flush();

        $insert = '';
        foreach ($statements as $statement) {
            if (str_contains($statement['sql'], 'INSERT IGNORE INTO')) {
                $insert = $statement['sql'];
            }
        }

        self::assertSame(1, substr_count($insert, 'UNHEX('), 'Doppelte fallen vor dem Schreiben zusammen.');
        self::assertSame(2, $map->size());
    }

    /**
     * Ohne Einträge wird nichts geschrieben — auch nicht die leere Anweisung, die sonst bei
     * jedem Durchgang über einen leeren Bestand entstünde.
     */
    #[Test]
    public function flushingAnEmptyBatchDoesNothing(): void
    {
        $statements = [];
        $map = new TempTableUsageMap($this->connection($statements));

        $map->flush();

        self::assertSame([], $statements);
        self::assertSame(0, $map->size());
    }

    /**
     * Der Name der Ablage kommt erst heraus, wenn sie auch existiert — sonst zeigte er auf eine
     * Tabelle, die niemand angelegt hat.
     */
    #[Test]
    public function askingForTheTableNameCreatesIt(): void
    {
        $statements = [];
        $map = new TempTableUsageMap($this->connection($statements));

        $name = $map->tableName();

        self::assertNotSame('', $name);
        self::assertStringContainsString(
            'CREATE',
            implode("\n", array_column($statements, 'sql')),
            'Der Name wird erst nach dem Anlegen herausgegeben.',
        );
    }

    /**
     * @param list<array{sql: string, params: array<string, mixed>}> $statements
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connection(array &$statements): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );
        $connection->method('fetchOne')->willReturn(false);

        return $connection;
    }
}
