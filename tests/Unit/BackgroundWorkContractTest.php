<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\ScheduledTask\MediaGarbageCollectorTask;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Message\CheckMediaBatchMessage;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\ScheduledTask\HealthCheckScanTask;
use Ruhrcoder\RcMediaToolkit\Module\Repair\ScheduledTask\RepairAuditCleanupTask;
use Ruhrcoder\RcMediaToolkit\Module\Repair\ScheduledTask\RepairAuditCleanupTaskHandler;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairAuditRepository;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message\WebPConvertMessage;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\ScheduledTask\WebPBulkConvertTask;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\ScheduledTask\WebPBulkConvertTaskHandler;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPRegistry;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use RuntimeException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Was im Hintergrund läuft: die vier Termine, ihre Bearbeiter und die Nachrichten dazwischen.
 *
 * Hintergrundarbeit hat eine unangenehme Eigenschaft — **niemand sieht sie**. Läuft sie nicht,
 * fällt es erst auf, wenn jemand nach dem Ergebnis sucht; läuft sie zu oft, fällt es erst bei der
 * Systemlast auf. Deshalb stehen hier drei Dinge fest: die Namen der Termine, weil sie in der
 * Datenbank stehen und ein neuer Name den alten Eintrag verwaisen lässt; die Takte, weil sie
 * bewusst gewählt sind; und das Verhalten der Schalter, denn ein Schalter, der nicht greift, ist
 * schlimmer als keiner.
 */
#[CoversClass(MediaGarbageCollectorTask::class)]
#[CoversClass(HealthCheckScanTask::class)]
#[CoversClass(WebPBulkConvertTask::class)]
#[CoversClass(RepairAuditCleanupTask::class)]
#[CoversClass(CheckMediaBatchMessage::class)]
#[CoversClass(WebPConvertMessage::class)]
#[CoversClass(WebPBulkConvertTaskHandler::class)]
#[CoversClass(RepairAuditCleanupTaskHandler::class)]
final class BackgroundWorkContractTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function taskNames(): array
    {
        return [
            'Aufräumer' => [MediaGarbageCollectorTask::class, 'rc_media_toolkit.garbage_collector'],
            'Medienprüfung' => [HealthCheckScanTask::class, 'rc_media_toolkit.health_check_scan'],
            'Umwandlung' => [WebPBulkConvertTask::class, 'rc_media_toolkit.webp_bulk_convert'],
            'Prüfspur' => [RepairAuditCleanupTask::class, 'rc_media_toolkit.repair_audit_cleanup'],
        ];
    }

    /**
     * @param class-string<\Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask> $task
     *
     * Was: Der Name jedes Termins.
     * Warum: Er steht als Zeichenkette in der Terminverwaltung der Datenbank. Ändert er sich,
     *        bleibt der alte Eintrag als Waise stehen und der neue wird beim nächsten Aktivieren
     *        angelegt — bis dahin **läuft die Aufgabe überhaupt nicht**, ohne Fehlermeldung.
     */
    #[Test]
    #[DataProvider('taskNames')]
    public function everyTaskKeepsItsRegisteredName(string $task, string $expected): void
    {
        self::assertSame($expected, $task::getTaskName());
    }

    /**
     * Was: Die Takte.
     * Warum: Jeder ist eine Abwägung — der Aufräumer geht über den gesamten Bestand und läuft
     *        deshalb wöchentlich, die Prüfung liest nur und läuft täglich, die Umwandlung
     *        arbeitet in kleinen Portionen und meldet sich viertelstündlich. Eine unbedachte
     *        Änderung fällt in der Entwicklung nicht auf und auf einem großen Shop sofort.
     */
    #[Test]
    public function theIntervalsMatchTheirPurpose(): void
    {
        self::assertSame(7 * 24 * 60 * 60, MediaGarbageCollectorTask::getDefaultInterval(), 'Der Aufräumer läuft wöchentlich.');
        self::assertSame(24 * 60 * 60, HealthCheckScanTask::getDefaultInterval(), 'Die Prüfung läuft täglich.');
        self::assertSame(15 * 60, WebPBulkConvertTask::getDefaultInterval(), 'Die Umwandlung läuft viertelstündlich.');
        self::assertSame(24 * 60 * 60, RepairAuditCleanupTask::getDefaultInterval(), 'Die Prüfspur wird täglich beschnitten.');
    }

    /**
     * Was: Die Nachrichten tragen nur Angaben, keine Daten.
     * Warum: Eine Nachricht liegt unter Umständen lange in der Warteschlange. Trüge sie die
     *        Bilddaten, summierten sich über einen ganzen Bestand Hunderte Megabyte in der
     *        Warteschlange — und beim Abarbeiten wären die Daten womöglich längst veraltet.
     */
    #[Test]
    public function theMessagesCarryReferencesNotPayloads(): void
    {
        $convert = new WebPConvertMessage('media/ab/bild.jpg', 'vorgang-1');
        self::assertSame('media/ab/bild.jpg', $convert->sourcePath);
        self::assertSame('vorgang-1', $convert->correlationId);

        $batch = new CheckMediaBatchMessage('missing_file', ['aabb'], 'vorgang-2');
        self::assertSame('missing_file', $batch->checkType);
        self::assertSame(['aabb'], $batch->mediaIds);
    }

    /**
     * Ohne Vorgangskennung sind die Nachrichten trotzdem gültig — dann erzeugt der Bearbeiter
     * eine. Sonst ließe sich eine einzeln eingestellte Nachricht nicht abschicken.
     */
    #[Test]
    public function theCorrelationIdIsOptional(): void
    {
        self::assertNull((new WebPConvertMessage('media/ab/bild.jpg'))->correlationId);
        self::assertSame([], (new CheckMediaBatchMessage('missing_file'))->mediaIds);
    }

    /**
     * Was: Der Massenlauf der Umwandlung stellt je offenem Bild eine Nachricht ein.
     * Warum: Der Zweck des Termins. Alle Nachrichten tragen dieselbe Vorgangskennung — nur
     *        dadurch lassen sich die Protokollzeilen der Arbeiter später einem Lauf zuordnen.
     */
    #[Test]
    public function theBulkRunQueuesOneMessagePerOutstandingImage(): void
    {
        $dispatched = [];
        $handler = $this->bulkHandler(
            moduleActive: true,
            cronEnabled: true,
            rows: [
                ['id' => 'a', 'file_extension' => 'jpg', 'path' => 'media/a/eins.jpg'],
                ['id' => 'b', 'file_extension' => 'png', 'path' => 'media/a/zwei.png'],
            ],
            dispatched: $dispatched,
        );

        $handler->run();

        self::assertCount(2, $dispatched);
        self::assertSame('media/a/eins.jpg', $dispatched[0]->sourcePath);
        self::assertSame(
            $dispatched[0]->correlationId,
            $dispatched[1]->correlationId,
            'Alle Aufträge eines Laufs gehören zusammen.',
        );
    }

    /**
     * Was: Das abgeschaltete Modul.
     * Warum: **Ein Schalter, der nicht greift, ist die schlimmste Art von Schalter.** Wer die
     *        Umwandlung abstellt, tut das meist, weil sie gerade Last erzeugt; liefe sie
     *        weiter, suchte er die Ursache überall, nur nicht hier.
     */
    #[Test]
    public function anInactiveModuleQueuesNothing(): void
    {
        $dispatched = [];
        $handler = $this->bulkHandler(
            moduleActive: false,
            cronEnabled: true,
            rows: [['id' => 'a', 'file_extension' => 'jpg', 'path' => 'media/a/eins.jpg']],
            dispatched: $dispatched,
        );

        $handler->run();

        self::assertSame([], $dispatched);
    }

    /**
     * Dieselbe Zusage für den eigenen Schalter des Termins: Das Modul kann an sein und der
     * zeitgesteuerte Lauf trotzdem aus. Beides sind getrennte Entscheidungen — die Umwandlung
     * lässt sich von Hand anstoßen, ohne dass sie nachts weiterläuft.
     */
    #[Test]
    public function aDisabledCronQueuesNothingEvenWithAnActiveModule(): void
    {
        $dispatched = [];
        $handler = $this->bulkHandler(
            moduleActive: true,
            cronEnabled: false,
            rows: [['id' => 'a', 'file_extension' => 'jpg', 'path' => 'media/a/eins.jpg']],
            dispatched: $dispatched,
        );

        $handler->run();

        self::assertSame([], $dispatched);
    }

    /**
     * Was: Die Aufbewahrung der Prüfspur steht auf null.
     * Warum: **Null heißt „nie löschen", nicht „sofort löschen".** Die Prüfspur ist der einzige
     *        Nachweis darüber, wer welche Reparatur ausgelöst hat; ein Missverständnis an dieser
     *        Stelle löschte genau den Nachweis, der im Zweifel gebraucht wird.
     */
    #[Test]
    public function aRetentionOfZeroMeansKeepEverything(): void
    {
        $statements = [];
        $handler = $this->cleanupHandler(retentionDays: 0, statements: $statements);

        $handler->run();

        self::assertSame([], $statements, 'Ohne Aufbewahrungsfrist wird nichts gelöscht.');
    }

    /**
     * Die Gegenprobe: Mit gesetzter Frist wird beschnitten. Ohne sie bewiese der vorige Fall nur,
     * dass der Bearbeiter überhaupt nichts tut.
     */
    #[Test]
    public function aConfiguredRetentionPrunesTheAuditTrail(): void
    {
        $statements = [];
        $handler = $this->cleanupHandler(retentionDays: 30, statements: $statements);

        $handler->run();

        self::assertCount(1, $statements);
        self::assertStringContainsString('DELETE', $statements[0]);
    }

    /**
     * Was: Das Beschneiden schlägt fehl.
     * Warum: Der Fehler wird ins Protokoll geschrieben und nicht weitergereicht. Käme er heraus,
     *        setzte Shopware den Termin auf „fehlgeschlagen" — und dann liefe **auch die nächste
     *        Aufräumrunde nicht mehr**, wegen einer Störung, die längst vorbei ist.
     */
    #[Test]
    public function aFailingPruneDoesNotBreakTheSchedule(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(
            new RuntimeException('Datenbank nicht erreichbar'),
        );

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(30);

        $handler = new RepairAuditCleanupTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new RepairAuditRepository($connection),
            $config,
            new MediaToolkitLogger(new NullLogger()),
        );

        $handler->run();

        self::addToAssertionCount(1); // Der erwartete Abbruch ist eingetreten — das ist die Prüfung.
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<WebPConvertMessage>   $dispatched
     */
    private function bulkHandler(
        bool $moduleActive,
        bool $cronEnabled,
        array $rows,
        array &$dispatched,
    ): WebPBulkConvertTaskHandler {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'RcMediaToolkit.config.webpBulkCronEnabled'
                ? $cronEnabled
                : $moduleActive,
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('0');
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static fn (string $sql, array $params = []): array => (int) ($params['offset'] ?? 0) === 0 ? $rows : [],
        );

        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturn(false);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );

        return new WebPBulkConvertTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new ModuleRegistry($config),
            new WebPRegistry($connection, $filesystem),
            $bus,
            $config,
        );
    }

    /**
     * @param list<string> $statements
     */
    private function cleanupHandler(int $retentionDays, array &$statements): RepairAuditCleanupTaskHandler
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($retentionDays);

        return new RepairAuditCleanupTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new RepairAuditRepository($connection),
            $config,
            new MediaToolkitLogger(new NullLogger()),
        );
    }
}
