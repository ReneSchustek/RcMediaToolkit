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
 * SSRF-Härte-Tests: stellt sicher, dass die Allowlist- und Blocklist-Logik beim
 * Aufruf greift, ohne dass tatsächlich HTTP-Verbindungen aufgebaut werden.
 *
 * `isUrlSafe` ist privat; die Tests rufen das öffentliche Verhalten über den
 * Reflection-Bypass an, weil ein „echter run" eine echte Datenbank bräuchte.
 */
#[CoversClass(ExternalUrlCheck::class)]
final class ExternalUrlCheckSecurityTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function urlSafetyCases(): array
    {
        return [
            'https-Public' => ['https://cdn.example.com/img.jpg', true],
            'http-Public' => ['http://example.com/img.png', true],
            'localhost' => ['http://localhost/img.jpg', false],
            'IPv4-localhost' => ['http://127.0.0.1/img.jpg', false],
            'IPv6-localhost' => ['http://[::1]/img.jpg', false],
            'AWS-Metadata' => ['http://169.254.169.254/latest/meta-data/', false],
            'private-RFC1918-10' => ['http://10.0.0.1/img.jpg', false],
            'private-RFC1918-192' => ['http://192.168.1.1/img.jpg', false],
            'private-RFC1918-172' => ['http://172.16.0.1/img.jpg', false],
            '.local-Domain' => ['http://server.local/img.jpg', false],
            '.internal-Domain' => ['http://api.internal/img.jpg', false],
            'file-Schema' => ['file:///etc/passwd', false],
            'ftp-Schema' => ['ftp://example.com/img.jpg', false],
        ];
    }

    #[Test]
    #[DataProvider('urlSafetyCases')]
    public function isUrlSafeAppliesSsrfPolicy(string $url, bool $expected): void
    {
        $check = $this->buildCheck(null);

        $reflection = new ReflectionClass($check);
        $method = $reflection->getMethod('isUrlSafe');
        $method->setAccessible(true);

        $result = $method->invoke($check, $url, null);
        self::assertSame($expected, $result, sprintf('URL %s sollte %s sein', $url, $expected ? 'erlaubt' : 'blockiert'));
    }

    #[Test]
    public function allowlistRestrictsToConfiguredHostsOnly(): void
    {
        $check = $this->buildCheck('cdn.example.com,images.partner.com');

        $reflection = new ReflectionClass($check);
        $method = $reflection->getMethod('isUrlSafe');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($check, 'https://cdn.example.com/img.jpg', ['cdn.example.com', 'images.partner.com']));
        self::assertTrue($method->invoke($check, 'https://sub.cdn.example.com/img.jpg', ['cdn.example.com', 'images.partner.com']));
        self::assertFalse($method->invoke($check, 'https://evil.com/img.jpg', ['cdn.example.com', 'images.partner.com']));
    }

    private function buildCheck(?string $allowlist): ExternalUrlCheck
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnMap([
            ['RcMediaToolkit.config.healthExternalAllowlist', null, null, $allowlist ?? ''],
        ]);
        return new ExternalUrlCheck($this->createMock(Connection::class), $config);
    }
}
