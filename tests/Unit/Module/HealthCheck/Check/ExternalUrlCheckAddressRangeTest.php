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
 * Die Adressbereiche, die der Erreichbarkeits-Test **nicht** anfassen darf.
 *
 * Der Test ruft Adressen ab, die in Inhalten stehen — also Adressen, die jemand von außen dort
 * hineinschreiben kann. Ohne diese Grenzen wird daraus ein Werkzeug, mit dem sich das interne
 * Netz des Servers abklopfen lässt: Nachbarrechner, Metadatendienste der Cloud,
 * Verwaltungsoberflächen hinter der Firewall.
 *
 * Die Prüfung deckt deshalb genau die Bereiche ab, die eine schlichte „ist das privat?"-Abfrage
 * **nicht** kennt.
 */
#[CoversClass(ExternalUrlCheck::class)]
final class ExternalUrlCheckAddressRangeTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function addressCases(): array
    {
        return [
            // Diese vier stehen in keiner der eingebauten Filterlisten von PHP.
            'Anbieter-NAT 100.64.0.1' => ['100.64.0.1', true],
            'Anbieter-NAT Obergrenze' => ['100.127.255.254', true],
            'knapp darunter, öffentlich' => ['100.63.255.255', false],
            'knapp darüber, öffentlich' => ['100.128.0.1', false],

            // Der Weg, mit dem sich eine Sperre auf Zeichenketten umgehen lässt.
            'Rückschleife als IPv6-abgebildete IPv4' => ['::ffff:127.0.0.1', true],
            'privates Netz als IPv6-abgebildete IPv4' => ['::ffff:10.0.0.1', true],
            'öffentliche Adresse, IPv6-abgebildet' => ['::ffff:93.184.216.34', false],
            'dasselbe in Großschreibung' => ['::FFFF:127.0.0.1', true],

            'Metadatendienst der Cloud' => ['169.254.169.254', true],
            'Rückschleife' => ['127.0.0.1', true],
            'öffentliche Adresse' => ['93.184.216.34', false],
        ];
    }

    #[Test]
    #[DataProvider('addressCases')]
    public function specialRangesAreRecognised(string $ip, bool $expectedBlocked): void
    {
        self::assertSame($expectedBlocked, $this->invoke('isPrivateOrSpecialIp', $ip));
    }

    /**
     * Die Bereichsrechnung selbst — sie trägt die vier Fälle oben.
     */
    #[Test]
    public function theRangeCalculationHoldsAtItsEdges(): void
    {
        self::assertTrue($this->invoke('isInIpv4Range', '10.0.0.5', '10.0.0.0', 8));
        self::assertTrue($this->invoke('isInIpv4Range', '10.255.255.255', '10.0.0.0', 8));
        self::assertFalse($this->invoke('isInIpv4Range', '11.0.0.1', '10.0.0.0', 8));

        // Kein gültiges Muster: Die Rechnung darf dabei nicht werfen, sondern verneinen.
        self::assertFalse($this->invoke('isInIpv4Range', 'kein-ip', '10.0.0.0', 8));
        self::assertFalse($this->invoke('isInIpv4Range', '::1', '10.0.0.0', 8));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function hostCases(): array
    {
        return [
            'localhost' => ['localhost', true],
            'nulladresse' => ['0.0.0.0', true],
            'IPv6-Rückschleife' => ['::1', true],
            'Rundruf' => ['broadcasthost', true],
            'Endung .localhost' => ['dev.localhost', true],
            'Endung .local' => ['drucker.local', true],
            'Endung .internal' => ['api.internal', true],
            'gewöhnlicher Name' => ['cdn.example.com', false],
            'Name mit local im Wort' => ['localbusiness.example.com', false],
        ];
    }

    #[Test]
    #[DataProvider('hostCases')]
    public function blockedHostsAreRecognised(string $host, bool $expectedBlocked): void
    {
        self::assertSame($expectedBlocked, $this->invoke('isBlockedHost', $host));
    }

    /**
     * **Die Gegenprobe zum Namensmuster.** „localbusiness.example.com" endet nicht auf `.local`
     * — wer die Prüfung als „enthält" statt als „endet auf" schriebe, sperrte einen völlig
     * gewöhnlichen Anbieter aus, und niemand fände den Grund.
     */
    #[Test]
    public function anOrdinaryHostIsNotCaughtByTheSuffixRule(): void
    {
        self::assertFalse($this->invoke('isBlockedHost', 'localbusiness.example.com'));
        self::assertFalse($this->invoke('isBlockedHost', 'internal-affairs.example.org'));
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $check = new ExternalUrlCheck(
            $this->createMock(Connection::class),
            $this->createMock(SystemConfigService::class),
        );

        $reflection = new ReflectionClass($check);
        $target = $reflection->getMethod($method);
        $target->setAccessible(true);

        return $target->invoke($check, ...$arguments);
    }
}
