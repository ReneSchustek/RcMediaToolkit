<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Service\Encoder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\CwebpBinaryEncoder;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Der Pfad zum Fremdprogramm wird dort geprüft, wo er benutzt wird.
 *
 * Auf die Reihenfolge allein ist kein Verlass: Die Encoder-Kette fragt erst nach Verfügbarkeit,
 * und deren Ergebnis liegt zwischengespeichert — während der Pfad bei jedem Lauf frisch aus
 * der Konfiguration kommt. In einem lang laufenden Worker liegt dazwischen ein Zeitfenster,
 * in dem ein geänderter Pfad ungeprüft gestartet würde.
 */
#[CoversClass(CwebpBinaryEncoder::class)]
final class CwebpBinaryPathTest extends TestCase
{
    /**
     * Was: Ein Pfad, der nicht auf der Liste steht.
     * Warum: **Der Hauptfall.** Ein stiller Rückfall auf den Standardpfad wäre hier das
     *        Gefährlichste — wer einen nicht zugelassenen Pfad einträgt, bekäme dann klaglos
     *        ein anderes Programm gestartet.
     * Erwartet: Ausnahme, und der beanstandete Pfad steht darin.
     */
    #[Test]
    public function aPathOutsideTheAllowlistIsRefusedWhereItIsUsed(): void
    {
        $encoder = $this->encoder('/tmp/mein-eigenes-cwebp');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('~/tmp/mein-eigenes-cwebp~');

        $encoder->encode('irgendwas', 'jpeg', 80, false, true);
    }

    /**
     * Was: Keine Einstellung gesetzt.
     * Warum: Die Gegenprobe. Eine leere Einstellung bedeutet „nimm den Standardpfad", nicht
     *        „leerer Pfad" — eine Prüfung, die auch den Auslieferungszustand abweist, hätte
     *        das Modul für alle unbrauchbar gemacht, die nichts eingestellt haben.
     * Erwartet: Der Standardpfad wird genommen; die Meldung nennt ihn, nicht die Allowlist.
     */
    #[Test]
    public function anUnsetSettingFallsBackToTheAllowedDefault(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('~cwebp-Encoding fehlgeschlagen~');

        $this->encoder('')->encode('irgendwas', 'jpeg', 80, false, true);
    }

    /**
     * Was: Derselbe unzulässige Pfad über die Verfügbarkeitsprüfung.
     * Warum: Beide Wege müssen dieselbe Antwort geben. Wäre `isAvailable()` weiterhin
     *        großzügiger, hinge die Sicherheit wieder an der Reihenfolge der Aufrufe.
     */
    #[Test]
    public function theAvailabilityCheckAgreesWithTheExecutionPath(): void
    {
        self::assertFalse($this->encoder('/tmp/mein-eigenes-cwebp')->isAvailable());
    }

    private function encoder(string $path): CwebpBinaryEncoder
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key): mixed => str_contains($key, 'Path') ? $path : null,
        );

        return new CwebpBinaryEncoder($config, new MediaToolkitLogger(new NullLogger()));
    }
}
