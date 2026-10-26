<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\ExternalUrlCheck;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Woher der Erreichbarkeits-Test seine Adressen nimmt.
 *
 * Er durchsucht Inhalte: Erlebniswelten, Produkt- und Kategorietexte. Drei Dinge entscheiden
 * dabei, ob aus einer Prüfung eine Last wird — die Obergrenze, das Auslassen fehlender Tabellen
 * und der Umgang mit einer Freigabeliste. Alle drei fallen still aus, wenn sie fehlen: Der Lauf
 * arbeitet dann einfach länger, oder er bricht mit einer Meldung ab, die niemand liest.
 */
#[CoversClass(ExternalUrlCheck::class)]
final class ExternalUrlCheckCollectionTest extends TestCase
{
    /**
     * Was: Eine Tabelle, die es nicht gibt.
     * Warum: Die durchsuchten Tabellen kommen aus dem Kern — aber nicht jede Installation hat
     *        jede davon, und eine fehlende darf den ganzen Lauf nicht mitnehmen. Ohne diese
     *        Abfrage stünde im Protokoll ein Datenbankfehler statt eines Prüfergebnisses.
     */
    #[Test]
    public function aMissingTableIsSkippedInsteadOfBreakingTheRun(): void
    {
        $connection = $this->connectionWith(tablesExist: false);
        $connection->expects(self::never())->method('fetchAllAssociative');

        $urls = [];
        $this->invoke($connection, 'collectFromColumn', 'gibt_es_nicht', 'config', 'id', 'test', $urls);

        self::assertSame([], $urls);
    }

    /**
     * Was: Adressen werden aus dem Inhalt gelesen, mit Herkunftsangabe.
     * Warum: Die Herkunft ist der halbe Nutzen des Fundes. Ohne sie meldet der Test „diese
     *        Adresse antwortet nicht" und niemand weiß, auf welcher Seite sie steht.
     */
    #[Test]
    public function everyFoundUrlCarriesItsOrigin(): void
    {
        $connection = $this->connectionWith(rows: [
            ['source_id' => 'aabbccdd', 'payload' => 'Text mit https://cdn.example.com/bild.jpg drin'],
        ]);

        $urls = [];
        $this->invoke($connection, 'collectFromColumn', 'product_translation', 'description', 'product_id', 'product', $urls);

        self::assertArrayHasKey('https://cdn.example.com/bild.jpg', $urls);
        self::assertSame(
            [['sourceType' => 'product', 'sourceReference' => 'product:aabbccdd']],
            $urls['https://cdn.example.com/bild.jpg'],
        );
    }

    /**
     * Was: Dieselbe Adresse an zwei Stellen.
     * Warum: Abgerufen wird sie einmal, gemeldet für **jede** Fundstelle. Andernfalls
     *        reparierte jemand die eine Seite und wunderte sich, dass der Fund bleibt.
     */
    #[Test]
    public function theSameUrlInTwoPlacesKeepsBothOrigins(): void
    {
        $connection = $this->connectionWith(rows: [
            ['source_id' => 'aaaa', 'payload' => '<img src="https://cdn.example.com/bild.png">'],
            ['source_id' => 'bbbb', 'payload' => '<img src="https://cdn.example.com/bild.png">'],
        ]);

        $urls = [];
        $this->invoke($connection, 'collectFromColumn', 'category_translation', 'description', 'category_id', 'category', $urls);

        self::assertCount(2, $urls['https://cdn.example.com/bild.png']);
    }

    /**
     * Was: Nur Adressen, die nach einer Bilddatei aussehen.
     * Warum: Der Test fragt Adressen mit einer HEAD-Anfrage ab. Ohne diese Einschränkung
     *        klopfte er auch Verweise auf fremde Webseiten ab — das ist keine Bildprüfung mehr,
     *        sondern ein Besuch bei Dritten.
     */
    #[Test]
    public function onlyImageLikeUrlsAreCollected(): void
    {
        $connection = $this->connectionWith(rows: [
            ['source_id' => 'aaaa', 'payload' => 'Zum Partner: https://example.com/impressum und ein Bild https://example.com/foto.webp'],
        ]);

        $urls = [];
        $this->invoke($connection, 'collectFromColumn', 'product_translation', 'description', 'product_id', 'product', $urls);

        self::assertSame(['https://example.com/foto.webp'], array_keys($urls));
    }

    /**
     * @return array<string, array{mixed, list<string>|null}>
     */
    public static function allowlistCases(): array
    {
        return [
            'nicht gesetzt' => [null, null],
            'leer' => ['', null],
            'nur Leerzeichen' => ['   ', null],
            'ein Name' => ['cdn.example.com', ['cdn.example.com']],
            'mehrere mit Leerraum' => [' cdn.example.com , bilder.example.org ', ['cdn.example.com', 'bilder.example.org']],
            'Großschreibung' => ['CDN.Example.COM', ['cdn.example.com']],
            'leere Einträge dazwischen' => ['a.example.com,,b.example.com', ['a.example.com', 'b.example.com']],
        ];
    }

    /**
     * @param list<string>|null $expected
     *
     * Was: Die Freigabeliste aus den Einstellungen.
     * Warum: `null` und leere Liste bedeuten **Gegensätzliches** — ohne Liste wird alles
     *        Öffentliche geprüft, mit leerer Liste nichts. Ein Betreiber, der das Feld leert,
     *        will das erste, nicht das zweite.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('allowlistCases')]
    public function theAllowlistIsParsedForgivingly(mixed $configured, ?array $expected): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($configured);

        $check = new ExternalUrlCheck($this->createMock(Connection::class), $config);

        $method = (new ReflectionClass($check))->getMethod('parseHostAllowlist');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invoke($check));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connectionWith(array $rows = [], bool $tablesExist = true): Connection
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn($tablesExist);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return $connection;
    }

    /**
     * @param array<string, list<array{sourceType: string, sourceReference: string}>> $urls
     */
    private function invoke(
        Connection $connection,
        string $method,
        string $table,
        string $column,
        string $idColumn,
        string $sourceType,
        array &$urls,
    ): void {
        $check = new ExternalUrlCheck($connection, $this->createMock(SystemConfigService::class));

        $target = (new ReflectionClass($check))->getMethod($method);
        $target->setAccessible(true);
        $target->invokeArgs($check, [$table, $column, $idColumn, $sourceType, &$urls]);
    }
}
