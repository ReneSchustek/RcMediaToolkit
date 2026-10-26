<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\BrokenThumbnailCheck;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\MissingFileCheck;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\ZeroByteCheck;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use RuntimeException;

/**
 * Die drei Prüfungen, die Datenbank und Speicher gegeneinanderhalten.
 *
 * Alle drei suchen dasselbe Muster: Der Eintrag steht, die Datei fehlt oder taugt nichts. Im Shop
 * sieht man davon einen leeren Bilderrahmen — **keine Fehlermeldung, keinen Protokolleintrag**.
 * Die Prüfungen sind also die einzige Stelle, an der so etwas überhaupt sichtbar wird, und ein
 * stiller Ausfall in ihnen bleibt für immer stumm.
 *
 * Drei Dinge entscheiden über ihre Brauchbarkeit: die Bildung des Pfades aus dem Datensatz, die
 * saubere Abgrenzung untereinander (kein Fund darf doppelt gemeldet werden) und das Blättern über
 * den Bestand — bricht es zu früh ab, prüft der Lauf nur die ersten Hundert Medien und meldet
 * trotzdem „fertig".
 */
#[CoversClass(MissingFileCheck::class)]
#[CoversClass(ZeroByteCheck::class)]
#[CoversClass(BrokenThumbnailCheck::class)]
final class FileLevelChecksTest extends TestCase
{
    /**
     * Was: Jede Prüfung nennt ihre Kennung und ihren Einstellungsschlüssel.
     * Warum: Die Kennung steht in der Berichtstabelle und im Filter des Aufrufs, der Schlüssel
     *        entscheidet über An und Aus. Ändert sich eine von beiden unbemerkt, liegen die alten
     *        Befunde unter einem Namen, den niemand mehr abfragt — und die Prüfung lässt sich
     *        nicht mehr abschalten.
     */
    #[Test]
    public function everyCheckNamesItselfAndItsSetting(): void
    {
        $missing = new MissingFileCheck($this->createMock(Connection::class), $this->filesystem());
        $zero = new ZeroByteCheck($this->createMock(Connection::class), $this->filesystem());
        $thumbnail = new BrokenThumbnailCheck($this->createMock(Connection::class), $this->filesystem());

        self::assertSame('missing_file', $missing->type());
        self::assertSame('zero_byte', $zero->type());
        self::assertSame('broken_thumbnail', $thumbnail->type());

        self::assertSame('RcMediaToolkit.config.healthCheckMissingFiles', $missing->configKey());
        self::assertSame('RcMediaToolkit.config.healthCheckZeroByte', $zero->configKey());
        self::assertSame('RcMediaToolkit.config.healthCheckBrokenThumbnails', $thumbnail->configKey());
    }

    /**
     * Was: Eine fehlende Datei wird gemeldet, eine vorhandene nicht.
     * Warum: Der eigentliche Zweck. Der zweite Teil ist der wichtigere: Meldete die Prüfung auch
     *        heile Medien, ertränke sie den echten Befund in Tausenden falschen — und niemand
     *        sähe den Bericht je wieder an.
     */
    #[Test]
    public function onlyTheAbsentFileIsReported(): void
    {
        $check = new MissingFileCheck(
            $this->connection([
                ['id' => 'aa', 'file_name' => 'da', 'file_extension' => 'jpg', 'path' => 'media/ab/da.jpg'],
                ['id' => 'bb', 'file_name' => 'weg', 'file_extension' => 'jpg', 'path' => 'media/cd/weg.jpg'],
            ]),
            $this->filesystem(['media/ab/da.jpg' => 100]),
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings);
        self::assertSame('media/cd/weg.jpg', $findings[0]->urlPath);
        self::assertSame(Finding::SEVERITY_CRITICAL, $findings[0]->severity);
        self::assertSame('media:bb', $findings[0]->sourceReference);
    }

    /**
     * Was: Ein Datensatz ohne Pfadangabe.
     * Warum: Ältere Bestände tragen den Pfad nicht in der Spalte, sondern nur Name und Endung.
     *        Ohne den Rückfall meldete die Prüfung dort **jedes** Medium als fehlend — ein
     *        Bericht, der nur aus Fehlalarm besteht.
     */
    #[Test]
    public function anEmptyPathColumnFallsBackToNameAndExtension(): void
    {
        $check = new MissingFileCheck(
            $this->connection([
                ['id' => 'aa', 'file_name' => 'bild', 'file_extension' => 'jpg', 'path' => ''],
            ]),
            $this->filesystem(['media/bild.jpg' => 100]),
        );

        self::assertSame([], iterator_to_array($check->run(), false), 'Der zusammengesetzte Pfad muss gefunden werden.');
    }

    /**
     * Ein Datensatz ganz ohne Dateinamen lässt sich nirgendwo suchen. Er wird übergangen statt
     * gemeldet — ein Befund ohne Pfad wäre für niemanden zu gebrauchen.
     */
    #[Test]
    public function aRecordWithoutAFileNameIsSkipped(): void
    {
        $check = new MissingFileCheck(
            $this->connection([['id' => 'aa', 'file_name' => null, 'file_extension' => null, 'path' => null]]),
            $this->filesystem(),
        );

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Ein voller Block zieht den nächsten nach sich.
     * Warum: **Der gefährlichste stille Ausfall dieser Klassen.** Bräche das Blättern nach dem
     *        ersten Block ab, prüfte der Lauf die ersten 200 Medien, meldete „keine Befunde" und
     *        sähe genauso aus wie ein gesunder Bestand.
     */
    #[Test]
    public function aFullBatchIsFollowedByTheNextOne(): void
    {
        $first = [];
        for ($i = 0; $i < 200; ++$i) {
            $first[] = ['id' => sprintf('%032x', $i), 'file_name' => 'b', 'file_extension' => 'jpg', 'path' => 'media/da.jpg'];
        }

        $offsets = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($first, &$offsets): array {
                $offset = (int) ($params['offset'] ?? 0);
                $offsets[] = $offset;

                return $offset === 0 ? $first : [];
            },
        );

        $check = new MissingFileCheck($connection, $this->filesystem(['media/da.jpg' => 10]));
        iterator_to_array($check->run(), false);

        self::assertSame([0, 200], $offsets, 'Nach einem vollen Block muss der nächste geholt werden.');
    }

    /**
     * Was: Eine Datei ohne Inhalt.
     * Warum: Der tückischere Fall — jede Existenzprüfung ist zufrieden, der Browser bekommt
     *        trotzdem nichts. Gemessen wird am Speicher, nicht an der Größenangabe im Datensatz:
     *        Die stammt vom Hochladen und weiß von einem späteren Abbruch nichts.
     */
    #[Test]
    public function anEmptyFileIsReportedEvenThoughTheRecordClaimsASize(): void
    {
        $check = new ZeroByteCheck(
            $this->connection([
                ['id' => 'aa', 'file_name' => 'leer', 'file_extension' => 'jpg', 'file_size' => 51200, 'path' => 'media/ab/leer.jpg'],
                ['id' => 'bb', 'file_name' => 'heil', 'file_extension' => 'jpg', 'file_size' => 900, 'path' => 'media/ab/heil.jpg'],
            ]),
            $this->filesystem(['media/ab/leer.jpg' => 0, 'media/ab/heil.jpg' => 900]),
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings);
        self::assertSame('media/ab/leer.jpg', $findings[0]->urlPath);
        self::assertSame(['actualSize' => 0, 'declaredSize' => 51200], $findings[0]->errorDetail);
    }

    /**
     * Was: Eine ganz fehlende Datei ist **nicht** der Fall dieser Prüfung.
     * Warum: Sie gehört der Prüfung auf fehlende Dateien. Meldeten beide, stünde jeder Fund
     *        doppelt im Bericht — und die Zahl der Befunde wäre keine Größe mehr, an der sich
     *        ein Fortschritt ablesen lässt.
     */
    #[Test]
    public function anAbsentFileIsLeftToTheOtherCheck(): void
    {
        $check = new ZeroByteCheck(
            $this->connection([
                ['id' => 'aa', 'file_name' => 'weg', 'file_extension' => 'jpg', 'file_size' => 100, 'path' => 'media/ab/weg.jpg'],
            ]),
            $this->filesystem(),
        );

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Der Speicher verweigert die Größenangabe.
     * Warum: Bei angebundenem Fremdspeicher kommt das vor — eine Zeitüberschreitung reicht. Der
     *        Lauf darf daran nicht enden, sonst nimmt eine einzelne klemmende Datei den ganzen
     *        Bericht mit.
     */
    #[Test]
    public function aFailingSizeLookupDoesNotEndTheRun(): void
    {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturn(true);
        $filesystem->method('size')->willReturnCallback(
            static function (string $path): int {
                if ($path === 'media/ab/klemmt.jpg') {
                    throw new RuntimeException('Zeitüberschreitung beim Speicher');
                }

                return 0;
            },
        );

        $check = new ZeroByteCheck(
            $this->connection([
                ['id' => 'aa', 'file_name' => 'a', 'file_extension' => 'jpg', 'file_size' => 1, 'path' => 'media/ab/klemmt.jpg'],
                ['id' => 'bb', 'file_name' => 'b', 'file_extension' => 'jpg', 'file_size' => 1, 'path' => 'media/ab/leer.jpg'],
            ]),
            $filesystem,
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings, 'Die klemmende Datei wird übergangen, die nächste noch geprüft.');
        self::assertSame('media/ab/leer.jpg', $findings[0]->urlPath);
    }

    /**
     * Was: Ein Vorschaubild ohne Datei.
     * Warum: Es wirkt sich anders aus als ein fehlendes Original — die Seite zeigt weiter ein
     *        Bild, nur nicht in jeder Größe. Deshalb ist der Befund eine Warnung und kein
     *        kritischer Fall; die Einstufung entscheidet darüber, was zuerst repariert wird.
     */
    #[Test]
    public function aMissingThumbnailIsAWarningNotACriticalFinding(): void
    {
        $check = new BrokenThumbnailCheck(
            $this->connection([
                ['thumbnail_id' => 'cc', 'media_id' => 'aa', 'width' => 400, 'height' => 400, 'path' => 'media/ab/weg_400.jpg'],
            ]),
            $this->filesystem(),
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings);
        self::assertSame(Finding::SEVERITY_WARNING, $findings[0]->severity);
        self::assertSame('thumbnail:cc', $findings[0]->sourceReference);
        self::assertSame('aa', $findings[0]->mediaId, 'Der Befund muss auf das Medium zeigen, nicht nur auf die Größe.');
        self::assertSame('file_not_found', $findings[0]->errorDetail['reason']);
    }

    /**
     * Was: Ein Eintrag ohne Pfad.
     * Warum: Er ist ebenso kaputt wie eine fehlende Datei — nur aus einem anderen Grund, und der
     *        gehört in den Befund. Ohne die Unterscheidung suchte jemand nach einer Datei, die
     *        nie einen Namen hatte.
     */
    #[Test]
    public function aThumbnailWithoutAPathCarriesItsOwnReason(): void
    {
        $check = new BrokenThumbnailCheck(
            $this->connection([
                ['thumbnail_id' => 'cc', 'media_id' => 'aa', 'width' => 400, 'height' => 400, 'path' => ''],
            ]),
            $this->filesystem(),
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertSame('no_path_in_db', $findings[0]->errorDetail['reason']);
        self::assertNull($findings[0]->urlPath);
    }

    /**
     * Die Gegenprobe für alle drei: Ein heiler Bestand ergibt keinen einzigen Befund. Ohne sie
     * ließe sich nicht unterscheiden, ob eine Prüfung etwas findet oder alles meldet.
     */
    #[Test]
    public function aHealthyStockProducesNoFindings(): void
    {
        $filesystem = $this->filesystem(['media/ab/bild.jpg' => 4096, 'media/ab/bild_400.jpg' => 512]);

        $missing = new MissingFileCheck(
            $this->connection([['id' => 'aa', 'file_name' => 'bild', 'file_extension' => 'jpg', 'path' => 'media/ab/bild.jpg']]),
            $filesystem,
        );
        $zero = new ZeroByteCheck(
            $this->connection([['id' => 'aa', 'file_name' => 'bild', 'file_extension' => 'jpg', 'file_size' => 4096, 'path' => 'media/ab/bild.jpg']]),
            $filesystem,
        );
        $thumbnail = new BrokenThumbnailCheck(
            $this->connection([['thumbnail_id' => 'cc', 'media_id' => 'aa', 'width' => 400, 'height' => 400, 'path' => 'media/ab/bild_400.jpg']]),
            $filesystem,
        );

        self::assertSame([], iterator_to_array($missing->run(), false));
        self::assertSame([], iterator_to_array($zero->run(), false));
        self::assertSame([], iterator_to_array($thumbnail->run(), false));
    }

    /**
     * Was: Ein leerer Datensatz bei der Prüfung auf leere Dateien.
     * Warum: Auch sie muss den Pfad aus Name und Endung bilden können. Ältere Bestände tragen
     *        die Pfadspalte nicht; ohne den Rückfall prüfte sie dort **gar nichts** und meldete
     *        einen sauberen Bestand.
     */
    #[Test]
    public function theEmptyFileCheckAlsoFallsBackToNameAndExtension(): void
    {
        $check = new ZeroByteCheck(
            $this->connection([
                ['id' => 'aa', 'file_name' => 'leer', 'file_extension' => 'jpg', 'file_size' => 10, 'path' => ''],
            ]),
            $this->filesystem(['media/leer.jpg' => 0]),
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings);
        self::assertSame('media/leer.jpg', $findings[0]->urlPath);
    }

    /**
     * Ohne Dateinamen lässt sich auch hier nichts bilden — der Datensatz wird übergangen statt
     * unter einem geratenen Pfad geprüft.
     */
    #[Test]
    public function theEmptyFileCheckSkipsRecordsWithoutAName(): void
    {
        $check = new ZeroByteCheck(
            $this->connection([['id' => 'aa', 'file_name' => null, 'file_extension' => null, 'file_size' => 0, 'path' => null]]),
            $this->filesystem(),
        );

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Ein Datensatz ohne Endung.
     * Warum: Es gibt Medien ohne Endung — der Pfad besteht dann nur aus dem Namen. Ein
     *        angehängter Punkt machte daraus einen Pfad, den es nicht gibt, und die Prüfung
     *        meldete das Medium als fehlend.
     */
    #[Test]
    public function aRecordWithoutAnExtensionKeepsItsBareName(): void
    {
        $check = new MissingFileCheck(
            $this->connection([['id' => 'aa', 'file_name' => 'ohne-endung', 'file_extension' => null, 'path' => '']]),
            $this->filesystem(['media/ohne-endung' => 10]),
        );

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Auch ohne Endung entsteht ein brauchbarer Pfad — Medien ohne Endung gibt es, und ein
     * angehängter Punkt machte daraus einen Pfad, den es nicht gibt.
     */
    #[Test]
    public function theEmptyFileCheckHandlesRecordsWithoutAnExtension(): void
    {
        $check = new ZeroByteCheck(
            $this->connection([
                ['id' => 'aa', 'file_name' => 'ohne-endung', 'file_extension' => null, 'file_size' => 5, 'path' => ''],
            ]),
            $this->filesystem(['media/ohne-endung' => 0]),
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings);
        self::assertSame('media/ohne-endung', $findings[0]->urlPath);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connection(array $rows): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($rows): array {
                return (int) ($params['offset'] ?? 0) === 0 ? $rows : [];
            },
        );

        return $connection;
    }

    /**
     * @param array<string, int> $files Pfad auf Dateigröße
     *
     * @return FilesystemAdapterInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function filesystem(array $files = []): FilesystemAdapterInterface
    {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturnCallback(
            static fn (string $path): bool => isset($files[$path]),
        );
        $filesystem->method('size')->willReturnCallback(
            static fn (string $path): int => $files[$path] ?? 0,
        );

        return $filesystem;
    }
}
