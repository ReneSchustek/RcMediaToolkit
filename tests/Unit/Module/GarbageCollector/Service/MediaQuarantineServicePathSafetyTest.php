<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;

/**
 * Defense-in-Depth-Tests gegen manipulierte Quarantine-Manifeste:
 * isQuarantinedPathSafe / isOriginalPathSafe sind private — die Tests verifizieren
 * sie indirekt durch Aufrufen der öffentlichen API restore().
 */
#[CoversClass(MediaQuarantineService::class)]
final class MediaQuarantineServicePathSafetyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unsafeQuarantinedPaths(): array
    {
        return [
            'traversal über ..' => ['var/media_quarantine/20260511-120000-aaaaaaaa/../../../etc'],
            'NUL-Byte' => ["var/media_quarantine/20260511-120000-aaaaaaaa/x\x00.jpg"],
            'empty' => [''],
            'falscher Run-Prefix' => ['var/etc/passwd'],
            'absolut' => ['/etc/passwd'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeOriginalPaths(): array
    {
        return [
            'traversal' => ['media/../../etc/passwd'],
            'absolut Unix' => ['/etc/passwd'],
            'absolut Windows' => ['C:\\Windows\\system32'],
            'NUL-Byte' => ["media/x\x00.jpg"],
            'falscher Prefix' => ['something/else.jpg'],
            'empty' => [''],
        ];
    }

    #[Test]
    public function isQuarantinedPathSafeRejectsUnsafePaths(): void
    {
        $service = $this->build();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isQuarantinedPathSafe');
        $method->setAccessible(true);

        $expectedPrefix = 'var/media_quarantine/20260511-120000-aaaaaaaa/';

        foreach (self::unsafeQuarantinedPaths() as [$path]) {
            self::assertFalse(
                $method->invoke($service, $path, $expectedPrefix),
                sprintf('Pfad "%s" sollte abgelehnt werden', $path),
            );
        }
    }

    #[Test]
    public function isQuarantinedPathSafeAcceptsValidPath(): void
    {
        $service = $this->build();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isQuarantinedPathSafe');
        $method->setAccessible(true);

        $expectedPrefix = 'var/media_quarantine/20260511-120000-aaaaaaaa/';
        $valid = $expectedPrefix . '0123456789abcdef0123456789abcdef_test.jpg';

        self::assertTrue($method->invoke($service, $valid, $expectedPrefix));
    }

    #[Test]
    #[DataProvider('unsafeOriginalPaths')]
    public function isOriginalPathSafeRejectsUnsafePaths(string $path): void
    {
        $service = $this->build();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isOriginalPathSafe');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($service, $path));
    }

    #[Test]
    public function isOriginalPathSafeAcceptsMediaPrefix(): void
    {
        $service = $this->build();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('isOriginalPathSafe');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($service, 'media/test.jpg'));
        self::assertTrue($method->invoke($service, 'media/sub/folder/file.png'));
    }

    private function build(): MediaQuarantineService
    {
        return new MediaQuarantineService(
            $this->createMock(Connection::class),
            $this->createMock(FilesystemAdapterInterface::class),
            new MediaToolkitLogger(new NullLogger()),
            sys_get_temp_dir(),
        );
    }
}
