<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\Repair\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use JsonException;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Dto\RepairProposal;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Führt einen ausgewählten Repair-Vorschlag aus und schreibt in einer DBAL-Transaktion
 * Apply + Audit-Insert. Bei Rollback liest den Audit-Eintrag und führt die inverse
 * Operation aus.
 *
 * Sicherheit:
 *  - Apply prüft `repairEnabled` und wirft bei `false`.
 *  - Apply + Audit + Resolve-Update laufen in einer Transaktion. Crash zwischen Steps
 *    rollt automatisch zurück.
 *  - Rollback ist eine vollwertige Inverse, kein Soft-Resolve.
 */
final class RepairExecutor
{
    private const CONFIG_KEY_ENABLED = 'RcMediaToolkit.config.repairEnabled';

    public function __construct(
        private readonly Connection $connection,
        private readonly MediaToolkitLogger $logger,
        private readonly SystemConfigService $systemConfigService,
        private readonly MediaReferenceUpdater $referenceUpdater,
    ) {
    }

    /**
     * @param array<string, mixed> $finding
     * @throws DbalException
     */
    public function apply(array $finding, RepairProposal $proposal, string $appliedBy): string
    {
        $this->assertRepairEnabled();

        $logger = $this->logger->withModule('Repair');
        $auditId = Uuid::randomHex();

        $this->connection->transactional(function (Connection $connection) use ($finding, $proposal, $appliedBy, $auditId, $logger): void {
            $beforeState = ['finding' => $this->serializableFinding($finding)];
            $afterState = ['proposalType' => $proposal->proposalType, 'payload' => $proposal->payload];

            match ($proposal->proposalType) {
                RepairProposal::TYPE_RELINK_BY_NAME => $this->applyRelink($finding, $proposal, $beforeState, $afterState),
                RepairProposal::TYPE_REGENERATE_THUMBNAIL => $this->applyRegenerateThumbnail($connection, $proposal, $beforeState, $afterState),
                RepairProposal::TYPE_REWRITE_CMS_REFERENCE => $this->applyRewriteCmsReference($finding, $proposal, $beforeState, $afterState),
                default => throw new InvalidArgumentException(sprintf('Unbekannter Proposal-Typ: %s', $proposal->proposalType)),
            };

            $findingHexId = $this->extractValidFindingId($finding);

            $this->insertAudit(
                $connection,
                $auditId,
                $findingHexId,
                $proposal->proposalType,
                $beforeState,
                $afterState,
                $appliedBy,
            );

            if ($findingHexId !== null) {
                $connection->executeStatement(
                    'UPDATE rc_media_health_report SET resolved_at = :now WHERE id = UNHEX(:id)',
                    [
                        'now' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
                        'id' => $findingHexId,
                    ],
                );
            }

            $logger->info('Repair angewendet', [
                'auditId' => $auditId,
                'proposalType' => $proposal->proposalType,
                'appliedBy' => $appliedBy,
            ]);
        });

        return $auditId;
    }

    /**
     * @throws DbalException
     */
    public function rollback(string $auditHexId): void
    {
        $this->assertRepairEnabled();
        $this->assertHexUuid($auditHexId);

        $auditRow = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, proposal_type, before_state, after_state '
            . 'FROM rc_media_repair_audit WHERE id = UNHEX(:id)',
            ['id' => $auditHexId],
        );

        if ($auditRow === false) {
            throw new InvalidArgumentException(sprintf('Audit-Eintrag %s nicht gefunden', $auditHexId));
        }

        $beforeState = json_decode((string) $auditRow['before_state'], true);
        $afterState = json_decode((string) $auditRow['after_state'], true);
        if (!is_array($beforeState) || !is_array($afterState)) {
            throw new RuntimeException('Audit-State konnte nicht decodiert werden');
        }

        $logger = $this->logger->withModule('Repair');
        $proposalType = (string) $auditRow['proposal_type'];

        $this->connection->transactional(function (Connection $connection) use ($beforeState, $afterState, $proposalType, $auditHexId, $logger): void {
            match ($proposalType) {
                RepairProposal::TYPE_RELINK_BY_NAME => $this->rollbackRelink($beforeState, $afterState),
                RepairProposal::TYPE_REWRITE_CMS_REFERENCE => $this->rollbackRewriteCms($beforeState, $afterState),
                RepairProposal::TYPE_REGENERATE_THUMBNAIL => null,
                default => throw new RuntimeException(sprintf('Rollback für %s nicht implementiert', $proposalType)),
            };

            $connection->executeStatement(
                'INSERT INTO rc_media_repair_audit (id, finding_id, proposal_type, before_state, after_state, applied_by, applied_at) '
                . 'VALUES (UNHEX(:id), NULL, :type, :before, :after, :user, :now)',
                [
                    'id' => Uuid::randomHex(),
                    'type' => 'rollback:' . $proposalType,
                    'before' => json_encode(['auditRollbackOf' => $auditHexId, 'restoredState' => $beforeState], \JSON_THROW_ON_ERROR),
                    'after' => json_encode(['previousAfterState' => $afterState], \JSON_THROW_ON_ERROR),
                    'user' => 'rollback',
                    'now' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
                ],
            );

            $logger->info('Rollback ausgeführt', ['auditId' => $auditHexId, 'proposalType' => $proposalType]);
        });
    }

    /**
     * @param array<string, mixed> $finding
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     */
    private function applyRelink(array $finding, RepairProposal $proposal, array &$beforeState, array &$afterState): void
    {
        $oldId = isset($finding['media_id']) && is_string($finding['media_id']) ? $finding['media_id'] : null;
        $newId = isset($proposal->payload['replacementMediaId']) && is_string($proposal->payload['replacementMediaId'])
            ? $proposal->payload['replacementMediaId']
            : null;

        if ($oldId === null || $newId === null) {
            throw new RuntimeException('relink_by_name: media_id im Finding oder replacementMediaId im Payload fehlt');
        }

        $touched = $this->referenceUpdater->rewriteReferences($oldId, $newId);

        $beforeState['relinkSource'] = $oldId;
        $beforeState['affectedRows'] = $touched;
        $afterState['relinkTarget'] = $newId;
        $afterState['totalAffectedRows'] = array_sum($touched);
    }

    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     */
    private function applyRegenerateThumbnail(Connection $connection, RepairProposal $proposal, array &$beforeState, array &$afterState): void
    {
        $mediaId = isset($proposal->payload['mediaId']) && is_string($proposal->payload['mediaId'])
            ? $proposal->payload['mediaId']
            : null;
        if ($mediaId === null) {
            throw new RuntimeException('mediaId fehlt im Proposal-Payload');
        }
        $this->assertHexUuid($mediaId);

        $removed = (int) $connection->executeStatement(
            'DELETE FROM media_thumbnail WHERE media_id = UNHEX(:id)',
            ['id' => $mediaId],
        );

        $beforeState['removedThumbnailRows'] = $removed;
        $afterState['note'] = 'Thumbnail-Rows entfernt — werden bei nächstem Storefront-Zugriff regeneriert.';
    }

    /**
     * @param array<string, mixed> $finding
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     */
    private function applyRewriteCmsReference(array $finding, RepairProposal $proposal, array &$beforeState, array &$afterState): void
    {
        $sourceRef = isset($finding['source_reference']) && is_string($finding['source_reference'])
            ? $finding['source_reference']
            : null;
        $oldId = isset($proposal->payload['oldMediaId']) && is_string($proposal->payload['oldMediaId'])
            ? $proposal->payload['oldMediaId']
            : null;
        $newId = isset($proposal->payload['newMediaId']) && is_string($proposal->payload['newMediaId'])
            ? $proposal->payload['newMediaId']
            : null;

        if ($sourceRef === null || $oldId === null || $newId === null) {
            throw new RuntimeException('rewrite_cms_reference: source_reference oder Payload unvollständig');
        }

        if (preg_match('/^slot:([a-f0-9]{32}):([a-f0-9]{32})$/', $sourceRef, $matches) !== 1) {
            throw new RuntimeException('rewrite_cms_reference: source_reference im falschen Format');
        }
        $slotId = $matches[1];

        $rows = $this->referenceUpdater->rewriteCmsSlotReference($slotId, $oldId, $newId);

        $beforeState['slotId'] = $slotId;
        $beforeState['rewriteFromMedia'] = $oldId;
        $afterState['rewriteToMedia'] = $newId;
        $afterState['affectedRows'] = $rows;
    }

    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     */
    private function rollbackRelink(array $beforeState, array $afterState): void
    {
        $oldId = isset($beforeState['relinkSource']) && is_string($beforeState['relinkSource'])
            ? $beforeState['relinkSource']
            : null;
        $newId = isset($afterState['relinkTarget']) && is_string($afterState['relinkTarget'])
            ? $afterState['relinkTarget']
            : null;
        if ($oldId === null || $newId === null) {
            throw new RuntimeException('Rollback relink_by_name: State unvollständig');
        }
        // Inverse — Refs, die jetzt auf newId zeigen, zurück auf oldId. Zwischen Apply und
        // Rollback hinzugekommene Refs werden ebenfalls erfasst; Audit-Zeile dokumentiert das.
        $this->referenceUpdater->rewriteReferences($newId, $oldId);
    }

    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     */
    private function rollbackRewriteCms(array $beforeState, array $afterState): void
    {
        $slotId = isset($beforeState['slotId']) && is_string($beforeState['slotId'])
            ? $beforeState['slotId']
            : null;
        $oldId = isset($beforeState['rewriteFromMedia']) && is_string($beforeState['rewriteFromMedia'])
            ? $beforeState['rewriteFromMedia']
            : null;
        $newId = isset($afterState['rewriteToMedia']) && is_string($afterState['rewriteToMedia'])
            ? $afterState['rewriteToMedia']
            : null;
        if ($slotId === null || $oldId === null || $newId === null) {
            throw new RuntimeException('Rollback rewrite_cms_reference: State unvollständig');
        }
        $this->referenceUpdater->rewriteCmsSlotReference($slotId, $newId, $oldId);
    }

    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     * @throws DbalException
     */
    private function insertAudit(
        Connection $connection,
        string $hexId,
        ?string $findingHexId,
        string $proposalType,
        array $beforeState,
        array $afterState,
        string $appliedBy,
    ): void {
        try {
            $beforeJson = json_encode($beforeState, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            $afterJson = json_encode($afterState, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('State konnte nicht serialisiert werden', 0, $e);
        }

        $idBinary = hex2bin($hexId);
        if ($idBinary === false) {
            throw new RuntimeException('Audit-ID konnte nicht in Binary konvertiert werden');
        }
        $findingBinary = null;
        if (is_string($findingHexId)) {
            $findingBinary = hex2bin($findingHexId);
            if ($findingBinary === false) {
                throw new RuntimeException('Finding-ID konnte nicht in Binary konvertiert werden');
            }
        }

        $connection->insert('rc_media_repair_audit', [
            'id' => $idBinary,
            'finding_id' => $findingBinary,
            'proposal_type' => $proposalType,
            'before_state' => $beforeJson,
            'after_state' => $afterJson,
            'applied_by' => $appliedBy,
            'applied_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
        ]);
    }

    /**
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    private function serializableFinding(array $finding): array
    {
        $clean = [];
        foreach ($finding as $key => $value) {
            $clean[(string) $key] = is_scalar($value) || $value === null || is_array($value) ? $value : (string) $value;
        }
        return $clean;
    }

    private function assertRepairEnabled(): void
    {
        if (!(bool) $this->systemConfigService->get(self::CONFIG_KEY_ENABLED)) {
            throw new RuntimeException(
                'Repair-Modul ist nicht aktiviert. Setze RcMediaToolkit.config.repairEnabled = true.',
            );
        }
    }

    private function assertHexUuid(string $hex): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $hex) !== 1) {
            throw new InvalidArgumentException(sprintf('Ungültige Hex-UUID: "%s"', $hex));
        }
    }

    /**
     * Defense-in-Depth: validiert die Finding-ID, bevor sie als binärer Wert in die DB geht.
     *
     * @param array<string, mixed> $finding
     */
    private function extractValidFindingId(array $finding): ?string
    {
        if (!isset($finding['id']) || !is_string($finding['id'])) {
            return null;
        }
        if (preg_match('/^[a-f0-9]{32}$/', $finding['id']) !== 1) {
            throw new InvalidArgumentException('Finding-ID ist keine valide Hex-UUID');
        }
        return $finding['id'];
    }
}
