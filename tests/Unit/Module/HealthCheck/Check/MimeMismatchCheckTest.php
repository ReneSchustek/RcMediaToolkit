<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\MimeMismatchCheck;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use RuntimeException;

/**
 * Die Prüfung auf Dateien, deren Inhalt nicht zum eingetragenen Typ passt.
 *
 * Sie ist die einzige, die jede Datei tatsächlich liest — die Endung ist ja gerade die Angabe,
 * der nicht zu trauen ist. Damit ist sie die teuerste der Prüfungen und die mit der größten
 * Neigung zum Fehlalarm: **Jede Schreibweise, die sie nicht als gleichbedeutend erkennt, meldet
 * sie als Fund.** Ein Bericht mit tausend falschen Befunden ist so wertlos wie gar keiner.
 */
#[CoversClass(MimeMismatchCheck::class)]
final class MimeMismatchCheckTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const GIF_1X1 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * Was: Ein Bild, das anders heißt, als es ist.
     * Warum: Der Zweck der Prüfung. Solche Dateien entstehen beim Umbenennen von Endungen — der
     *        Browser bekommt einen Typ genannt, dem der Inhalt nicht entspricht, und jeder
     *        entscheidet anders, was er damit tut.
     */
    #[Test]
    public function contentThatContradictsTheRecordIsReported(): void
    {
        $check = $this->check(
            [$this->row('media/ab/falsch.png', 'image/png')],
            ['media/ab/falsch.png' => self::bytes(self::GIF_1X1)],
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings);
        self::assertSame(Finding::SEVERITY_WARNING, $findings[0]->severity);
        self::assertSame('image/png', $findings[0]->errorDetail['declaredMime']);
        self::assertSame('image/gif', $findings[0]->errorDetail['actualMime']);
    }

    /**
     * Die Gegenprobe: Passt der Inhalt zum Eintrag, gibt es keinen Befund. Ohne sie bewiese der
     * vorige Fall nur, dass die Prüfung überhaupt etwas meldet.
     */
    #[Test]
    public function aMatchingRecordProducesNoFinding(): void
    {
        $check = $this->check(
            [$this->row('media/ab/richtig.png', 'image/png')],
            ['media/ab/richtig.png' => self::bytes(self::PNG_1X1)],
        );

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: `image/jpg` und `image/jpeg`.
     * Warum: Beides steht im Bestand, gemeint ist dasselbe, und die Browser behandeln es gleich.
     *        Ohne diese Gleichsetzung meldete die Prüfung **jede** so eingetragene Datei — und in
     *        gewachsenen Beständen sind das schnell Hunderte.
     */
    #[Test]
    public function theTwoSpellingsOfJpegAreTheSameThing(): void
    {
        $check = $this->check(
            [$this->row('media/ab/foto.jpg', 'image/jpg')],
            ['media/ab/foto.jpg' => $this->jpegBytes()],
        );

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Groß- und Kleinschreibung sowie Leerraum im Eintrag.
     * Warum: Beides kommt aus Ausfuhren fremder Systeme. Beides ist bedeutungslos — würde es
     *        mitgeprüft, entstünde ein Fund aus einem Leerzeichen.
     */
    #[Test]
    public function caseAndWhitespaceInTheRecordAreIrrelevant(): void
    {
        $check = $this->check(
            [$this->row('media/ab/bild.png', '  IMAGE/PNG ')],
            ['media/ab/bild.png' => self::bytes(self::PNG_1X1)],
        );

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Eine Datei, die es nicht gibt.
     * Warum: Sie ist der Fall der Prüfung auf fehlende Dateien. Meldete diese Prüfung sie
     *        ebenfalls, stünde jeder Fund doppelt im Bericht — und die Zahl der Befunde taugte
     *        nicht mehr als Maß für den Fortschritt.
     */
    #[Test]
    public function anAbsentFileIsLeftToTheOtherCheck(): void
    {
        $check = $this->check([$this->row('media/ab/weg.png', 'image/png')], []);

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Was: Der Speicher verweigert das Lesen.
     * Warum: Bei angebundenem Fremdspeicher reicht dafür eine Zeitüberschreitung. Der Lauf darf
     *        daran nicht enden — sonst nimmt eine einzelne klemmende Datei den ganzen Bericht
     *        mit, und zwar ausgerechnet bei der Prüfung, die am längsten läuft.
     */
    #[Test]
    public function aFailingReadDoesNotEndTheRun(): void
    {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturn(true);
        $filesystem->method('read')->willReturnCallback(
            static function (string $path): string {
                if ($path === 'media/ab/klemmt.png') {
                    throw new RuntimeException('Zeitüberschreitung beim Speicher');
                }

                return self::bytes(self::GIF_1X1);
            },
        );

        $check = new MimeMismatchCheck(
            $this->connection([
                $this->row('media/ab/klemmt.png', 'image/png'),
                $this->row('media/ab/danach.png', 'image/png'),
            ]),
            $filesystem,
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings, 'Nach der klemmenden Datei wird weitergeprüft.');
        self::assertSame('media/ab/danach.png', $findings[0]->urlPath);
    }

    /**
     * Was: Eine Datei ohne Inhalt.
     * Warum: Sie gehört der Prüfung auf leere Dateien — und nur ihr. Die Typerkennung gibt für
     *        leeren Inhalt `application/x-empty` zurück, einen gültigen Wert; ohne diese Grenze
     *        stünde **jede** abgebrochene Übertragung zweimal im Bericht, das zweite Mal mit der
     *        falschen Begründung. Wer der zweiten Meldung nachginge, suchte einen Typfehler, wo
     *        die Datei schlicht leer ist.
     */
    #[Test]
    public function anEmptyFileIsLeftToTheZeroByteCheck(): void
    {
        $check = $this->check(
            [$this->row('media/ab/leer.png', 'image/png')],
            ['media/ab/leer.png' => ''],
        );

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * Die Prüfung nennt ihre Kennung und ihren Schalter. Beides steht in der Berichtstabelle
     * beziehungsweise im Backend; ein unbemerkter Wechsel machte alte Befunde unauffindbar.
     */
    #[Test]
    public function theCheckNamesItselfAndItsSetting(): void
    {
        $check = $this->check([], []);

        self::assertSame('mime_mismatch', $check->type());
        self::assertSame('RcMediaToolkit.config.healthCheckMimeMismatch', $check->configKey());
    }

    /**
     * Was: Ein Datensatz ohne Pfadangabe.
     * Warum: Auch die Typprüfung muss den Pfad aus Name und Endung bilden können. Ohne den
     *        Rückfall prüfte sie in älteren Beständen **gar nichts** und meldete einen sauberen
     *        Bestand — die teuerste der vier Prüfungen liefe dann folgenlos.
     */
    #[Test]
    public function theTypeCheckAlsoFallsBackToNameAndExtension(): void
    {
        $check = $this->check(
            [[
                'id' => 'aa',
                'file_name' => 'falsch',
                'file_extension' => 'png',
                'mime_type' => 'image/png',
                'path' => '',
            ]],
            ['media/falsch.png' => self::bytes(self::GIF_1X1)],
        );

        $findings = iterator_to_array($check->run(), false);

        self::assertCount(1, $findings);
        self::assertSame('media/falsch.png', $findings[0]->urlPath);
    }

    /**
     * Ohne Dateinamen gibt es keinen Pfad — der Datensatz wird übergangen statt unter einem
     * geratenen Pfad gelesen.
     */
    #[Test]
    public function theTypeCheckSkipsRecordsWithoutAName(): void
    {
        $check = $this->check(
            [['id' => 'aa', 'file_name' => null, 'file_extension' => null, 'mime_type' => 'image/png', 'path' => null]],
            [],
        );

        self::assertSame([], iterator_to_array($check->run(), false));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function magicNumbers(): array
    {
        return [
            'JPEG' => ["\xFF\xD8\xFF\xE0irgendwas", 'image/jpeg'],
            'PNG' => ["\x89PNG\r\n\x1a\nirgendwas", 'image/png'],
            'GIF alt' => ['GIF87airgendwas', 'image/gif'],
            'GIF neu' => ['GIF89airgendwas', 'image/gif'],
            'WebP' => ['RIFF' . 'XXXX' . 'WEBPirgendwas', 'image/webp'],
            'unbekannt' => ['einfach nur Text', null],
            'zu kurz' => ["\xFF\xD8", null],
            'RIFF ohne WebP' => ['RIFF' . 'XXXX' . 'WAVEirgendwas', null],
        ];
    }

    /**
     * Was: Die Erkennung am Dateikopf.
     * Warum: Sie ist der Rückfall für Installationen ohne die Erweiterung `fileinfo` — in
     *        gehärteten Umgebungen ist die abgeschaltet. **Ohne diesen Rückfall meldete die
     *        Prüfung dort gar nichts**, und ein Bestand voller falsch benannter Dateien sähe
     *        sauber aus.
     *
     *        Geprüft wird die Methode unmittelbar: Solange `fileinfo` geladen ist, kommt der
     *        Weg dorthin über den gewöhnlichen Aufruf nie zustande.
     */
    #[Test]
    #[DataProvider('magicNumbers')]
    public function theFallbackRecognisesTheFourImageFormats(string $bytes, ?string $expected): void
    {
        $check = $this->check([], []);

        $methode = (new ReflectionClass($check))->getMethod('detectByMagicNumber');
        $methode->setAccessible(true);

        self::assertSame($expected, $methode->invoke($check, $bytes));
    }

    /**
     * @return array{id: string, file_name: string, file_extension: string, mime_type: string, path: string}
     */
    private function row(string $path, string $mimeType): array
    {
        return [
            'id' => md5($path),
            'file_name' => basename($path),
            'file_extension' => pathinfo($path, \PATHINFO_EXTENSION),
            'mime_type' => $mimeType,
            'path' => $path,
        ];
    }

    /**
     * Entpackt eine der eingebetteten Bilddateien. Die Entpackung kann fehlschlagen; hier kann
     * sie es nicht, und der Fall wird abgefangen statt weitergereicht.
     */
    private static function bytes(string $base64): string
    {
        $decoded = base64_decode($base64, true);
        self::assertIsString($decoded);

        return $decoded;
    }

    /**
     * Ein gültiges JPEG von einem Bildpunkt — kürzer geht es nicht, und die Erkennung braucht
     * einen echten Dateikopf.
     */
    private function jpegBytes(): string
    {
        $base64 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUB'
            . 'AQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1Fh'
            . 'ByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNk'
            . 'ZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT'
            . '1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL'
            . '/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYk'
            . 'NOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImK'
            . 'kpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3'
            . '+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==';

        return self::bytes($base64);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string>      $files
     */
    private function check(array $rows, array $files): MimeMismatchCheck
    {
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturnCallback(
            static fn (string $path): bool => isset($files[$path]),
        );
        $filesystem->method('read')->willReturnCallback(
            static fn (string $path): string => $files[$path] ?? '',
        );

        return new MimeMismatchCheck($this->connection($rows), $filesystem);
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
            static fn (string $sql, array $params = []): array => (int) ($params['offset'] ?? 0) === 0 ? $rows : [],
        );

        return $connection;
    }
}
