<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;

/**
 * Prüft das HealthReportRepository gegen **MySQL** — den einzigen Dialekt, auf dem das Plugin
 * läuft.
 *
 * Der Vorgänger dieses Tests arbeitete gegen SQLite-In-Memory und polyfillte UNHEX/HEX, „damit
 * das Repository-SQL identisch läuft". Für Funktionen geht das auf, für Syntax nicht:
 * `ON DUPLICATE KEY UPDATE` ist eine MySQL-Klausel und lässt sich nicht als SQLite-Funktion
 * nachrüsten. Ausgerechnet der Upsert — der Grund, aus dem es diesen Test gibt — war damit nicht
 * prüfbar, und vier von fünf Fällen liefen in einen Syntaxfehler.
 *
 * Zwei Konsequenzen für den Aufbau hier:
 *
 *  - Getestet wird gegen die **echte** Tabelle aus der Migration, nicht gegen ein nachgebautes
 *    Schema. Ein nachgebautes Schema kann Abweichungen zwischen Migration und Erwartung
 *    grundsätzlich nicht finden.
 *  - Die Test-Datenbank teilen sich mehrere Plugins. Jeder Fall arbeitet deshalb unter einem
 *    eigenen `check_type`-Präfix, zählt nur seine eigenen Zeilen und räumt sie wieder ab.
 */
final class HealthReportRepositoryMysqlTest extends TestCase
{
    /** Klammert alle Zeilen dieses Tests, damit Fremddaten in der geteilten Datenbank unberührt bleiben. */
    private const PREFIX = 'rcmt_it_';

    private Connection $connection;

    private HealthReportRepository $repository;

    protected function setUp(): void
    {
        $url = getenv('DATABASE_URL');
        if (!\is_string($url) || !str_starts_with($url, 'mysql')) {
            self::markTestSkipped('Ohne MySQL-Verbindung (DATABASE_URL) nicht ausführbar');
        }

        $this->connection = DriverManager::getConnection(
            (new DsnParser(['mysql' => 'pdo_mysql']))->parse($url),
        );

        // Die Tabelle entsteht durch die Plugin-Migration. Fehlt sie, ist das Plugin in dieser
        // Datenbank nicht installiert — dann beweist der Test nichts und darf nicht rot sein.
        $existing = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => 'rc_media_health_report'],
        );
        if ((int) $existing === 0) {
            self::markTestSkipped(
                'Tabelle rc_media_health_report fehlt — RcMediaToolkit ist in dieser Datenbank nicht installiert.',
            );
        }

        $this->removeOwnRows();

        $this->repository = new HealthReportRepository($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->removeOwnRows();
        }
    }

    #[Test]
    public function upsertInsertsNewFinding(): void
    {
        $this->repository->upsert(
            $this->finding('missing_file', str_repeat('a', 32), Finding::SEVERITY_CRITICAL),
            new DateTimeImmutable('2026-05-11T10:00:00+00:00'),
        );

        self::assertSame(1, $this->ownRows());
    }

    #[Test]
    public function upsertIsIdempotentForSameDedupKey(): void
    {
        $finding = $this->finding('mime_mismatch', str_repeat('b', 32), Finding::SEVERITY_WARNING);

        $this->repository->upsert($finding, new DateTimeImmutable('2026-05-11T10:00:00+00:00'));
        $this->repository->upsert($finding, new DateTimeImmutable('2026-05-11T11:00:00+00:00'));
        $this->repository->upsert($finding, new DateTimeImmutable('2026-05-11T12:00:00+00:00'));

        self::assertSame(1, $this->ownRows(), 'Identischer Finding darf nicht dupliziert werden');

        $lastSeen = $this->connection->fetchOne(
            'SELECT last_seen_at FROM rc_media_health_report WHERE check_type = :ct',
            ['ct' => self::PREFIX . 'mime_mismatch'],
        );
        self::assertStringContainsString('12:00:00', (string) $lastSeen, 'der letzte Lauf muss last_seen_at fortschreiben');
    }

    #[Test]
    public function upsertWithNullMediaIdIsIdempotent(): void
    {
        // NULL-Werte erzeugen in einem MySQL-UNIQUE-Index keine Kollision, weil NULL nie gleich
        // NULL ist. Ohne den Sentinel im dedup_key entstünden hier Duplikate — nachweisen lässt
        // sich das nur gegen MySQL, denn SQLite kann den Upsert gar nicht ausführen.
        $finding = new Finding(
            checkType: self::PREFIX . 'orphaned_file',
            severity: Finding::SEVERITY_WARNING,
            sourceType: 'filesystem',
            mediaId: null,
            sourceReference: 'fs:media/orphan.jpg',
        );

        $this->repository->upsert($finding, new DateTimeImmutable('2026-05-11T10:00:00+00:00'));
        $this->repository->upsert($finding, new DateTimeImmutable('2026-05-11T11:00:00+00:00'));

        self::assertSame(1, $this->ownRows(), 'NULL-mediaId-Findings dürfen nicht doppelt persistiert werden');
    }

    #[Test]
    public function autoResolveStaleFindingsMarksOldRows(): void
    {
        $checkType = self::PREFIX . 'missing_file';

        $this->repository->upsert(
            $this->finding('missing_file', str_repeat('c', 32), Finding::SEVERITY_CRITICAL),
            new DateTimeImmutable('2026-05-11T10:00:00+00:00'),
        );

        $resolved = $this->repository->autoResolveStaleFindings(
            $checkType,
            new DateTimeImmutable('2026-05-11T20:00:00+00:00'),
        );

        self::assertSame(1, $resolved);

        $resolvedAt = $this->connection->fetchOne(
            'SELECT resolved_at FROM rc_media_health_report WHERE check_type = :ct',
            ['ct' => $checkType],
        );
        self::assertNotNull($resolvedAt, 'ein nicht mehr gesehener Finding muss als erledigt markiert werden');
    }

    #[Test]
    public function purgeResolvedOlderThanDeletesOldResolvedRows(): void
    {
        // Ein sehr alter, bereits erledigter Eintrag — direkt eingefügt, weil das Repository
        // keinen Weg anbietet, resolved_at in die Vergangenheit zu setzen.
        $this->connection->executeStatement(
            'INSERT INTO rc_media_health_report '
            . '(id, media_id, check_type, severity, source_type, source_reference, dedup_key, '
            . ' first_seen_at, last_seen_at, resolved_at) '
            . 'VALUES (:id, NULL, :ct, :sev, :st, NULL, :dk, :seen, :seen, :resolved)',
            [
                'id' => random_bytes(16),
                'ct' => self::PREFIX . 'old_check',
                'sev' => 'info',
                'st' => 'test',
                'dk' => self::PREFIX . 'old_check|_NULL_|_NULL_',
                'seen' => '2025-01-01 00:00:00.000',
                'resolved' => '2025-01-01 00:00:00.000',
            ],
        );

        self::assertSame(1, $this->ownRows(), 'Vorbedingung: die alte Zeile liegt in der Tabelle');

        $purged = $this->repository->purgeResolvedOlderThan(30);

        self::assertGreaterThanOrEqual(1, $purged, 'die abgelaufene Zeile muss mitgezählt werden');
        self::assertSame(0, $this->ownRows(), 'und sie muss verschwunden sein');
    }

    // --- Hilfen ---------------------------------------------------------

    private function finding(string $checkType, string $mediaId, string $severity): Finding
    {
        return new Finding(
            checkType: self::PREFIX . $checkType,
            severity: $severity,
            sourceType: 'media_table',
            mediaId: $mediaId,
            sourceReference: 'media:' . $mediaId,
        );
    }

    /**
     * Zählt ausschließlich die Zeilen dieses Tests — die Tabelle teilen sich mehrere Läufe.
     *
     * Der Platzhalter heißt `:prefix` und nicht `:präfix`: Doctrine liest einen
     * Parameternamen nur bis zum ersten Nicht-ASCII-Zeichen. Aus `:präfix` würde `:pr`, und
     * dafür gäbe es keinen Wert — der Test bräche ab, ohne dass etwas am Code falsch wäre.
     * Deshalb bleiben Bezeichner englisch und ASCII, auch in Tests.
     */
    private function ownRows(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM rc_media_health_report WHERE check_type LIKE :prefix',
            ['prefix' => self::PREFIX . '%'],
        );
    }

    private function removeOwnRows(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM rc_media_health_report WHERE check_type LIKE :prefix',
            ['prefix' => self::PREFIX . '%'],
        );
    }
}
