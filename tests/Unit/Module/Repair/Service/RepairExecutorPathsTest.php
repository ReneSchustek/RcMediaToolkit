<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\Repair\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
 * Die drei Reparaturwege und ihr Rückweg.
 *
 * Eine Reparatur ändert Verweise im laufenden Shop. Das ist der einzige Vorgang dieses Plugins,
 * der Daten **umschreibt** statt sie nur zu verschieben — und damit der, bei dem ein Fehler am
 * teuersten ist. Drei Zusagen tragen ihn:
 *
 * **Nichts ohne Freischaltung.** Das Reparaturmodul ist im Auslieferungszustand aus; solange es
 * das ist, endet jeder Weg mit einer Ausnahme, nicht mit einer stillen Änderung.
 *
 * **Nichts ohne Prüfspur.** Jede Reparatur schreibt fest, was vorher galt. Ohne diesen Eintrag
 * gibt es keinen Rückweg — und der Rückweg ist das Einzige, was einen automatischen Eingriff in
 * fremde Daten vertretbar macht.
 *
 * **Nichts halb.** Änderung und Prüfspur liegen in einer Transaktion. Ein Abbruch dazwischen
 * hinterließe geänderte Verweise ohne Aufzeichnung, wie sie vorher aussahen.
 */
#[CoversClass(RepairExecutor::class)]
final class RepairExecutorPathsTest extends TestCase
{
    private const MEDIA_ID = 'aabbccddeeff00112233445566778899';

    private const ERSATZ_ID = '11112222333344445555666677778888';

    private const SLOT_ID = '99998888777766665555444433332222';

    /** @var list<array{sql: string, params: array<string, mixed>}> */
    private array $statements = [];

    protected function setUp(): void
    {
        $this->statements = [];
    }

    /**
     * @return array<string, array{RepairProposal}>
     */
    public static function proposals(): array
    {
        return [
            'neu verknüpfen' => [new RepairProposal(
                proposalType: RepairProposal::TYPE_RELINK_BY_NAME,
                description: 'Mit einem anderen Medium verknüpfen',
                confidence: 0.9,
                payload: ['replacementMediaId' => self::ERSATZ_ID],
            )],
            'Vorschaubild neu' => [new RepairProposal(
                proposalType: RepairProposal::TYPE_REGENERATE_THUMBNAIL,
                description: 'Vorschaubild neu berechnen lassen',
                confidence: 0.9,
                payload: ['mediaId' => self::MEDIA_ID],
            )],
            'Verweis umbiegen' => [new RepairProposal(
                proposalType: RepairProposal::TYPE_REWRITE_CMS_REFERENCE,
                description: 'Verweis in der Erlebniswelt umbiegen',
                confidence: 0.3,
                payload: ['oldMediaId' => self::MEDIA_ID, 'newMediaId' => self::ERSATZ_ID, 'slotReference' => 'slot'],
            )],
        ];
    }

    /**
     * Was: Jeder Reparaturweg bei abgeschaltetem Modul.
     * Warum: **Er endet mit einer Ausnahme, bevor irgendetwas geschieht.** Die Freischaltung ist
     *        die Zusage, dass dieses Plugin fremde Daten nur anfasst, wenn jemand das ausdrücklich
     *        wollte — sie muss an jedem Eingang greifen und nicht nur an einem.
     */
    #[Test]
    #[DataProvider('proposals')]
    public function nothingHappensWhileTheModuleIsSwitchedOff(RepairProposal $proposal): void
    {
        $executor = $this->executor(enabled: false);

        try {
            $executor->apply($this->finding(), $proposal, 'test');
            self::fail('Bei abgeschaltetem Modul darf keine Reparatur laufen.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('repairEnabled', $exception->getMessage());
        }

        self::assertSame([], $this->statements, 'Es darf keine einzige Anweisung abgesetzt worden sein.');
    }

    /**
     * Was: Jeder Reparaturweg hinterlässt eine Prüfspur.
     * Warum: **Ohne sie gibt es keinen Rückweg.** Der Eintrag hält fest, was vorher galt; erst
     *        er macht aus einem Eingriff in fremde Daten einen umkehrbaren Vorgang.
     */
    #[Test]
    #[DataProvider('proposals')]
    public function everyRepairLeavesAnAuditEntry(RepairProposal $proposal): void
    {
        $auditId = $this->executor()->apply($this->finding($proposal->proposalType), $proposal, 'cli:tester');

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $auditId);
        self::assertSame(1, $this->countStatements('rc_media_repair_audit'), 'Genau ein Eintrag je Reparatur.');
    }

    /**
     * Was: Der Befund gilt danach als erledigt.
     * Warum: Sonst stünde er beim nächsten Blick in den Bericht wieder da, und jemand
     *        reparierte ein zweites Mal, was längst repariert ist.
     */
    #[Test]
    public function theFindingIsMarkedAsResolvedAfterwards(): void
    {
        $this->executor()->apply(
            $this->finding(RepairProposal::TYPE_REGENERATE_THUMBNAIL),
            self::proposals()['Vorschaubild neu'][0],
            'cli:tester',
        );

        self::assertSame(1, $this->countStatements('SET resolved_at'));
    }

    /**
     * Was: Ein Vorschlag, dem die nötige Angabe fehlt.
     * Warum: Er endet mit einer Ausnahme, bevor etwas geändert wird. Der Fall entsteht, wenn
     *        sich der Bestand zwischen Vorschlag und Ausführung geändert hat — dann fehlt lieber
     *        die Reparatur als dass sie auf das falsche Medium zeigt.
     */
    #[Test]
    public function anIncompleteProposalIsRefusedBeforeAnythingChanges(): void
    {
        $executor = $this->executor();

        $ohneZiel = new RepairProposal(
            proposalType: RepairProposal::TYPE_RELINK_BY_NAME,
            description: 'ohne Ziel',
            confidence: 0.5,
            payload: [],
        );

        $this->expectException(RuntimeException::class);

        $executor->apply($this->finding(), $ohneZiel, 'test');
    }

    /**
     * Was: Eine Fundstelle im falschen Format.
     * Warum: Aus ihr wird die Kennung des Bausteins gelesen, in dem umgeschrieben wird. Passt
     *        das Format nicht, ist unklar, **wo** geändert würde — und dann wird gar nicht
     *        geändert.
     */
    #[Test]
    public function aMalformedSourceReferenceStopsTheRewrite(): void
    {
        $proposal = new RepairProposal(
            proposalType: RepairProposal::TYPE_REWRITE_CMS_REFERENCE,
            description: 'Verweis umbiegen',
            confidence: 0.3,
            payload: ['oldMediaId' => self::MEDIA_ID, 'newMediaId' => self::ERSATZ_ID],
        );

        $finding = $this->finding();
        $finding['source_reference'] = 'irgendwas-anderes';

        $this->expectException(RuntimeException::class);

        $this->executor()->apply($finding, $proposal, 'test');
    }

    /**
     * Was: Ein unbekannter Reparaturtyp.
     * Warum: Er wird abgelehnt, statt stillschweigend nichts zu tun. Ein Vorschlagstyp, den
     *        niemand ausführen kann, wäre sonst eine Reparatur, die als erfolgreich gilt und
     *        nichts bewirkt hat.
     */
    #[Test]
    public function anUnknownRepairTypeIsRefused(): void
    {
        $proposal = new RepairProposal(
            proposalType: 'gibt_es_nicht',
            description: 'unbekannt',
            confidence: 1.0,
            payload: [],
        );

        $this->expectException(InvalidArgumentException::class);

        $this->executor()->apply($this->finding(), $proposal, 'test');
    }

    /**
     * Was: Die Rücknahme eines Vorgangs, den es nicht gibt.
     * Warum: Sie wird abgelehnt. Eine stille Erfolgsmeldung wäre die schlimmste Auskunft
     *        überhaupt — jemand glaubte, der alte Zustand sei wiederhergestellt.
     */
    #[Test]
    public function rollingBackAnUnknownOperationIsRefused(): void
    {
        $connection = $this->recordingConnection();
        $connection->method('fetchAssociative')->willReturn(false);

        $this->expectException(InvalidArgumentException::class);

        $this->executorWith($connection)->rollback(self::MEDIA_ID);
    }

    /**
     * Was: Eine Kennung, die keine ist.
     * Warum: Sie geht in eine Abfrage. Geprüft wird das Format, bevor sie irgendwo landet —
     *        derselbe Gedanke wie bei den Pfaden der Quarantäne.
     */
    #[Test]
    public function aMalformedAuditIdNeverReachesTheDatabase(): void
    {
        $executor = $this->executor();

        foreach (["'; DROP TABLE media; --", 'zu-kurz', ''] as $unsinn) {
            try {
                $executor->rollback($unsinn);
                self::fail(sprintf('Die Kennung "%s" hätte abgelehnt werden müssen.', $unsinn));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1); // Der erwartete Abbruch ist eingetreten — das ist die Prüfung.
            }
        }

        self::assertSame([], $this->statements);
    }

    /**
     * Was: Die Rücknahme einer Neu-Verknüpfung.
     * Warum: Sie schreibt die Verweise zurück **und** hinterlässt selbst einen Eintrag in der
     *        Prüfspur. Eine Rücknahme ohne eigenen Eintrag wäre eine Änderung ohne Nachweis —
     *        und die Spur soll den vollständigen Verlauf zeigen, nicht nur die Hinwege.
     */
    #[Test]
    public function aRollbackIsItselfRecorded(): void
    {
        $connection = $this->recordingConnection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::MEDIA_ID,
            'proposal_type' => RepairProposal::TYPE_RELINK_BY_NAME,
            'before_state' => json_encode(['relinkSource' => self::MEDIA_ID, 'affectedRows' => []]),
            'after_state' => json_encode(['relinkTarget' => self::ERSATZ_ID]),
        ]);

        $this->executorWith($connection)->rollback(self::MEDIA_ID);

        self::assertSame(1, $this->countStatements('rc_media_repair_audit'));
        self::assertStringContainsString(
            'rollback:',
            (string) $this->firstParam('rc_media_repair_audit', 'type'),
            'Der Eintrag muss als Rücknahme erkennbar sein.',
        );
    }

    /**
     * Eine Prüfspur mit unlesbarem Inhalt wird nicht ausgeführt. Sie könnte von Hand geändert
     * worden sein; dann ist der beschriebene Zustand unbekannt, und ein Zurückschreiben ins
     * Blaue wäre schlimmer als keines.
     */
    #[Test]
    public function anUnreadableAuditStateStopsTheRollback(): void
    {
        $connection = $this->recordingConnection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::MEDIA_ID,
            'proposal_type' => RepairProposal::TYPE_RELINK_BY_NAME,
            'before_state' => 'kein JSON',
            'after_state' => 'auch nicht',
        ]);

        $this->expectException(RuntimeException::class);

        $this->executorWith($connection)->rollback(self::MEDIA_ID);
    }

    /**
     * Was: Die Rücknahme eines umgebogenen Verweises.
     * Warum: Sie tauscht die beiden Kennungen zurück — in **derselben** Erlebniswelt und nur
     *        dort. Griffe sie breiter, machte die Rücknahme mehr rückgängig als die Reparatur
     *        angerichtet hat.
     */
    #[Test]
    public function rollingBackACmsRewriteSwapsTheIdsBack(): void
    {
        $connection = $this->recordingConnection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::MEDIA_ID,
            'proposal_type' => RepairProposal::TYPE_REWRITE_CMS_REFERENCE,
            'before_state' => json_encode([
                'slotId' => self::SLOT_ID,
                'rewriteFromMedia' => self::MEDIA_ID,
            ]),
            'after_state' => json_encode(['rewriteToMedia' => self::ERSATZ_ID]),
        ]);

        $this->executorWith($connection)->rollback(self::MEDIA_ID);

        $anweisungen = implode(chr(10), array_column($this->statements, 'sql'));
        self::assertStringContainsString('cms_slot_id = UNHEX(:slotId)', $anweisungen);
        self::assertSame(1, $this->countStatements('rc_media_repair_audit'));
    }

    /**
     * Was: Die Rücknahme eines neu berechneten Vorschaubildes.
     * Warum: Es gibt nichts zurückzunehmen — die Bilder entstehen beim nächsten Zugriff von
     *        selbst neu. Trotzdem wird der Vorgang in der Prüfspur vermerkt, damit der Verlauf
     *        vollständig bleibt.
     */
    #[Test]
    public function rollingBackAThumbnailRepairOnlyRecordsIt(): void
    {
        $connection = $this->recordingConnection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::MEDIA_ID,
            'proposal_type' => RepairProposal::TYPE_REGENERATE_THUMBNAIL,
            'before_state' => json_encode(['removedThumbnailRows' => 3]),
            'after_state' => json_encode(['note' => 'egal']),
        ]);

        $this->executorWith($connection)->rollback(self::MEDIA_ID);

        self::assertSame(1, $this->countStatements('rc_media_repair_audit'));
    }

    /**
     * Was: Eine Prüfspur, deren aufgezeichneter Zustand unvollständig ist.
     * Warum: Dann wird **nicht** zurückgeschrieben. Der Zustand ist die einzige Quelle dafür,
     *        was vorher galt; fehlt eine Angabe, wäre jedes Zurückschreiben geraten.
     */
    #[Test]
    public function anIncompleteRecordedStateStopsTheRollback(): void
    {
        foreach ([
            RepairProposal::TYPE_RELINK_BY_NAME,
            RepairProposal::TYPE_REWRITE_CMS_REFERENCE,
        ] as $typ) {
            $connection = $this->recordingConnection();
            $connection->method('fetchAssociative')->willReturn([
                'id' => self::MEDIA_ID,
                'proposal_type' => $typ,
                'before_state' => json_encode(['unvollständig' => true]),
                'after_state' => json_encode([]),
            ]);

            try {
                $this->executorWith($connection)->rollback(self::MEDIA_ID);
                self::fail('Ein unvollständiger Zustand darf nicht zurückgeschrieben werden.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1); // Der erwartete Abbruch ist eingetreten — das ist die Prüfung.
            }
        }
    }

    /**
     * Ein Vorgangstyp, für den es keine Rücknahme gibt, wird abgewiesen. Der Fall entsteht,
     * wenn eine ältere Fassung des Plugins einen Typ geschrieben hat, den die heutige nicht
     * mehr kennt — dann ist Nichtstun mit Meldung die einzig ehrliche Antwort.
     */
    #[Test]
    public function anUnknownRecordedTypeIsRefused(): void
    {
        $connection = $this->recordingConnection();
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::MEDIA_ID,
            'proposal_type' => 'gibt_es_nicht_mehr',
            'before_state' => json_encode([]),
            'after_state' => json_encode([]),
        ]);

        $this->expectException(RuntimeException::class);

        $this->executorWith($connection)->rollback(self::MEDIA_ID);
    }

    /**
     * Was: Ein Vorschlag zum Umbiegen ohne die Kennungen im Gepäck.
     * Warum: Er endet mit einer Ausnahme, bevor etwas geändert wird — dieselbe Zusage wie beim
     *        Neu-Verknüpfen. Ein Vorschlag ohne Ziel ist kein Vorschlag.
     */
    #[Test]
    public function aCmsProposalWithoutIdsIsRefused(): void
    {
        $proposal = new RepairProposal(
            proposalType: RepairProposal::TYPE_REWRITE_CMS_REFERENCE,
            description: 'ohne Kennungen',
            confidence: 0.3,
            payload: [],
        );

        $this->expectException(RuntimeException::class);

        $this->executor()->apply($this->finding(RepairProposal::TYPE_REWRITE_CMS_REFERENCE), $proposal, 'test');
    }

    /**
     * Was: Ein Befund, dessen Kennung keine gültige Hexdarstellung ist.
     * Warum: Der Vorgang bricht ab, und die Prüfspur bleibt leer. Aus der Kennung wird der
     *        Binärwert für den Eintrag; ließe man sie durch, entstünde eine Spur, die keinem
     *        Befund mehr zuzuordnen ist — und damit ein Rückweg, den niemand findet.
     *
     *        Die eigentliche Änderung liegt mit dem Eintrag in **einer** Transaktion. Der
     *        Abbruch nimmt sie deshalb mit; was hier zählt, ist, dass kein halber Zustand
     *        zurückbleibt.
     */
    #[Test]
    public function aFindingIdThatIsNoHexValueStopsTheRepair(): void
    {
        $finding = $this->finding(RepairProposal::TYPE_REGENERATE_THUMBNAIL);
        $finding['id'] = 'keine-hexdarstellung';

        try {
            $this->executor()->apply($finding, self::proposals()['Vorschaubild neu'][0], 'test');
            self::fail('Eine unbrauchbare Befund-Kennung hätte den Vorgang anhalten müssen.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $this->countStatements('rc_media_repair_audit'), 'Keine Spur ohne gültige Zuordnung.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $proposalType = RepairProposal::TYPE_RELINK_BY_NAME): array
    {
        return [
            'id' => self::MEDIA_ID,
            'media_id' => self::MEDIA_ID,
            'check_type' => 'missing_file',
            'severity' => 'critical',
            'source_type' => 'media_table',
            'source_reference' => $proposalType === RepairProposal::TYPE_REWRITE_CMS_REFERENCE
                ? 'slot:' . self::SLOT_ID . ':' . self::MEDIA_ID
                : 'media:' . self::MEDIA_ID,
            'error_detail' => null,
            'url_path' => 'media/ab/bild.jpg',
        ];
    }

    private function executor(bool $enabled = true): RepairExecutor
    {
        return $this->executorWith($this->recordingConnection(), $enabled);
    }

    private function executorWith(Connection $connection, bool $enabled = true): RepairExecutor
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($enabled);

        $logger = new MediaToolkitLogger(new NullLogger());

        return new RepairExecutor(
            $connection,
            $logger,
            $config,
            new MediaReferenceUpdater($connection, new MediaReferenceSources($connection)),
        );
    }

    /**
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function recordingConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shopware');
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'INFORMATION_SCHEMA')) {
                    return [['TABLE_NAME' => 'product_media', 'COLUMN_NAME' => 'media_id']];
                }

                return [];
            },
        );
        $connection->method('transactional')->willReturnCallback(
            static fn (callable $work): mixed => $work($connection),
        );
        // Der Eintrag in die Prüfspur geht über insert(), die übrigen Änderungen über
        // executeStatement(); wer nur eines mitschreibt, sieht die halbe Wahrheit.
        $connection->method('insert')->willReturnCallback(
            /** @param array<string, mixed> $data */
            function (string $table, array $data = []): int {
                $this->statements[] = ['sql' => 'INSERT INTO ' . $table, 'params' => $data];

                return 1;
            },
        );
        $connection->method('executeStatement')->willReturnCallback(
            /** @param array<string, mixed> $params */
            function (string $sql, array $params = []): int {
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );

        return $connection;
    }

    private function countStatements(string $needle): int
    {
        $count = 0;
        foreach ($this->statements as $statement) {
            if (str_contains($statement['sql'], $needle)) {
                ++$count;
            }
        }

        return $count;
    }

    private function firstParam(string $needle, string $param): mixed
    {
        foreach ($this->statements as $statement) {
            if (str_contains($statement['sql'], $needle)) {
                return $statement['params'][$param] ?? null;
            }
        }

        return null;
    }
}
