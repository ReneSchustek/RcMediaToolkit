<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\Repair\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Dto\RepairProposal;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\MediaReferenceUpdater;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Service\RepairExecutor;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Was der Reparaturlauf hinterlässt, wenn er tatsächlich handelt.
 *
 * **Die Spur ist wichtiger als die Änderung.** Eine Reparatur ohne Protokolleintrag lässt sich
 * nicht zurückdrehen: Niemand weiß hinterher, welcher Zustand vorher galt. Deshalb prüfen die
 * Fälle hier nicht nur, dass etwas geschieht, sondern dass es **aufgeschrieben** wird — und dass
 * der behandelte Fund als erledigt markiert wird.
 */
#[CoversClass(RepairExecutor::class)]
final class RepairExecutorAuditTest extends TestCase
{
    private const FINDING_ID = '0123456789abcdef0123456789abcdef';
    private const MEDIA_ID = 'aaaaaaaabbbbccccddddeeeeffff0000';

    /**
     * Was: Der Lauf zum Neuaufbau der Vorschaubilder.
     * Warum: Er entfernt Zeilen aus einer fremden Tabelle. Ohne Protokoll wüsste hinterher
     *        niemand, wie viele — und ob überhaupt der richtige Datensatz getroffen wurde.
     */
    #[Test]
    public function aThumbnailRepairWritesItsAuditEntry(): void
    {
        $statements = [];
        $connection = $this->recordingConnection($statements);

        $auditId = $this->executor($connection)->apply(
            ['id' => self::FINDING_ID],
            new RepairProposal(
                RepairProposal::TYPE_REGENERATE_THUMBNAIL,
                'Vorschaubilder neu aufbauen',
                1.0,
                ['mediaId' => self::MEDIA_ID],
            ),
            'reviewer',
        );

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $auditId, 'Der Lauf gibt seine Protokoll-Kennung zurück.');

        $sql = implode("\n", array_column($statements, 'sql'));
        self::assertStringContainsString('DELETE FROM media_thumbnail', $sql, 'Die Vorschaubilder werden entfernt.');
        self::assertStringContainsString('INSERT INTO rc_media_repair_audit', $sql, 'Der Eingriff wird protokolliert.');
        self::assertStringContainsString('UPDATE rc_media_health_report', $sql, 'Der Fund wird als erledigt markiert.');
    }

    /**
     * Was: Der Protokolleintrag trägt den Vorher- und den Nachher-Zustand.
     * Warum: Genau daraus baut der Rücklauf seine Gegenbewegung. Steht dort nichts Brauchbares,
     *        ist der Eintrag ein Zeitstempel ohne Nutzen.
     */
    #[Test]
    public function theAuditEntryCarriesBothStates(): void
    {
        $statements = [];
        $connection = $this->recordingConnection($statements);

        $this->executor($connection)->apply(
            ['id' => self::FINDING_ID],
            new RepairProposal(
                RepairProposal::TYPE_REGENERATE_THUMBNAIL,
                'Vorschaubilder neu aufbauen',
                1.0,
                ['mediaId' => self::MEDIA_ID],
            ),
            'reviewer',
        );

        $audit = null;
        foreach ($statements as $statement) {
            if (str_contains($statement['sql'], 'INSERT INTO rc_media_repair_audit')) {
                $audit = $statement['params'];
            }
        }

        self::assertIsArray($audit, 'Ohne Protokolleintrag gibt es nichts zu prüfen.');
        self::assertSame('reviewer', $audit['applied_by'] ?? null);
        self::assertStringContainsString('removedThumbnailRows', (string) ($audit['before_state'] ?? ''));
        self::assertStringContainsString(self::MEDIA_ID, (string) ($audit['after_state'] ?? ''));
    }

    /**
     * Was: Ein Fund ohne Kennung.
     * Warum: Der Lauf darf trotzdem laufen — die Kennung ist für das Markieren da, nicht für die
     *        Reparatur. Ohne sie unterbleibt nur das Markieren, und zwar wortlos statt mit einer
     *        Anweisung gegen `NULL`.
     */
    #[Test]
    public function aFindingWithoutAnIdIsRepairedButNotMarked(): void
    {
        $statements = [];
        $connection = $this->recordingConnection($statements);

        $this->executor($connection)->apply(
            [],
            new RepairProposal(
                RepairProposal::TYPE_REGENERATE_THUMBNAIL,
                'Vorschaubilder neu aufbauen',
                1.0,
                ['mediaId' => self::MEDIA_ID],
            ),
            'reviewer',
        );

        $sql = implode("\n", array_column($statements, 'sql'));
        self::assertStringContainsString('INSERT INTO rc_media_repair_audit', $sql);
        self::assertStringNotContainsString('UPDATE rc_media_health_report', $sql);
    }

    /**
     * Was: Ein Rücklauf auf einen Protokolleintrag, den es nicht gibt.
     * Warum: Die Kennung kommt aus der Oberfläche. Ein Tippfehler darf nicht dazu führen, dass
     *        eine Gegenbewegung auf leeren Zustand losgelassen wird.
     */
    #[Test]
    public function aRollbackOfAnUnknownAuditEntryStops(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);
        $connection->expects(self::never())->method('transactional');

        $this->expectException(InvalidArgumentException::class);

        $this->executor($connection)->rollback(self::FINDING_ID);
    }

    /**
     * Was: Ein Protokolleintrag, dessen Zustand sich nicht lesen lässt.
     * Warum: Dann ist die Grundlage der Gegenbewegung unbekannt. Weitermachen hieße raten — und
     *        geraten wird an einer Stelle, die fremde Daten verändert, nicht.
     */
    #[Test]
    public function aRollbackWithUnreadableStateStops(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::FINDING_ID,
            'proposal_type' => RepairProposal::TYPE_RELINK_BY_NAME,
            'before_state' => 'kein json',
            'after_state' => 'auch nicht',
        ]);
        $connection->expects(self::never())->method('transactional');

        $this->expectException(RuntimeException::class);

        $this->executor($connection)->rollback(self::FINDING_ID);
    }

    /**
     * Zeichnet alle Anweisungen auf, die der Lauf absetzt, und führt den Transaktionsrumpf aus.
     *
     * @param list<array{sql: string, params: array<string, mixed>}> $statements
     *
     * @return Connection&MockObject
     */
    private function recordingConnection(array &$statements): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(
            static fn (callable $callback) => $callback($connection),
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );
        // Der Protokolleintrag geht nicht über eine Anweisung, sondern über `insert()`.
        // Wer nur Anweisungen aufzeichnet, sieht ihn nicht — und schlösse daraus, es gebe ihn
        // nicht.
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $data) use (&$statements): int {
                $statements[] = ['sql' => 'INSERT INTO ' . $table, 'params' => $data];

                return 1;
            },
        );

        return $connection;
    }

    private function executor(Connection $connection): RepairExecutor
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(true);

        return new RepairExecutor(
            $connection,
            new MediaToolkitLogger(new NullLogger()),
            $config,
            new MediaReferenceUpdater(
                $this->createMock(Connection::class),
                new MediaReferenceSources($this->createMock(Connection::class)),
            ),
        );
    }
}
