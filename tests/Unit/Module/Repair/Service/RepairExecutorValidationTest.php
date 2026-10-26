<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\Repair\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\MediaReferenceUpdater;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairExecutor;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Defense-in-Depth-Tests für die Hex-Validation der finding['id'].
 */
#[CoversClass(RepairExecutor::class)]
final class RepairExecutorValidationTest extends TestCase
{
    #[Test]
    public function extractValidFindingIdReturnsNullForMissingId(): void
    {
        $executor = $this->build();
        $reflection = new ReflectionClass($executor);
        $method = $reflection->getMethod('extractValidFindingId');
        $method->setAccessible(true);

        self::assertNull($method->invoke($executor, []));
        self::assertNull($method->invoke($executor, ['id' => 123]));
        self::assertNull($method->invoke($executor, ['other' => 'value']));
    }

    #[Test]
    public function extractValidFindingIdRejectsInvalidHex(): void
    {
        $executor = $this->build();
        $reflection = new ReflectionClass($executor);
        $method = $reflection->getMethod('extractValidFindingId');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($executor, ['id' => 'not-a-hex-uuid']);
    }

    #[Test]
    public function extractValidFindingIdAcceptsValidHex(): void
    {
        $executor = $this->build();
        $reflection = new ReflectionClass($executor);
        $method = $reflection->getMethod('extractValidFindingId');
        $method->setAccessible(true);

        $valid = '0123456789abcdef0123456789abcdef';
        self::assertSame($valid, $method->invoke($executor, ['id' => $valid]));
    }

    private function build(): RepairExecutor
    {
        return new RepairExecutor(
            $this->createMock(Connection::class),
            new MediaToolkitLogger(new NullLogger()),
            $this->createMock(SystemConfigService::class),
            new MediaReferenceUpdater(
                $this->createMock(Connection::class),
                new MediaReferenceSources($this->createMock(Connection::class)),
            ),
        );
    }
}
