<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\JsonReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\TempTableUsageMap;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Command\HealthCheckReportCommand;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\ScheduledTask\HealthCheckScanTaskHandler;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Service\HealthCheckRunner;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\MediaReferenceUpdater;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairAuditRepository;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Command\WebPStatusCommand;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\EncoderChain;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPRegistry;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\RecordingFilesystem;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Command\StubEncoder;
use RuntimeException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Stringable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Die verbliebenen Verzweigungen, die keine eigene Geschichte tragen.
 *
 * Sie sind einzeln zu klein für einen eigenen Fall und zusammen genau das, was in einem
 * Prüflauf am Ende übrig bleibt: Abbruchbedingungen, Zählungen, Ausgabezweige. Keine davon
 * würde man vermissen — bis eine davon falsch ist, und dann fehlt genau hier der Nachweis.
 */
#[CoversClass(TempTableUsageMap::class)]
#[CoversClass(JsonReportFormatter::class)]
#[CoversClass(HealthCheckScanTaskHandler::class)]
#[CoversClass(RepairAuditRepository::class)]
#[CoversClass(MediaReferenceUpdater::class)]
#[CoversClass(WebPRegistry::class)]
#[CoversClass(EncoderChain::class)]
#[CoversClass(WebPStatusCommand::class)]
#[CoversClass(HealthCheckReportCommand::class)]
final class RemainingBranchesTest extends TestCase
{
    /**
     * Was: Die Merkliste schreibt von selbst, sobald genug beisammen ist.
     * Warum: Ohne diese Schwelle sammelte ein Lauf über Millionen Medien alles im
     *        Arbeitsspeicher, bis er umkippt — die gebündelte Schreibweise wäre wirkungslos.
     */
    #[Test]
    public function theBatchIsWrittenOnceItIsFullEnough(): void
    {
        $anweisungen = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$anweisungen): int {
                $anweisungen[] = $sql;

                return 1;
            },
        );
        $connection->method('fetchOne')->willReturn(false);

        $map = new TempTableUsageMap($connection);
        for ($i = 0; $i < 1_200; ++$i) {
            $map->add(sprintf('%032x', $i));
        }

        $geschrieben = 0;
        foreach ($anweisungen as $sql) {
            if (str_contains($sql, 'INSERT IGNORE INTO')) {
                ++$geschrieben;
            }
        }

        self::assertGreaterThan(0, $geschrieben, 'Ohne Aufforderung muss zwischendurch geschrieben werden.');
        self::assertSame(1_200, $map->size());
    }

    /**
     * Was: Ein Bericht, der sich nicht in Maschinenform bringen lässt.
     * Warum: Ungültige Zeichenfolgen aus dem Dateisystem kommen vor — Dateinamen tragen alles,
     *        was das Betriebssystem zulässt. Der Fehler wird zur Ausnahme, statt einen halben
     *        Bericht auszugeben, den die Auswertung für vollständig hält.
     */
    #[Test]
    public function anUnencodableReportRaisesInsteadOfTruncating(): void
    {
        $fund = new OrphanedMedia(
            mediaId: null,
            // Eine Bytefolge, die keine gültige Zeichenkodierung ergibt.
            path: "media/ab/\xB1\x31\x00.jpg",
            size: 1,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            fileName: "\xB1\x31\x00.jpg",
            sourceType: OrphanedMedia::SOURCE_FS_ORPHAN,
        );

        $this->expectException(RuntimeException::class);

        (new JsonReportFormatter())->render([$fund]);
    }

    /**
     * Was: Der Termin der Medienprüfung mit abgeschaltetem Zeitplan.
     * Warum: Modul und Zeitplan sind getrennte Schalter. Wer die Prüfung von Hand anstößt, aber
     *        nachts seine Ruhe haben will, verlässt sich darauf.
     */
    #[Test]
    public function theScanScheduleCanBeSwitchedOffSeparately(): void
    {
        $anweisungen = [];
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => $key !== 'RcMediaToolkit.config.healthCronEnabled',
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$anweisungen): int {
                $anweisungen[] = $sql;

                return 1;
            },
        );

        $repository = new HealthReportRepository($connection);

        (new HealthCheckScanTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new ModuleRegistry($config),
            new HealthCheckRunner([], $repository, $config, new MediaToolkitLogger(new NullLogger())),
            $repository,
            $config,
        ))->run();

        self::assertSame([], $anweisungen);
    }

    /**
     * Die Zahl der Einträge in der Prüfspur ist die Grundlage der Aufbewahrungsentscheidung. Sie
     * wird gezählt, nicht geladen — die Spur wächst mit jeder Reparatur und wird nie kleiner.
     */
    #[Test]
    public function theAuditTrailIsCountedNotLoaded(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('42');

        self::assertSame(42, (new RepairAuditRepository($connection))->count());
    }

    /**
     * Was: Die Liste der Spalten, die auf Medien zeigen.
     * Warum: Sie kommt aus der Datenbank selbst. Über sie lässt sich vor einer Reparatur
     *        anzeigen, was überhaupt betroffen wäre — ohne die Reparatur auszuführen.
     */
    #[Test]
    public function theListOfReferencingColumnsComesFromTheDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shopware');
        $connection->method('fetchAllAssociative')->willReturn([
            ['TABLE_NAME' => 'product_media', 'COLUMN_NAME' => 'media_id'],
        ]);

        $updater = new MediaReferenceUpdater($connection, new MediaReferenceSources($connection));

        self::assertSame([['table' => 'product_media', 'column' => 'media_id']], $updater->knownFkColumns());
    }

    /**
     * Was: Ein Medium ohne Pfadangabe in der Arbeitsliste der Umwandlung.
     * Warum: Es wird übergangen. Ohne Pfad gäbe es nichts umzuwandeln — und ein Auftrag mit
     *        leerem Pfad liefe im Arbeiter in einen Fehler, der wie ein Speicherproblem
     *        aussieht.
     */
    #[Test]
    public function aMediumWithoutAPathIsNotQueuedForConversion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('2');
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static fn (string $sql, array $params = []): array => (int) ($params['offset'] ?? 0) === 0
                ? [
                    ['id' => 'aa', 'file_extension' => 'jpg', 'path' => ''],
                    ['id' => 'bb', 'file_extension' => 'jpg', 'path' => 'media/ab/echt.jpg'],
                ]
                : [],
        );

        $registry = new WebPRegistry($connection, new RecordingFilesystem([]));

        self::assertSame(['media/ab/echt.jpg'], iterator_to_array($registry->pathsMissingWebp(), false));
    }

    /**
     * Was: Die Meldung über den gewählten Umwandler.
     * Warum: Sie steht **einmal** im Protokoll, nicht bei jedem Bild. Bei zehntausend Bildern
     *        wären das zehntausend gleiche Zeilen — und die eine Zeile, die zählt, ginge darin
     *        unter.
     */
    #[Test]
    public function theChosenEncoderIsLoggedOnlyOnce(): void
    {
        $inner = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $zeilen = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->zeilen[] = (string) $message;
            }
        };

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(null);

        $chain = new EncoderChain([new StubEncoder()], $config, new MediaToolkitLogger($inner));

        $chain->selectEncoder();
        $chain->selectEncoder();
        $chain->selectEncoder();

        $auswahl = array_filter($inner->zeilen, static fn (string $z): bool => str_contains($z, 'Encoder ausgewählt'));

        self::assertCount(1, $auswahl);
    }

    /**
     * Der Statusbefehl zeigt jeden bekannten Umwandler mit seiner Verfügbarkeit. Ohne diese
     * Aufstellung bliebe unklar, warum auf einem System ein anderer arbeitet als erwartet.
     */
    #[Test]
    public function theStatusCommandListsEveryEncoderWithItsAvailability(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('fetchAllAssociative')->willReturn([]);

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(null);

        $command = new WebPStatusCommand(
            new WebPRegistry($connection, new RecordingFilesystem([])),
            new EncoderChain([new StubEncoder()], $config, new MediaToolkitLogger(new NullLogger())),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('gd', $tester->getDisplay());
        self::assertStringContainsString('verfügbar', $tester->getDisplay());
    }

    /**
     * Was: Ein Bericht mit einer Angabe, die sich nicht in Maschinenform bringen lässt.
     * Warum: Auch hier gilt: lieber eine Ausnahme als ein halber Bericht. Der Unterschied ist,
     *        dass dieser Bericht aus der Datenbank kommt — und dort steht, was Anwender
     *        eingegeben haben.
     */
    #[Test]
    public function anUnencodableFindingReportRaises(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'GROUP BY severity')) {
                    return [];
                }

                return [[
                    'id' => 'aa',
                    'check_type' => "\xB1\x31\x00",
                    'severity' => 'critical',
                    'media_id' => null,
                    'source_type' => 'media_table',
                    'source_reference' => null,
                    'url_path' => null,
                    'last_seen_at' => '2026-08-12 10:00:00',
                ]];
            },
        );

        $tester = new CommandTester(new HealthCheckReportCommand(new HealthReportRepository($connection)));

        $this->expectException(RuntimeException::class);

        $tester->execute(['--format' => 'json']);
    }

    /**
     * Die Tabellenform kommt mit denselben Angaben zurecht — sie schreibt Zeichen durch, statt
     * sie zu deuten. Ein Bericht, der an einem Dateinamen scheitert, wäre genau dann nicht zu
     * bekommen, wenn man ihn braucht.
     */
    #[Test]
    public function theSpreadsheetFormSurvivesUnusualCharacters(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'GROUP BY severity')) {
                    return [];
                }

                return [[
                    'id' => 'aa',
                    'check_type' => 'missing_file',
                    'severity' => 'critical',
                    'media_id' => null,
                    'source_type' => 'media_table',
                    'source_reference' => 'media:aa',
                    'url_path' => 'media/ab/Datei mit "Anführungszeichen"; und Semikolon.jpg',
                    'last_seen_at' => '2026-08-12 10:00:00',
                ]];
            },
        );

        $tester = new CommandTester(new HealthCheckReportCommand(new HealthReportRepository($connection)));

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'csv']));
        self::assertStringContainsString('Semikolon', $tester->getDisplay());
    }
}
