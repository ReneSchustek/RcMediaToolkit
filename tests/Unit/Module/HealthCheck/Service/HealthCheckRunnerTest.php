<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\HealthCheck\Service;

use Doctrine\DBAL\Connection;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\MediaHealthCheckInterface;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository\HealthReportRepository;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Service\HealthCheckRunner;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Der Lauf, der die Prüfungen ausführt und ihre Befunde wegschreibt.
 *
 * Er entscheidet über drei Dinge, die alle drei still schiefgehen können: **ob eine Prüfung
 * überhaupt läuft** (der Schalter im Backend), **was passiert, wenn eine von ihnen abbricht**
 * (die übrigen müssen weiterlaufen), und **wann ein alter Befund als erledigt gilt**. Vor allem
 * das Letzte ist heikel: Wird nach einem Abbruch trotzdem aufgeräumt, verschwinden echte Befunde
 * aus dem Bericht, ohne dass jemand etwas repariert hätte.
 */
#[CoversClass(HealthCheckRunner::class)]
final class HealthCheckRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $statements = [];

    /**
     * Was: Eine abgeschaltete Prüfung läuft nicht.
     * Warum: Die teuerste Prüfung liest jede Datei einmal ganz. Griffe der Schalter nicht, ließe
     *        sich das auf einem großen Bestand nicht mehr abstellen — und im Bericht stünden
     *        Befunde einer Prüfung, die laut Backend aus ist.
     */
    #[Test]
    public function aDisabledCheckIsNotRunAtAll(): void
    {
        $check = new SpyCheck('missing_file', [$this->finding('missing_file')]);
        $runner = $this->runner([$check], enabled: false);

        $result = $runner->runAll();

        self::assertFalse($check->wasRun, 'Die abgeschaltete Prüfung darf nicht einmal beginnen.');
        self::assertSame([], $result);
        self::assertSame([], $this->statements, 'Ohne Lauf wird auch nichts weggeschrieben.');
    }

    /**
     * Die Gegenprobe: Eingeschaltet läuft sie, jeder Befund wird weggeschrieben und die Zahl
     * kommt zurück. Ohne diesen Fall bewiese der vorige nur, dass gar nichts passiert.
     */
    #[Test]
    public function anEnabledCheckPersistsEveryFinding(): void
    {
        $check = new SpyCheck('missing_file', [
            $this->finding('missing_file'),
            $this->finding('missing_file'),
        ]);

        $result = $this->runner([$check], enabled: true)->runAll();

        self::assertSame(['missing_file' => 2], $result);
        self::assertSame(2, $this->countStatements('INSERT INTO rc_media_health_report'));
    }

    /**
     * Was: Eine Prüfung bricht ab.
     * Warum: Sie darf die anderen nicht mitnehmen. Ein Speicher, der bei einer einzigen Datei
     *        klemmt, kostete sonst den gesamten Bericht — und der Lauf endete mit einer Meldung,
     *        die nach einem Plugin-Fehler aussieht statt nach einer kaputten Datei.
     */
    #[Test]
    public function oneFailingCheckDoesNotStopTheOthers(): void
    {
        $failing = new SpyCheck('zero_byte', [], failAfter: 0);
        $healthy = new SpyCheck('missing_file', [$this->finding('missing_file')]);

        $result = $this->runner([$failing, $healthy], enabled: true)->runAll();

        self::assertTrue($healthy->wasRun, 'Die zweite Prüfung muss trotzdem laufen.');
        self::assertSame(['missing_file' => 1], $result);
        self::assertArrayNotHasKey('zero_byte', $result, 'Eine abgebrochene Prüfung meldet keine Zahl.');
    }

    /**
     * Was: Nach einem Abbruch wird **nicht** aufgeräumt.
     * Warum: **Der gefährlichste Fall dieser Klasse.** Das Aufräumen erklärt alle Befunde für
     *        erledigt, die in diesem Lauf nicht wieder aufgetaucht sind. Nach einem Abbruch ist
     *        aber kein einziger aufgetaucht — der Bericht wäre schlagartig leer, und niemand
     *        käme auf die Idee, dass die Reparatur ausgeblieben ist.
     */
    #[Test]
    public function anAbortedCheckDoesNotResolveItsOldFindings(): void
    {
        $failing = new SpyCheck('zero_byte', [$this->finding('zero_byte')], failAfter: 1);

        $this->runner([$failing], enabled: true)->runAll();

        self::assertSame(0, $this->countStatements('SET resolved_at'), 'Nach einem Abbruch wird nichts erledigt.');
    }

    /**
     * Die Gegenprobe dazu: Nach einem sauberen Durchgang wird aufgeräumt. Ohne das bliebe jeder
     * einmal gemeldete Befund für immer im Bericht stehen, auch wenn die Datei längst wieder da
     * ist.
     */
    #[Test]
    public function aCompletedCheckResolvesWhatItNoLongerSees(): void
    {
        $this->runner([new SpyCheck('missing_file', [])], enabled: true)->runAll();

        self::assertSame(1, $this->countStatements('SET resolved_at'));
    }

    /**
     * Was: Eine einzelne Prüfung wird ausdrücklich angefordert.
     * Warum: Dann läuft sie, **auch wenn der Schalter aus ist**. Wer sie namentlich aufruft, hat
     *        sich entschieden; ein stilles Nichtstun sähe an dieser Stelle wie ein leerer Bestand
     *        aus. Das ist ein bewusster Unterschied zum Gesamtlauf und steht deshalb hier fest.
     */
    #[Test]
    public function anExplicitSingleRunIgnoresTheSwitch(): void
    {
        $check = new SpyCheck('zero_byte', [$this->finding('zero_byte')]);

        $persisted = $this->runner([$check], enabled: false)->runSingle('zero_byte');

        self::assertSame(1, $persisted);
        self::assertTrue($check->wasRun);
    }

    /**
     * Ein unbekannter Prüfungsname hält den Aufruf an, statt stillschweigend nichts zu tun. Ein
     * Tippfehler im zeitgesteuerten Aufruf sähe sonst wie ein sauberer Lauf ohne Befunde aus.
     */
    #[Test]
    public function anUnknownCheckTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runner([new SpyCheck('missing_file', [])], enabled: true)->runSingle('gibt_es_nicht');
    }

    /**
     * Die Liste der Prüfungen kommt aus den eingetragenen Diensten, nicht aus einer festen
     * Aufzählung. Sonst nennte die Hilfe des Aufrufs irgendwann eine Prüfung, die es nicht mehr
     * gibt — oder verschwiege eine neue.
     */
    #[Test]
    public function theAvailableTypesComeFromTheRegisteredChecks(): void
    {
        $runner = $this->runner([new SpyCheck('missing_file', []), new SpyCheck('zero_byte', [])], enabled: true);

        self::assertSame(['missing_file', 'zero_byte'], $runner->availableTypes());
    }

    /**
     * @param list<MediaHealthCheckInterface> $checks
     */
    private function runner(array $checks, bool $enabled): HealthCheckRunner
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($enabled);

        return new HealthCheckRunner(
            $checks,
            new HealthReportRepository($this->recordingConnection()),
            $config,
            new MediaToolkitLogger(new NullLogger()),
        );
    }

    /**
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function recordingConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql): int {
                $this->statements[] = $sql;

                return 1;
            },
        );

        return $connection;
    }

    private function countStatements(string $needle): int
    {
        $count = 0;
        foreach ($this->statements as $sql) {
            if (str_contains($sql, $needle)) {
                ++$count;
            }
        }

        return $count;
    }

    private function finding(string $type): Finding
    {
        return new Finding(
            checkType: $type,
            severity: Finding::SEVERITY_CRITICAL,
            sourceType: 'media_table',
            mediaId: 'aabbccddeeff00112233445566778899',
            sourceReference: 'media:aabbccddeeff00112233445566778899',
            urlPath: 'media/ab/bild.jpg',
            errorDetail: ['reason' => 'file_not_found'],
        );
    }
}

/**
 * Eine Prüfung, die aufschreibt, ob sie gelaufen ist, und auf Wunsch mittendrin abbricht.
 */
final class SpyCheck implements MediaHealthCheckInterface
{
    public bool $wasRun = false;

    /**
     * @param list<Finding> $findings
     * @param int|null      $failAfter Nach wie vielen Befunden abgebrochen wird
     */
    public function __construct(
        private readonly string $type,
        private readonly array $findings,
        private readonly ?int $failAfter = null,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function configKey(): string
    {
        return 'RcMediaToolkit.config.healthCheck' . ucfirst($this->type);
    }

    public function run(): Generator
    {
        $this->wasRun = true;
        $yielded = 0;

        foreach ($this->findings as $finding) {
            if ($this->failAfter !== null && $yielded >= $this->failAfter) {
                throw new RuntimeException('Speicher antwortet nicht');
            }
            ++$yielded;
            yield $finding;
        }

        if ($this->failAfter !== null && $yielded >= $this->failAfter) {
            throw new RuntimeException('Speicher antwortet nicht');
        }
    }
}
