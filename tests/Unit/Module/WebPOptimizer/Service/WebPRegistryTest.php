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
     */
    private function filesystem(array $existing): FilesystemAdapterInterface
    {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturnCallback(
            static fn (string $path): bool => in_array($path, $existing, true),
        );

        return $filesystem;
    }
}
