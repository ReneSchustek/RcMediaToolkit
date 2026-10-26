<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Stringable;

#[CoversClass(MediaToolkitLogger::class)]
final class MediaToolkitLoggerTest extends TestCase
{
    private RecordingLogger $inner;
    private MediaToolkitLogger $logger;

    protected function setUp(): void
    {
        $this->inner = new RecordingLogger();
        $this->logger = new MediaToolkitLogger($this->inner);
    }

    #[Test]
    public function infoForwardsMessageAndContext(): void
    {
        $this->logger->info('Hallo Welt', ['extra' => 'wert']);

        self::assertCount(1, $this->inner->records);
        self::assertSame('info', $this->inner->records[0]['level']);
        self::assertSame('Hallo Welt', $this->inner->records[0]['message']);
        self::assertSame('wert', $this->inner->records[0]['context']['extra']);
    }

    #[Test]
    public function withModuleAddsModuleToContext(): void
    {
        $moduleLogger = $this->logger->withModule('GarbageCollector');
        $moduleLogger->info('Lauf gestartet');

        self::assertSame('GarbageCollector', $this->inner->records[0]['context']['module']);
    }

    #[Test]
    public function mediaIdLongerThanEightCharsIsMasked(): void
    {
        $this->logger->info('Verarbeite', ['mediaId' => '0123456789abcdef0123456789abcdef']);

        self::assertSame('01234567…', $this->inner->records[0]['context']['mediaId']);
    }

    #[Test]
    public function mediaIdShorterOrEqualEightCharsStaysUnchanged(): void
    {
        $this->logger->info('Verarbeite', ['mediaId' => 'abcd1234']);

        self::assertSame('abcd1234', $this->inner->records[0]['context']['mediaId']);
    }

    #[Test]
    public function mediaIdsArrayIsMaskedElementwise(): void
    {
        $this->logger->info('Batch', ['mediaIds' => ['aaaaaaaaaaaa', 'bbbbbbbbbbbb']]);

        self::assertSame(['aaaaaaaa…', 'bbbbbbbb…'], $this->inner->records[0]['context']['mediaIds']);
    }

    #[Test]
    public function longPathIsMasked(): void
    {
        $longPath = '/media/products/some-very-long-customer-named-file.jpg';
        $this->logger->info('Pfad', ['path' => $longPath]);

        $logged = $this->inner->records[0]['context']['path'];
        self::assertStringEndsWith('…', $logged);
        self::assertLessThan(strlen($longPath), strlen($logged));
    }

    #[Test]
    public function defaultContextOverridesEnrichment(): void
    {
        $loggerWithDefault = new MediaToolkitLogger($this->inner, ['module' => 'HealthCheck']);
        $loggerWithDefault->error('Fehler', ['extra' => '1']);

        self::assertSame('HealthCheck', $this->inner->records[0]['context']['module']);
        self::assertSame('1', $this->inner->records[0]['context']['extra']);
    }
    /**
     * @return array<string, array{string}>
     */
    public static function levels(): array
    {
        return [
            'Notfall' => ['emergency'],
            'Alarm' => ['alert'],
            'kritisch' => ['critical'],
            'Fehler' => ['error'],
            'Warnung' => ['warning'],
            'Hinweis' => ['notice'],
            'Information' => ['info'],
            'Fehlersuche' => ['debug'],
        ];
    }

    /**
     * Was: Jede Protokollstufe reicht die Zusatzangaben weiter.
     * Warum: Modul und Vorgangskennung hängen an **jeder** Zeile, nicht nur an den bequemen.
     *        Fehlten sie bei einer Stufe, ließe sich ausgerechnet die Zeile nicht zuordnen, die
     *        man nach einem Zwischenfall sucht — und das ist selten die Informationszeile.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('levels')]
    public function everyLevelCarriesTheContext(string $level): void
    {
        $inner = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<array{string, array<string, mixed>}> */
            public array $zeilen = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->zeilen[] = [(string) $level, $context];
            }
        };

        $logger = (new \Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger($inner))
            ->withModule('GarbageCollector')
            ->withCorrelationId('vorgang-1');

        $logger->{$level}('Nachricht');
        $logger->log($level, 'noch eine');

        self::assertCount(2, $inner->zeilen);
        foreach ($inner->zeilen as [$gemeldet, $context]) {
            self::assertSame($level, $gemeldet);
            self::assertSame('GarbageCollector', $context['module'] ?? null);
            self::assertSame('vorgang-1', $context['correlationId'] ?? null);
        }
    }
}

final class RecordingLogger extends AbstractLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

}
