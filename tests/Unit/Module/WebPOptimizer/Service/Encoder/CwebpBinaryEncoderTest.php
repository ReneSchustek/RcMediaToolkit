<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Service\Encoder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\CwebpBinaryEncoder;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Der Umwandler startet ein Programm auf dem Server — mit einem Pfad aus den Einstellungen.
 *
 * Damit hängt an dieser Klasse die Frage, ob jemand mit Zugang zum Backoffice ein beliebiges
 * Programm starten lassen kann. Die Fälle hier prüfen die Grenzen dieser Freiheit und die
 * Umgangsformen mit unsinnigen Werten — beides ohne einen einzigen echten Programmstart.
 */
#[CoversClass(CwebpBinaryEncoder::class)]
final class CwebpBinaryEncoderTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int}>
     */
    public static function timeoutCases(): array
    {
        return [
            'nicht gesetzt' => [null, 60],
            'leerer Text' => ['', 60],
            'kein Zahlenwert' => ['bald', 60],
            'als Text geliefert' => ['45', 45],
            'als Zahl geliefert' => [45, 45],
            'unter der Untergrenze' => [1, 5],
            'null Sekunden' => [0, 5],
            'negativ' => [-30, 5],
            'über der Obergrenze' => [9999, 600],
        ];
    }

    /**
     * Was: Die Zeitgrenze für den Programmlauf.
     * Warum: **Beide Ränder sind gefährlich.** Ohne Untergrenze bricht die Umwandlung eines
     *        großen Bildes reihenweise ab, und im Protokoll steht nur „fehlgeschlagen". Ohne
     *        Obergrenze hängt ein Arbeitsprozess unbegrenzt an einem Programm, das nicht
     *        zurückkommt — und blockiert die Warteschlange dahinter.
     */
    #[Test]
    #[DataProvider('timeoutCases')]
    public function theTimeoutStaysWithinItsBounds(mixed $configured, int $expected): void
    {
        self::assertSame($expected, $this->invoke($configured, 'encodeTimeoutSeconds'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function pathCases(): array
    {
        return [
            'üblicher Ort' => ['/usr/bin/cwebp', true],
            'zweiter üblicher Ort' => ['/usr/local/bin/cwebp', true],
            'im Ablageverzeichnis' => ['/tmp/exploit.sh', false],
            'im Heimatverzeichnis' => ['/home/nutzer/eigenes-programm', false],
            'relativer Pfad' => ['cwebp', false],
            'leer' => ['', false],
        ];
    }

    /**
     * Was: Welche Pfade als Programm zugelassen sind.
     * Warum: Wer im Backoffice einen Pfad eintragen darf, dürfte sonst jedes Skript auf dem
     *        Server starten lassen — mit den Rechten des Webservers.
     */
    #[Test]
    #[DataProvider('pathCases')]
    public function onlyAllowedLocationsPass(string $path, bool $expected): void
    {
        self::assertSame($expected, $this->invoke($path, 'isPathAllowed', $path));
    }

    /**
     * **Kein stiller Rückfall.** Ein nicht zugelassener Pfad muss zu einer Ausnahme führen und
     * nicht dazu, dass klaglos das Standardprogramm startet: Wer einen Pfad einträgt, soll
     * erfahren, dass er abgelehnt wurde — sonst läuft etwas anderes, als er glaubt.
     */
    #[Test]
    public function aForbiddenPathRaisesInsteadOfFallingBack(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nicht zugelassen/');

        $this->invoke('/tmp/exploit.sh', 'checkedBinaryPath');
    }

    /**
     * Ohne Eintrag gilt der übliche Ort — und der ist zugelassen. Ein Plugin, das ohne
     * Einstellung gar nicht erst startet, wäre für die meisten Installationen unbrauchbar.
     */
    #[Test]
    public function anEmptySettingUsesTheAllowedDefault(): void
    {
        self::assertSame('/usr/bin/cwebp', $this->invoke(null, 'checkedBinaryPath'));
        self::assertSame('/usr/bin/cwebp', $this->invoke('', 'checkedBinaryPath'));
    }

    /**
     * Ruft eine nicht öffentliche Methode auf. Der Umwandler bekommt dafür eine
     * Einstellungs-Doublette, die jeden Schlüssel mit demselben Wert beantwortet — beide
     * gefragten Werte kommen in den Fällen hier nie gemeinsam vor.
     */
    private function invoke(mixed $configured, string $method, mixed ...$arguments): mixed
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($configured);

        $encoder = new CwebpBinaryEncoder($config, new MediaToolkitLogger(new NullLogger()));

        $target = (new ReflectionClass($encoder))->getMethod($method);
        $target->setAccessible(true);

        return $target->invoke($encoder, ...$arguments);
    }
}
