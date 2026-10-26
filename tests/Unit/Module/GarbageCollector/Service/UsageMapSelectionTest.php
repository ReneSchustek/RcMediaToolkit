<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\InMemoryUsageMap;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\TempTableUsageMap;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\UsageMapFactory;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Die Merkliste der benutzten Medien gibt es zweimal: im Arbeitsspeicher und als Tabelle.
 *
 * Welche genommen wird, entscheidet eine Einstellung. **Beide müssen sich gleich verhalten** —
 * sonst hinge es an einem Häkchen im Backend, ob ein Bild als verwaist gilt. Die Tabellen-Fassung
 * hat einen eigenen Fall (siehe [[TempTableUsageMapBatchTest]]); hier geht es um die Auswahl und
 * um die Fassung im Arbeitsspeicher.
 */
#[CoversClass(InMemoryUsageMap::class)]
#[CoversClass(UsageMapFactory::class)]
final class UsageMapSelectionTest extends TestCase
{
    /**
     * Was: Ohne Einstellung kommt die Fassung im Arbeitsspeicher.
     * Warum: Sie ist die richtige Wahl für nahezu jeden Shop. Käme die Tabellen-Fassung ohne
     *        Zutun, legte jeder Aufräumlauf eine Tabelle an, die niemand bestellt hat.
     */
    #[Test]
    public function withoutTheSettingTheInMemoryMapIsUsed(): void
    {
        self::assertInstanceOf(InMemoryUsageMap::class, $this->factory(null)->create());
        self::assertInstanceOf(InMemoryUsageMap::class, $this->factory(false)->create());
    }

    /**
     * Was: Mit gesetzter Einstellung kommt die Tabellen-Fassung.
     * Warum: Bei Millionen Medien reicht der Arbeitsspeicher nicht. Griffe die Einstellung nicht,
     *        liefe der Aufräumlauf genau dort ins Speicherlimit, wo er gebraucht wird — und der
     *        Betreiber sähe nur einen Abbruch, keine Erklärung.
     */
    #[Test]
    public function theSettingSwitchesToTheTableBackedMap(): void
    {
        self::assertInstanceOf(TempTableUsageMap::class, $this->factory(true)->create());
    }

    /**
     * Was: Jeder Aufruf liefert eine frische Merkliste.
     * Warum: Zwei Läufe hintereinander dürfen sich nicht vermischen. Käme dieselbe Liste zurück,
     *        gälten die Funde des ersten Laufs im zweiten weiter — und der zweite fände nichts
     *        mehr zu löschen, ohne dass etwas aufgeräumt worden wäre.
     */
    #[Test]
    public function everyCallReturnsAFreshMap(): void
    {
        $factory = $this->factory(false);

        $first = $factory->create();
        $first->add('0123456789abcdef0123456789abcdef');

        self::assertSame(0, $factory->create()->size(), 'Die zweite Liste beginnt leer.');
    }

    /**
     * Was: Dieselbe Kennung zweimal.
     * Warum: Ein Medium kann an vielen Stellen benutzt werden. Die Fassung im Arbeitsspeicher
     *        zählt **Medien**, nicht Fundstellen — anders als die Tabellen-Fassung. Der
     *        Unterschied steht hier fest, damit er nicht unbemerkt zur Fehlerquelle wird.
     */
    #[Test]
    public function theInMemoryMapCountsMediaNotOccurrences(): void
    {
        $map = new InMemoryUsageMap();
        $map->add('0123456789abcdef0123456789abcdef');
        $map->add('0123456789abcdef0123456789abcdef');

        self::assertSame(1, $map->size());
        self::assertTrue($map->has('0123456789abcdef0123456789abcdef'));
    }

    /**
     * Was: Eine Kennung, die nie eingetragen wurde.
     * Warum: Sie muss als „nicht benutzt" herauskommen — das ist die Antwort, aus der eine
     *        Löschempfehlung wird. Eine leere Liste, die auf alles mit „ja" antwortete, hielte
     *        den Schaden zwar auf, machte den Aufräumlauf aber wirkungslos.
     */
    #[Test]
    public function anUnknownIdIsReportedAsUnused(): void
    {
        $map = new InMemoryUsageMap();
        $map->add('0123456789abcdef0123456789abcdef');

        self::assertFalse($map->has('fedcba9876543210fedcba9876543210'));
    }

    private function factory(?bool $configured): UsageMapFactory
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'RcMediaToolkit.config.gcUseTempTableUsageMap'
                ? $configured
                : null,
        );

        return new UsageMapFactory($this->createMock(Connection::class), $config);
    }
}
