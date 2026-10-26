<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaUsageIndexer;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;

/**
 * Der Test, der den Datenverlust-Fehler gefunden hätte: er prüft gegen einen echten Shop, dass
 * jedes per Fremdschlüssel referenzierte Medium auch als „verwendet" erkannt wird.
 *
 * Mit gemockter Datenbank ist das nicht zu haben — eine unvollständige Quellenliste sieht gemockt
 * genauso richtig aus wie eine vollständige. Deshalb MySQL und echte Daten.
 *
 * Läuft nur mit gesetzter `DATABASE_URL` (also innerhalb einer Shopware-Instanz), sonst übersprungen.
 */
final class MediaReferenceCompletenessMysqlTest extends TestCase
{
    /** Mehr Stichproben je Spalte kosten Laufzeit, ohne mehr zu beweisen. */
    private const SAMPLE_SIZE = 5;

    private Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('DATABASE_URL');
        if (!\is_string($url) || !str_starts_with($url, 'mysql')) {
            self::markTestSkipped('Ohne MySQL-Verbindung (DATABASE_URL) nicht ausführbar');
        }

        $this->connection = DriverManager::getConnection(
            (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url),
        );
    }

    #[Test]
    public function everyForeignKeyReferencedMediumCountsAsUsed(): void
    {
        // Bewusst nicht über MediaReferenceSources: sonst prüft der Test nur die Spalten, die der
        // Dienst ohnehin schon kennt — eine fehlende Quelle bliebe unsichtbar.
        $foreignKeys = $this->foreignKeyColumnsFromSchema();

        self::assertNotEmpty($foreignKeys, 'Ohne erkannte Fremdschlüssel beweist der Test nichts.');

        $usage = (new MediaUsageIndexer(
            $this->connection,
            new MediaToolkitLogger(new NullLogger()),
            new MediaReferenceSources($this->connection),
        ))->buildUsageMap();

        $missing = [];
        foreach ($foreignKeys as $foreignKey) {
            foreach ($this->sampleReferencedIds($foreignKey['table'], $foreignKey['column']) as $mediaId) {
                if (!$usage->has($mediaId)) {
                    $missing[] = $foreignKey['table'] . '.' . $foreignKey['column'] . ' → ' . $mediaId;
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            "Referenzierte Medien gelten als unbenutzt und würden gelöscht:\n" . implode("\n", $missing),
        );
    }

    /**
     * Alle Fremdschlüssel auf `media.id` direkt aus dem Schema — ohne die Kindtabellen des Mediums
     * selbst, die keine Verwendung sind.
     *
     * @return list<array{table: string, column: string}>
     */
    private function foreignKeyColumnsFromSchema(): array
    {
        /** @var list<array{TABLE_NAME: string, COLUMN_NAME: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = "media" '
            . 'AND REFERENCED_COLUMN_NAME = "id" '
            . 'AND TABLE_NAME NOT IN ("media_translation", "media_thumbnail", "media_tag") '
            . 'ORDER BY TABLE_NAME, COLUMN_NAME',
        );

        return array_map(
            static fn (array $row): array => ['table' => $row['TABLE_NAME'], 'column' => $row['COLUMN_NAME']],
            $rows,
        );
    }

    /**
     * @return list<string>
     */
    private function sampleReferencedIds(string $table, string $column): array
    {
        $quotedColumn = $this->connection->quoteIdentifier($column);

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(sprintf(
            'SELECT DISTINCT LOWER(HEX(%s)) FROM %s WHERE %s IS NOT NULL LIMIT %d',
            $quotedColumn,
            $this->connection->quoteIdentifier($table),
            $quotedColumn,
            self::SAMPLE_SIZE,
        ));

        return $ids;
    }
}
