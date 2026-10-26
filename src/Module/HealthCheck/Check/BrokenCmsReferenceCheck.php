<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Check;

use Doctrine\DBAL\Connection;
use Generator;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;

/**
 * Findet UUID-Verweise in CMS-Slot-JSON, deren Media-Eintrag fehlt.
 *
 * Idee: alle 32-Hex-Zeichen-Sequenzen aus dem JSON ziehen, gegen Media-Tabelle abgleichen.
 * Mögliche False-Positives bei nicht-Media-UUIDs in der gleichen JSON-Struktur — wird durch
 * Existenz-Check minimiert: nur wenn die UUID NICHT in media.id existiert UND als "mediaId"-
 * Wert auftaucht (Heuristik: in Slot-Config sehr typisch).
 */
final class BrokenCmsReferenceCheck implements MediaHealthCheckInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function type(): string
    {
        return 'broken_cms_reference';
    }

    public function configKey(): string
    {
        return 'RcMediaToolkit.config.healthCheckBrokenCmsReferences';
    }

    public function run(): Generator
    {
        $offset = 0;
        $batchSize = 200;

        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(cms_slot_id)) AS slot_id, config '
                . 'FROM cms_slot_translation '
                . 'WHERE config IS NOT NULL ORDER BY cms_slot_id LIMIT :limit OFFSET :offset',
                ['limit' => $batchSize, 'offset' => $offset],
                ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $config = (string) ($row['config'] ?? '');
                if ($config === '') {
                    continue;
                }

                // Nur Werte unter Schlüssel "value" hinter einer "mediaId"-Source ziehen.
                if (preg_match_all(
                    '/"source"\s*:\s*"mediaId"\s*,\s*"value"\s*:\s*"([0-9a-f]{32})"/i',
                    $config,
                    $matches,
                ) === false || empty($matches[1])) {
                    continue;
                }

                // `array_unique` behält die Schlüssel: Fällt ein Doppel heraus, entsteht eine
                // Lücke, und das Ergebnis ist keine Liste mehr. `existingMediaIds()` sagt eine
                // Liste zu — hier wird sie hergestellt statt die Zusage aufzuweichen.
                $uniqueIds = array_values(array_unique(array_map('strtolower', $matches[1])));
                $existing = $this->existingMediaIds($uniqueIds);

                foreach ($uniqueIds as $candidateId) {
                    if (isset($existing[$candidateId])) {
                        continue;
                    }
                    yield new Finding(
                        checkType: $this->type(),
                        severity: Finding::SEVERITY_CRITICAL,
                        sourceType: 'cms_slot',
                        mediaId: $candidateId,
                        sourceReference: 'slot:' . $row['slot_id'] . ':' . $candidateId,
                        errorDetail: ['reason' => 'media_id_not_in_media_table'],
                    );
                }
            }

            $offset += $batchSize;
        } while (count($rows) === $batchSize);
    }

    /**
     * @param list<string> $hexIds
     * @return array<string, true>
     */
    private function existingMediaIds(array $hexIds): array
    {
        if ($hexIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        $i = 0;
        foreach ($hexIds as $hex) {
            $key = 'id_' . $i++;
            $placeholders[] = 'UNHEX(:' . $key . ')';
            $params[$key] = $hex;
        }

        $sql = 'SELECT LOWER(HEX(id)) AS id FROM media WHERE id IN (' . implode(',', $placeholders) . ')';
        /** @var list<array{id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $set = [];
        foreach ($rows as $row) {
            $set[$row['id']] = true;
        }
        return $set;
    }
}
