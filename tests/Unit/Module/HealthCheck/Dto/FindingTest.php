<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\HealthCheck\Dto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;

#[CoversClass(Finding::class)]
final class FindingTest extends TestCase
{
    #[Test]
    public function constructorAcceptsValidSeverities(): void
    {
        foreach ([Finding::SEVERITY_CRITICAL, Finding::SEVERITY_WARNING, Finding::SEVERITY_INFO] as $sev) {
            $finding = new Finding('test', $sev, 'source');
            self::assertSame($sev, $finding->severity);
        }
    }

    #[Test]
    public function constructorRejectsUnknownSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Finding('test', 'apocalyptic', 'source');
    }

    #[Test]
    public function defaultErrorDetailIsEmptyArray(): void
    {
        $finding = new Finding('t', Finding::SEVERITY_WARNING, 's');
        self::assertSame([], $finding->errorDetail);
    }
}
