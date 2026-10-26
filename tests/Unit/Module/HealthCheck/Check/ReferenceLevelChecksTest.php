<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\BrokenCmsReferenceCheck;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\OrphanedFileCheck;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use RuntimeException;

/**
 * Die beiden Prüfungen, die von der anderen Seite kommen.
 *
 * Die übrigen gehen vom Datensatz aus und suchen die Datei. Diese beiden drehen die Richtung um:
 * Die eine sucht Dateien ohne Datensatz, die andere Verweise aus Erlebniswelten auf Medien, die
 * es nicht mehr gibt. Damit tragen sie ein Risiko, das die anderen nicht haben — **ein Fehlalarm
 * hier führt zu einer Löschempfehlung für eine Datei, die gebraucht wird.** Die Fälle prüfen
 * deshalb vor allem, unter welchen Umständen die Prüfungen einen Fund *nicht* melden.
 */
#[CoversClass(OrphanedFileCheck::class)]
#[CoversClass(BrokenCmsReferenceCheck::class)]
final class ReferenceLevelChecksTest extends TestCase
{
    /**
     * Was: Eine Datei ohne Datensatz.
     * Warum: Der Zweck der Prüfung. Die Einstufung ist eine Warnung, kein kritischer Fall — die
     *        Datei stört niemanden, sie belegt nur Platz.
     */
    #[Test]
    public function aFileWithoutARecordIsReported(): void
    {
        $check = new OrphanedFileCheck(
            $this->connection(['media/ab/bekannt.jpg']),
            $this->filesystem(['media/ab/bekannt.jpg', 'media/cd/verwaist.jpg']),
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings);
        self::assertSame('media/cd/verwaist.jpg', $findings[0]->urlPath);
        self::assertSame(Finding::SEVERITY_WARNING, $findings[0]->severity);
        self::assertSame('fs:media/cd/verwaist.jpg', $findings[0]->sourceReference);
        self::assertNull($findings[0]->mediaId, 'Zu einer verwaisten Datei gibt es keine Kennung.');
    }

    /**
     * Was: Ein Datensatz, der den Pfad nicht in der eigenen Spalte trägt.
     * Warum: **Der gefährlichste Fehlalarm des ganzen Moduls.** Ältere Bestände speichern nur
     *        Name und Endung; ohne den zweiten Abgleich gälte dort der komplette Medienbestand
     *        als verwaist — und am Ende der Kette steht das Löschen.
     */
    #[Test]
    public function theSecondLookupCatchesRecordsWithoutAPathColumn(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'SELECT path FROM media')) {
                    return [];
                }

                return [['file_name' => 'bild', 'file_extension' => 'jpg']];
            },
        );

        $check = new OrphanedFileCheck($connection, $this->filesystem(['media/bild.jpg']));

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Vorschaubilder werden übergangen.
     * Warum: Zu ihnen gibt es nie einen eigenen Medieneintrag — sie stünden also **alle** auf der
     *        Liste der Verwaisten. Zuständig für sie ist die Prüfung der Vorschaubilder, die von
     *        deren eigener Tabelle ausgeht.
     */
    #[Test]
    public function thumbnailsAreLeftToTheirOwnCheck(): void
    {
        $check = new OrphanedFileCheck(
            $this->connection([]),
            $this->filesystem(['media/ab/thumbnail/bild_400x400.jpg']),
        );

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Der Speicher lässt sich nicht auflisten.
     * Warum: Bei angebundenem Fremdspeicher kommt das vor. Der Lauf endet dann ohne Befunde —
     *        und **nicht** mit der Meldung, alles sei verwaist. Ein Abbruch ist an dieser Stelle
     *        die einzig sichere Antwort.
     */
    #[Test]
    public function anUnreadableStorageEndsTheCheckWithoutFindings(): void
    {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('listContents')->willThrowException(new RuntimeException('Speicher nicht erreichbar'));

        $check = new OrphanedFileCheck($this->connection([]), $filesystem);

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Ein Verweis aus einer Erlebniswelt auf ein Medium, das es nicht mehr gibt.
     * Warum: Im Shop bleibt an der Stelle eine Lücke im Inhalt — ohne Fehlermeldung. Der Befund
     *        nennt sowohl den Baustein als auch die Kennung, sonst wäre die Fundstelle in einer
     *        großen Erlebniswelt nicht zu finden.
     */
    #[Test]
    public function aReferenceToAVanishedMediumIsReported(): void
    {
        $config = '{"media":{"source":"mediaId","value":"aabbccddeeff00112233445566778899"}}';

        $check = new BrokenCmsReferenceCheck($this->cmsConnection([
            ['slot_id' => '11112222333344445555666677778888', 'config' => $config],
        ], existingMediaIds: []));

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings);
        self::assertSame(Finding::SEVERITY_CRITICAL, $findings[0]->severity);
        self::assertSame('aabbccddeeff00112233445566778899', $findings[0]->mediaId);
        self::assertSame(
            'slot:11112222333344445555666677778888:aabbccddeeff00112233445566778899',
            $findings[0]->sourceReference,
        );
    }

    /**
     * Die Gegenprobe: Gibt es das Medium, gibt es keinen Befund. Ohne sie meldete die Prüfung
     * jeden Bildbaustein des Shops.
     */
    #[Test]
    public function anIntactReferenceProducesNoFinding(): void
    {
        $config = '{"media":{"source":"mediaId","value":"aabbccddeeff00112233445566778899"}}';

        $check = new BrokenCmsReferenceCheck($this->cmsConnection(
            [['slot_id' => '1111', 'config' => $config]],
            existingMediaIds: ['aabbccddeeff00112233445566778899'],
        ));

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Kennungen, die keine Medien sind.
     * Warum: In derselben Einstellung stehen auch Kennungen von Produkten, Kategorien und
     *        Regeln. Gezogen wird deshalb nur, was ausdrücklich als Medienangabe gekennzeichnet
     *        ist — sonst meldete die Prüfung reihenweise Verweise, die nie welche waren.
     */
    #[Test]
    public function onlyValuesMarkedAsMediaAreConsidered(): void
    {
        $config = '{"produkt":{"source":"static","value":"99998888777766665555444433332222"},'
            . '"regel":{"source":"ruleId","value":"11112222333344445555666677778888"}}';

        $check = new BrokenCmsReferenceCheck($this->cmsConnection(
            [['slot_id' => '1111', 'config' => $config]],
            existingMediaIds: [],
        ));

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Dieselbe Kennung mehrfach in einem Baustein.
     * Warum: Sie wird einmal gemeldet und einmal nachgeschlagen. Andernfalls stünde ein Bild,
     *        das in einer Einstellung dreimal vorkommt, dreimal im Bericht.
     */
    #[Test]
    public function aRepeatedReferenceIsReportedOnce(): void
    {
        $id = 'aabbccddeeff00112233445566778899';
        $config = sprintf(
            '{"a":{"source":"mediaId","value":"%s"},"b":{"source":"mediaId","value":"%s"}}',
            $id,
            $id,
        );

        $check = new BrokenCmsReferenceCheck($this->cmsConnection(
            [['slot_id' => '1111', 'config' => $config]],
            existingMediaIds: [],
        ));

        self::assertCount(1, iterator_to_array($check->run(), false));
    }

    /**
     * Eine leere Einstellung wird übergangen. Sie kommt bei jedem noch nicht gefüllten Baustein
     * vor und ist kein Fehler.
     */
    #[Test]
    public function anEmptyConfigIsSkipped(): void
    {
        $check = new BrokenCmsReferenceCheck($this->cmsConnection(
            [['slot_id' => '1111', 'config' => '']],
            existingMediaIds: [],
        ));

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Beide Prüfungen nennen ihre Kennung und ihren Schalter.
     */
    #[Test]
    public function bothChecksNameThemselvesAndTheirSetting(): void
    {
        $orphaned = new OrphanedFileCheck($this->connection([]), $this->filesystem([]));
        $reference = new BrokenCmsReferenceCheck($this->createMock(Connection::class));

        self::assertSame('orphaned_file', $orphaned->type());
        self::assertSame('RcMediaToolkit.config.healthCheckOrphanedFiles', $orphaned->configKey());
        self::assertSame('broken_cms_reference', $reference->type());
        self::assertSame('RcMediaToolkit.config.healthCheckBrokenCmsReferences', $reference->configKey());
    }

    /**
     * Was: Ein Speicher mit sehr vielen Dateien.
     * Warum: Der Lauf hört nach einer festen Zahl auf. **Ohne die Grenze liefe eine einzelne
     *        Prüfung über Millionen Dateien**, und der nächtliche Lauf käme nie zum Ende — die
     *        übrigen Prüfungen kämen dann gar nicht mehr dran.
     */
    #[Test]
    public function theOrphanScanStopsAtItsUpperBound(): void
    {
        $dateien = [];
        for ($i = 0; $i < 5_100; ++$i) {
            $dateien[] = sprintf('media/ab/datei%d.jpg', $i);
        }

        $check = new OrphanedFileCheck($this->connection([]), $this->filesystem($dateien));

        $findings = iterator_to_array($check->run(), false);

        self::assertLessThanOrEqual(5_000, count($findings), 'Mehr als die Obergrenze darf nicht geprüft werden.');
        self::assertGreaterThan(0, count($findings));
    }

    /**
     * Die Abfrage geht in Blöcken an die Datenbank, nicht Datei für Datei. Bei fünftausend
     * Dateien wären das sonst fünftausend Abfragen in einem einzigen Prüflauf.
     */
    #[Test]
    public function theLookupHappensInBatches(): void
    {
        $dateien = [];
        for ($i = 0; $i < 450; ++$i) {
            $dateien[] = sprintf('media/ab/datei%d.jpg', $i);
        }

        $abfragen = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use (&$abfragen): array {
                if (str_contains($sql, 'SELECT path FROM media')) {
                    ++$abfragen;
                }

                return [];
            },
        );

        $check = new OrphanedFileCheck($connection, $this->filesystem($dateien));
        iterator_to_array($check->run(), false);

        self::assertGreaterThan(1, $abfragen, 'Bei 450 Dateien muss mehr als ein Block entstehen.');
        self::assertLessThan(10, $abfragen, 'Und deutlich weniger als eine Abfrage je Datei.');
    }

    /**
     * Was: Ein Baustein, dessen Einstellung keine Medienangabe enthält.
     * Warum: Er wird übergangen, ohne nachzuschlagen. Die meisten Bausteine einer Erlebniswelt
     *        enthalten keine Bilder; jede vermiedene Abfrage zählt bei einem Bestand mit
     *        Tausenden davon.
     */
    #[Test]
    public function aBlockWithoutAnyMediaValueIsSkippedWithoutALookup(): void
    {
        $abfragen = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use (&$abfragen): array {
                if (str_contains($sql, 'cms_slot_translation')) {
                    return (int) ($params['offset'] ?? 0) === 0
                        ? [['slot_id' => '1111', 'config' => '{"text":{"source":"static","value":"nur Text"}}']]
                        : [];
                }
                ++$abfragen;

                return [];
            },
        );

        $check = new BrokenCmsReferenceCheck($connection);

        self::assertSame([], iterator_to_array($check->run(), false));
        self::assertSame(0, $abfragen, 'Ohne Medienangabe darf nichts nachgeschlagen werden.');
    }

    /**
     * Was: Dateien außerhalb des Medienbereichs.
     * Warum: Der zweite Abgleich über Name und Endung greift nur unterhalb von `media/`. Was
     *        darüber hinaus im Speicher liegt, gehört nicht zum Medienbestand — und wird
     *        deshalb gar nicht erst nachgeschlagen.
     */
    #[Test]
    public function filesOutsideTheMediaAreaAreNotLookedUpTwice(): void
    {
        $zweiteAbfragen = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use (&$zweiteAbfragen): array {
                if (str_contains($sql, 'file_name IN')) {
                    ++$zweiteAbfragen;
                }

                return [];
            },
        );

        $check = new OrphanedFileCheck($connection, $this->filesystem(['sitemap/eintrag.xml']));

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings, 'Gemeldet wird sie trotzdem — sie hat keinen Datensatz.');
        self::assertSame(0, $zweiteAbfragen, 'Aber der Namensabgleich entfällt.');
    }

    /**
     * Ein leerer Speicher ergibt keinen einzigen Fund und keine einzige Abfrage. Der Zustand
     * ist bei einer frischen Installation der Normalfall.
     */
    #[Test]
    public function anEmptyStorageAsksNothing(): void
    {
        $abfragen = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function () use (&$abfragen): array {
                ++$abfragen;

                return [];
            },
        );

        $check = new OrphanedFileCheck($connection, $this->filesystem([]));

        self::assertSame([], iterator_to_array($check->run(), false));
        self::assertSame(0, $abfragen);
    }

    /**
     * Ohne Medienangabe im Baustein wird auch nicht nachgeschlagen, ob die Kennungen existieren
     * — dieselbe Ersparnis von der anderen Seite.
     */
    #[Test]
    public function anEmptyIdListIsNeverLookedUp(): void
    {
        $check = new BrokenCmsReferenceCheck($this->cmsConnection(
            [['slot_id' => '1111', 'config' => '{"a":{"source":"mediaId","value":"zu-kurz"}}']],
            existingMediaIds: [],
        ));

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * @param list<string> $knownPaths
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connection(array $knownPaths): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use ($knownPaths): array {
                if (!str_contains($sql, 'SELECT path FROM media')) {
                    return [];
                }

                return array_map(static fn (string $path): array => ['path' => $path], $knownPaths);
            },
        );

        return $connection;
    }

    /**
     * @param list<array<string, mixed>> $slots
     * @param list<string>               $existingMediaIds
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function cmsConnection(array $slots, array $existingMediaIds): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($slots, $existingMediaIds): array {
                if (str_contains($sql, 'cms_slot_translation')) {
                    return (int) ($params['offset'] ?? 0) === 0 ? $slots : [];
                }

                $rows = [];
                foreach ($existingMediaIds as $id) {
                    if (in_array($id, array_values($params), true)) {
                        $rows[] = ['id' => $id];
                    }
                }

                return $rows;
            },
        );

        return $connection;
    }

    /**
     * @param list<string> $paths
     *
     * @return FilesystemAdapterInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function filesystem(array $paths): FilesystemAdapterInterface
    {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('listContents')->willReturn($paths);

        return $filesystem;
    }
}
