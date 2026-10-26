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
final class MediaToolkitLoggerCorrelationTest extends TestCase
{
    #[Test]
    public function withCorrelationIdAddsContextToLogs(): void
    {
        $inner = new class () extends AbstractLogger implements LoggerInterface {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $logger = (new MediaToolkitLogger($inner))
            ->withModule('GarbageCollector')
            ->withCorrelationId('abcd1234');

        $logger->info('Test');

        self::assertSame('GarbageCollector', $inner->records[0]['context']['module']);
        self::assertSame('abcd1234', $inner->records[0]['context']['correlationId']);
    }

    #[Test]
    public function generatedCorrelationIdHasExpectedFormat(): void
    {
        $id = MediaToolkitLogger::generateCorrelationId();
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $id);
    }

    #[Test]
    public function correlationIdIsImmutableAcrossWithCalls(): void
    {
        $inner = new class () extends AbstractLogger implements LoggerInterface {
            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $base = new MediaToolkitLogger($inner);
        $first = $base->withCorrelationId('aaaaaaaa');
        $second = $base->withCorrelationId('bbbbbbbb');

        $first->info('first');
        $second->info('second');

        self::assertSame('aaaaaaaa', $inner->contexts[0]['correlationId']);
        self::assertSame('bbbbbbbb', $inner->contexts[1]['correlationId']);
    }
}
