<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Command\WebPConvertCommand;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Command\WebPStatusCommand;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message\WebPConvertMessage;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\EncoderChain;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPConverter;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPRegistry;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPSettingsResolver;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\RecordingFilesystem;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Die beiden Befehle der Bildumwandlung.
 *
 * Der Umwandlungsbefehl ist der Einzige im Plugin, der auf einem großen Bestand **stundenlang**
 * laufen kann. Daraus folgen die Zusagen, die hier festgehalten sind: Er lässt sich vorher
 * ansehen, ohne etwas zu tun; er lässt sich portionieren; er lässt sich in die Warteschlange
 * geben, statt die Konsole zu belegen. Und er meldet einen Fehlerstatus, sobald auch nur ein
 * Bild gescheitert ist — auf einem Bestand von Zehntausenden ist das die einzige Chance, den
 * einen Fehlschlag überhaupt zu bemerken.
 */
#[CoversClass(WebPConvertCommand::class)]
#[CoversClass(WebPStatusCommand::class)]
final class WebPCommandsTest extends TestCase
{
    /**
     * Was: Der Probelauf.
     * Warum: Er zeigt, was geschähe, und tut nichts. Vor einem Lauf über Zehntausende Bilder ist
     *        das der einzige Weg, den Umfang abzuschätzen, ohne ihn auszulösen.
     */
    #[Test]
    public function theDryRunListsWithoutConverting(): void
    {
        $converted = [];
        $tester = new CommandTester($this->convertCommand(['media/a/eins.jpg', 'media/a/zwei.png'], $converted));

        $status = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $converted, 'Im Probelauf wird kein Bild angefasst.');
        self::assertStringContainsString('media/a/eins.jpg', $tester->getDisplay());
        self::assertStringContainsString('media/a/zwei.png', $tester->getDisplay());
    }

    /**
     * Die Gegenprobe: Ohne Probelauf wird umgewandelt, und zwar jedes gefundene Bild.
     */
    #[Test]
    public function theRealRunConvertsEveryFoundImage(): void
    {
        $converted = [];
        $tester = new CommandTester($this->convertCommand(['media/a/eins.jpg', 'media/a/zwei.png'], $converted));

        $tester->execute([]);

        self::assertSame(['media/a/eins.jpg', 'media/a/zwei.png'], $converted);
    }

    /**
     * Was: Der Lauf über die Warteschlange.
     * Warum: Auf einem großen Bestand ist er der Regelfall — die Konsole kehrt sofort zurück,
     *        und die Arbeiter arbeiten den Bestand ab. Umgewandelt wird dabei **hier** nichts,
     *        sonst geschähe die Arbeit doppelt.
     */
    #[Test]
    public function theAsynchronousRunQueuesInsteadOfConverting(): void
    {
        $converted = [];
        $dispatched = [];
        $tester = new CommandTester(
            $this->convertCommand(['media/a/eins.jpg', 'media/a/zwei.png'], $converted, $dispatched),
        );

        $tester->execute(['--async' => true]);

        self::assertSame([], $converted);
        self::assertCount(2, $dispatched);
        self::assertSame('media/a/eins.jpg', $dispatched[0]->sourcePath);
    }

    /**
     * Was: Ein einziger Fehlschlag.
     * Warum: **Er färbt den Rückgabewert.** Auf einem Bestand von Zehntausenden geht die eine
     *        Fehlermeldung in der Ausgabe unter; der Rückgabewert ist das Einzige, was ein
     *        zeitgesteuerter Aufruf weitergibt.
     */
    #[Test]
    public function aSingleFailureShowsInTheExitStatus(): void
    {
        $converted = [];
        $tester = new CommandTester(
            $this->convertCommand(['media/a/geht.jpg', 'media/a/klemmt.jpg'], $converted, failFor: 'media/a/klemmt.jpg'),
        );

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('1 Fehler', $tester->getDisplay());
    }

    /**
     * Ein leerer Bestand ist ein Erfolg, kein Fehler — er bedeutet, dass alles umgewandelt ist.
     */
    #[Test]
    public function anEmptyBacklogIsASuccess(): void
    {
        $converted = [];
        $tester = new CommandTester($this->convertCommand([], $converted));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Keine', $tester->getDisplay());
    }

    /**
     * Was: Das abgeschaltete Modul.
     * Warum: Fehlerstatus statt stillem Erfolg — dieselbe Zusage wie bei den übrigen Befehlen.
     *        Wer die Umwandlung abgeschaltet hat und den Befehl trotzdem in einem Zeitplan
     *        stehen lässt, soll das an der Rückmeldung sehen.
     */
    #[Test]
    public function anInactiveModuleMakesTheConversionFail(): void
    {
        $converted = [];
        $tester = new CommandTester($this->convertCommand([], $converted, moduleActive: false));

        self::assertSame(Command::FAILURE, $tester->execute([]));
    }

    /**
     * Der Statusbefehl nennt den Bestand und die verfügbaren Umwandler. Ohne die zweite Angabe
     * bliebe unklar, warum auf einem System nichts umgewandelt wird — dort fehlt schlicht jedes
     * Programm dafür.
     */
    #[Test]
    public function theStatusCommandNamesStockAndAvailableEncoders(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('7');
        $connection->method('fetchAllAssociative')->willReturn([]);

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(null);

        $command = new WebPStatusCommand(
            new WebPRegistry($connection, new RecordingFilesystem([])),
            new EncoderChain([], $config, new MediaToolkitLogger(new NullLogger())),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('7', $tester->getDisplay());
    }

    /**
     * @param list<string>            $paths
     * @param list<string>            $converted
     * @param list<WebPConvertMessage> $dispatched
     */
    private function convertCommand(
        array $paths,
        array &$converted,
        array &$dispatched = [],
        bool $moduleActive = true,
        ?string $failFor = null,
    ): WebPConvertCommand {
        $rows = [];
        foreach ($paths as $index => $path) {
            $rows[] = [
                'id' => str_pad((string) $index, 32, '0', \STR_PAD_LEFT),
                'file_extension' => pathinfo($path, \PATHINFO_EXTENSION),
                'path' => $path,
            ];
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn((string) count($rows));
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static fn (string $sql, array $params = []): array => (int) ($params['offset'] ?? 0) === 0 ? $rows : [],
        );

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'RcMediaToolkit.config.webPOptimizerActive' ? $moduleActive : null,
        );

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );

        return new WebPConvertCommand(
            new ModuleRegistry($config),
            new WebPRegistry($connection, new RecordingFilesystem([])),
            $this->converter($converted, $failFor),
            $bus,
        );
    }

    /**
     * Der Umwandler wird echt gebaut; ersetzt ist nur der Speicher darunter. So bleibt die
     * Entscheidung, was umgewandelt und was übergangen wird, Gegenstand der Prüfung.
     *
     * @param list<string> $converted
     */
    private function converter(array &$converted, ?string $failFor): WebPConverter
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(null);

        $logger = new MediaToolkitLogger(new NullLogger());

        return new WebPConverter(
            new EncoderChain([new StubEncoder($failFor)], $config, $logger),
            new ConversionRecordingFilesystem($converted),
            $config,
            $logger,
            new WebPSettingsResolver($config, $logger),
        );
    }
}

/**
 * Ein Umwandler, der die Bytes unverändert zurückgibt — und bei einer benannten Datei klemmt.
 */
final class StubEncoder implements \Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\WebPEncoderInterface
{
    public function __construct(private readonly ?string $failFor = null)
    {
    }

    public function name(): string
    {
        // Die Kette wählt nach der eingestellten Reihenfolge; ein Name außerhalb davon
        // wäre nie an der Reihe.
        return 'gd';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function encode(string $sourceBytes, string $sourceFormat, int $quality, bool $lossless, bool $stripMetadata): string
    {
        if ($this->failFor !== null && $sourceBytes === $this->failFor) {
            throw new RuntimeException('Umwandlung nicht möglich');
        }

        return 'webp:' . $sourceBytes;
    }

    public function version(): string
    {
        return 'gd (Doppel)';
    }
}

/**
 * Ein Speicher, der jeden Pfad kennt und seinen eigenen Namen als Inhalt zurückgibt — so trägt
 * jede Datei ihre Kennung mit, und der Umwandler ist an seinen Schreibvorgängen ablesbar.
 */
final class ConversionRecordingFilesystem implements \Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface
{
    /**
     * @param list<string> $converted Sammelstelle des Falls; die Liste gehört ihm, nicht hier
     */
    public function __construct(private array &$converted)
    {
        $this->converted = $converted;
    }

    /**
     * @return list<string>
     */
    public function convertedPaths(): array
    {
        return $this->converted;
    }

    public function exists(string $path): bool
    {
        return !str_ends_with($path, '.webp');
    }

    public function read(string $path): string
    {
        return $path;
    }

    public function write(string $path, string $contents): void
    {
        $this->converted[] = substr($path, 0, -strlen('.webp'));
    }

    public function delete(string $path): void
    {
    }

    public function size(string $path): int
    {
        // Deutlich über jeder Mindestgröße: Ob kleine Dateien übergangen werden, ist Sache
        // der Fälle des Umwandlers, nicht dieser Befehle.
        return 5_000_000;
    }

    public function lastModified(string $path): int
    {
        return 0;
    }

    public function move(string $source, string $destination): void
    {
    }

    public function makeDirectory(string $path): void
    {
    }

    public function listContents(string $path, bool $recursive = false): array
    {
        return [];
    }
}
