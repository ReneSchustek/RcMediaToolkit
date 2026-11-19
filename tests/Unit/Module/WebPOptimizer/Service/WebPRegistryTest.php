<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPRegistry;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;

/**
 * Der Bestandsüberblick des WebP-Moduls: was ist umgewandelt, was fehlt noch.
 *
 * Die Klasse führt keine eigene Tabelle, sondern liest den Zustand jedes Mal aus dem Speicher.
 * Das ist der richtige Zuschnitt — eine eigene Tabelle liefe irgendwann auseinander —, verlagert
 * die Genauigkeit aber vollständig auf zwei Entscheidungen: **welche Dateien überhaupt in Frage
 * kommen** und **unter welchem Namen die Variante gesucht wird**. Beides ist von außen nicht zu
 * sehen: Eine falsche Antwort ergibt eine Zahl, die plausibel aussieht.
 */
#[CoversClass(WebPRegistry::class)]
final class WebPRegistryTest extends TestCase
{
    /**
     * Was: Nur Bildformate zählen als umwandelbar.
     * Warum: Im Medienbestand liegen auch PDF-Dateien, Videos und Zeichnungen. Zählte die
     *        Übersicht sie mit, meldete sie dauerhaft „noch nicht umgewandelt" für Dateien, die
     *        nie umgewandelt werden — und der Bestand sähe nie fertig aus.
     */
    #[Test]
    public function onlyImageFormatsCountAsConvertible(): void
    {
        $registry = new WebPRegistry(
            $this->connection(total: 5, rows: [
                $this->row('media/a/bild.jpg', 'jpg'),
                $this->row('media/a/foto.jpeg', 'jpeg'),
                $this->row('media/a/logo.png', 'png'),
                $this->row('media/a/anleitung.pdf', 'pdf'),
                $this->row('media/a/film.mp4', 'mp4'),
            ]),
            $this->filesystem([]),
        );

        $stats = $registry->stats();

        self::assertSame(3, $stats['eligible'], 'Nur die drei Bilder kommen in Frage.');
        self::assertSame(5, $stats['total'], 'Der Gesamtbestand zählt trotzdem alles.');
    }

    /**
     * Was: Die Schreibweise der Endung.
     * Warum: Im Bestand steht sie mal `JPG`, mal `jpg` — je nachdem, wie die Datei hochgeladen
     *        wurde. Ohne Vereinheitlichung fiele ein Teil der Bilder aus der Übersicht heraus,
     *        und zwar genau der Teil, der aus Kameras kommt.
     */
    #[Test]
    public function theExtensionIsComparedRegardlessOfCase(): void
    {
        $registry = new WebPRegistry(
            $this->connection(total: 2, rows: [
                $this->row('media/a/bild.JPG', 'JPG'),
                $this->row('media/a/logo.PNG', 'PNG'),
            ]),
            $this->filesystem([]),
        );

        self::assertSame(2, $registry->stats()['eligible']);
    }

    /**
     * Was: Die Variante wird als `<pfad>.webp` gesucht.
     * Warum: **Genau diese Namensbildung ist im Modul zweimal falsch gewesen.** Suchte die
     *        Übersicht unter einem anderen Namen, meldete sie dauerhaft „nichts umgewandelt",
     *        während der Speicher voller Varianten liegt — und ein Massenlauf würde alles noch
     *        einmal umwandeln.
     */
    #[Test]
    public function anExistingVariantIsFoundUnderTheSuffixedPath(): void
    {
        $registry = new WebPRegistry(
            $this->connection(total: 2, rows: [
                $this->row('media/a/bild.jpg', 'jpg'),
                $this->row('media/a/logo.png', 'png'),
            ]),
            $this->filesystem(['media/a/bild.jpg.webp']),
        );

        $stats = $registry->stats();

        self::assertSame(1, $stats['withWebp']);
        self::assertSame(1, $stats['withoutWebp']);
    }

    /**
     * Was: Ein Datensatz ohne Pfad.
     * Warum: Er kommt bei abgebrochenen Uploads vor. Ohne Pfad gibt es nichts zu suchen — der
     *        Datensatz darf die Zählung aber weder anhalten noch als umgewandelt gelten.
     */
    #[Test]
    public function aRecordWithoutAPathIsNeverCountedAsConverted(): void
    {
        $registry = new WebPRegistry(
            $this->connection(total: 2, rows: [
                ['id' => 'aabb', 'file_extension' => 'jpg', 'path' => ''],
                ['id' => 'ccdd', 'file_extension' => 'jpg', 'path' => null],
            ]),
            $this->filesystem(['.webp']),
        );

        $stats = $registry->stats();

        self::assertSame(2, $stats['eligible']);
        self::assertSame(0, $stats['withWebp']);
    }

    /**
     * Die Zahl der fehlenden Umwandlungen fällt nie unter null — auch dann nicht, wenn im
     * Speicher mehr Varianten liegen als Datensätze da sind. Eine negative Zahl im Backend wäre
     * ein Rätsel, das niemand auflösen kann.
     */
    #[Test]
    public function theOutstandingCountNeverGoesNegative(): void
    {
        $registry = new WebPRegistry(
            $this->connection(total: 1, rows: [$this->row('media/a/bild.jpg', 'jpg')]),
            $this->filesystem(['media/a/bild.jpg.webp']),
        );

        self::assertSame(0, $registry->stats()['withoutWebp']);
    }

    /**
     * Was: Die Liste der noch offenen Pfade.
     * Warum: Aus ihr speist sich der Massenlauf. Stünde ein bereits umgewandeltes Bild darin,
     *        liefe jeder Durchgang über denselben Bestand — die Umwandlung käme nie ans Ende.
     */
    #[Test]
    public function theWorkListSkipsWhatIsAlreadyConverted(): void
    {
        $registry = new WebPRegistry(
            $this->connection(total: 3, rows: [
                $this->row('media/a/fertig.jpg', 'jpg'),
                $this->row('media/a/offen.png', 'png'),
                $this->row('media/a/anleitung.pdf', 'pdf'),
            ]),
            $this->filesystem(['media/a/fertig.jpg.webp']),
        );

        self::assertSame(['media/a/offen.png'], iterator_to_array($registry->pathsMissingWebp(), false));
    }

    /**
     * Was: Die Obergrenze der Liste.
     * Warum: Der Massenlauf holt sich Arbeit in Portionen. Ohne wirksame Grenze zöge ein Aufruf
     *        den gesamten Bestand in den Arbeitsspeicher.
     */
    #[Test]
    public function theWorkListStopsAtTheRequestedLimit(): void
    {
        $rows = [];
        for ($i = 0; $i < 10; ++$i) {
            $rows[] = $this->row(sprintf('media/a/bild%d.jpg', $i), 'jpg');
        }

        $registry = new WebPRegistry($this->connection(total: 10, rows: $rows), $this->filesystem([]));

        self::assertCount(3, iterator_to_array($registry->pathsMissingWebp(3), false));
    }

    /**
     * @return array{id: string, file_extension: string, path: string}
     */
    private function row(string $path, string $extension): array
    {
        return ['id' => md5($path), 'file_extension' => $extension, 'path' => $path];
    }

    /**
     * Was: Vorschaubilder stehen vor den Originalen in der Arbeitsliste.
     * Warum: Die Seiten zeigen fast nur Vorschaubilder. Arbeitet der Massenlauf in Portionen,
     *        bringt so schon die erste Portion etwas; Umgewandeltes und Fremdformate fallen
     *        heraus wie bei den Originalen.
     */
    #[Test]
    public function thumbnailsComeFirstInTheWorkList(): void
    {
        $registry = new WebPRegistry(
            $this->connectionWithThumbnails(
                ['thumbnail/a/b_400x225.png', 'thumbnail/a/b_600x338.png', 'thumbnail/a/c_800x400.webp'],
                [$this->row('media/a/b.png', 'png')],
            ),
            $this->filesystem(['thumbnail/a/b_400x225.png.webp']),
        );

        self::assertSame(
            ['thumbnail/a/b_600x338.png', 'media/a/b.png'],
            iterator_to_array($registry->pathsMissingWebp(), false),
        );
    }

    /**
     * Was: Fehlende und zu kleine Quelldateien bleiben aus der Arbeitsliste.
     * Warum: Die Umwandlung übergeht sie. Stünden sie in der Liste, bekäme jede Portion dieselben
     *        Pfade wieder: Ein Lauf in Blöcken zu 2.000 meldete „2.000 übergangen“ und käme nie
     *        über den ersten Block hinaus.
     */
    #[Test]
    public function theWorkListLeavesOutWhatTheConversionWouldSkip(): void
    {
        $config = $this->createStub(\Shopware\Core\System\SystemConfig\SystemConfigService::class);
        $config->method('get')->willReturn(10);

        $registry = new WebPRegistry(
            $this->connectionWithThumbnails(
                ['thumbnail/a/fehlt.png', 'thumbnail/a/klein.jpg', 'thumbnail/a/gut.png'],
                [$this->row('media/a/fehlt.png', 'png')],
            ),
            $this->filesystem(
                [],
                missingSources: ['thumbnail/a/fehlt.png', 'media/a/fehlt.png'],
                sizes: ['thumbnail/a/klein.jpg' => 9 * 1024],
            ),
            $config,
        );

        self::assertSame(['thumbnail/a/gut.png'], iterator_to_array($registry->pathsMissingWebp(), false));
    }

    /**
     * Was: Die Obergrenze gilt für beide Teile zusammen.
     * Warum: Sonst holte ein Aufruf mit „100" bis zu 200 Aufträge, und auf den langsamen Platten
     *        des Servers dauerte eine Portion doppelt so lange wie geplant.
     */
    #[Test]
    public function theLimitCoversThumbnailsAndOriginalsTogether(): void
    {
        $registry = new WebPRegistry(
            $this->connectionWithThumbnails(
                ['thumbnail/a/1.png', 'thumbnail/a/2.png'],
                [$this->row('media/a/3.png', 'png')],
            ),
            $this->filesystem([]),
        );

        self::assertSame(['thumbnail/a/1.png', 'thumbnail/a/2.png'], iterator_to_array($registry->pathsMissingWebp(2), false));
    }

    /**
     * Was: Der Bestand der Vorschaubilder.
     * Warum: Ohne ihn sähe der Status fertig aus, sobald die Originale umgewandelt sind, obwohl
     *        auf den Seiten noch kein Bild als WebP ankommt.
     */
    #[Test]
    public function thumbnailStatsCountOnlyJpegAndPng(): void
    {
        $registry = new WebPRegistry(
            $this->connectionWithThumbnails(['thumbnail/a/1.png', 'thumbnail/a/2.jpg', 'thumbnail/a/3.webp'], [], total: 3),
            $this->filesystem(['thumbnail/a/1.png.webp']),
        );

        self::assertSame(
            ['total' => 3, 'withWebp' => 1, 'withoutWebp' => 1, 'eligible' => 2],
            $registry->thumbnailStats(),
        );
    }

    /**
     * @param list<string> $thumbnails
     * @param list<array<string, mixed>> $rows
     */
    private function connectionWithThumbnails(array $thumbnails, array $rows, int $total = 0): Connection
    {
        $connection = $this->connection($total, $rows);
        $connection->method('fetchFirstColumn')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($thumbnails): array {
                $offset = (int) ($params['offset'] ?? 0);

                return $offset === 0 ? $thumbnails : [];
            },
        );

        return $connection;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connection(int $total, array $rows): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn((string) $total);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($rows): array {
                // Der zweite Durchgang der Arbeitsliste muss leer sein, sonst liefe die Schleife
                // endlos über dieselben Zeilen.
                $offset = (int) ($params['offset'] ?? 0);

                return $offset === 0 ? $rows : [];
            },
        );

        return $connection;
    }

    /**
     * @param list<string> $existing
     * @param list<string> $missingSources
     * @param array<string, int> $sizes
     */
    private function filesystem(array $existing, array $missingSources = [], array $sizes = []): FilesystemAdapterInterface
    {
        // Fassungen gibt es nur, wenn sie genannt sind; Quelldateien sind da, außer sie fehlen
        // ausdrücklich. So bleibt jeder Test bei der einen Frage, um die es ihm geht.
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturnCallback(
            static fn (string $path): bool => str_ends_with($path, '.webp')
                ? in_array($path, $existing, true)
                : !in_array($path, $missingSources, true),
        );
        $filesystem->method('size')->willReturnCallback(
            static fn (string $path): int => $sizes[$path] ?? 50 * 1024,
        );

        return $filesystem;
    }
}
