<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\ExternalUrlCheck;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Zusätzliche SSRF-Härte-Tests für zwei leicht übersehene Blocklist-Pfade:
 * Carrier-Grade NAT (100.64.0.0/10) und IPv4-mapped IPv6.
 */
#[CoversClass(ExternalUrlCheck::class)]
final class ExternalUrlCheckRebindingTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function privateIpCases(): array
    {
        return [
            'CGN 100.64.0.0' => ['100.64.0.0', true],
            'CGN 100.127.255.255' => ['100.127.255.255', true],
            'außerhalb CGN 100.128.0.0' => ['100.128.0.0', false],
            'außerhalb CGN 100.63.255.255' => ['100.63.255.255', false],
            'IPv4-mapped IPv6 loopback' => ['::ffff:127.0.0.1', true],
            'IPv4-mapped IPv6 RFC1918' => ['::FFFF:10.0.0.1', true],
            'IPv4-mapped IPv6 public' => ['::ffff:8.8.8.8', false],
            'normales IPv4 public' => ['8.8.8.8', false],
            'normales IPv4 RFC1918' => ['10.0.0.1', true],
            'normales IPv4 AWS-Metadata' => ['169.254.169.254', true],
        ];
    }

    #[Test]
    #[DataProvider('privateIpCases')]
    public function isPrivateOrSpecialIpHandlesExtendedRanges(string $ip, bool $expected): void
    {
        $check = $this->buildCheck();
        $reflection = new ReflectionClass($check);
        $method = $reflection->getMethod('isPrivateOrSpecialIp');
        $method->setAccessible(true);

        self::assertSame(
            $expected,
            $method->invoke($check, $ip),
            sprintf('IP "%s" sollte %s sein', $ip, $expected ? 'geblockt' : 'erlaubt'),
        );
    }

    #[Test]
    public function isInIpv4RangeWorksWithCidr(): void
    {
        $check = $this->buildCheck();
        $reflection = new ReflectionClass($check);
        $method = $reflection->getMethod('isInIpv4Range');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($check, '100.64.5.1', '100.64.0.0', 10));
        self::assertFalse($method->invoke($check, '100.128.5.1', '100.64.0.0', 10));
        self::assertTrue($method->invoke($check, '10.5.0.1', '10.0.0.0', 8));
    }

    private function buildCheck(): ExternalUrlCheck
    {
        $config = $this->createMock(SystemConfigService::class);
        return new ExternalUrlCheck($this->createMock(Connection::class), $config);
    }
}
