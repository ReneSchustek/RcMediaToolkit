<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\MediaReferenceUpdater;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapter;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaRepositoryWrapper;
use RuntimeException;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Die drei Schichten unter den Modulen: Speicher, Berichtstabelle, Verweispflege.
 *
 * Sie haben keine eigene Fachlichkeit — sie tragen die der anderen. Genau deshalb sind ihre
 * Fehler die unauffälligsten: Eine Abfrage, die den Filter vergisst, liefert **mehr** Zeilen und
 * sieht nach mehr Arbeit aus; ein Schreibfehler, der verschluckt wird, sieht nach getaner Arbeit
 * aus. Beides fällt erst weit später auf, an einer Stelle, die mit der Ursache nichts zu tun hat.
 */
#[CoversClass(FilesystemAdapter::class)]
#[CoversClass(HealthReportRepository::class)]
#[CoversClass(MediaReferenceUpdater::class)]
#[CoversClass(MediaRepositoryWrapper::class)]
final class StorageAndReportingTest extends TestCase
{
    private const MEDIA_ID = 'aabbccddeeff00112233445566778899';

    private const ERSATZ_ID = '11112222333344445555666677778888';

    /** @var list<array{sql: string, params: array<string, mixed>}> */
    private array $statements = [];

    protected function setUp(): void
    {
        $this->statements = [];
    }

    /**
     * Was: Schreiben, Verschieben und Anlegen bei gestörtem Speicher.
     * Warum: **Alle drei werden zur Ausnahme.** Ein verschluckter Schreibfehler ist der
     *        schlimmste Fall überhaupt: Die Quarantäne hielte ihn für erledigt und entfernte
     *        anschließend das Original — die Sicherungskopie gäbe es aber nicht.
     */
    #[Test]
    public function everyWritingOperationRaisesInsteadOfSwallowing(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('write')->willThrowException(UnableToWriteFile::atLocation('media/ab/bild.jpg'));
        $operator->method('move')->willThrowException(UnableToMoveFile::fromLocationTo('a', 'b'));
        $operator->method('createDirectory')->willThrowException(UnableToCreateDirectory::atLocation('media/neu'));

        $adapter = new FilesystemAdapter($operator);

        foreach ([
            static fn () => $adapter->write('media/ab/bild.jpg', 'inhalt'),
            static fn () => $adapter->move('media/ab/bild.jpg', 'media/cd/bild.jpg'),
            static fn () => $adapter->makeDirectory('media/neu'),
        ] as $vorgang) {
            try {
                $vorgang();
                self::fail('Ein fehlgeschlagener Schreibvorgang muss auffallen.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1); // Der erwartete Abbruch ist eingetreten — das ist die Prüfung.
            }
        }
    }

    /**
     * Die Gegenprobe: Gelingt der Vorgang, kommt er ohne Aufhebens durch — und reicht genau das
     * durch, was er bekommen hat.
     */
    #[Test]
    public function successfulOperationsPassThroughUnchanged(): void
    {
        $geschrieben = [];
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('write')->willReturnCallback(
            static function (string $path, string $contents) use (&$geschrieben): void {
                $geschrieben[$path] = $contents;
            },
        );
        $operator->method('lastModified')->willReturn(1_700_000_000);

        $adapter = new FilesystemAdapter($operator);
        $adapter->write('media/ab/bild.jpg', 'inhalt');

        self::assertSame(['media/ab/bild.jpg' => 'inhalt'], $geschrieben);
        self::assertSame(1_700_000_000, $adapter->lastModified('media/ab/bild.jpg'));
    }

    /**
     * Auch die Frage nach dem Änderungszeitpunkt wird zur Ausnahme, wenn sie scheitert. Sie
     * entscheidet darüber, ob eine bestehende Umwandlung als aktuell gilt — eine stille Null
     * ließe jedes Bild dauerhaft veraltet erscheinen und den Bestand endlos neu umwandeln.
     */
    #[Test]
    public function aFailingTimestampLookupIsNotAnsweredWithZero(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('lastModified')->willThrowException(
            UnableToRetrieveMetadata::lastModified('media/ab/bild.jpg'),
        );

        $this->expectException(RuntimeException::class);

        (new FilesystemAdapter($operator))->lastModified('media/ab/bild.jpg');
    }

    /**
     * Was: Die Liste der offenen Befunde.
     * Warum: Sie ist die Grundlage jeder Ansicht im Verwaltungsfenster. Erledigte Befunde dürfen
     *        nicht darin stehen — sonst wüchse die Liste mit jeder Reparatur weiter, und der
     *        Fortschritt wäre nicht mehr abzulesen.
     */
    #[Test]
    public function onlyUnresolvedFindingsAreListed(): void
    {
        $repository = new HealthReportRepository($this->recordingConnection([$this->findingRow()]));

        $befunde = $repository->listOpenFindings();

        self::assertCount(1, $befunde);
        self::assertStringContainsString('resolved_at IS NULL', $this->statements[0]['sql']);
    }

    /**
     * Der Filter auf eine Prüfungsart wird an die Abfrage weitergereicht und nicht nachträglich
     * aussortiert. Bei sechsstelligen Beständen ist das der Unterschied zwischen einer Ansicht
     * und einer Zeitüberschreitung.
     */
    #[Test]
    public function theCheckTypeFilterReachesTheQuery(): void
    {
        $repository = new HealthReportRepository($this->recordingConnection([]));

        $repository->listOpenFindings('missing_file', 25, 50);

        self::assertStringContainsString('check_type = :checkType', $this->statements[0]['sql']);
        self::assertSame('missing_file', $this->statements[0]['params']['checkType']);
        self::assertSame(25, $this->statements[0]['params']['limit']);
        self::assertSame(50, $this->statements[0]['params']['offset']);
    }

    /**
     * Die Zählung nach Schwere ist die Zahl, die im Verwaltungsfenster ganz oben steht. Sie zählt
     * ebenfalls nur die offenen — eine Zahl, die auch Erledigtes enthielte, stiege für immer.
     */
    #[Test]
    public function theSeverityCountOnlyCountsWhatIsStillOpen(): void
    {
        $connection = $this->recordingConnection([
            ['severity' => 'critical', 'cnt' => 3],
            ['severity' => 'warning', 'cnt' => 7],
        ]);

        $counts = (new HealthReportRepository($connection))->countOpenBySeverity();

        self::assertSame(['critical' => 3, 'warning' => 7], $counts);
        self::assertStringContainsString('resolved_at IS NULL', $this->statements[0]['sql']);
    }

    /**
     * Was: Das Beschneiden des Berichts.
     * Warum: Es trifft **nur Erledigtes**. Ein offener Befund, der nach 90 Tagen verschwände,
     *        wäre ein Schaden, den niemand bemerkt: Das Bild bliebe kaputt, der Bericht sähe
     *        sauber aus.
     */
    #[Test]
    public function pruningNeverTouchesOpenFindings(): void
    {
        $repository = new HealthReportRepository($this->recordingConnection([]));

        $repository->purgeResolvedOlderThan(90);

        self::assertStringContainsString('resolved_at IS NOT NULL', $this->statements[0]['sql']);
    }

    /**
     * Ohne Frist wird nicht beschnitten — dieselbe Zusage wie bei der Prüfspur der Reparatur.
     */
    #[Test]
    public function pruningWithoutARetentionDoesNothing(): void
    {
        $repository = new HealthReportRepository($this->recordingConnection([]));

        self::assertSame(0, $repository->purgeResolvedOlderThan(0));
        self::assertSame([], $this->statements);
    }

    /**
     * Was: Ein Befund wird zweimal gemeldet.
     * Warum: Der Bericht führt ihn **einmal** und schreibt nur den Zeitpunkt fort. Andernfalls
     *        wüchse die Tabelle mit jedem nächtlichen Lauf um denselben Befund, und nach einem
     *        Monat stünde jedes fehlende Bild dreißigmal darin.
     */
    #[Test]
    public function repeatedFindingsAreFoldedIntoOneRow(): void
    {
        $repository = new HealthReportRepository($this->recordingConnection([]));
        $finding = new Finding(
            checkType: 'missing_file',
            severity: Finding::SEVERITY_CRITICAL,
            sourceType: 'media_table',
            mediaId: self::MEDIA_ID,
            sourceReference: 'media:' . self::MEDIA_ID,
            urlPath: 'media/ab/bild.jpg',
            errorDetail: ['reason' => 'file_not_found'],
        );

        $repository->upsert($finding, new DateTimeImmutable('2026-08-12 10:00:00'));
        $repository->upsert($finding, new DateTimeImmutable('2026-08-13 10:00:00'));

        foreach ($this->statements as $statement) {
            self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $statement['sql']);
        }
        self::assertSame(
            $this->statements[0]['params']['dedupKey'],
            $this->statements[1]['params']['dedupKey'],
            'Derselbe Befund muss denselben Schlüssel bekommen.',
        );
    }

    /**
     * Was: Das Umbiegen aller Verweise auf ein anderes Medium.
     * Warum: Es geht über **jede** Spalte, die auf Medien zeigt — die Liste kommt aus der
     *        Datenbank selbst und nicht aus einer gepflegten Aufzählung. Eine übersehene Spalte
     *        hinterließe einen Verweis auf ein Medium, das es nicht mehr gibt.
     */
    #[Test]
    public function rewritingGoesThroughEveryReferencingColumn(): void
    {
        $connection = $this->updaterConnection([
            ['TABLE_NAME' => 'product_media', 'COLUMN_NAME' => 'media_id'],
            ['TABLE_NAME' => 'category', 'COLUMN_NAME' => 'media_id'],
        ]);

        $betroffen = (new MediaReferenceUpdater($connection, new MediaReferenceSources($connection)))
            ->rewriteReferences(self::MEDIA_ID, self::ERSATZ_ID);

        self::assertSame(['product_media.media_id' => 1, 'category.media_id' => 1], $betroffen);
        self::assertCount(2, $this->statements);
    }

    /**
     * Was: Eine Kennung, die keine ist.
     * Warum: Sie ginge in eine Anweisung, die Daten ändert. Geprüft wird das Format, bevor sie
     *        irgendwo landet — und es wird **keine einzige** Anweisung abgesetzt.
     */
    #[Test]
    public function aMalformedIdNeverReachesAnUpdate(): void
    {
        $connection = $this->updaterConnection([]);
        $updater = new MediaReferenceUpdater($connection, new MediaReferenceSources($connection));

        foreach (["'; DROP TABLE media; --", 'zu-kurz', ''] as $unsinn) {
            try {
                $updater->rewriteReferences($unsinn, self::ERSATZ_ID);
                self::fail(sprintf('Die Kennung "%s" hätte abgelehnt werden müssen.', $unsinn));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1); // Der erwartete Abbruch ist eingetreten — das ist die Prüfung.
            }
        }

        self::assertSame([], $this->statements);
    }

    /**
     * Das Umschreiben in einer Erlebniswelt trifft genau einen Baustein. Ohne die Einschränkung
     * auf seine Kennung liefe der Austausch über den gesamten Inhaltsbestand — eine Reparatur,
     * die niemand beauftragt hat.
     */
    #[Test]
    public function theCmsRewriteIsLimitedToOneBlock(): void
    {
        $connection = $this->updaterConnection([]);

        (new MediaReferenceUpdater($connection, new MediaReferenceSources($connection)))
            ->rewriteCmsSlotReference(self::ERSATZ_ID, self::MEDIA_ID, self::ERSATZ_ID);

        self::assertCount(1, $this->statements);
        self::assertStringContainsString('cms_slot_id = UNHEX(:slotId)', $this->statements[0]['sql']);
    }

    /**
     * Was: Ein einzelner Befund über seine Kennung.
     * Warum: Über diesen Weg holen die Reparaturbefehle den Befund, den jemand angibt. Kommt
     *        nichts zurück, ist die Kennung falsch — deshalb muss der Unterschied zwischen
     *        „nicht gefunden" und „leerer Datensatz" sauber sein.
     */
    #[Test]
    public function asingleFindingIsFetchedByItsId(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->findingRow());

        $repository = new HealthReportRepository($connection);

        self::assertSame('missing_file', $repository->findById(self::MEDIA_ID)['check_type'] ?? null);
    }

    /**
     * Die Gegenprobe: Zu einer unbekannten Kennung kommt `null`. Ein leeres Feld wäre für den
     * Aufrufer nicht von einem Befund ohne Angaben zu unterscheiden.
     */
    #[Test]
    public function anUnknownIdAnswersWithNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        self::assertNull((new HealthReportRepository($connection))->findById(self::MEDIA_ID));
    }

    /**
     * Was: Eine Auflistung, die scheitert.
     * Warum: Sie wird zur Ausnahme. Ein leeres Ergebnis hieße „keine Dateien vorhanden" — und
     *        die Prüfung auf verwaiste Dateien machte daraus, dass **alles** in der Datenbank
     *        verwaist ist. Das ist der gefährlichste stille Fehler, den dieser Speicher haben
     *        könnte.
     */
    #[Test]
    public function aFailingListingIsNotAnsweredWithAnEmptyList(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('listContents')->willThrowException(
            UnableToListContents::atLocation('media', true, new RuntimeException('Speicher nicht erreichbar')),
        );

        $this->expectException(RuntimeException::class);

        (new FilesystemAdapter($operator))->listContents('media', true);
    }

    /**
     * Was: Mehrere Medien auf einmal.
     * Warum: Der Weg, den die Module für Sammelzugriffe nehmen. Er stellt **eine** Abfrage,
     *        nicht eine je Kennung — bei einem Bericht mit hundert Befunden ist das der
     *        Unterschied zwischen einer und hundert Abfragen.
     */
    #[Test]
    public function severalMediaAreFetchedInOneQuery(): void
    {
        $collection = new MediaCollection([$this->media('aa'), $this->media('bb')]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'media',
                2,
                $collection,
                null,
                $criteria,
                $context,
            ),
        );

        $gefunden = (new MediaRepositoryWrapper($repository))->findByIds(['aa', 'bb'], Context::createDefaultContext());

        self::assertCount(2, $gefunden);
    }

    private function media(string $id): MediaEntity
    {
        $entity = new MediaEntity();
        $entity->setId($id);
        $entity->setUniqueIdentifier($id);

        return $entity;
    }

    /**
     * @return array<string, mixed>
     */
    private function findingRow(): array
    {
        return [
            'id' => self::MEDIA_ID,
            'media_id' => self::MEDIA_ID,
            'check_type' => 'missing_file',
            'severity' => 'critical',
            'source_type' => 'media_table',
            'source_reference' => 'media:' . self::MEDIA_ID,
            'error_detail' => null,
            'url_path' => 'media/ab/bild.jpg',
            'first_seen_at' => '2026-08-01 10:00:00',
            'last_seen_at' => '2026-08-12 10:00:00',
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function recordingConnection(array $rows): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            function (string $sql, array $params = []) use ($rows): array {
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                return $rows;
            },
        );
        $connection->method('executeStatement')->willReturnCallback(
            /** @param array<string, mixed> $params */
            function (string $sql, array $params = []): int {
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );

        return $connection;
    }

    /**
     * @param list<array{TABLE_NAME: string, COLUMN_NAME: string}> $foreignKeys
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function updaterConnection(array $foreignKeys): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shopware');
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('fetchAllAssociative')->willReturn($foreignKeys);
        $connection->method('executeStatement')->willReturnCallback(
            /** @param array<string, mixed> $params */
            function (string $sql, array $params = []): int {
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );

        return $connection;
    }
}
