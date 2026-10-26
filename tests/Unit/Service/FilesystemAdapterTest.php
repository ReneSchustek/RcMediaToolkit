<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Service;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapter;
use RuntimeException;

/**
 * Die Hülle um Shopwares Dateizugriff.
 *
 * Sie tut wenig — und genau das ist ihr Zweck: Alle Module sprechen über sie mit dem Speicher,
 * damit ein Wechsel auf einen Fremdspeicher keine Änderung in den Modulen erzwingt. Ihre einzige
 * eigene Entscheidung ist der Umgang mit Fehlern, und die ist **an einer Stelle bewusst anders
 * als an allen übrigen**: Die Existenzfrage beantwortet einen Fehler mit „nein", jede andere
 * Bewegung reicht ihn als Ausnahme weiter.
 *
 * Der Unterschied ist der ganze Grund, warum es diese Fälle gibt. Eine Existenzfrage, die eine
 * Ausnahme wirft, brächte jeden Prüflauf zum Stehen. Ein Schreibfehler dagegen, der stillschweigend
 * mit „geht schon" antwortet, führt dazu, dass später eine Datei fehlt, die als geschrieben gilt.
 */
#[CoversClass(FilesystemAdapter::class)]
final class FilesystemAdapterTest extends TestCase
{
    /**
     * Was: Die Existenzfrage bei gestörtem Speicher.
     * Warum: Sie antwortet mit „nein" statt zu scheitern. Über sie laufen die Prüfungen, und die
     *        gehen über den gesamten Bestand — eine Ausnahme bei einer einzigen Datei nähme den
     *        ganzen Lauf mit.
     */
    #[Test]
    public function theExistenceQuestionAnswersNoInsteadOfFailing(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('fileExists')->willThrowException(
            UnableToCheckFileExistence::forLocation('media/ab/bild.jpg'),
        );

        self::assertFalse((new FilesystemAdapter($operator))->exists('media/ab/bild.jpg'));
    }

    /**
     * Die Gegenprobe: Die Antwort des Speichers wird unverändert durchgereicht. Ohne sie bewiese
     * der vorige Fall nur, dass immer „nein" herauskommt.
     */
    #[Test]
    public function anUndisturbedExistenceQuestionIsPassedThrough(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('fileExists')->willReturnCallback(
            static fn (string $path): bool => $path === 'media/ab/bild.jpg',
        );

        $adapter = new FilesystemAdapter($operator);

        self::assertTrue($adapter->exists('media/ab/bild.jpg'));
        self::assertFalse($adapter->exists('media/ab/anderes.jpg'));
    }

    /**
     * Was: Ein Lesefehler.
     * Warum: Er wird **nicht** verschluckt. Der Aufrufer bekommt eine Ausnahme mit dem Pfad im
     *        Text — ein leerer Rückgabewert sähe wie eine leere Datei aus, und die Typprüfung
     *        machte daraus einen Befund über den Inhalt statt über die Störung.
     */
    #[Test]
    public function aReadErrorIsRaisedWithThePathInTheMessage(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('read')->willThrowException(UnableToReadFile::fromLocation('media/ab/bild.jpg'));

        $adapter = new FilesystemAdapter($operator);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/media\/ab\/bild\.jpg/');

        $adapter->read('media/ab/bild.jpg');
    }

    /**
     * Was: Die ursprüngliche Störung bleibt erhalten.
     * Warum: Die eigene Meldung sagt, **was** nicht ging; die eingehängte sagt, **warum**. Ohne
     *        sie stünde im Protokoll „Konnte Datei nicht lesen" und sonst nichts — und niemand
     *        wüsste, ob der Speicher voll, langsam oder gar nicht erreichbar war.
     */
    #[Test]
    public function theOriginalFailureIsKeptAsTheCause(): void
    {
        $original = UnableToReadFile::fromLocation('media/ab/bild.jpg');

        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('read')->willThrowException($original);

        try {
            (new FilesystemAdapter($operator))->read('media/ab/bild.jpg');
            self::fail('Der Lesefehler hätte weitergereicht werden müssen.');
        } catch (RuntimeException $exception) {
            self::assertSame($original, $exception->getPrevious());
        }
    }

    /**
     * Was: Fehler beim Löschen und beim Ermitteln der Größe.
     * Warum: Beides wird zur Ausnahme. Beim Löschen ist das offensichtlich; bei der Größe
     *        weniger — dort verhinderte eine stille Null einen Befund „Datei ist leer", der
     *        keiner ist.
     */
    #[Test]
    public function writingAndMeasuringFailuresAreNotSwallowed(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('delete')->willThrowException(UnableToDeleteFile::atLocation('media/ab/bild.jpg'));
        $operator->method('fileSize')->willThrowException(
            UnableToRetrieveMetadata::fileSize('media/ab/bild.jpg'),
        );

        $adapter = new FilesystemAdapter($operator);

        try {
            $adapter->delete('media/ab/bild.jpg');
            self::fail('Ein fehlgeschlagenes Löschen muss auffallen.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1); // Der erwartete Abbruch ist eingetreten — das ist die Prüfung.
        }

        $this->expectException(RuntimeException::class);
        $adapter->size('media/ab/bild.jpg');
    }

    /**
     * Was: Die Auflistung gibt Pfade zurück, keine Dateiobjekte.
     * Warum: Die Prüfung auf verwaiste Dateien vergleicht die Auflistung mit der Datenbank. Kämen
     *        Objekte heraus, hinge jedes Modul an der Fassung der Speicherbibliothek — genau das,
     *        was diese Hülle verhindern soll.
     */
    #[Test]
    public function theListingYieldsPlainPaths(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('listContents')->willReturn(new \League\Flysystem\DirectoryListing([
            new \League\Flysystem\FileAttributes('media/ab/eins.jpg'),
            new \League\Flysystem\FileAttributes('media/ab/zwei.jpg'),
        ]));

        $paths = (new FilesystemAdapter($operator))->listContents('media', true);

        self::assertSame(['media/ab/eins.jpg', 'media/ab/zwei.jpg'], $paths);
    }
}
