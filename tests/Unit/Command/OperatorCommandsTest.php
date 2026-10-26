<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Command\StatusCommand;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Command\HealthCheckReportCommand;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Command\HealthCheckScanCommand;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Message\CheckMediaBatchMessage;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Service\HealthCheckRunner;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Command\RepairApplyCommand;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Command\RepairListCommand;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Command\RepairRollbackCommand;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\MediaReferenceUpdater;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairExecutor;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairProposalService;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Die Befehle, mit denen jemand das Plugin bedient.
 *
 * Sie sind die Bedienoberfläche dieses Plugins — ein Admin-Fenster gibt es für die meisten
 * Vorgänge nicht. Deshalb entscheidet sich hier, ob das Werkzeug beherrschbar ist, und drei
 * Zusagen tragen das:
 *
 * **Der Rückgabewert stimmt.** Diese Befehle laufen zeitgesteuert; dort sieht niemand die
 * Ausgabe, sondern nur die Zahl am Ende. Ein Erfolg für „konnte nicht laufen" macht den
 * Unterschied zwischen einem gepflegten und einem verwahrlosten Shop unsichtbar.
 *
 * **Vor jedem Eingriff wird gefragt.** Reparatur und Rücknahme ändern Verweise im Shop; beide
 * fragen nach, und ein Nein bedeutet, dass nichts geschehen ist.
 *
 * **Ein Verweis auf etwas Fehlendes ist ein Fehler, kein leeres Ergebnis.** Wer eine Kennung
 * angibt, die es nicht gibt, hat sich vertippt — und soll das erfahren, statt eine leere Liste
 * für eine Antwort zu halten.
 */
#[CoversClass(StatusCommand::class)]
#[CoversClass(HealthCheckScanCommand::class)]
#[CoversClass(HealthCheckReportCommand::class)]
#[CoversClass(RepairListCommand::class)]
#[CoversClass(RepairApplyCommand::class)]
#[CoversClass(RepairRollbackCommand::class)]
final class OperatorCommandsTest extends TestCase
{
    private const FINDING_ID = 'aabbccddeeff00112233445566778899';

    /**
     * Der Statusbefehl nennt jedes Modul mit seinem Zustand. Er ist der erste Griff bei „warum
     * passiert nichts?" — und beantwortet genau diese Frage, wenn ein Modul abgeschaltet ist.
     */
    #[Test]
    public function theStatusCommandNamesEveryModuleWithItsState(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key): bool => $key === 'RcMediaToolkit.config.healthCheckActive',
        );

        $tester = new CommandTester(new StatusCommand(new ModuleRegistry($config)));
        $tester->execute([]);

        $ausgabe = $tester->getDisplay();
        self::assertStringContainsString('healthCheck', $ausgabe);
        self::assertStringContainsString('garbageCollector', $ausgabe);
        self::assertStringContainsString('webPOptimizer', $ausgabe);
        self::assertStringContainsString('aktiv', $ausgabe);
        self::assertStringContainsString('inaktiv', $ausgabe);
    }

    /**
     * Was: Die Medienprüfung bei abgeschaltetem Modul.
     * Warum: Fehlerstatus statt stillem Erfolg. In einem zeitgesteuerten Aufruf ist der
     *        Rückgabewert das einzige Signal; ein Erfolg für „habe nichts getan" ließe den
     *        Ausfall monatelang unbemerkt.
     */
    #[Test]
    public function anInactiveModuleMakesTheScanFail(): void
    {
        $tester = new CommandTester($this->scanCommand(moduleActive: false));

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    /**
     * Was: Der Lauf über die Warteschlange.
     * Warum: Er stellt je Prüfung einen Auftrag ein und kehrt sofort zurück. Auf einem großen
     *        Bestand ist das der einzige Weg, der nicht in eine Zeitüberschreitung läuft — und
     *        jeder Auftrag muss seine Prüfung benennen, sonst arbeitet der Arbeiter ins Leere.
     */
    #[Test]
    public function theAsynchronousScanQueuesOneJobPerCheck(): void
    {
        $dispatched = [];
        $tester = new CommandTester($this->scanCommand(dispatched: $dispatched));

        $status = $tester->execute(['--async' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(2, $dispatched);
        self::assertSame(['missing_file', 'zero_byte'], array_map(
            static fn (CheckMediaBatchMessage $message): string => $message->checkType,
            $dispatched,
        ));
    }

    /**
     * Wird eine einzelne Prüfung benannt, geht auch nur ein Auftrag hinaus. Sonst zöge der
     * Versuch, eine Prüfung nachzuholen, den gesamten Bestand durch alle Prüfungen.
     */
    #[Test]
    public function askingForOneCheckQueuesOnlyThatOne(): void
    {
        $dispatched = [];
        $tester = new CommandTester($this->scanCommand(dispatched: $dispatched));

        $tester->execute(['--async' => true, '--check' => 'zero_byte']);

        self::assertCount(1, $dispatched);
        self::assertSame('zero_byte', $dispatched[0]->checkType);
    }

    /**
     * Was: Der Bericht in Maschinenform.
     * Warum: Er wird ausgewertet, nicht gelesen. Deshalb darf in dieser Form **nur** der Bericht
     *        selbst auf der Ausgabe stehen — jede Überschrift, jede Zusammenfassung machte ihn
     *        unlesbar für das, was ihn weiterverarbeitet.
     */
    #[Test]
    public function theMachineReadableReportContainsNothingButTheReport(): void
    {
        $tester = new CommandTester($this->reportCommand([$this->findingRow()]));

        $tester->execute(['--format' => 'json']);

        $gelesen = json_decode(trim($tester->getDisplay()), true);
        self::assertIsArray($gelesen);
        self::assertCount(1, $gelesen);
        self::assertSame('missing_file', $gelesen[0]['check_type']);
    }

    /**
     * Was: Die Tabellenform für die Weitergabe.
     * Warum: Sie wird in Tabellenkalkulationen geöffnet und weitergereicht. Die Kopfzeile muss
     *        deshalb jede Spalte benennen — ohne sie ist eine Zeile aus acht Werten nicht mehr
     *        zuzuordnen, sobald jemand anders sie ansieht.
     */
    #[Test]
    public function theSpreadsheetFormatCarriesItsHeader(): void
    {
        $tester = new CommandTester($this->reportCommand([$this->findingRow()]));

        $tester->execute(['--format' => 'csv']);

        $zeilen = array_values(array_filter(explode(chr(10), trim($tester->getDisplay()))));

        self::assertStringContainsString('check_type', $zeilen[0]);
        self::assertStringContainsString('url_path', $zeilen[0]);
        self::assertStringContainsString('missing_file', $zeilen[1]);
    }

    /**
     * Was: Die Form für Textberichte.
     * Warum: Sie landet in Notizen und Fehlermeldungen. Ein senkrechter Strich im Pfad zerbräche
     *        dort die Tabelle — er wird deshalb geschützt, statt die Darstellung zu zerlegen.
     */
    #[Test]
    public function theTextReportProtectsTheColumnSeparator(): void
    {
        $befund = $this->findingRow();
        $befund['url_path'] = 'media/ab/bild|mit|strich.jpg';

        $tester = new CommandTester($this->reportCommand([$befund]));
        $tester->execute(['--format' => 'md']);

        $ausgabe = $tester->getDisplay();

        self::assertStringContainsString('| Check | Severity |', $ausgabe);
        self::assertStringContainsString(chr(92) . '|mit' . chr(92) . '|strich', $ausgabe);
    }

    /**
     * Auch ohne Befund entsteht ein vollständiger Bericht und keine leere Datei — sonst sähe ein
     * gesunder Bestand wie ein fehlgeschlagener Lauf aus.
     */
    #[Test]
    public function anEmptyTextReportIsStillAReport(): void
    {
        $tester = new CommandTester($this->reportCommand([]));

        $tester->execute(['--format' => 'md']);

        self::assertStringContainsString('# Health-Check-Findings', $tester->getDisplay());
    }

    /**
     * Die Form für Menschen fasst zusammen und meldet ausdrücklich, wenn nichts offen ist. Eine
     * leere Tabelle ohne Wort sähe aus wie ein Fehler im Werkzeug.
     */
    #[Test]
    public function theHumanReportSaysSoWhenNothingIsOpen(): void
    {
        $tester = new CommandTester($this->reportCommand([]));

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Keine offenen Findings', $tester->getDisplay());
    }

    /**
     * Was: Vorschläge zu einem Befund, den es nicht gibt.
     * Warum: **Fehlerstatus, nicht leere Liste.** Eine leere Liste hieße „für diesen Befund gibt
     *        es nichts zu tun" — und jemand legte die Sache zu den Akten, obwohl er sich schlicht
     *        vertippt hat.
     */
    #[Test]
    public function proposalsForAnUnknownFindingAreAnError(): void
    {
        $tester = new CommandTester($this->listCommand(finding: null));

        self::assertSame(Command::FAILURE, $tester->execute(['findingId' => self::FINDING_ID]));
        self::assertStringContainsString('nicht gefunden', $tester->getDisplay());
    }

    /**
     * Die Vorschlagsliste nennt zu jedem Eintrag seine Zuversicht und den Aufruf zum Anwenden.
     * Ohne die Zuversicht fehlte das Einzige, woran sich entscheiden lässt, welchem Vorschlag
     * man folgt.
     */
    #[Test]
    public function theProposalListingShowsConfidenceAndHowToApply(): void
    {
        $tester = new CommandTester($this->listCommand($this->findingRow('broken_thumbnail')));

        $tester->execute(['findingId' => self::FINDING_ID]);

        $ausgabe = $tester->getDisplay();
        self::assertStringContainsString('regenerate_thumbnail', $ausgabe);
        self::assertStringContainsString('90 %', $ausgabe);
        self::assertStringContainsString('rc:media:repair:apply', $ausgabe);
    }

    /**
     * Was: Ein Vorschlagsplatz, den es nicht gibt.
     * Warum: Die Plätze stammen aus einer vorher gezeigten Liste; hat sich der Bestand seither
     *        geändert, zeigt dieselbe Zahl auf etwas anderes oder auf nichts. Angewendet wird
     *        dann **nichts** — die Alternative wäre eine Reparatur, die jemand so nie
     *        beauftragt hat.
     */
    #[Test]
    public function anOutOfRangeProposalIndexAppliesNothing(): void
    {
        $statements = [];
        $tester = new CommandTester($this->applyCommand($this->findingRow('broken_thumbnail'), $this->executor($statements)));

        $status = $tester->execute(['findingId' => self::FINDING_ID, 'proposalIndex' => '7']);

        self::assertSame(Command::INVALID, $status);
        self::assertSame([], $statements);
    }

    /**
     * Was: Die verneinte Rückfrage vor der Reparatur.
     * Warum: Eine Reparatur biegt Verweise im Shop um. Wird verneint, darf nichts geschehen sein
     *        — und der Befehl endet trotzdem ordentlich, denn ein Abbruch auf Wunsch ist kein
     *        Fehler.
     */
    #[Test]
    public function aDeclinedRepairChangesNothing(): void
    {
        $statements = [];
        $tester = new CommandTester($this->applyCommand($this->findingRow('broken_thumbnail'), $this->executor($statements)));
        $tester->setInputs(['no']);

        $status = $tester->execute(['findingId' => self::FINDING_ID, 'proposalIndex' => '0']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $statements, 'Ohne Zustimmung wird keine einzige Anweisung abgesetzt.');
    }

    /**
     * Die Gegenprobe: Zugestimmt wird die Reparatur ausgeführt — und der Befehl nennt die
     * Kennung der Prüfspur samt Aufruf für die Rücknahme. Ohne diese Kennung gäbe es keinen
     * Rückweg.
     */
    #[Test]
    public function aConfirmedRepairRunsAndNamesTheWayBack(): void
    {
        $statements = [];
        $tester = new CommandTester($this->applyCommand($this->findingRow('broken_thumbnail'), $this->executor($statements)));
        $tester->setInputs(['yes']);

        $tester->execute(['findingId' => self::FINDING_ID, 'proposalIndex' => '0']);

        self::assertNotSame([], $statements, 'Die Reparatur muss stattgefunden haben.');
        self::assertStringContainsString('rc_media_repair_audit', implode(chr(10), $statements), 'Ohne Prüfspur gäbe es keinen Rückweg.');
        self::assertStringContainsString('rc:media:repair:rollback', $tester->getDisplay());
    }

    /**
     * Was: Die Rücknahme mit verneinter Rückfrage.
     * Warum: Auch die Rücknahme ist ein Eingriff — sie stellt einen früheren Zustand wieder her
     *        und verwirft damit alles, was seither an dieser Stelle geschehen ist.
     */
    #[Test]
    public function aDeclinedRollbackChangesNothing(): void
    {
        $statements = [];
        $tester = new CommandTester(new RepairRollbackCommand($this->executor($statements)));
        $tester->setInputs(['no']);

        $status = $tester->execute(['auditId' => self::FINDING_ID]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $statements);
    }

    /**
     * Was: Eine Rücknahme, die scheitert.
     * Warum: Fehlerstatus und die Meldung des Grundes. Ein Erfolg an dieser Stelle wäre die
     *        schlimmste Auskunft überhaupt: Jemand glaubte, der alte Zustand sei wieder da.
     */
    #[Test]
    public function aFailingRollbackReportsFailure(): void
    {
        $statements = [];
        $tester = new CommandTester(new RepairRollbackCommand($this->executor($statements, fail: true)));
        $tester->setInputs(['yes']);

        $status = $tester->execute(['auditId' => self::FINDING_ID]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('fehlgeschlagen', $tester->getDisplay());
    }

    /**
     * Was: Der Lauf ohne Warteschlange.
     * Warum: Auf kleinen Beständen der übliche Weg — man will das Ergebnis sofort sehen. Die
     *        Übersicht nennt jede Prüfung mit ihrer Zahl; ohne die Aufschlüsselung bliebe
     *        offen, welche Prüfung überhaupt gelaufen ist.
     */
    #[Test]
    public function theSynchronousRunShowsEveryCheckWithItsCount(): void
    {
        $tester = new CommandTester($this->scanCommand());

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        $ausgabe = $tester->getDisplay();
        self::assertStringContainsString('missing_file', $ausgabe);
        self::assertStringContainsString('zero_byte', $ausgabe);
    }

    /**
     * Wird eine einzelne Prüfung benannt, läuft auch nur sie — und der Befehl nennt sie samt
     * der Zahl der weggeschriebenen Befunde.
     */
    #[Test]
    public function askingForOneCheckRunsOnlyThatOne(): void
    {
        $tester = new CommandTester($this->scanCommand());

        $tester->execute(['--check' => 'zero_byte']);

        self::assertStringContainsString('zero_byte', $tester->getDisplay());
        self::assertStringNotContainsString('missing_file', $tester->getDisplay());
    }

    /**
     * Was: Die Übersicht für Menschen mit Befunden.
     * Warum: Sie ist der erste Blick nach einem nächtlichen Lauf. Jede Zeile trägt Kennung,
     *        Schwere, Prüfung und Pfad — genug, um zu entscheiden, was zuerst angefasst wird,
     *        ohne eine zweite Abfrage.
     */
    #[Test]
    public function theHumanReportShowsOneRowPerFinding(): void
    {
        $tester = new CommandTester($this->reportCommand([$this->findingRow()]));

        $tester->execute([]);

        $ausgabe = $tester->getDisplay();
        self::assertStringContainsString('missing_file', $ausgabe);
        self::assertStringContainsString('critical', $ausgabe);
        self::assertStringContainsString('media/ab/bild.jpg', $ausgabe);
    }

    /**
     * Ein Befundtyp ohne Vorschläge endet nicht als Fehler: Für verwaiste Dateien gibt es
     * bewusst keine automatische Reparatur, und das ist eine Auskunft und kein Fehlschlag.
     */
    #[Test]
    public function aFindingWithoutProposalsIsNotAnError(): void
    {
        $befund = $this->findingRow('orphaned_file');

        $tester = new CommandTester($this->listCommand($befund));
        $status = $tester->execute(['findingId' => self::FINDING_ID]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Keine Vorschläge', $tester->getDisplay());
    }

    /**
     * Was: Die Reparatur scheitert mitten im Vorgang.
     * Warum: Fehlerstatus und die Meldung des Grundes. Ein Erfolg wäre hier die schlimmste
     *        Auskunft: Jemand hielte den Befund für erledigt und sähe ihn nie wieder an.
     */
    #[Test]
    public function aFailingRepairReportsFailure(): void
    {
        $statements = [];
        $executor = $this->executor($statements, fail: true);

        $tester = new CommandTester($this->applyCommand($this->findingRow('broken_thumbnail'), $executor));
        $tester->setInputs(['yes']);

        // Ohne Freischaltung endet der Ausführer mit einer Ausnahme — der Befehl fängt sie und
        // meldet einen Fehler, statt sie bis in die Konsole durchzureichen.
        $status = $tester->execute(['findingId' => self::FINDING_ID, 'proposalIndex' => '0']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('fehlgeschlagen', $tester->getDisplay());
    }

    /**
     * Die gelungene Rücknahme meldet sich ausdrücklich. Sie ist ein Eingriff, dessen Ergebnis
     * niemand direkt sieht — die Bestätigung ist das Einzige, woran sich ablesen lässt, dass
     * sie stattgefunden hat.
     */
    #[Test]
    public function aCompletedRollbackSaysSo(): void
    {
        $statements = [];
        $tester = new CommandTester(new RepairRollbackCommand($this->executor($statements)));
        $tester->setInputs(['yes']);

        $status = $tester->execute(['auditId' => self::FINDING_ID]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('abgeschlossen', $tester->getDisplay());
    }

    /**
     * Was: Eine Reparatur für einen Befund, den es nicht gibt.
     * Warum: Fehlerstatus, kein stilles Nichtstun. Die Kennung kommt von Hand aus einer Liste;
     *        ein Tippfehler darf nicht wie eine erledigte Reparatur aussehen.
     */
    #[Test]
    public function repairingAnUnknownFindingIsAnError(): void
    {
        $statements = [];
        $tester = new CommandTester($this->applyCommand(null, $this->executor($statements)));

        $status = $tester->execute(['findingId' => self::FINDING_ID, 'proposalIndex' => '0']);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame([], $statements);
        self::assertStringContainsString('nicht gefunden', $tester->getDisplay());
    }

    /**
     * @param list<CheckMediaBatchMessage> $dispatched
     */
    private function scanCommand(bool $moduleActive = true, array &$dispatched = []): HealthCheckScanCommand
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($moduleActive);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );

        $runner = new HealthCheckRunner(
            [new NamedCheck('missing_file'), new NamedCheck('zero_byte')],
            new HealthReportRepository($this->createMock(Connection::class)),
            $config,
            new MediaToolkitLogger(new NullLogger()),
        );

        return new HealthCheckScanCommand(new ModuleRegistry($config), $runner, $bus);
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function reportCommand(array $findings): HealthCheckReportCommand
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            // Die Zählung nach Schwere geht über dieselbe Methode wie die Liste; ein Doppel,
            // das beiden dieselben Zeilen gibt, erzeugt Meldungen über fehlende Spalten.
            static function (string $sql) use ($findings): array {
                if (str_contains($sql, 'GROUP BY severity')) {
                    return $findings === [] ? [] : [['severity' => 'critical', 'cnt' => count($findings)]];
                }

                return $findings;
            },
        );

        return new HealthCheckReportCommand(new HealthReportRepository($connection));
    }

    /**
     * @param array<string, mixed>|null $finding
     */
    private function listCommand(?array $finding): RepairListCommand
    {
        return new RepairListCommand($this->proposalService(), $this->reportRepository($finding));
    }

    /**
     * @param array<string, mixed>|null $finding
     */
    private function applyCommand(?array $finding, RepairExecutor $executor): RepairApplyCommand
    {
        return new RepairApplyCommand($this->proposalService(), $executor, $this->reportRepository($finding));
    }

    private function proposalService(): RepairProposalService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['file_name' => 'bild', 'file_extension' => 'jpg']);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(3);

        return new RepairProposalService($connection, $config);
    }

    /**
     * @param array<string, mixed>|null $finding
     */
    private function reportRepository(?array $finding): HealthReportRepository
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($finding ?? false);

        return new HealthReportRepository($connection);
    }

    /**
     * Gebaut wird der echte Ausführer über eine Verbindung, die jede Anweisung mitschreibt.
     * Er ist versiegelt, und ein Doppel bewiese ohnehin nichts über das Zusammenspiel.
     *
     * @param list<string> $statements
     */
    private function executor(array &$statements, bool $fail = false): RepairExecutor
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(!$fail);

        $logger = new MediaToolkitLogger(new NullLogger());
        $connection = $this->createMock(Connection::class);

        if ($fail) {
            $connection->method('fetchAssociative')->willReturn(false);
        } else {
            $connection->method('fetchAssociative')->willReturn([
                'id' => self::FINDING_ID,
                'proposal_type' => 'regenerate_thumbnail',
                'before_state' => '{"finding":{}}',
                'after_state' => '{"proposalType":"regenerate_thumbnail","payload":{}}',
            ]);
        }

        $connection->method('transactional')->willReturnCallback(
            static function (callable $work) use ($connection): mixed {
                return $work($connection);
            },
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 1;
            },
        );
        $connection->method('insert')->willReturnCallback(
            static function (string $table) use (&$statements): int {
                $statements[] = 'INSERT INTO ' . $table;

                return 1;
            },
        );

        return new RepairExecutor(
            $connection,
            $logger,
            $config,
            new MediaReferenceUpdater($connection, new MediaReferenceSources($connection)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function findingRow(string $checkType = 'missing_file'): array
    {
        return [
            'id' => self::FINDING_ID,
            'media_id' => self::FINDING_ID,
            'check_type' => $checkType,
            'severity' => 'critical',
            'source_type' => 'media_table',
            'source_reference' => 'media:' . self::FINDING_ID,
            'error_detail' => null,
            'url_path' => 'media/ab/bild.jpg',
            'first_seen_at' => '2026-08-01 10:00:00',
            'last_seen_at' => '2026-08-12 10:00:00',
            'resolved_at' => null,
        ];
    }
}

/**
 * Eine Prüfung, die nur ihren Namen kennt. Für die Befehle zählt allein, dass es sie gibt und
 * wie sie heißt — was sie findet, ist Sache ihrer eigenen Fälle.
 */
final class NamedCheck implements \Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\MediaHealthCheckInterface
{
    public function __construct(private readonly string $type)
    {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function configKey(): string
    {
        return 'RcMediaToolkit.config.healthCheck' . ucfirst($this->type);
    }

    public function run(): Generator
    {
        yield from [];
    }
}
