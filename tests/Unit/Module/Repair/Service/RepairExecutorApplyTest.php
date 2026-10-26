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
 * Der Reparaturlauf ist die einzige Stelle dieses Plugins, die **fremde Daten verändert**.
 *
 * Deshalb prüfen die Fälle hier nicht, ob er funktioniert, sondern ob er sich **weigert**, wo er
 * soll — und ob er, wenn er handelt, seine Spur hinterlässt. Ein Reparaturlauf ohne Protokoll ist
 * schlimmer als keiner: Man weiß hinterher nicht, was zurückzudrehen wäre.
 */
#[CoversClass(RepairExecutor::class)]
final class RepairExecutorApplyTest extends TestCase
{
    private const FINDING_ID = '0123456789abcdef0123456789abcdef';

    /**
     * Was: Das Modul ist abgeschaltet.
     * Warum: Der Schalter ist die einzige Bremse, die ein Betreiber hat. Griffe der Lauf trotzdem
     *        zu, wäre die Einstellung eine Beschriftung ohne Wirkung.
     */
    #[Test]
    public function aSwitchedOffModuleRefusesToTouchAnything(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $this->expectException(RuntimeException::class);

        $this->executor($connection, repairEnabled: false)->apply(
            ['id' => self::FINDING_ID],
            $this->proposal(RepairProposal::TYPE_RELINK_BY_NAME),
            'reviewer',
        );
    }

    /**
     * Was: Ein Vorschlagstyp, den es nicht gibt.
     * Warum: Der Fall entsteht nicht durch Tippen, sondern durch eine spätere Erweiterung, die
     *        einen Typ ergänzt und diese Stelle vergisst. Dann muss es krachen — und nicht
     *        stillschweigend nichts passieren, während das Protokoll „repariert" meldet.
     */
    #[Test]
    public function anUnknownProposalTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->executor($this->transactionalConnection())->apply(
            ['id' => self::FINDING_ID],
            $this->proposal('gibt_es_nicht'),
            'reviewer',
        );
    }

    /**
     * Was: Eine Kennung, die keine Hex-UUID ist.
     * Warum: Sie geht als Binärwert in die Datenbank. Ohne Prüfung entstünde daraus entweder ein
     *        Fehler mitten in der Transaktion oder — schlimmer — ein Treffer auf einen fremden
     *        Datensatz.
     */
    #[Test]
    public function aFindingIdThatIsNoUuidStopsTheRun(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->executor($this->transactionalConnection())->apply(
            ['id' => 'keine-uuid'],
            $this->proposal(RepairProposal::TYPE_REGENERATE_THUMBNAIL, ['mediaId' => self::FINDING_ID]),
            'reviewer',
        );
    }

    /**
     * Was: Eine Kennung im Vorschlag selbst, die keine Hex-UUID ist.
     * Warum: Sie geht als Binärwert in ein DELETE. Ohne Prüfung träfe die Anweisung entweder
     *        nichts oder den falschen Datensatz — und beides fiele erst später auf.
     */
    #[Test]
    public function aMediaIdThatIsNoUuidStopsTheRun(): void
    {
        $connection = $this->transactionalConnection();
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(InvalidArgumentException::class);

        $this->executor($connection)->apply(
            ['id' => self::FINDING_ID],
            $this->proposal(RepairProposal::TYPE_REGENERATE_THUMBNAIL, ['mediaId' => 'keine-uuid']),
            'reviewer',
        );
    }

    /**
     * Was: Der Vorschlag trägt gar keine Kennung.
     * Warum: Dann gibt es nichts zu tun — und der Lauf darf das nicht als Erfolg protokollieren.
     */
    #[Test]
    public function aProposalWithoutAMediaIdIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->executor($this->transactionalConnection())->apply(
            ['id' => self::FINDING_ID],
            $this->proposal(RepairProposal::TYPE_REGENERATE_THUMBNAIL),
            'reviewer',
        );
    }

    /**
     * Was: Der Rücklauf mit einer ungültigen Kennung.
     * Warum: Dieselbe Begründung, andere Richtung. Der Rücklauf liest zuerst — ohne Prüfung ginge
     *        eine unkontrollierte Zeichenkette in die Abfrage.
     */
    #[Test]
    public function aRollbackWithAnInvalidIdNeverReachesTheDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAssociative');

        $this->expectException(InvalidArgumentException::class);

        $this->executor($connection)->rollback('keine-uuid');
    }

    /**
     * Was: Der Rücklauf ist ebenfalls an den Schalter gebunden.
     * Warum: Ein abgeschaltetes Modul, das noch zurückdrehen kann, verändert Daten in einem
     *        Zustand, in dem der Betreiber nichts erwartet.
     */
    #[Test]
    public function aSwitchedOffModuleAlsoRefusesToRollBack(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAssociative');

        $this->expectException(RuntimeException::class);

        $this->executor($connection, repairEnabled: false)->rollback(self::FINDING_ID);
    }

    /**
     * Eine Doublette, die den übergebenen Ablauf ausführt — sonst liefe der Rumpf der
     * Transaktion nie, und die Prüfungen darin blieben unerreicht.
     */
    /**
     * @return Connection&MockObject
     */
    private function transactionalConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(
            static fn (callable $callback) => $callback($connection),
        );

        return $connection;
    }

    private function executor(Connection $connection, bool $repairEnabled = true): RepairExecutor
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($repairEnabled);

        return new RepairExecutor(
            $connection,
            new MediaToolkitLogger(new NullLogger()),
            $config,
            // Der Aktualisierer ist `final` und wird deshalb echt gebaut, mit Doubletten
            // darunter. Für die Fälle hier ist das folgenlos: Sie halten alle **vor** ihm an.
            new MediaReferenceUpdater(
                $this->createMock(Connection::class),
                new MediaReferenceSources($this->createMock(Connection::class)),
            ),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function proposal(string $type, array $payload = []): RepairProposal
    {
        return new RepairProposal($type, 'Testvorschlag', 1.0, $payload);
    }
}
