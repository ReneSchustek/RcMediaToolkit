<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Controller;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use Ruhrcoder\RcMediaToolkit\Controller\Admin\GarbageCollectorController;
use Ruhrcoder\RcMediaToolkit\Controller\Admin\HealthCheckController;
use Ruhrcoder\RcMediaToolkit\Controller\Admin\StatusController;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\ExclusionMatcher;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaUsageIndexer;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\OrphanedMediaFinder;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\UsageMapFactory;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Message\CheckMediaBatchMessage;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Service\HealthCheckRunner;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Command\NamedCheck;
use Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\RecordingFilesystem;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Die Endpunkte, die das Verwaltungsfenster ansprechen kann.
 *
 * Sie sind von außen erreichbar und damit die Angriffsfläche dieses Plugins. Drei Dinge stehen
 * hier fest, und jedes davon hat einen unangenehmen Fehlerfall hinter sich:
 *
 * **Jeder Endpunkt trägt eine Rechteprüfung**, und die eingreifenden verlangen ein höheres Recht
 * als die lesenden. Eine vergessene Angabe fällt beim Ausprobieren nicht auf — der eigene Zugang
 * hat ohnehin alle Rechte —, sondern erst, wenn jemand mit weniger Rechten sie benutzt.
 *
 * **Ein Wert aus der Anfrage wird begrenzt, bevor er in eine Abfrage geht.** Eine offene
 * Obergrenze ist eine Einladung, den Shop über einen einzigen Aufruf lahmzulegen.
 *
 * **Eine ungültige Kennung ergibt eine saubere Absage, keine Ausnahme.** Die Lauf-Kennung kommt
 * aus der Anfrage und wird zu einem Pfad — an dieser Stelle entscheidet sich, ob jemand mit
 * `../` aus dem vorgesehenen Verzeichnis herauskommt.
 */
#[CoversClass(StatusController::class)]
#[CoversClass(HealthCheckController::class)]
#[CoversClass(GarbageCollectorController::class)]
final class AdminEndpointContractTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/rc-media-toolkit-api-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/media_quarantine', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    /**
     * Was: Jeder Endpunkt nennt ein Recht.
     * Warum: Ohne Angabe ist die Route für **jeden angemeldeten Zugang** offen, gleich mit
     *        welcher Rolle. Der Fehler ist im Betrieb nicht zu sehen; er zeigt sich erst, wenn
     *        jemand mit eingeschränkten Rechten etwas tut, das ihm nicht zusteht.
     */
    #[Test]
    public function everyEndpointCarriesAnAclRequirement(): void
    {
        foreach ($this->routeAttributes() as $bezeichnung => $defaults) {
            self::assertArrayHasKey('_acl', $defaults, $bezeichnung . ' hat keine Rechteprüfung.');
            self::assertNotSame([], $defaults['_acl'], $bezeichnung . ' hat eine leere Rechteprüfung.');
            self::assertSame(['api'], $defaults['_routeScope'] ?? null, $bezeichnung . ' liegt im falschen Bereich.');
        }
    }

    /**
     * Was: Eingreifende Endpunkte verlangen mehr als lesende.
     * Warum: **Zurückholen und endgültig Löschen sind Eingriffe in den Bestand.** Verlangten sie
     *        nur das Leserecht, könnte jeder, der den Bericht ansehen darf, auch Medien
     *        löschen — und genau so ist die Rollenverteilung nicht gemeint.
     */
    #[Test]
    public function changingEndpointsRequireTheHigherRight(): void
    {
        $rechte = [];
        foreach ($this->routeAttributes() as $bezeichnung => $defaults) {
            $rechte[$bezeichnung] = $defaults['_acl'];
        }

        foreach (['restore', 'purge', 'dispatchScan'] as $eingreifend) {
            $key = $this->findKey($rechte, $eingreifend);
            self::assertContains(
                'rc_media_toolkit.editor',
                $rechte[$key],
                $eingreifend . ' darf nicht mit dem Leserecht auskommen.',
            );
        }

        foreach (['status', 'findings', 'scan', 'runs'] as $lesend) {
            $key = $this->findKey($rechte, $lesend);
            self::assertContains('rc_media_toolkit.viewer', $rechte[$key]);
        }
    }

    /**
     * Was: Eine ausufernde Obergrenze aus der Anfrage.
     * Warum: Sie wird gedeckelt. Ohne die Grenze zöge ein einziger Aufruf mit `limit=1000000`
     *        den gesamten Medienbestand in den Arbeitsspeicher — aus einer Verwaltungsansicht
     *        heraus, ohne dass es dafür einen Grund gäbe.
     */
    #[Test]
    public function anExcessiveLimitIsCapped(): void
    {
        $antwort = $this->garbageCollectorController(orphans: array_map(
            static fn (int $i): string => 'bild' . $i,
            range(1, 20),
        ))->scan(new Request(['limit' => '1000000']));

        $inhalt = $this->decode($antwort->getContent());

        self::assertLessThanOrEqual(500, $inhalt['count'], 'Mehr als die Höchstzahl darf nicht herauskommen.');
    }

    /**
     * Eine Null oder eine negative Angabe ergibt mindestens einen Datensatz statt einer leeren
     * Antwort — sonst sähe ein Tippfehler in der Adresse wie ein sauberer Bestand aus.
     */
    #[Test]
    public function aZeroLimitStillReturnsSomething(): void
    {
        $antwort = $this->garbageCollectorController(orphans: ['bild1', 'bild2'])
            ->scan(new Request(['limit' => '0']));

        self::assertSame(1, $this->decode($antwort->getContent())['count']);
    }

    /**
     * Was: Eine Lauf-Kennung, die nicht dem Schema entspricht.
     * Warum: **Sie wird zu einem Verzeichnispfad.** Ohne Prüfung käme jemand mit `../../` aus
     *        dem Quarantänebereich heraus — und der Endpunkt, der aufräumt, löschte an einer
     *        Stelle, die ihn nichts angeht. Geantwortet wird mit einer Absage, nicht mit einer
     *        Ausnahme.
     */
    #[Test]
    public function anInvalidRunIdIsRefusedCleanly(): void
    {
        $controller = $this->garbageCollectorController();

        foreach (['../../etc', 'nicht-echt', ''] as $boesartig) {
            self::assertSame(400, $controller->restore($boesartig)->getStatusCode());
            self::assertSame(400, $controller->purge($boesartig)->getStatusCode());
        }
    }

    /**
     * Was: Ein unbekannter Prüfungsname in der Anfrage.
     * Warum: Absage mit Begründung statt einer leeren Ergebnisliste. Wer sich vertippt, soll das
     *        erfahren — eine leere Liste hieße „keine Befunde", und das ist eine ganz andere
     *        Auskunft.
     */
    #[Test]
    public function anUnknownCheckTypeIsRefusedInsteadOfReturningNothing(): void
    {
        $controller = $this->healthCheckController();

        $antwort = $controller->findings(new Request(['check' => 'gibt_es_nicht']));

        self::assertSame(400, $antwort->getStatusCode());
    }

    /**
     * Was: Der Anstoß einer Prüfung ohne Angabe, welcher.
     * Warum: Er wird abgelehnt. Ein Rückfall auf „dann eben alle" löste aus einer
     *        Verwaltungsansicht heraus einen Lauf über den gesamten Bestand aus — mit einem
     *        Klick, der etwas anderes versprochen hat.
     */
    #[Test]
    public function dispatchingWithoutACheckTypeIsRefused(): void
    {
        $dispatched = [];
        $controller = $this->healthCheckController($dispatched);

        $antwort = $controller->dispatchScan(new Request());

        self::assertSame(400, $antwort->getStatusCode());
        self::assertSame([], $dispatched);
    }

    /**
     * Die Gegenprobe: Mit gültigem Namen geht genau ein Auftrag hinaus, und die Antwort nennt
     * ihn zurück. Ohne die Rückmeldung wüsste die Oberfläche nicht, worauf sie wartet.
     */
    #[Test]
    public function aValidDispatchQueuesExactlyOneJob(): void
    {
        $dispatched = [];
        $controller = $this->healthCheckController($dispatched);

        $antwort = $controller->dispatchScan(new Request([], ['check' => 'missing_file']));

        self::assertSame(200, $antwort->getStatusCode());
        self::assertCount(1, $dispatched);
        self::assertSame('missing_file', $dispatched[0]->checkType);
        self::assertSame('missing_file', $this->decode($antwort->getContent())['checkType']);
    }

    /**
     * Die Übersicht fasst alle drei Module in einer Antwort zusammen — Zustand, offene Befunde
     * und Umwandlungsbestand. Sie ist die einzige Stelle, an der jemand den Gesamtzustand sieht,
     * ohne drei Aufrufe abzusetzen.
     */
    #[Test]
    public function theOverviewAnswersForAllThreeModulesAtOnce(): void
    {
        $inhalt = $this->decode($this->statusController()->status()->getContent());

        self::assertArrayHasKey('modules', $inhalt);
        self::assertArrayHasKey('health', $inhalt);
        self::assertArrayHasKey('webp', $inhalt);
        self::assertArrayHasKey('encoders', $inhalt['webp']);
        self::assertArrayHasKey('garbageCollector', $inhalt['modules']);
    }

    /**
     * Was: Die Übersicht der Quarantäne-Läufe.
     * Warum: Sie ist die einzige Stelle, an der das Verwaltungsfenster sieht, was zurückgeholt
     *        werden könnte. Zu jedem Lauf gehört die Zahl der enthaltenen Medien — ohne sie
     *        müsste man jeden Lauf einzeln öffnen.
     */
    #[Test]
    public function theRunOverviewNamesEachRunWithItsSize(): void
    {
        $runId = $this->existingRun(items: 2);

        $inhalt = $this->decode($this->garbageCollectorController()->runs()->getContent());

        self::assertCount(1, $inhalt['runs']);
        self::assertSame($runId, $inhalt['runs'][0]['runId']);
        self::assertSame(2, $inhalt['runs'][0]['itemCount']);
    }

    /**
     * Was: Zurückholen und endgültiges Löschen über die Schnittstelle.
     * Warum: Beide antworten mit einer Zahl, nicht mit einem bloßen „in Ordnung". Die
     *        Oberfläche muss dem Anwender sagen können, **wie viel** geschehen ist — sonst
     *        klickt jemand ein zweites Mal, weil er nicht sicher ist, ob es gewirkt hat.
     */
    #[Test]
    public function bothChangingEndpointsAnswerWithACount(): void
    {
        $controller = $this->garbageCollectorController();

        $restoreRun = $this->existingRun(items: 1);
        $purgeRun = $this->existingRun(items: 3);

        self::assertSame(0, $this->decode($controller->restore($restoreRun)->getContent())['restored']);
        self::assertSame(3, $this->decode($controller->purge($purgeRun)->getContent())['purged']);
    }

    /**
     * Was: Die Liste der offenen Befunde.
     * Warum: Sie trägt die Befunde **und** die Zusammenfassung nach Schwere in einer Antwort.
     *        Die Oberfläche zeigt beides nebeneinander; zwei Aufrufe dafür wären zwei
     *        Gelegenheiten, einen widersprüchlichen Stand anzuzeigen.
     */
    #[Test]
    public function theFindingsEndpointAnswersWithListAndSummary(): void
    {
        $inhalt = $this->decode(
            $this->healthCheckController(befunde: [['id' => 'aa', 'check_type' => 'missing_file']])
                ->findings(new Request())
                ->getContent(),
        );

        self::assertSame(1, $inhalt['count']);
        self::assertArrayHasKey('severitySummary', $inhalt);
        self::assertSame('missing_file', $inhalt['findings'][0]['check_type']);
    }

    /**
     * Ein bekannter Prüfungsname wird durchgelassen — sonst bewiese die Abweisung des
     * unbekannten nur, dass grundsätzlich nichts durchkommt.
     */
    #[Test]
    public function aKnownCheckTypeIsAccepted(): void
    {
        $antwort = $this->healthCheckController()->findings(new Request(['check' => 'missing_file']));

        self::assertSame(200, $antwort->getStatusCode());
    }

    /**
     * Ein unbekannter Prüfungsname beim Anstoßen wird ebenfalls abgewiesen, und die Antwort
     * zählt auf, welche es gibt. Ohne die Aufzählung bliebe nur Raten.
     */
    #[Test]
    public function anUnknownDispatchTargetIsRefusedWithTheAlternatives(): void
    {
        $dispatched = [];
        $antwort = $this->healthCheckController($dispatched)
            ->dispatchScan(new Request([], ['check' => 'gibt_es_nicht']));

        self::assertSame(400, $antwort->getStatusCode());
        self::assertSame([], $dispatched);
        self::assertContains('missing_file', $this->decode($antwort->getContent())['available']);
    }

    /**
     * Legt einen Lauf im erwarteten Schema an — der Weg, auf dem sich die Übersicht und das
     * Löschen ohne einen vollständigen Aufräumlauf prüfen lassen.
     */
    private function existingRun(int $items): string
    {
        $runId = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $verzeichnis = $this->projectDir . '/var/media_quarantine/' . $runId;
        mkdir($verzeichnis, 0o777, true);

        $entries = [];
        for ($i = 0; $i < $items; ++$i) {
            $entries[] = [
                'mediaId' => str_pad((string) $i, 32, '0', \STR_PAD_LEFT),
                'fileName' => 'bild' . $i,
                'fileExtension' => 'jpg',
                'originalPath' => 'media/bild' . $i . '.jpg',
                'quarantinedPath' => 'var/media_quarantine/' . $runId . '/bild' . $i . '.jpg',
                'quarantinedAt' => date(DateTimeInterface::ATOM),
                'size' => 1024,
                'status' => 'quarantined',
            ];
        }

        file_put_contents(
            $verzeichnis . '/manifest.json',
            json_encode(
                ['runId' => $runId, 'createdAt' => date(DateTimeInterface::ATOM), 'items' => $entries],
                \JSON_PRETTY_PRINT,
            ),
        );

        return $runId;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function routeAttributes(): array
    {
        $gefunden = [];

        foreach ([StatusController::class, HealthCheckController::class, GarbageCollectorController::class] as $controller) {
            foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Route::class) as $attribute) {
                    /** @var Route $route */
                    $route = $attribute->newInstance();
                    $gefunden[$controller . '::' . $method->getName()] = $route->getDefaults();
                }
            }
        }

        self::assertNotSame([], $gefunden, 'Ohne gefundene Routen prüft dieser Fall nichts.');

        return $gefunden;
    }

    /**
     * @param array<string, list<string>> $rechte
     */
    private function findKey(array $rechte, string $methode): string
    {
        foreach (array_keys($rechte) as $key) {
            if (str_ends_with($key, '::' . $methode)) {
                return $key;
            }
        }

        self::fail('Endpunkt nicht gefunden: ' . $methode);
    }

    /**
     * @param list<string> $orphans
     */
    private function garbageCollectorController(array $orphans = []): GarbageCollectorController
    {
        $rows = [];
        foreach ($orphans as $index => $name) {
            $rows[] = [
                'id' => str_pad((string) $index, 32, '0', \STR_PAD_LEFT),
                'file_name' => $name,
                'file_extension' => 'jpg',
                'file_size' => 1024,
                'created_at' => '2020-01-01 10:00:00',
                'media_folder_id' => null,
            ];
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shopware');
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($rows): array {
                if (str_contains($sql, 'INFORMATION_SCHEMA')) {
                    return [['TABLE_NAME' => 'product_media', 'COLUMN_NAME' => 'media_id']];
                }
                if (str_contains($sql, 'file_size')) {
                    return ($params['lastId'] ?? null) === null ? $rows : [];
                }

                return [];
            },
        );

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'RcMediaToolkit.config.gcGracePeriodDays' ? 30 : null,
        );

        $finder = new OrphanedMediaFinder(
            $connection,
            new MediaUsageIndexer(
                $connection,
                new MediaToolkitLogger(new NullLogger()),
                new MediaReferenceSources($connection),
            ),
            new ExclusionMatcher($config),
            $config,
            new UsageMapFactory($connection, $config),
        );

        return new GarbageCollectorController(
            $finder,
            new MediaQuarantineService(
                $connection,
                new RecordingFilesystem([]),
                new MediaToolkitLogger(new NullLogger()),
                $this->projectDir,
            ),
        );
    }

    /**
     * @param list<CheckMediaBatchMessage>    $dispatched
     * @param list<array<string, mixed>>      $befunde
     */
    private function healthCheckController(array &$dispatched = [], array $befunde = []): HealthCheckController
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(true);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            },
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use ($befunde): array {
                if (str_contains($sql, 'GROUP BY severity')) {
                    return $befunde === [] ? [] : [['severity' => 'critical', 'cnt' => count($befunde)]];
                }

                return $befunde;
            },
        );

        return new HealthCheckController(
            new HealthReportRepository($connection),
            $bus,
            new HealthCheckRunner(
                [new NamedCheck('missing_file')],
                new HealthReportRepository($connection),
                $config,
                new MediaToolkitLogger(new NullLogger()),
            ),
        );
    }

    private function statusController(): StatusController
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('fetchAllKeyValue')->willReturn([]);
        $connection->method('fetchOne')->willReturn('0');

        $encoderChain = new \Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\EncoderChain(
            [],
            $config,
            new MediaToolkitLogger(new NullLogger()),
        );

        return new StatusController(
            new \Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry($config),
            new HealthReportRepository($connection),
            new \Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPRegistry(
                $connection,
                new RecordingFilesystem([]),
            ),
            $encoderChain,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string|false $json): array
    {
        self::assertIsString($json);
        $gelesen = json_decode($json, true);
        self::assertIsArray($gelesen);

        return $gelesen;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }
}
