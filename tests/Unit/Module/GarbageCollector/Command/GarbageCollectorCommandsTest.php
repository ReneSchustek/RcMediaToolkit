<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Command;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Command\GarbageCollectorPurgeCommand;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Command\GarbageCollectorRestoreCommand;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Command\GarbageCollectorScanCommand;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\ConsoleReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\CsvReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\JsonReportFormatter;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\ReportFormatterRegistry;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\ExclusionMatcher;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaUsageIndexer;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\OrphanedMediaFinder;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\UsageMapFactory;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\RecordingFilesystem;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\SystemConfig\SystemConfigService as Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Die drei Konsolenbefehle des Aufräumers.
 *
 * Sie sind der einzige Weg, auf dem ein Mensch dieses Modul etwas tun lässt — und zwei von ihnen
 * greifen dabei tief ein: Der eine verschiebt Medien aus dem Shop, der andere löscht sie
 * endgültig. Für beide gilt dieselbe Zusage, und sie ist der Grund für diese Fälle:
 *
 * **Ohne ausdrückliche Zustimmung passiert nichts.** Weder eine unbedachte Voreinstellung noch
 * ein vergessenes Argument darf dazu führen, dass Dateien verschwinden. Ein Aufruf ohne Zusatz
 * ist ein Bericht, sonst nichts.
 */
#[CoversClass(GarbageCollectorScanCommand::class)]
#[CoversClass(GarbageCollectorPurgeCommand::class)]
#[CoversClass(GarbageCollectorRestoreCommand::class)]
final class GarbageCollectorCommandsTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/rc-media-toolkit-cmd-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/media_quarantine', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    /**
     * Was: Der Suchlauf ohne Zusätze.
     * Warum: **Er berichtet und fasst nichts an.** Das ist die Grundzusage des Befehls; wer ihn
     *        zum ersten Mal auf einem Produktivsystem ausführt, muss sich darauf verlassen
     *        können, ohne die Hilfe gelesen zu haben.
     */
    #[Test]
    public function theScanIsReadOnlyWithoutFurtherArguments(): void
    {
        $filesystem = new RecordingFilesystem(['media/alt.jpg' => 'x']);
        $tester = new CommandTester($this->scanCommand($filesystem, ['alt']));

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $filesystem->deleted);
        self::assertSame([], $this->quarantineRuns());
        self::assertStringContainsString('alt.jpg', $tester->getDisplay());
    }

    /**
     * Was: Der Suchlauf mit ausdrücklichem Verschieben, aber verneinter Rückfrage.
     * Warum: **Die Rückfrage ist die letzte Sicherung vor dem Eingriff.** Wird sie verneint,
     *        darf nichts geschehen sein — auch kein halb angelegter Lauf, der später wie ein
     *        abgebrochener Vorgang aussieht.
     */
    #[Test]
    public function aDeclinedConfirmationLeavesEverythingUntouched(): void
    {
        $filesystem = new RecordingFilesystem(['media/alt.jpg' => 'x']);
        $tester = new CommandTester($this->scanCommand($filesystem, ['alt']));
        $tester->setInputs(['no']);

        $status = $tester->execute(['--quarantine' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $filesystem->deleted);
        self::assertSame([], $this->quarantineRuns());
        self::assertStringContainsString('Abgebrochen', $tester->getDisplay());
    }

    /**
     * Die Gegenprobe: Wird zugestimmt, wandern die Kandidaten in einen Lauf. Ohne sie bewiesen
     * die beiden vorigen Fälle nur, dass der Befehl überhaupt nichts tut.
     */
    #[Test]
    public function aConfirmedQuarantineActuallyMovesTheCandidates(): void
    {
        $filesystem = new RecordingFilesystem(['media/alt.jpg' => 'x']);
        $tester = new CommandTester($this->scanCommand($filesystem, ['alt']));
        $tester->setInputs(['yes']);

        $tester->execute(['--quarantine' => true]);

        self::assertSame(['media/alt.jpg'], $filesystem->deleted);
        self::assertCount(1, $this->quarantineRuns());
    }

    /**
     * Was: Das abgeschaltete Modul.
     * Warum: Der Befehl endet mit einem Fehlerstatus, nicht mit einem stillen Erfolg. Läuft er
     *        in einem zeitgesteuerten Aufruf, ist der Rückgabewert das Einzige, was jemand
     *        sieht — ein Erfolg für „habe nichts getan" verschleiert den Zustand.
     */
    #[Test]
    public function anInactiveModuleEndsWithAFailureStatus(): void
    {
        $tester = new CommandTester($this->scanCommand(new RecordingFilesystem([]), [], moduleActive: false));

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    /**
     * Was: Ein unbekanntes Berichtsformat.
     * Warum: Der Aufruf hält an, statt auf die Konsolenform zurückzufallen. Ein Tippfehler in
     *        einem zeitgesteuerten Aufruf erzeugte sonst einen Bericht, den die nachgelagerte
     *        Auswertung nicht lesen kann — und der Fehler fiele erst Wochen später auf.
     */
    #[Test]
    public function anUnknownReportFormatStopsTheCommand(): void
    {
        $tester = new CommandTester($this->scanCommand(new RecordingFilesystem([]), ['alt']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/json/');

        $tester->execute(['--format' => 'jsno']);
    }

    /**
     * Was: Der Bericht in eine Datei.
     * Warum: Der übliche Weg für einen nächtlichen Lauf. Geschrieben wird der vollständige
     *        Bericht, und der Befehl sagt, wohin — sonst sucht ihn jemand im falschen
     *        Verzeichnis.
     */
    #[Test]
    public function theReportCanBeWrittenToAFile(): void
    {
        $ziel = $this->projectDir . '/bericht.json';
        $tester = new CommandTester($this->scanCommand(new RecordingFilesystem([]), ['alt']));

        $tester->execute(['--format' => 'json', '--output' => $ziel]);

        self::assertFileExists($ziel);
        $inhalt = json_decode((string) file_get_contents($ziel), true);
        self::assertIsArray($inhalt);
        self::assertCount(1, $inhalt);
        self::assertStringContainsString($ziel, $tester->getDisplay());
    }

    /**
     * Was: Das endgültige Löschen mit verneinter Rückfrage.
     * Warum: **Hier gibt es keinen Rückweg mehr.** Wird die Rückfrage verneint, muss der Lauf
     *        vollständig erhalten bleiben; ein teilweise gelöschter Lauf wäre schlimmer als
     *        beides, weil er weder zurückgeholt noch als erledigt betrachtet werden kann.
     */
    #[Test]
    public function aDeclinedPurgeKeepsTheRun(): void
    {
        $runId = $this->existingRun();
        $tester = new CommandTester($this->purgeCommand());
        $tester->setInputs(['no']);

        $status = $tester->execute(['runId' => $runId]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertDirectoryExists($this->projectDir . '/var/media_quarantine/' . $runId);
    }

    /**
     * Die Gegenprobe: Zugestimmt wird der Lauf entfernt — mitsamt Verzeichnis, nicht nur dem
     * Verzeichniseintrag.
     */
    #[Test]
    public function aConfirmedPurgeRemovesTheRunEntirely(): void
    {
        $runId = $this->existingRun();
        $tester = new CommandTester($this->purgeCommand());
        $tester->setInputs(['yes']);

        $tester->execute(['runId' => $runId]);

        self::assertDirectoryDoesNotExist($this->projectDir . '/var/media_quarantine/' . $runId);
    }

    /**
     * Was: Eine unsinnige Altersangabe.
     * Warum: Sie wird abgelehnt, statt als Null ausgelegt zu werden. Eine negative Angabe ergäbe
     *        einen Stichtag in der Zukunft — und damit fiele **jeder** Lauf unter die Löschung,
     *        auch der von heute Nacht.
     */
    #[Test]
    public function aNegativeAgeIsRefused(): void
    {
        $tester = new CommandTester($this->purgeCommand());

        self::assertSame(Command::INVALID, $tester->execute(['--older-than' => '-5']));
        self::assertStringContainsString('>= 0', $tester->getDisplay());
    }

    /**
     * Was: Ein Aufruf ohne jede Angabe.
     * Warum: Dann gilt die Aufbewahrungsfrist aus den Einstellungen — und der Befehl sagt das
     *        ausdrücklich, bevor er fragt. Wer nichts angibt, soll nicht raten müssen, worauf er
     *        gerade antwortet.
     */
    #[Test]
    public function withoutAnyFilterTheConfiguredRetentionApplies(): void
    {
        $tester = new CommandTester($this->purgeCommand(retentionDays: 42));
        $tester->setInputs(['no']);

        $tester->execute([]);

        self::assertStringContainsString('42', $tester->getDisplay());
    }

    /**
     * Was: Das Zurückholen ohne Lauf-Kennung.
     * Warum: Der Befehl weist den Aufruf zurück und nennt den Ausweg. Ein stilles Nichtstun
     *        sähe an dieser Stelle wie „nichts zurückzuholen" aus — und das ist eine ganz andere
     *        Aussage als „du hast vergessen zu sagen, was".
     */
    #[Test]
    public function restoringWithoutARunIdIsRefusedWithAHint(): void
    {
        $tester = new CommandTester($this->restoreCommand());

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('--list', $tester->getDisplay());
    }

    /**
     * Die Übersicht der Läufe nennt zu jedem die Anzahl der enthaltenen Medien. Ohne sie müsste
     * jemand jeden Lauf einzeln zurückholen, um zu sehen, was darin liegt.
     */
    #[Test]
    public function theRunListingShowsHowMuchEachRunHolds(): void
    {
        $runId = $this->existingRun(items: 3);
        $tester = new CommandTester($this->restoreCommand());

        $tester->execute(['--list' => true]);

        self::assertStringContainsString($runId, $tester->getDisplay());
        self::assertStringContainsString('3', $tester->getDisplay());
    }

    /**
     * Ohne Läufe gibt es eine Meldung und keinen Fehler — ein leerer Quarantänebereich ist der
     * Normalzustand eines gesunden Shops.
     */
    #[Test]
    public function anEmptyQuarantineIsNotAnError(): void
    {
        $tester = new CommandTester($this->restoreCommand());

        self::assertSame(Command::SUCCESS, $tester->execute(['--list' => true]));
        self::assertStringContainsString('Keine', $tester->getDisplay());
    }

    /**
     * Was: Die Suche bricht ab.
     * Warum: Der Befehl endet mit einem Fehlerstatus und nennt den Grund. In einem
     *        zeitgesteuerten Aufruf ist der Rückgabewert das Einzige, was ankommt — ein Erfolg
     *        für „konnte nicht suchen" verschwiege einen Ausfall.
     */
    #[Test]
    public function aFailingSearchEndsWithAFailureStatus(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shopware');
        $connection->method('fetchAllAssociative')->willThrowException(new RuntimeException('Datenbank weg'));

        $tester = new CommandTester($this->scanCommandWith($connection, new RecordingFilesystem([])));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Datenbank weg', $tester->getDisplay());
    }

    /**
     * Was: Der Bericht lässt sich nicht schreiben.
     * Warum: Ein falsch gesetzter Pfad oder ein volles Dateisystem darf nicht wie ein
     *        erfolgreicher Lauf aussehen — sonst sucht jemand nach einem Bericht, den es nie
     *        gab.
     */
    #[Test]
    #[WithoutErrorHandler]
    public function anUnwritableReportPathIsAFailure(): void
    {
        $tester = new CommandTester($this->scanCommand(new RecordingFilesystem([]), ['alt']));

        // Ein Verzeichnis als Ziel: Das Schreiben schlägt fehl, ohne dass dafür Rechte
        // verbogen werden müssten.
        $status = @$tester->execute(['--output' => $this->projectDir]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('nicht schreiben', $tester->getDisplay());
    }

    /**
     * Ohne Kandidaten gibt es nichts zu verschieben — der Befehl sagt das und legt keinen
     * leeren Lauf an. Ein Lauf ohne Inhalt wäre später nicht von einem abgebrochenen zu
     * unterscheiden.
     */
    #[Test]
    public function anEmptyResultCreatesNoQuarantineRun(): void
    {
        $tester = new CommandTester($this->scanCommand(new RecordingFilesystem([]), []));

        $status = $tester->execute(['--quarantine' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $this->quarantineRuns());
        self::assertStringContainsString('Keine Kandidaten', $tester->getDisplay());
    }

    /**
     * Was: Ein einzelner Kandidat lässt sich nicht verschieben.
     * Warum: Die übrigen wandern trotzdem, und der Befehl nennt beide Zahlen. Ein Lauf, der
     *        beim ersten Hindernis endete, käme auf einem großen Bestand nie durch.
     */
    #[Test]
    public function oneFailingCandidateDoesNotStopTheOthers(): void
    {
        $filesystem = new RecordingFilesystem(
            ['media/klemmt.jpg' => 'x', 'media/geht.jpg' => 'y'],
            unreadable: ['media/klemmt.jpg'],
        );

        $tester = new CommandTester($this->scanCommand($filesystem, ['klemmt', 'geht']));
        $tester->setInputs(['yes']);
        $tester->execute(['--quarantine' => true]);

        self::assertSame(['media/geht.jpg'], $filesystem->deleted);
        self::assertStringContainsString('1/2', $tester->getDisplay());
    }

    /**
     * Was: Das endgültige Löschen nach Alter, wenn nichts alt genug ist.
     * Warum: Der Befehl sagt das ausdrücklich. Eine wortlose Erfolgsmeldung ließe offen, ob
     *        nichts gefunden oder nichts getan wurde.
     */
    #[Test]
    public function purgingByAgeReportsWhenNothingIsOldEnough(): void
    {
        $tester = new CommandTester($this->purgeCommand());
        $tester->setInputs(['yes']);

        $status = $tester->execute(['--older-than' => '30']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Keine zu löschenden', $tester->getDisplay());
    }

    /**
     * Die Gegenprobe: Gibt es alte Läufe, werden sie entfernt, und der Befehl nennt beide
     * Zahlen — Läufe und enthaltene Medien.
     */
    #[Test]
    public function purgingByAgeRemovesTheOldRunsAndCountsThem(): void
    {
        $alt = $this->existingRun(items: 2, createdAt: '-90 days');

        $tester = new CommandTester($this->purgeCommand());
        $tester->setInputs(['yes']);
        $tester->execute(['--older-than' => '30']);

        self::assertDirectoryDoesNotExist($this->projectDir . '/var/media_quarantine/' . $alt);
        self::assertStringContainsString('1 Run', $tester->getDisplay());
        self::assertStringContainsString('2 Items', $tester->getDisplay());
    }

    /**
     * Was: Das Zurückholen eines ganzen Laufs.
     * Warum: Der übliche Weg nach einem Aufräumlauf, der zu weit ging. Der Befehl nennt die
     *        Zahl der zurückgeholten Medien — ohne sie bliebe offen, ob überhaupt etwas
     *        gefunden wurde.
     */
    #[Test]
    public function restoringAWholeRunReportsHowManyCameBack(): void
    {
        $runId = $this->existingRun(items: 1);
        file_put_contents(
            $this->projectDir . '/var/media_quarantine/' . $runId . '/bild0.jpg',
            'Bildinhalt',
        );

        $tester = new CommandTester($this->restoreCommand());
        $status = $tester->execute(['runId' => $runId]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('1 Medium', $tester->getDisplay());
    }

    /**
     * @param list<string> $orphans
     */
    private function scanCommand(
        RecordingFilesystem $filesystem,
        array $orphans,
        bool $moduleActive = true,
    ): GarbageCollectorScanCommand {
        $connection = $this->connectionWith($orphans);
        $config = $this->configWith($moduleActive);

        return new GarbageCollectorScanCommand(
            new ModuleRegistry($config),
            new OrphanedMediaFinder(
                $connection,
                new MediaUsageIndexer(
                    $connection,
                    new MediaToolkitLogger(new NullLogger()),
                    new MediaReferenceSources($connection),
                ),
                new ExclusionMatcher($config),
                $config,
                new UsageMapFactory($connection, $config),
            ),
            $this->quarantineService($connection, $filesystem),
            new ReportFormatterRegistry([
                new JsonReportFormatter(),
                new CsvReportFormatter(),
                new ConsoleReportFormatter(),
            ]),
            new MediaToolkitLogger(new NullLogger()),
        );
    }

    /**
     * Was: Der Quarantäne-Lauf lässt sich nicht anlegen.
     * Warum: Dann wird **nichts** verschoben. Ohne Lauf gäbe es keine Beschreibungsdatei und
     *        damit keinen Rückweg; ein Verschieben ohne Rückweg ist ein Löschen.
     */
    #[Test]
    #[WithoutErrorHandler]
    public function aFailingRunCreationMovesNothing(): void
    {
        $filesystem = new RecordingFilesystem(['media/alt.jpg' => 'x']);
        $config = $this->configWith(true);

        // Der Ablageort ist eine Datei — darunter lässt sich kein Lauf anlegen.
        $blockiert = $this->projectDir . '/blockiert';
        file_put_contents($blockiert, 'keine Ablage');

        $connection = $this->connectionWith(['alt']);

        $command = new GarbageCollectorScanCommand(
            new ModuleRegistry($config),
            $this->finderFor($connection, $config),
            new MediaQuarantineService(
                $connection,
                $filesystem,
                new MediaToolkitLogger(new NullLogger()),
                $blockiert,
            ),
            new ReportFormatterRegistry([new ConsoleReportFormatter()]),
            new MediaToolkitLogger(new NullLogger()),
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);
        $status = @$tester->execute(['--quarantine' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame([], $filesystem->deleted);
    }

    private function scanCommandWith(Connection $connection, RecordingFilesystem $filesystem): GarbageCollectorScanCommand
    {
        $config = $this->configWith(true);

        return new GarbageCollectorScanCommand(
            new ModuleRegistry($config),
            $this->finderFor($connection, $config),
            $this->quarantineService($connection, $filesystem),
            new ReportFormatterRegistry([new ConsoleReportFormatter()]),
            new MediaToolkitLogger(new NullLogger()),
        );
    }

    private function finderFor(Connection $connection, SystemConfigService $config): OrphanedMediaFinder
    {
        return new OrphanedMediaFinder(
            $connection,
            new MediaUsageIndexer(
                $connection,
                new MediaToolkitLogger(new NullLogger()),
                new MediaReferenceSources($connection),
            ),
            new ExclusionMatcher($config),
            $config,
            new UsageMapFactory($connection, $config),
        );
    }

    private function purgeCommand(int $retentionDays = 14): GarbageCollectorPurgeCommand
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn($retentionDays);

        return new GarbageCollectorPurgeCommand(
            $this->quarantineService($this->createMock(Connection::class), new RecordingFilesystem([])),
            $config,
        );
    }

    private function restoreCommand(): GarbageCollectorRestoreCommand
    {
        return new GarbageCollectorRestoreCommand(
            $this->quarantineService($this->createMock(Connection::class), new RecordingFilesystem([])),
        );
    }

    private function quarantineService(Connection $connection, RecordingFilesystem $filesystem): MediaQuarantineService
    {
        return new MediaQuarantineService(
            $connection,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $this->projectDir,
        );
    }

    /**
     * @param list<string> $orphans
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connectionWith(array $orphans): Connection
    {
        $rows = [];
        foreach ($orphans as $index => $name) {
            $rows[] = [
                'id' => str_pad((string) $index, 32, '0', \STR_PAD_LEFT),
                'file_name' => $name,
                'file_extension' => 'jpg',
                'file_size' => 1024,
                'created_at' => '2020-01-01 10:00:00',
                'media_folder_id' => null,
            ];
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shopware');
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($rows): array {
                if (str_contains($sql, 'INFORMATION_SCHEMA')) {
                    return [['TABLE_NAME' => 'product_media', 'COLUMN_NAME' => 'media_id']];
                }
                if (str_contains($sql, 'file_size')) {
                    return ($params['lastId'] ?? null) === null ? $rows : [];
                }

                return [];
            },
        );
        $connection->method('fetchAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($rows): array|false {
                foreach ($rows as $row) {
                    if ($row['id'] === ($params['id'] ?? null)) {
                        return [
                            'id' => $row['id'],
                            'file_name' => $row['file_name'],
                            'file_extension' => 'jpg',
                            'path' => 'media/' . $row['file_name'] . '.jpg',
                        ];
                    }
                }

                return false;
            },
        );

        return $connection;
    }

    /**
     * @return SystemConfigService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function configWith(bool $moduleActive): SystemConfigService
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'RcMediaToolkit.config.garbageCollectorActive' => $moduleActive,
                'RcMediaToolkit.config.gcGracePeriodDays' => 30,
                default => null,
            },
        );

        return $config;
    }

    /**
     * Legt einen Lauf an, wie ihn der Dienst selbst anlegen würde — samt Verzeichnisnamen im
     * erwarteten Schema, denn der Befehl weist alles andere zurück.
     */
    private function existingRun(int $items = 1, string $createdAt = 'now'): string
    {
        $runId = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $verzeichnis = $this->projectDir . '/var/media_quarantine/' . $runId;
        mkdir($verzeichnis, 0o777, true);

        $entries = [];
        for ($i = 0; $i < $items; ++$i) {
            $entries[] = [
                'mediaId' => str_pad((string) $i, 32, '0', \STR_PAD_LEFT),
                'fileName' => 'bild' . $i,
                'fileExtension' => 'jpg',
                'originalPath' => 'media/bild' . $i . '.jpg',
                'quarantinedPath' => 'var/media_quarantine/' . $runId . '/bild' . $i . '.jpg',
                'quarantinedAt' => date(DateTimeInterface::ATOM),
                'size' => 1024,
                'status' => 'quarantined',
            ];
        }

        file_put_contents(
            $verzeichnis . '/manifest.json',
            json_encode(
                [
                    'runId' => $runId,
                    'createdAt' => (new DateTimeImmutable($createdAt))->format(DateTimeInterface::ATOM),
                    'items' => $entries,
                ],
                \JSON_PRETTY_PRINT,
            ),
        );

        return $runId;
    }

    /**
     * @return list<string>
     */
    private function quarantineRuns(): array
    {
        $base = $this->projectDir . '/var/media_quarantine';
        $runs = [];
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($base . '/' . $entry)) {
                $runs[] = $entry;
            }
        }

        return $runs;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }
}
