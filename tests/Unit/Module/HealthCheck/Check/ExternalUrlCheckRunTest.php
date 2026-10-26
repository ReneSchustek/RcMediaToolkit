<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check\ExternalUrlCheck;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

/**
 * Der Lauf des Erreichbarkeits-Tests — die Auswertung dessen, was von außen zurückkommt.
 *
 * Diese Prüfung ist die einzige im Plugin, die **fremde Server** anspricht. Sie ist deshalb
 * bewusst abschaltbar und wird nie von selbst aktiv. Was hier festgehalten wird, ist die
 * Auswertung der Antworten, und die trägt eine Unterscheidung, die man leicht übersieht:
 *
 * **404 ist schlimmer als 500.** Ein Serverfehler ist meist vorübergehend — der Server ist
 * überlastet, gleich geht es wieder. Ein „nicht gefunden" dagegen ist dauerhaft: Das Bild ist
 * weg, und die Seite zeigt eine Lücke, bis jemand eingreift. Deshalb ist das eine eine Warnung
 * und das andere ein kritischer Befund; die Einstufung entscheidet darüber, was zuerst
 * bearbeitet wird.
 *
 * Geprüft wird gegen vorbereitete Antworten. Ein Test, der wirklich ins Netz greift, wäre vom
 * Wetter abhängig und würde bei jedem Lauf fremde Server behelligen.
 */
#[CoversClass(ExternalUrlCheck::class)]
final class ExternalUrlCheckRunTest extends TestCase
{
    /**
     * Was: Ein Bild, das es nicht mehr gibt.
     * Warum: Der eigentliche Fund. Er wird für **jede** Fundstelle gemeldet — dasselbe Bild kann
     *        auf mehreren Seiten eingebunden sein, und jede davon zeigt jetzt eine Lücke.
     */
    #[Test]
    public function aVanishedImageIsACriticalFinding(): void
    {
        $findings = $this->findingsFor([new Response(404)]);

        self::assertCount(1, $findings);
        self::assertSame(Finding::SEVERITY_CRITICAL, $findings[0]->severity);
        self::assertSame('https://cdn.example.com/bild.jpg', $findings[0]->urlPath);
        self::assertSame(404, $findings[0]->errorDetail['httpStatus']);
    }

    /**
     * Was: Ein Serverfehler auf der Gegenseite.
     * Warum: Nur eine Warnung — er ist meist vorübergehend. Würde er gleich streng behandelt,
     *        stünde nach jeder kurzen Störung eines Fremdservers ein kritischer Befund im
     *        Bericht, und die wirklich kritischen gingen darin unter.
     */
    #[Test]
    public function aServerErrorIsOnlyAWarning(): void
    {
        $findings = $this->findingsFor([new Response(503)]);

        self::assertCount(1, $findings);
        self::assertSame(Finding::SEVERITY_WARNING, $findings[0]->severity);
    }

    /**
     * Die Gegenprobe: Antwortet der Server sauber, gibt es keinen Befund. Auch eine Umleitung
     * zählt nicht als Fehler — sie ist die übliche Antwort von Bilddiensten.
     */
    #[Test]
    public function aWorkingImageProducesNoFinding(): void
    {
        self::assertSame([], $this->findingsFor([new Response(200)]));
        self::assertSame([], $this->findingsFor([new Response(301)]));
    }

    /**
     * Was: Der Server antwortet gar nicht.
     * Warum: Auch das ist ein Befund, aber eine Warnung — unterscheiden lässt sich nicht, ob der
     *        Dienst dauerhaft weg ist oder gerade nicht erreichbar. Der Grund gehört in den
     *        Befund, sonst steht dort nur „geht nicht".
     */
    #[Test]
    public function anUnreachableServerIsReportedWithItsReason(): void
    {
        $findings = $this->findingsFor([
            new ConnectException('Zeitüberschreitung', new Request('HEAD', 'https://cdn.example.com/bild.jpg')),
        ]);

        self::assertCount(1, $findings);
        self::assertSame(Finding::SEVERITY_WARNING, $findings[0]->severity);
        self::assertStringContainsString('Zeitüberschreitung', (string) $findings[0]->errorDetail['transportError']);
    }

    /**
     * Was: Dieselbe Adresse an zwei Stellen.
     * Warum: Abgefragt wird sie einmal, gemeldet für jede Fundstelle. Andernfalls reparierte
     *        jemand die eine Seite und wunderte sich, dass der Fund bleibt.
     */
    #[Test]
    public function everyOccurrenceGetsItsOwnFinding(): void
    {
        $findings = $this->findingsFor(
            [new Response(404)],
            rows: [
                ['source_id' => 'aaaa', 'payload' => '<img src="https://cdn.example.com/bild.jpg">'],
                ['source_id' => 'bbbb', 'payload' => '<img src="https://cdn.example.com/bild.jpg">'],
            ],
        );

        self::assertCount(2, $findings);
        self::assertSame(
            ['product:aaaa', 'product:bbbb'],
            array_map(static fn (Finding $f): string => (string) $f->sourceReference, $findings),
        );
    }

    /**
     * Ohne gefundene Adressen wird keine einzige Anfrage abgesetzt. Der Lauf über einen Bestand
     * ohne fremde Bilder darf keine Verbindung nach außen aufbauen — schon gar nicht, um
     * festzustellen, dass es nichts zu prüfen gibt.
     */
    #[Test]
    public function withoutCandidatesNoRequestIsMade(): void
    {
        $handler = new MockHandler([new Response(200)]);

        $findings = $this->findingsFor([], rows: [], handler: $handler);

        self::assertSame([], $findings);
        self::assertCount(1, $handler, 'Die vorbereitete Antwort wurde nicht abgerufen.');
    }

    /**
     * Die Prüfung nennt ihre Kennung und ihren Schalter. Sie ist die einzige, die fremde Server
     * anspricht — der Schalter ist deshalb kein Beiwerk, sondern die Zustimmung dazu.
     */
    #[Test]
    public function theCheckNamesItselfAndItsSetting(): void
    {
        $check = new ExternalUrlCheck(
            $this->createMock(Connection::class),
            $this->createMock(SystemConfigService::class),
        );

        self::assertSame('external_url', $check->type());
        self::assertSame('RcMediaToolkit.config.healthCheckExternalUrls', $check->configKey());
    }

    /**
     * Was: Eine Freigabeliste, die den gefundenen Server nicht enthält.
     * Warum: Dann wird er **nicht** abgefragt. Die Liste ist die Zusage an den Betreiber, dass
     *        sein Shop nur die Server anspricht, die er benannt hat — eine Prüfung, die
     *        darüber hinausgeht, wäre ein Besuch bei Dritten ohne Auftrag.
     */
    #[Test]
    public function aHostOutsideTheAllowlistIsNeverContacted(): void
    {
        $handler = new MockHandler([new Response(404)]);

        $findings = $this->findingsFor([], handler: $handler, allowlist: 'nur.dieser.example');

        self::assertSame([], $findings);
        self::assertCount(1, $handler, 'Es darf keine Anfrage abgesetzt worden sein.');
    }

    /**
     * Die Gegenprobe: Steht der Server auf der Liste, wird er abgefragt. Ohne sie bewiese der
     * vorige Fall nur, dass mit Liste nie etwas passiert.
     */
    #[Test]
    public function aHostOnTheAllowlistIsChecked(): void
    {
        $findings = $this->findingsFor([new Response(404)], allowlist: 'cdn.example.com');

        self::assertCount(1, $findings);
    }

    /**
     * @param list<Response|Throwable>  $responses
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Finding>
     */
    private function findingsFor(
        array $responses,
        ?array $rows = null,
        ?MockHandler $handler = null,
        ?string $allowlist = null,
    ): array {
        $handler ??= new MockHandler($responses);

        $rows ??= [['source_id' => 'aaaa', 'payload' => '<img src="https://cdn.example.com/bild.jpg">']];

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturnCallback(
            /** @param list<string> $tables */
            static fn (array $tables): bool => $tables === ['product_translation'],
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('fetchAllAssociative')->willReturn($rows);

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($allowlist);

        $check = new ExternalUrlCheck(
            $connection,
            $config,
            new Client(['handler' => HandlerStack::create($handler), 'http_errors' => false]),
        );

        return iterator_to_array($check->run(), false);
    }
}
