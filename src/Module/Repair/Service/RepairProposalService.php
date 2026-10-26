<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\Repair\Service;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcMediaToolkit\Module\Repair\Dto\RepairProposal;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Erzeugt Reparatur-Vorschläge für ein Finding. Pro Finding-Typ eigene Strategie.
 * Sortiert nach Confidence (höchste zuerst).
 */
final class RepairProposalService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * @param array<string, mixed> $finding Row aus rc_media_health_report
     * @return list<RepairProposal>
     */
    public function proposalsFor(array $finding): array
    {
        $checkType = (string) ($finding['check_type'] ?? '');
        $proposals = match ($checkType) {
            'missing_file' => $this->proposalsForMissingFile($finding),
            'broken_thumbnail' => $this->proposalsForBrokenThumbnail($finding),
            'broken_cms_reference' => $this->proposalsForBrokenCmsReference($finding),
            default => [],
        };

        usort(
            $proposals,
            static fn (RepairProposal $a, RepairProposal $b): int => $b->confidence <=> $a->confidence,
        );

        return $proposals;
    }

    /**
     * @param array<string, mixed> $finding
     * @return list<RepairProposal>
     */
    private function proposalsForMissingFile(array $finding): array
    {
        $mediaId = (string) ($finding['media_id'] ?? '');
        if ($mediaId === '') {
            return [];
        }

        $original = $this->connection->fetchAssociative(
            'SELECT file_name, file_extension FROM media WHERE id = UNHEX(:id)',
            ['id' => $mediaId],
        );
        if ($original === false || !isset($original['file_name'])) {
            return [];
        }

        $name = (string) $original['file_name'];
        $maxDistance = (int) ($this->systemConfigService->get('RcMediaToolkit.config.repairMaxLevenshteinDistance') ?? 3);

        $candidates = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, file_name, file_extension '
            . 'FROM media WHERE id != UNHEX(:id) AND file_name IS NOT NULL LIMIT 5000',
            ['id' => $mediaId],
        );

        $proposals = [];
        foreach ($candidates as $candidate) {
            $candidateName = (string) $candidate['file_name'];
            $distance = levenshtein($name, $candidateName);
            if ($distance > $maxDistance) {
                continue;
            }
            $confidence = max(0.0, 1.0 - $distance / max(1, $maxDistance + 1));
            $proposals[] = new RepairProposal(
                proposalType: RepairProposal::TYPE_RELINK_BY_NAME,
                description: sprintf(
                    'Verknüpfe mit Media "%s" (Distanz %d)',
                    $candidateName,
                    $distance,
                ),
                confidence: $confidence,
                payload: [
                    'replacementMediaId' => (string) $candidate['id'],
                    'distance' => $distance,
                ],
            );
        }

        // Erst sortieren, dann abschneiden: Ohne diese Reihenfolge wären die drei angebotenen
        // Vorschläge die drei, die in der Datenbank zuerst stehen — der exakte Treffer fiele
        // weg, sobald drei entferntere vor ihm liegen.
        usort(
            $proposals,
            static fn (RepairProposal $a, RepairProposal $b): int => $b->confidence <=> $a->confidence,
        );

        return array_slice($proposals, 0, 3);
    }

    /**
     * @param array<string, mixed> $finding
     * @return list<RepairProposal>
     */
    private function proposalsForBrokenThumbnail(array $finding): array
    {
        $mediaId = (string) ($finding['media_id'] ?? '');
        if ($mediaId === '') {
            return [];
        }
        return [
            new RepairProposal(
                proposalType: RepairProposal::TYPE_REGENERATE_THUMBNAIL,
                description: 'Thumbnail über Shopware-Default-Service neu generieren lassen',
                confidence: 0.9,
                payload: ['mediaId' => $mediaId],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $finding
     * @return list<RepairProposal>
     */
    private function proposalsForBrokenCmsReference(array $finding): array
    {
        $mediaId = (string) ($finding['media_id'] ?? '');
        $sourceRef = (string) ($finding['source_reference'] ?? '');
        if ($mediaId === '' || $sourceRef === '') {
            return [];
        }

        // Suche nach Medien mit ähnlichem file_name oder title — beste Treffer pro Distanz.
        $candidates = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, file_name FROM media WHERE file_name IS NOT NULL LIMIT 5000',
        );

        $maxDistance = (int) ($this->systemConfigService->get('RcMediaToolkit.config.repairMaxLevenshteinDistance') ?? 3);
        $proposals = [];
        foreach ($candidates as $candidate) {
            $distance = levenshtein($mediaId, (string) $candidate['id']);
            if ($distance > $maxDistance + 5) {
                // Bei UUIDs ist Levenshtein wenig aussagekräftig — überspringen.
                continue;
            }
            $proposals[] = new RepairProposal(
                proposalType: RepairProposal::TYPE_REWRITE_CMS_REFERENCE,
                description: sprintf(
                    'CMS-Slot-Referenz auf Media "%s" umbiegen',
                    $candidate['file_name'],
                ),
                confidence: 0.3,
                payload: [
                    'slotReference' => $sourceRef,
                    'oldMediaId' => $mediaId,
                    'newMediaId' => (string) $candidate['id'],
                ],
            );
        }

        return array_slice($proposals, 0, 3);
    }
}
