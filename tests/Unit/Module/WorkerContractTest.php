<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\ScheduledTask\MediaGarbageCollectorTaskHandler;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\ExclusionMatcher;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaUsageIndexer;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\OrphanedMediaFinder;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\UsageMapFactory;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\MediaHealthCheckInterface;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Message\CheckMediaBatchHandler;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Message\CheckMediaBatchMessage;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\ScheduledTask\HealthCheckScanTaskHandler;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Service\HealthCheckRunner;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairAuditRepository;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use RuntimeException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Was die unbeaufsichtigten Läufe tun dürfen — und was nicht.
 *
 * Diese Bearbeiter laufen nachts, ohne dass jemand zusieht. Drei Eigenschaften entscheiden
 * darüber, ob das zu verantworten ist.
 *
 * Die erste ist die **Vorgabe des Aufräumers: berichten, nicht handeln.** Er findet Kandidaten
 * zum Löschen; würde er sie ohne ausdrückliche Anweisung wegräumen, wäre das genau der
 * unbeaufsichtigte Eingriff, den man bei einem solchen Werkzeug nicht als Grundeinstellung haben
 * will.
 *
 * Die zweite: **Ein Fehlschlag bei einem Medium darf den Lauf nicht kosten** — weder den
 * laufenden noch die folgenden. Käme eine Ausnahme aus dem Bearbeiter heraus, setzte Shopware den
 * Termin auf „fehlgeschlagen", und er liefe auch in den nächsten Nächten nicht mehr.
 *
 * Die dritte ist der **Umgang mit Fehlern in der Warteschlange, und der ist bewusst
 * uneinheitlich**: Der Prüfungsbearbeiter reicht einen Fehlschlag weiter, damit der Auftrag
 * erneut vorgelegt wird; der Termin schluckt ihn, weil sein nächster Lauf ohnehin kommt.
 *
 * Geprüft wird gegen den echten Quarantänedienst mit einem Wegwerf-Verzeichnis, nicht gegen ein
 * Doppel: Bei einem Dienst, an dessen Ende Dateien verschwinden, ist das Zusammenspiel selbst der
 * Gegenstand.
 */
#[CoversClass(MediaGarbageCollectorTaskHandler::class)]
#[CoversClass(HealthCheckScanTaskHandler::class)]
#[CoversClass(CheckMediaBatchHandler::class)]
#[CoversClass(RepairAuditRepository::class)]
#[CoversClass(MediaQuarantineService::class)]
final class WorkerContractTest extends TestCase
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    private array $statements = [];

    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->statements = [];
        $this->projectDir = sys_get_temp_dir() . '/rc-media-toolkit-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/media_quarantine', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    /**
     * Was: Der nächtliche Aufräumer räumt von sich aus nichts weg.
     * Warum: **Die wichtigste Voreinstellung des ganzen Plugins.** Er zählt die Kandidaten und
     *        schreibt sie ins Protokoll; das Verschieben in die Quarantäne verlangt eine eigene,
     *        ausdrückliche Einstellung. Andernfalls entschiede ein nächtlicher Lauf ohne Aufsicht
     *        über den Bestand — und die Folge fiele erst auf, wenn jemand ein Bild vermisst.
     */
    #[Test]
    public function theNightlyCollectorReportsInsteadOfActing(): void
    {
        $filesystem = new RecordingFilesystem(['media/alt-eins.jpg' => 'x', 'media/alt-zwei.jpg' => 'y']);

        $this->collectorHandler($filesystem, autoQuarantine: false, orphans: ['alt-eins', 'alt-zwei'])->run();

        self::assertSame([], $filesystem->deleted, 'Ohne ausdrückliche Anweisung wird keine Datei angefasst.');
        self::assertSame([], $this->quarantineRuns(), 'Und es wird nicht einmal ein Lauf angelegt.');
    }

    /**
     * Die Gegenprobe: Mit gesetzter Einstellung wandern die Kandidaten in die Quarantäne — in
     * **einen** Lauf, nicht in einen je Medium. Der Lauf ist die Einheit, über die zurückgeholt
     * wird; zerfiele er, wäre die Rücknahme so mühsam wie das Löschen selbst.
     */
    #[Test]
    public function theExplicitSettingMovesEverythingIntoASingleRun(): void
    {
        $filesystem = new RecordingFilesystem(['media/alt-eins.jpg' => 'x', 'media/alt-zwei.jpg' => 'y']);

        $this->collectorHandler($filesystem, autoQuarantine: true, orphans: ['alt-eins', 'alt-zwei'])->run();

        self::assertSame(['media/alt-eins.jpg', 'media/alt-zwei.jpg'], $filesystem->deleted);
        self::assertCount(1, $this->quarantineRuns(), 'Ein Lauf für alles, was in dieser Nacht gefunden wurde.');
    }

    /**
     * Was: Die Sicherungskopie steht, bevor das Original verschwindet.
     * Warum: **Die Reihenfolge ist der ganze Unterschied zwischen Quarantäne und Löschen.** Wird
     *        erst entfernt und dann geschrieben, ist eine Störung dazwischen ein endgültiger
     *        Verlust. Der Fall hält fest, dass zum Zeitpunkt des Entfernens eine lesbare Kopie
     *        im Wegwerf-Verzeichnis liegt.
     */
    #[Test]
    public function theCopyExistsBeforeTheOriginalIsRemoved(): void
    {
        $kopienBeimLoeschen = [];
        $filesystem = new RecordingFilesystem(['media/alt-eins.jpg' => 'Bildinhalt']);
        $filesystem->onDelete = function () use (&$kopienBeimLoeschen): void {
            $kopienBeimLoeschen = $this->quarantinedFiles();
        };

        $this->collectorHandler($filesystem, autoQuarantine: true, orphans: ['alt-eins'])->run();

        self::assertCount(1, $kopienBeimLoeschen, 'Beim Entfernen muss die Kopie bereits liegen.');
        self::assertSame('Bildinhalt', file_get_contents($kopienBeimLoeschen[0]));
    }

    /**
     * Was: Ein einzelnes Medium lässt sich nicht verschieben.
     * Warum: Der Lauf geht weiter. Eine schreibgeschützte Datei oder ein Fremdspeicher, der
     *        gerade klemmt, darf nicht dazu führen, dass die übrigen Kandidaten liegen bleiben —
     *        sonst hinge der Fortschritt jedes Laufs am ersten Hindernis.
     */
    #[Test]
    public function oneFailingMoveDoesNotStopTheRun(): void
    {
        $filesystem = new RecordingFilesystem(
            ['media/klemmt.jpg' => 'x', 'media/geht.jpg' => 'y'],
            unreadable: ['media/klemmt.jpg'],
        );

        $this->collectorHandler($filesystem, autoQuarantine: true, orphans: ['klemmt', 'geht'])->run();

        self::assertSame(['media/geht.jpg'], $filesystem->deleted, 'Nach dem Hindernis wird weitergearbeitet.');
    }

    /**
     * Was: Das abgeschaltete Modul und der abgeschaltete Termin.
     * Warum: Zwei getrennte Schalter, jeder für sich muss den Lauf verhindern. Der Aufräumer ist
     *        der eingriffsfähigste Teil des Plugins; ein Schalter, der ihn nicht anhält, ist
     *        kein Schalter.
     */
    #[Test]
    public function eitherSwitchAloneStopsTheCollector(): void
    {
        foreach ([[false, true], [true, false]] as [$moduleActive, $cronEnabled]) {
            $filesystem = new RecordingFilesystem(['media/alt-eins.jpg' => 'x']);

            $this->collectorHandler(
                $filesystem,
                autoQuarantine: true,
                orphans: ['alt-eins'],
                moduleActive: $moduleActive,
                cronEnabled: $cronEnabled,
            )->run();

            self::assertSame([], $filesystem->deleted);
            self::assertSame([], $this->quarantineRuns());
        }
    }

    /**
     * Was: Die Suche selbst bricht ab.
     * Warum: Der Bearbeiter endet trotzdem ordentlich. Käme die Ausnahme heraus, setzte Shopware
     *        den Termin auf „fehlgeschlagen" — und der Aufräumer liefe **auch in den nächsten
     *        Nächten nicht mehr**, wegen einer Störung, die längst vorbei ist.
     */
    #[Test]
    public function aFailingSearchDoesNotBreakTheSchedule(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shopware');
        $connection->method('fetchAllAssociative')->willThrowException(new RuntimeException('Datenbank weg'));

        $config = $this->collectorConfig(true, true, false);
        $filesystem = new RecordingFilesystem([]);

        $handler = new MediaGarbageCollectorTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new ModuleRegistry($config),
            $this->finder($connection, $config),
            $this->quarantineService($connection, $filesystem),
            $config,
            new MediaToolkitLogger(new NullLogger()),
        );

        $handler->run();

        self::assertSame([], $filesystem->deleted);
    }

    /**
     * Was: Die nächtliche Medienprüfung beschneidet den Bericht nur mit gesetzter Frist.
     * Warum: Die Frist entscheidet, wie lange erledigte Befunde nachvollziehbar bleiben. Eine
     *        Null bedeutet **nicht** „sofort löschen", sondern „nicht beschneiden" — der
     *        Unterschied ist der zwischen einem gepflegten und einem geleerten Bericht.
     */
    #[Test]
    public function theNightlyScanPrunesOnlyWithARetentionSet(): void
    {
        $this->scanHandler(retentionDays: 0)->run();
        self::assertSame(0, $this->countStatements('DELETE FROM rc_media_health_report'));

        $this->statements = [];

        $this->scanHandler(retentionDays: 30)->run();
        self::assertSame(1, $this->countStatements('DELETE FROM rc_media_health_report'));
    }

    /**
     * Ein abgeschaltetes Modul räumt auch nicht im Bericht auf. Ein Werkzeug, das ausgeschaltet
     * ist und trotzdem Daten anfasst, wäre die unangenehmste Sorte Nebenwirkung.
     */
    #[Test]
    public function aDisabledScanPrunesNothingEither(): void
    {
        $this->scanHandler(retentionDays: 30, moduleActive: false)->run();

        self::assertSame([], $this->statements);
    }

    /**
     * Was: Ein fehlgeschlagener Auftrag aus der Warteschlange.
     * Warum: Er wird **weitergereicht**. Nur so legt die Warteschlange ihn erneut vor; verschluckt
     *        der Bearbeiter den Fehler, gilt der Auftrag als erledigt, und die Prüfung, die
     *        jemand angestoßen hat, ist lautlos ausgefallen. Das ist der bewusste Unterschied zu
     *        den Terminen, deren nächster Lauf ohnehin kommt.
     */
    #[Test]
    public function aFailedQueuedCheckIsHandedOnSoTheQueueRetries(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new RuntimeException('Datenbank weg'));

        $handler = new CheckMediaBatchHandler(
            $this->runnerWith($connection),
            new MediaToolkitLogger(new NullLogger()),
        );

        $this->expectException(RuntimeException::class);

        $handler(new CheckMediaBatchMessage('missing_file'));
    }

    /**
     * Die Gegenprobe: Ein gelungener Auftrag läuft durch und schreibt seinen Befund weg. Ohne sie
     * bewiese der vorige Fall nur, dass immer etwas herausfliegt.
     */
    #[Test]
    public function aSuccessfulQueuedCheckPersistsItsFindings(): void
    {
        $handler = new CheckMediaBatchHandler(
            $this->runnerWith($this->recordingConnection()),
            new MediaToolkitLogger(new NullLogger()),
        );

        $handler(new CheckMediaBatchMessage('missing_file'));

        self::assertSame(1, $this->countStatements('INSERT INTO rc_media_health_report'));
        self::assertSame(1, $this->countStatements('SET resolved_at'));
    }

    /**
     * Was: Die Prüfspur ohne Aufbewahrungsfrist.
     * Warum: **Null heißt „nie löschen".** Die Spur ist der einzige Rückweg jeder durchgeführten
     *        Reparatur; ein Löschbefehl mit einem Stichtag von „jetzt" nähme sie vollständig mit.
     *        Die Einstellung ist im Auslieferungszustand nicht gesetzt und liest sich als Null —
     *        deshalb liegt genau hier der gefährlichste Fall.
     */
    #[Test]
    public function anUnsetAuditRetentionDeletesNothing(): void
    {
        $repository = new RepairAuditRepository($this->recordingConnection());

        self::assertSame(0, $repository->purgeOlderThan(0));
        self::assertSame(0, $repository->purgeOlderThan(-1));
        self::assertSame([], $this->statements, 'Es darf kein Löschbefehl abgesetzt werden.');
    }

    /**
     * Die Gegenprobe: Mit gesetzter Frist wird gelöscht, und der Stichtag liegt in der
     * Vergangenheit. Läge er in der Zukunft, nähme ein einziger Lauf die gesamte Spur mit.
     */
    #[Test]
    public function aSetAuditRetentionDeletesOnlyWhatIsOlder(): void
    {
        $repository = new RepairAuditRepository($this->recordingConnection());

        $repository->purgeOlderThan(30);

        self::assertCount(1, $this->statements);
        self::assertStringContainsString('applied_at < :cutoff', $this->statements[0]['sql']);
        self::assertLessThan(
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
            (string) $this->statements[0]['params']['cutoff'],
            'Der Stichtag muss in der Vergangenheit liegen.',
        );
    }

    /**
     * Was: Der nächtliche Aufräumer beschneidet alte Quarantäne-Läufe.
     * Warum: Sonst füllt sich der Datenträger genau dort, wo aufgeräumt werden sollte. Die
     *        Frist ist einstellbar und wirkt nur nach oben: Ein Lauf von gestern bleibt liegen,
     *        auch wenn nachts aufgeräumt wird.
     */
    #[Test]
    public function theNightlyCollectorPrunesOldQuarantineRuns(): void
    {
        $filesystem = new RecordingFilesystem([]);
        $handler = $this->collectorHandler(
            $filesystem,
            autoQuarantine: false,
            orphans: [],
            retentionDays: 30,
        );

        $alt = $this->handWrittenRun('-90 days');
        $neu = $this->handWrittenRun('now');

        $handler->run();

        self::assertDirectoryDoesNotExist($alt);
        self::assertDirectoryExists($neu, 'Der junge Lauf bleibt liegen.');
    }

    /**
     * Legt einen Quarantäne-Lauf mit vorgegebenem Anlagedatum an.
     */
    private function handWrittenRun(string $createdAt): string
    {
        $runId = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd-His')
            . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $verzeichnis = $this->projectDir . '/var/media_quarantine/' . $runId;
        mkdir($verzeichnis, 0o777, true);
        file_put_contents($verzeichnis . '/manifest.json', json_encode([
            'runId' => $runId,
            'createdAt' => (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'items' => [],
        ]));

        return $verzeichnis;
    }

    /**
     * @param list<string> $orphans Dateinamen ohne Endung
     */
    private function collectorHandler(
        RecordingFilesystem $filesystem,
        bool $autoQuarantine,
        array $orphans,
        bool $moduleActive = true,
        bool $cronEnabled = true,
        int $retentionDays = 0,
    ): MediaGarbageCollectorTaskHandler {
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

        $config = $this->collectorConfig($moduleActive, $cronEnabled, $autoQuarantine, $retentionDays);

        return new MediaGarbageCollectorTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new ModuleRegistry($config),
            $this->finder($connection, $config),
            $this->quarantineService($connection, $filesystem),
            $config,
            new MediaToolkitLogger(new NullLogger()),
        );
    }

    private function quarantineService(Connection $connection, FilesystemAdapterInterface $filesystem): MediaQuarantineService
    {
        return new MediaQuarantineService(
            $connection,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $this->projectDir,
        );
    }

    private function finder(Connection $connection, SystemConfigService $config): OrphanedMediaFinder
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

    /**
     * @return SystemConfigService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function collectorConfig(
        bool $moduleActive,
        bool $cronEnabled,
        bool $autoQuarantine,
        int $retentionDays = 0,
    ): SystemConfigService {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'RcMediaToolkit.config.garbageCollectorActive' => $moduleActive,
                'RcMediaToolkit.config.gcCronEnabled' => $cronEnabled,
                'RcMediaToolkit.config.gcCronAutoQuarantine' => $autoQuarantine,
                'RcMediaToolkit.config.gcQuarantineRetentionDays' => $retentionDays,
                'RcMediaToolkit.config.gcGracePeriodDays' => 30,
                default => null,
            },
        );

        return $config;
    }

    private function scanHandler(int $retentionDays, bool $moduleActive = true): HealthCheckScanTaskHandler
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'RcMediaToolkit.config.healthCheckActive' => $moduleActive,
                'RcMediaToolkit.config.healthRetentionDays' => $retentionDays,
                default => true,
            },
        );

        $repository = new HealthReportRepository($this->recordingConnection());

        return new HealthCheckScanTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new ModuleRegistry($config),
            new HealthCheckRunner([], $repository, $config, new MediaToolkitLogger(new NullLogger())),
            $repository,
            $config,
        );
    }

    private function runnerWith(Connection $connection): HealthCheckRunner
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(true);

        return new HealthCheckRunner(
            [new SingleFindingCheck()],
            new HealthReportRepository($connection),
            $config,
            new MediaToolkitLogger(new NullLogger()),
        );
    }

    /**
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function recordingConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            /** @param array<string, mixed> $params */
            function (string $sql, array $params = []): int {
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );

        return $connection;
    }

    private function countStatements(string $needle): int
    {
        $count = 0;
        foreach ($this->statements as $statement) {
            if (str_contains($statement['sql'], $needle)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<string> Verzeichnisse der angelegten Quarantäne-Läufe
     */
    private function quarantineRuns(): array
    {
        $base = $this->projectDir . '/var/media_quarantine';
        if (!is_dir($base)) {
            return [];
        }

        $runs = [];
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($base . '/' . $entry)) {
                $runs[] = $base . '/' . $entry;
            }
        }

        return $runs;
    }

    /**
     * @return list<string> Die Sicherungskopien aller Läufe, ohne die Verzeichnisse selbst
     */
    private function quarantinedFiles(): array
    {
        $files = [];
        foreach ($this->quarantineRuns() as $run) {
            foreach (scandir($run) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'manifest.json') {
                    continue;
                }
                $files[] = $run . '/' . $entry;
            }
        }

        return $files;
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

/**
 * Ein Speicher, der sich merkt, was mit ihm geschehen ist, und auf Wunsch bei einzelnen Dateien
 * klemmt. Er ersetzt den angebundenen Speicher, nicht die geprüfte Klasse.
 */
final class RecordingFilesystem implements FilesystemAdapterInterface
{
    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> */
    public array $written = [];

    /** @var callable|null Wird vor jedem Entfernen aufgerufen */
    public $onDelete;

    /**
     * @param array<string, string> $files      Pfad auf Inhalt
     * @param list<string>          $unreadable Pfade, deren Lesen fehlschlägt
     */
    public function __construct(
        private array $files = [],
        private readonly array $unreadable = [],
    ) {
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function read(string $path): string
    {
        if (in_array($path, $this->unreadable, true)) {
            throw new RuntimeException(sprintf('Speicher antwortet nicht für "%s"', $path));
        }

        return $this->files[$path] ?? throw new RuntimeException(sprintf('Nicht vorhanden: "%s"', $path));
    }

    public function write(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
        $this->written[] = $path;
    }

    public function delete(string $path): void
    {
        if ($this->onDelete !== null) {
            ($this->onDelete)($path);
        }

        unset($this->files[$path]);
        $this->deleted[] = $path;
    }

    public function size(string $path): int
    {
        return strlen($this->files[$path] ?? '');
    }

    public function lastModified(string $path): int
    {
        return 0;
    }

    public function move(string $source, string $destination): void
    {
        $this->files[$destination] = $this->read($source);
        $this->delete($source);
    }

    public function makeDirectory(string $path): void
    {
    }

    public function listContents(string $path, bool $recursive = false): array
    {
        return array_keys($this->files);
    }
}

/**
 * Eine Prüfung, die genau einen Befund liefert — gerade genug, damit das Wegschreiben und das
 * Aufräumen danach beobachtbar sind.
 */
final class SingleFindingCheck implements MediaHealthCheckInterface
{
    public function type(): string
    {
        return 'missing_file';
    }

    public function configKey(): string
    {
        return 'RcMediaToolkit.config.healthCheckMissingFiles';
    }

    public function run(): Generator
    {
        yield new \Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding(
            checkType: 'missing_file',
            severity: \Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding::SEVERITY_CRITICAL,
            sourceType: 'media_table',
            mediaId: 'aabbccddeeff00112233445566778899',
            sourceReference: 'media:aabbccddeeff00112233445566778899',
            urlPath: 'media/ab/bild.jpg',
            errorDetail: ['reason' => 'file_not_found'],
        );
    }
}
