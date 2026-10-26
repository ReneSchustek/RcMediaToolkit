<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\RecordingFilesystem;
use RuntimeException;

/**
 * Der vollständige Weg eines Mediums in die Quarantäne und zurück.
 *
 * Die Quarantäne ist die Zusage, dass das Löschen umkehrbar bleibt. Sie besteht aus einem
 * Verzeichnis, einer Beschreibungsdatei und drei Vorgängen — hineinlegen, zurückholen,
 * endgültig entfernen. Was sie tragfähig macht, ist nicht der einzelne Vorgang, sondern der
 * **Rundlauf**: Was hineingeht, muss unverändert wieder herauskommen.
 *
 * Die zweite Hälfte dieser Fälle betrifft etwas, das man einem Aufräumwerkzeug nicht ansieht:
 * Die Beschreibungsdatei liegt im Dateisystem und ist damit veränderbar. Wer sie ändern kann,
 * bestimmt beim Zurückholen, **wohin** geschrieben wird — deshalb wird jeder Pfad daraus
 * geprüft, bevor er benutzt wird, und nicht nur beim Hineinlegen.
 */
#[CoversClass(MediaQuarantineService::class)]
final class MediaQuarantineLifecycleTest extends TestCase
{
    private const MEDIA_ID = 'aabbccddeeff00112233445566778899';

    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/rc-media-toolkit-q-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/media_quarantine', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    /**
     * Was: Der vollständige Rundlauf.
     * Warum: **Die eigentliche Zusage der Quarantäne.** Ein Bild verschwindet aus dem Shop,
     *        liegt im Wegwerf-Bereich und kommt auf Wunsch byteweise unverändert zurück. Ginge
     *        dabei auch nur ein Zeichen verloren, wäre die Quarantäne ein Löschen mit
     *        Zwischenschritt.
     */
    #[Test]
    public function whatGoesInComesBackUnchanged(): void
    {
        $inhalt = 'Bilddaten mit Umlauten: äöüß und einem Nullbyte:' . chr(0) . 'Ende';
        $filesystem = new RecordingFilesystem(['media/ab/eins.jpg' => $inhalt]);
        $service = $this->service($filesystem);

        $runId = $service->createRun();
        $service->quarantine($runId, $this->candidate(self::MEDIA_ID, 'eins'));

        self::assertFalse($filesystem->exists('media/ab/eins.jpg'), 'Aus dem Shop ist das Bild verschwunden.');

        $restored = $service->restore($runId);

        self::assertSame(1, $restored);
        self::assertSame($inhalt, $filesystem->read('media/ab/eins.jpg'));
    }

    /**
     * Was: Das Zurückholen eines einzelnen Mediums aus einem Lauf mit mehreren.
     * Warum: Ein Lauf umfasst alles, was eine Nacht gefunden hat; zurückgeholt wird meist nur
     *        das eine Bild, das jemand vermisst. Die übrigen müssen dabei **im Lauf bleiben** —
     *        sonst zöge das Zurückholen eines Bildes den ganzen Aufräumvorgang zurück.
     */
    #[Test]
    public function restoringOneMediumLeavesTheOthersInQuarantine(): void
    {
        $filesystem = new RecordingFilesystem([
            'media/ab/eins.jpg' => 'eins',
            'media/ab/zwei.jpg' => 'zwei',
        ]);
        $service = $this->service($filesystem);

        $runId = $service->createRun();
        $service->quarantine($runId, $this->candidate(self::MEDIA_ID, 'eins'));
        $service->quarantine($runId, $this->candidate(str_repeat('b', 32), 'zwei'));

        $restored = $service->restore($runId, self::MEDIA_ID);

        self::assertSame(1, $restored);
        self::assertTrue($filesystem->exists('media/ab/eins.jpg'));
        self::assertFalse($filesystem->exists('media/ab/zwei.jpg'), 'Das zweite Bild bleibt in der Quarantäne.');
        self::assertCount(1, $service->loadManifest($runId), 'Und es steht weiter in der Beschreibung.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeRunIds(): array
    {
        return [
            'Verzeichniswechsel' => ['../../etc'],
            'einfacher Name' => ['irgendwas'],
            'leer' => [''],
            'fast richtig' => ['20260812-180000-XYZ'],
            'mit Schrägstrich' => ['20260812-180000-aabbccdd/../..'],
        ];
    }

    /**
     * Was: Eine Lauf-Kennung, die nicht dem Schema entspricht.
     * Warum: **Aus ihr wird ein Verzeichnispfad.** Ohne die Prüfung könnte eine Kennung mit
     *        `../` auf ein beliebiges Verzeichnis zeigen — und der Vorgang, der endgültig
     *        entfernt, löschte dort. Geprüft wird deshalb an jedem Eingang, nicht nur an einem.
     */
    #[Test]
    #[DataProvider('unsafeRunIds')]
    public function everyEntranceRejectsAnUnsafeRunId(string $runId): void
    {
        $service = $this->service(new RecordingFilesystem([]));

        foreach (['restore', 'purge', 'loadManifest'] as $vorgang) {
            try {
                $service->{$vorgang}($runId);
                self::fail(sprintf('%s hätte "%s" ablehnen müssen.', $vorgang, $runId));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1); // Der erwartete Abbruch ist eingetreten — das ist die Prüfung.
            }
        }
    }

    /**
     * Was: Eine Beschreibungsdatei, die auf einen Pfad außerhalb des Laufs zeigt.
     * Warum: Sie liegt im Dateisystem und ist veränderbar. Beim Zurückholen entscheidet sie,
     *        **woher** gelesen und **wohin** geschrieben wird; ohne Prüfung ließe sich damit eine
     *        beliebige Datei des Systems in den öffentlichen Medienbereich kopieren. Der Eintrag
     *        wird übergangen, der Rest des Laufs läuft weiter.
     */
    #[Test]
    public function aTamperedManifestEntryIsSkippedInsteadOfFollowed(): void
    {
        $filesystem = new RecordingFilesystem([]);
        $service = $this->service($filesystem);

        // Beide Ziele werden wirklich angelegt: Ein Fall, in dem die Datei ohnehin fehlt, würde
        // den Waechter gar nicht erreichen und wäre als Nachweis wertlos.
        file_put_contents($this->projectDir . '/fremde-datei.txt', 'Inhalt außerhalb des Laufs');

        $runId = $this->handWrittenRun([
            $this->manifestItem(quarantinedPath: 'var/media_quarantine/../../fremde-datei.txt'),
            $this->manifestItem(mediaId: str_repeat('b', 32), originalPath: '../../public/shell.php'),
        ]);
        file_put_contents(
            $this->projectDir . '/var/media_quarantine/' . $runId . '/fehlt.jpg',
            'harmlos',
        );

        $restored = $service->restore($runId);

        self::assertSame(0, $restored);
        self::assertSame([], $filesystem->written, 'Es darf nichts geschrieben worden sein.');
        self::assertFileExists(
            $this->projectDir . '/fremde-datei.txt',
            'Und die fremde Datei bleibt unangetastet.',
        );
    }

    /**
     * Was: Ein Eintrag, dessen Datei fehlt.
     * Warum: Er wird übergangen und nicht mitgezählt. Der Fall entsteht, wenn jemand im
     *        Wegwerf-Bereich aufgeräumt hat; die Zahl der zurückgeholten Medien muss dann
     *        kleiner sein — sonst meldete der Vorgang einen Erfolg, den es nicht gab.
     */
    #[Test]
    public function aMissingQuarantineFileIsNotCountedAsRestored(): void
    {
        $filesystem = new RecordingFilesystem([]);
        $service = $this->service($filesystem);

        $runId = $this->handWrittenRun([$this->manifestItem()]);

        self::assertSame(0, $service->restore($runId));
    }

    /**
     * Was: Das endgültige Entfernen.
     * Warum: Es meldet, wie viele Medien dabei verloren gehen, und räumt das Verzeichnis
     *        vollständig ab. Bliebe ein Rest liegen, füllte sich der Datenträger genau dort, wo
     *        aufgeräumt werden sollte.
     */
    #[Test]
    public function purgingRemovesTheRunAndReportsItsSize(): void
    {
        $service = $this->service(new RecordingFilesystem([]));
        $runId = $this->handWrittenRun([$this->manifestItem(), $this->manifestItem(mediaId: str_repeat('b', 32))]);

        $anzahl = $service->purge($runId);

        self::assertSame(2, $anzahl);
        self::assertDirectoryDoesNotExist($this->projectDir . '/var/media_quarantine/' . $runId);
    }

    /**
     * Was: Das Entfernen nach Alter.
     * Warum: Es trifft **nur** Läufe, die älter sind als die Frist. Der Fall stellt zwei Läufe
     *        nebeneinander, einen alten und einen von heute; verwechselte der Vorgang die
     *        Richtung des Vergleichs, verschwände genau der Lauf, den jemand noch braucht.
     */
    #[Test]
    public function purgingByAgeSparesTheRecentRun(): void
    {
        $service = $this->service(new RecordingFilesystem([]));

        $alt = $this->handWrittenRun([$this->manifestItem()], createdAt: '-90 days');
        $neu = $this->handWrittenRun([$this->manifestItem()], createdAt: 'now');

        $entfernt = $service->purgeOlderThan(30);

        self::assertArrayHasKey($alt, $entfernt);
        self::assertArrayNotHasKey($neu, $entfernt);
        self::assertDirectoryDoesNotExist($this->projectDir . '/var/media_quarantine/' . $alt);
        self::assertDirectoryExists($this->projectDir . '/var/media_quarantine/' . $neu);
    }

    /**
     * Ein Lauf ohne Anlagedatum in der Beschreibung wird nicht entfernt. Ohne Datum lässt sich
     * sein Alter nicht beurteilen — und im Zweifel bleibt er liegen, statt verloren zu gehen.
     */
    #[Test]
    public function aRunWithoutACreationDateSurvives(): void
    {
        $service = $this->service(new RecordingFilesystem([]));
        $runId = $this->handWrittenRun([$this->manifestItem()], createdAt: null);

        self::assertSame([], $service->purgeOlderThan(0));
        self::assertDirectoryExists($this->projectDir . '/var/media_quarantine/' . $runId);
    }

    /**
     * Die Übersicht nennt nur, was auch dem Schema entspricht. Ein von Hand angelegtes
     * Verzeichnis daneben taucht nicht auf — sonst führte ein verirrter Ordner dazu, dass die
     * Übersicht auf etwas zeigt, das kein Lauf ist.
     */
    #[Test]
    public function theListingIgnoresDirectoriesThatAreNoRuns(): void
    {
        $service = $this->service(new RecordingFilesystem([]));
        $runId = $this->handWrittenRun([$this->manifestItem()]);
        mkdir($this->projectDir . '/var/media_quarantine/irgendwas-anderes');

        self::assertSame([$runId], $service->listRuns());
    }

    /**
     * Was: Ein Kandidat ohne Medienkennung.
     * Warum: Er stammt aus dem Dateisystem und hat keinen Datensatz. Ohne Kennung ließe sich
     *        weder die Beschreibung führen noch später zurückholen — deshalb wird er abgelehnt
     *        statt halb verarbeitet.
     */
    #[Test]
    public function aCandidateWithoutAMediaIdIsRefused(): void
    {
        $service = $this->service(new RecordingFilesystem([]));
        $runId = $service->createRun();

        $this->expectException(InvalidArgumentException::class);

        $service->quarantine($runId, new OrphanedMedia(
            mediaId: null,
            path: 'media/ab/verwaist.jpg',
            size: 100,
            createdAt: new DateTimeImmutable('2020-01-01'),
            fileName: 'verwaist.jpg',
            sourceType: OrphanedMedia::SOURCE_FS_ORPHAN,
        ));
    }

    /**
     * Was: Der Datensatz ist zwischen Suche und Verschieben verschwunden.
     * Warum: Zwischen beidem können Minuten liegen; jemand kann inzwischen aufgeräumt haben.
     *        Dann gibt es nichts zu verschieben — und vor allem darf die Datei **nicht**
     *        angefasst werden, denn ohne Datensatz wäre sie nicht mehr zuzuordnen.
     */
    #[Test]
    public function aVanishedRecordStopsTheMoveWithoutTouchingTheFile(): void
    {
        $filesystem = new RecordingFilesystem(['media/ab/eins.jpg' => 'x']);
        $service = $this->serviceWithoutRecords($filesystem);

        $runId = $service->createRun();
        $service->quarantine($runId, $this->candidate(self::MEDIA_ID, 'eins'));

        self::assertSame([], $filesystem->deleted);
        self::assertSame([], $service->loadManifest($runId));
    }

    /**
     * Was: Der Datensatz steht, die Datei fehlt.
     * Warum: **Auch hier bleibt der Datensatz stehen.** Löschte der Dienst die Zeilen an
     *        dieser Stelle, käme gar nichts in die Quarantäne — von diesem Medium bliebe
     *        nichts, nicht einmal ein Eintrag im Manifest. So wird es vermerkt und wandert
     *        mit dem Lauf in die Frist; endgültig
     *        gelöscht wird beim Ausräumen. Dass es im nächsten Durchlauf nicht wieder hochkommt,
     *        hält der Sucher fest, nicht dieser Dienst.
     */
    #[Test]
    public function aRecordWithoutItsFileIsNotedInsteadOfDeleted(): void
    {
        $filesystem = new RecordingFilesystem([]);
        $service = $this->service($filesystem);

        $runId = $service->createRun();
        $service->quarantine($runId, $this->candidate(self::MEDIA_ID, 'eins'));

        self::assertSame([], $filesystem->deleted, 'Es gab keine Datei zu entfernen.');

        $manifest = $service->loadManifest($runId);
        self::assertCount(1, $manifest, 'Der Vorgang gehört ins Manifest, auch ohne Datei.');
        self::assertSame('file-missing', $manifest[0]['status']);
        self::assertNull($manifest[0]['quarantinedPath'], 'Es liegt keine Kopie — also auch kein Pfad.');
    }

    /**
     * Was: Ein Lauf, dessen Beschreibungsdatei fehlt.
     * Warum: Er ergibt eine leere Liste statt eines Fehlers. Der Zustand entsteht, wenn jemand
     *        im Wegwerf-Bereich von Hand aufgeräumt hat — dann ist nichts mehr zurückzuholen,
     *        aber auch nichts kaputt.
     */
    #[Test]
    public function aRunWithoutAManifestIsEmptyRatherThanBroken(): void
    {
        $service = $this->service(new RecordingFilesystem([]));

        $runId = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        mkdir($this->projectDir . '/var/media_quarantine/' . $runId, 0o777, true);

        self::assertSame([], $service->loadManifest($runId));
        self::assertSame(0, $service->restore($runId));
    }

    /**
     * Eine unlesbare Beschreibungsdatei führt zum selben Ergebnis: leere Liste, kein Absturz.
     * Sie kann von einem abgebrochenen Schreibvorgang stammen.
     */
    #[Test]
    public function anUnreadableManifestIsTreatedAsEmpty(): void
    {
        $service = $this->service(new RecordingFilesystem([]));

        $runId = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $verzeichnis = $this->projectDir . '/var/media_quarantine/' . $runId;
        mkdir($verzeichnis, 0o777, true);
        file_put_contents($verzeichnis . '/manifest.json', '{kein gültiges JSON');

        self::assertSame([], $service->loadManifest($runId));
    }

    /**
     * Was: Ein Lauf mit unbrauchbarem Anlagedatum.
     * Warum: Er bleibt beim Entfernen nach Alter liegen. Ein unlesbares Datum darf nicht als
     *        „sehr alt" ausgelegt werden — sonst nähme die erste Aufräumrunde genau den Lauf
     *        mit, dessen Beschreibung ohnehin schon beschädigt ist.
     */
    #[Test]
    public function anUnparsableCreationDateProtectsTheRun(): void
    {
        $service = $this->service(new RecordingFilesystem([]));

        $runId = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $verzeichnis = $this->projectDir . '/var/media_quarantine/' . $runId;
        mkdir($verzeichnis, 0o777, true);
        file_put_contents(
            $verzeichnis . '/manifest.json',
            json_encode(['runId' => $runId, 'createdAt' => 'kein Datum', 'items' => []]),
        );

        self::assertSame([], $service->purgeOlderThan(0));
        self::assertDirectoryExists($verzeichnis);
    }

    /**
     * Was: Ein Eintrag, der bereits zurückgeholt wurde.
     * Warum: Er wird übergangen. Ohne diese Unterscheidung schriebe ein zweiter Aufruf dieselbe
     *        Datei ein zweites Mal — und überschriebe dabei möglicherweise eine inzwischen
     *        neu hochgeladene Fassung.
     */
    #[Test]
    public function anAlreadyRestoredEntryIsSkipped(): void
    {
        $filesystem = new RecordingFilesystem([]);
        $service = $this->service($filesystem);

        $runId = $this->handWrittenRun([
            ['mediaId' => self::MEDIA_ID, 'fileName' => 'bild', 'fileExtension' => 'jpg',
                'originalPath' => 'media/ab/bild.jpg',
                'quarantinedPath' => 'PLATZHALTER',
                'quarantinedAt' => date(DateTimeInterface::ATOM), 'size' => 100, 'status' => 'restored'],
        ]);

        self::assertSame(0, $service->restore($runId));
        self::assertSame([], $filesystem->written);
    }

    /**
     * Was: Das Zurückholen eines Mediums, das in diesem Lauf nicht liegt.
     * Warum: Es geschieht nichts, und der Lauf bleibt unverändert. Andernfalls würde eine
     *        vertippte Kennung den gesamten Lauf leeren, ohne eine einzige Datei
     *        zurückzuholen.
     */
    #[Test]
    public function restoringAnUnrelatedMediumChangesNothing(): void
    {
        $filesystem = new RecordingFilesystem([]);
        $service = $this->service($filesystem);

        $runId = $this->handWrittenRun([$this->manifestItem()]);

        self::assertSame(0, $service->restore($runId, str_repeat('c', 32)));
        self::assertCount(1, $service->loadManifest($runId), 'Der Eintrag bleibt stehen.');
    }

    /**
     * Eine Medienkennung im falschen Format wird abgewiesen, bevor irgendetwas geschieht — sie
     * geht sonst in einen Vergleich, dessen Ergebnis niemand vorhersagen kann.
     */
    #[Test]
    public function aMalformedMediaIdIsRefused(): void
    {
        $service = $this->service(new RecordingFilesystem([]));
        $runId = $this->handWrittenRun([$this->manifestItem()]);

        $this->expectException(InvalidArgumentException::class);

        $service->restore($runId, 'keine-kennung');
    }

    /**
     * Was: Ein Lauf, in den sich nicht schreiben lässt.
     * Warum: Er endet mit einer Ausnahme, **bevor** eine Datei aus dem Shop entfernt wird. Der
     *        Fall entsteht bei vollem Datenträger; würde er verschluckt, verschwände das
     *        Original ohne Sicherungskopie.
     */
    #[Test]
    #[WithoutErrorHandler]
    public function anUnwritableQuarantineStopsBeforeAnythingIsRemoved(): void
    {
        $filesystem = new RecordingFilesystem(['media/ab/eins.jpg' => 'x']);

        // Das Ablageverzeichnis ist eine Datei — darunter lässt sich nichts anlegen.
        $blockiert = $this->projectDir . '/blockiert';
        file_put_contents($blockiert, 'keine Ablage');

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::MEDIA_ID,
            'file_name' => 'eins',
            'file_extension' => 'jpg',
            'path' => 'media/ab/eins.jpg',
        ]);

        $service = new MediaQuarantineService(
            $connection,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $blockiert,
        );

        try {
            @$service->createRun();
            self::fail('Ohne Ablage darf kein Lauf entstehen.');
        } catch (RuntimeException) {
            self::assertSame([], $filesystem->deleted);
        }
    }

    /**
     * Ein Dienst, dessen Datenbank zu keiner Kennung mehr einen Datensatz kennt.
     */
    private function serviceWithoutRecords(RecordingFilesystem $filesystem): MediaQuarantineService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        return new MediaQuarantineService(
            $connection,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $this->projectDir,
        );
    }

    /**
     * Was: Der Quarantänebereich existiert noch gar nicht.
     * Warum: Der Zustand jeder frischen Installation. Die Übersicht antwortet mit einer leeren
     *        Liste, statt über ein fehlendes Verzeichnis zu stolpern — und das Verwaltungsfenster
     *        zeigt „nichts in Quarantäne" statt einer Fehlermeldung.
     */
    #[Test]
    public function anAbsentQuarantineAreaIsSimplyEmpty(): void
    {
        $frisch = sys_get_temp_dir() . '/rc-media-toolkit-leer-' . bin2hex(random_bytes(6));
        mkdir($frisch, 0o777, true);

        try {
            $service = new MediaQuarantineService(
                $this->createMock(Connection::class),
                new RecordingFilesystem([]),
                new MediaToolkitLogger(new NullLogger()),
                $frisch,
            );

            self::assertSame([], $service->listRuns());
            self::assertSame([], $service->purgeOlderThan(30));
        } finally {
            rmdir($frisch);
        }
    }

    /**
     * Was: Ein Medium, dessen Datensatz die Pfadspalte nicht führt.
     * Warum: Ältere Bestände tragen nur Name und Endung. Ohne den Rückfall fände die Quarantäne
     *        die Datei nicht — und entfernte den Datensatz, **ohne** eine Sicherungskopie
     *        anzulegen. Das wäre ein Löschen ohne Rückweg.
     */
    #[Test]
    public function aRecordWithoutThePathColumnIsStillFound(): void
    {
        $filesystem = new RecordingFilesystem(['media/eins.jpg' => 'Bildinhalt']);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::MEDIA_ID,
            'file_name' => 'eins',
            'file_extension' => 'jpg',
            'path' => '',
        ]);

        $service = new MediaQuarantineService(
            $connection,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $this->projectDir,
        );

        $runId = $service->createRun();
        $service->quarantine($runId, $this->candidate(self::MEDIA_ID, 'eins'));

        self::assertSame(['media/eins.jpg'], $filesystem->deleted);
        self::assertCount(1, $service->loadManifest($runId));
    }

    /**
     * Ein Datensatz ganz ohne Namen lässt sich nirgends suchen. Dann gilt die Datei als fehlend,
     * und nur der Datensatz wird entfernt — genau der Fall, für den es den Aufräumer gibt.
     */
    #[Test]
    public function aRecordWithoutAnyNameCountsAsFileless(): void
    {
        $filesystem = new RecordingFilesystem([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::MEDIA_ID,
            'file_name' => 'eins',
            'file_extension' => null,
            'path' => null,
        ]);

        $service = new MediaQuarantineService(
            $connection,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $this->projectDir,
        );

        $runId = $service->createRun();
        $service->quarantine($runId, $this->candidate(self::MEDIA_ID, 'eins'));

        self::assertSame([], $filesystem->deleted);

        $manifest = $service->loadManifest($runId);
        self::assertCount(1, $manifest, 'Auch ohne auflösbaren Pfad bleibt der Vorgang festgehalten.');
        self::assertSame('file-missing', $manifest[0]['status']);
    }

    /**
     * Was: Ein Lauf mit Unterverzeichnissen.
     * Warum: Das endgültige Entfernen räumt **vollständig** ab. Bliebe ein Unterverzeichnis
     *        liegen, füllte sich der Datenträger genau dort weiter, wo aufgeräumt werden sollte
     *        — und der Lauf sähe in der Übersicht weiter aus wie ein vorhandener.
     */
    #[Test]
    public function purgingClearsNestedDirectoriesToo(): void
    {
        $service = $this->service(new RecordingFilesystem([]));
        $runId = $this->handWrittenRun([$this->manifestItem()]);

        $verzeichnis = $this->projectDir . '/var/media_quarantine/' . $runId;
        mkdir($verzeichnis . '/tiefer/noch-tiefer', 0o777, true);
        file_put_contents($verzeichnis . '/tiefer/noch-tiefer/datei.bin', 'x');

        $service->purge($runId);

        self::assertDirectoryDoesNotExist($verzeichnis);
    }

    /**
     * Was: Einlagern und danach die abgesetzten Datenbankbefehle ansehen.
     * Warum: **Der Kern dieses Vorgangs.** Verschwänden hier die `media`-Zeilen, käme beim
     *        Wiederherstellen nur die Datei zurück — das Medium wäre für Shopware weg, die
     *        Zählung meldete trotzdem Erfolg. Eine Quarantäne, die ihr Versprechen nur
     *        halb einlöst, ist gefährlicher als sofortiges Löschen: Beim Löschen weiß man,
     *        woran man ist.
     */
    #[Test]
    public function quarantiningDeletesNoDatabaseRows(): void
    {
        $befehle = [];
        $service = $this->serviceRecordingStatements($befehle, new RecordingFilesystem([
            'media/ab/eins.jpg' => 'inhalt',
        ]));

        $runId = $service->createRun();
        $service->quarantine($runId, $this->candidate(self::MEDIA_ID, 'eins'));

        self::assertSame([], array_values(array_filter(
            $befehle,
            static fn (string $sql): bool => str_contains($sql, 'DELETE FROM media'),
        )), 'Beim Einlagern wird nichts gelöscht.');

        self::assertCount(1, array_filter(
            $befehle,
            static fn (string $sql): bool => str_contains($sql, 'rc_media_quarantine'),
        ), 'Stattdessen wird das Medium vermerkt.');
    }

    /**
     * Was: Das endgültige Ausräumen eines Laufs.
     * Warum: Hier — und nur hier — verschwinden die Zeilen. Das ist der Zeitpunkt, an dem die
     *        Frist abgelaufen ist und niemand widersprochen hat. Ohne diesen Schritt bliebe
     *        jedes eingelagerte Medium für immer in der Datenbank stehen, und der Aufräumer
     *        räumte nichts mehr auf.
     */
    #[Test]
    public function purgingIsWhereTheRowsFinallyGo(): void
    {
        $befehle = [];
        $service = $this->serviceRecordingStatements($befehle, new RecordingFilesystem([
            'media/ab/eins.jpg' => 'inhalt',
        ]));

        $runId = $service->createRun();
        $service->quarantine($runId, $this->candidate(self::MEDIA_ID, 'eins'));
        $befehle = [];

        $service->purge($runId);

        self::assertCount(1, array_filter(
            $befehle,
            static fn (string $sql): bool => str_contains($sql, 'DELETE FROM media WHERE'),
        ), 'Der Datensatz geht erst beim Ausräumen.');
        self::assertCount(1, array_filter(
            $befehle,
            static fn (string $sql): bool => str_contains($sql, 'DELETE FROM media_thumbnail'),
        ), 'Und seine Vorschaubilder mit ihm.');
    }

    /**
     * Was: Wiederherstellen.
     * Warum: Ist die Datei zurück, muss auch der Vermerk weg. Bliebe er stehen, übergäbe der
     *        Sucher das Medium beim nächsten Durchlauf — es wäre zurückgeholt und würde nie
     *        wieder aufgeräumt.
     */
    #[Test]
    public function restoringTakesTheNoteBack(): void
    {
        $befehle = [];
        $service = $this->serviceRecordingStatements($befehle, new RecordingFilesystem([
            'media/ab/eins.jpg' => 'inhalt',
        ]));

        $runId = $service->createRun();
        $service->quarantine($runId, $this->candidate(self::MEDIA_ID, 'eins'));
        $befehle = [];

        self::assertSame(1, $service->restore($runId));
        self::assertCount(1, array_filter(
            $befehle,
            static fn (string $sql): bool => str_contains($sql, 'DELETE FROM rc_media_quarantine'),
        ));
    }

    /**
     * Ein Dienst, der jeden abgesetzten Datenbankbefehl in `$befehle` legt.
     *
     * @param list<string> $befehle
     */
    private function serviceRecordingStatements(array &$befehle, RecordingFilesystem $filesystem): MediaQuarantineService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []): array|false {
                $id = $params['id'] ?? null;
                if (!is_string($id)) {
                    return false;
                }

                return [
                    'id' => $id,
                    'file_name' => 'bild',
                    'file_extension' => 'jpg',
                    'path' => 'media/ab/eins.jpg',
                ];
            },
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$befehle): int {
                $befehle[] = $sql;

                return 1;
            },
        );

        return new MediaQuarantineService(
            $connection,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $this->projectDir,
        );
    }

    private function service(RecordingFilesystem $filesystem): MediaQuarantineService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []): array|false {
                $id = $params['id'] ?? null;
                if (!is_string($id)) {
                    return false;
                }

                return [
                    'id' => $id,
                    'file_name' => 'bild',
                    'file_extension' => 'jpg',
                    'path' => 'media/ab/' . ($id === self::MEDIA_ID ? 'eins' : 'zwei') . '.jpg',
                ];
            },
        );

        return new MediaQuarantineService(
            $connection,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $this->projectDir,
        );
    }

    private function candidate(string $mediaId = self::MEDIA_ID, string $name = 'bild'): OrphanedMedia
    {
        return new OrphanedMedia(
            mediaId: $mediaId,
            path: 'media/ab/' . $name . '.jpg',
            size: 100,
            createdAt: new DateTimeImmutable('2020-01-01'),
            fileName: $name . '.jpg',
            sourceType: OrphanedMedia::SOURCE_DB_ORPHAN,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestItem(
        string $mediaId = self::MEDIA_ID,
        ?string $quarantinedPath = null,
        string $originalPath = 'media/ab/bild.jpg',
    ): array {
        return [
            'mediaId' => $mediaId,
            'fileName' => 'bild',
            'fileExtension' => 'jpg',
            'originalPath' => $originalPath,
            'quarantinedPath' => $quarantinedPath ?? 'PLATZHALTER',
            'quarantinedAt' => date(DateTimeInterface::ATOM),
            'size' => 100,
            'status' => 'quarantined',
        ];
    }

    /**
     * Legt einen Lauf von Hand an — der Weg, auf dem sich eine veränderte Beschreibungsdatei
     * nachstellen lässt.
     *
     * @param list<array<string, mixed>> $items
     */
    private function handWrittenRun(array $items, ?string $createdAt = 'now'): string
    {
        $runId = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd-His')
            . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $verzeichnis = $this->projectDir . '/var/media_quarantine/' . $runId;
        mkdir($verzeichnis, 0o777, true);

        foreach ($items as $index => $item) {
            if ($item['quarantinedPath'] === 'PLATZHALTER') {
                $items[$index]['quarantinedPath'] = 'var/media_quarantine/' . $runId . '/fehlt.jpg';
            }
        }

        $manifest = ['runId' => $runId, 'items' => array_values($items)];
        if ($createdAt !== null) {
            $manifest['createdAt'] = (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))
                ->format(DateTimeInterface::ATOM);
        }

        file_put_contents($verzeichnis . '/manifest.json', json_encode($manifest, \JSON_PRETTY_PRINT));

        return $runId;
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
