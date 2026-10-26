<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Exception;
use Generator;
use InvalidArgumentException;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Phase 2 — Comparison. Set-Difference: alle Media-IDs minus die referenzierten,
 * gefiltert nach Grace-Period und Exclusion-Patterns.
 *
 * Zwei Strategien:
 *  - In-Memory-Map (Default): pro Row Set-Lookup. Schnell bis ~5 M Medien.
 *  - TempTable + LEFT JOIN: Set-Difference auf SQL-Ebene. Skaliert auf 10 M+ Medien.
 *    Aktivierung über `gcUseTempTableUsageMap = true`.
 *
 * Pagination: beide Pfade nutzen ID-Cursor (`WHERE id > UNHEX(:lastId)`) statt OFFSET —
 * `OFFSET N` ist O(N) in MySQL, was bei n-ter Seite eines 10M-Stores katastrophale
 * Latenz erzeugen würde.
 */
final class OrphanedMediaFinder
{
    private const GRACE_CONFIG_KEY = 'RcMediaToolkit.config.gcGracePeriodDays';
    private const DEFAULT_GRACE_DAYS = 30;
    private const DEFAULT_BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly MediaUsageIndexer $usageIndexer,
        private readonly ExclusionMatcher $exclusionMatcher,
        private readonly SystemConfigService $systemConfigService,
        private readonly UsageMapFactory $usageMapFactory,
    ) {
    }

    /**
     * @return Generator<int, OrphanedMedia>
     * @throws DbalException
     */
    public function findOrphans(int $batchSize = self::DEFAULT_BATCH_SIZE, ?int $limit = null): Generator
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('batchSize muss >= 1 sein');
        }

        $usageMap = $this->usageIndexer->buildUsageMap($this->usageMapFactory->create());
        $graceCutoff = $this->resolveGraceCutoff();

        if ($usageMap instanceof TempTableUsageMap) {
            yield from $this->findOrphansViaTempTable($usageMap, $graceCutoff, $batchSize, $limit);
            return;
        }

        yield from $this->findOrphansInMemory($usageMap, $graceCutoff, $batchSize, $limit);
    }

    /**
     * ID-Cursor statt OFFSET: bei Millionen-Stores ist `OFFSET N` O(N), die n-te Seite
     * würde N*batchSize Rows skippen. Wir tracken stattdessen die letzte gesehene Hex-ID
     * und filtern `WHERE id > UNHEX(:lastId)` — konstante Page-Latenz.
     *
     * @return Generator<int, OrphanedMedia>
     * @throws DbalException
     */
    private function findOrphansInMemory(
        UsageMapInterface $usageMap,
        DateTimeImmutable $graceCutoff,
        int $batchSize,
        ?int $limit,
    ): Generator {
        $yielded = 0;
        $lastId = null;

        do {
            $rows = $this->fetchMediaBatchAfter($lastId, $batchSize);
            $rowCount = count($rows);
            if ($rowCount === 0) {
                return;
            }

            foreach ($rows as $row) {
                $hexId = strtolower((string) $row['id']);
                $lastId = $hexId;

                if ($usageMap->has($hexId)) {
                    continue;
                }

                if ($this->exclusionMatcher->isExcluded((string) $row['file_name'])) {
                    continue;
                }

                $created = $this->parseDate((string) $row['created_at']);
                if ($created > $graceCutoff) {
                    continue;
                }

                yield new OrphanedMedia(
                    mediaId: $hexId,
                    path: $this->buildMediaPath($row),
                    size: (int) $row['file_size'],
                    createdAt: $created,
                    fileName: (string) $row['file_name'] . ($row['file_extension'] !== null ? '.' . $row['file_extension'] : ''),
                    sourceType: OrphanedMedia::SOURCE_DB_ORPHAN,
                );

                $yielded++;
                if ($limit !== null && $yielded >= $limit) {
                    return;
                }
            }
        } while ($rowCount === $batchSize);
    }

    /**
     * SQL-LEFT-JOIN-Variante mit ID-Cursor: skaliert auf 10M+-Stores.
     *
     * @return Generator<int, OrphanedMedia>
     * @throws DbalException
     */
    private function findOrphansViaTempTable(
        TempTableUsageMap $usageMap,
        DateTimeImmutable $graceCutoff,
        int $batchSize,
        ?int $limit,
    ): Generator {
        $usageMap->flush();
        $tempTable = $this->connection->quoteIdentifier($usageMap->tableName());

        $yielded = 0;
        $lastId = null;

        do {
            $sql = sprintf(
                'SELECT LOWER(HEX(m.id)) AS id, m.file_name, m.file_extension, m.file_size, m.created_at '
                . 'FROM media m '
                . 'LEFT JOIN %s u ON u.id = m.id '
                . 'WHERE m.file_name IS NOT NULL '
                . 'AND u.id IS NULL '
                // Bereits eingelagerte Medien bleiben außen vor. Die Quarantäne lässt ihre
                // Zeilen stehen, also sind sie weiterhin verwaist und ihre Datei ist weg — ohne diese Bedingung griffe sie jeder nächste Durchlauf erneut auf
                // und schriebe einen zweiten Eintrag ins Manifest.
                . 'AND NOT EXISTS (SELECT 1 FROM rc_media_quarantine q WHERE q.media_id = m.id) '
                . 'AND m.created_at < :cutoff '
                . ($lastId !== null ? 'AND m.id > UNHEX(:lastId) ' : '')
                . 'ORDER BY m.id '
                . 'LIMIT :limit',
                $tempTable,
            );

            $params = [
                'cutoff' => $graceCutoff->format('Y-m-d H:i:s'),
                'limit' => $batchSize,
            ];
            $types = ['limit' => \Doctrine\DBAL\ParameterType::INTEGER];
            if ($lastId !== null) {
                $params['lastId'] = $lastId;
            }

            /** @var list<array{id: string, file_name: ?string, file_extension: ?string, file_size: ?int, created_at: ?string}> $rows */
            $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
            $rowCount = count($rows);
            if ($rowCount === 0) {
                return;
            }

            foreach ($rows as $row) {
                $hexId = strtolower((string) $row['id']);
                $lastId = $hexId;

                if ($this->exclusionMatcher->isExcluded((string) $row['file_name'])) {
                    continue;
                }

                yield new OrphanedMedia(
                    mediaId: $hexId,
                    path: $this->buildMediaPath($row),
                    size: (int) $row['file_size'],
                    createdAt: $this->parseDate((string) $row['created_at']),
                    fileName: (string) $row['file_name'] . ($row['file_extension'] !== null ? '.' . $row['file_extension'] : ''),
                    sourceType: OrphanedMedia::SOURCE_DB_ORPHAN,
                );

                $yielded++;
                if ($limit !== null && $yielded >= $limit) {
                    return;
                }
            }
        } while ($rowCount === $batchSize);
    }

    /**
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    private function fetchMediaBatchAfter(?string $lastHexId, int $limit): array
    {
        $sql = 'SELECT LOWER(HEX(id)) AS id, file_name, file_extension, file_size, created_at, '
            . 'LOWER(HEX(media_folder_id)) AS media_folder_id '
            . 'FROM media '
            . 'WHERE file_name IS NOT NULL '
            // Siehe oben: Eingelagertes wird nicht noch einmal aufgegriffen.
            . 'AND NOT EXISTS (SELECT 1 FROM rc_media_quarantine q WHERE q.media_id = media.id) '
            . ($lastHexId !== null ? 'AND id > UNHEX(:lastId) ' : '')
            . 'ORDER BY id '
            . 'LIMIT :limit';

        $params = ['limit' => $limit];
        $types = ['limit' => \Doctrine\DBAL\ParameterType::INTEGER];
        if ($lastHexId !== null) {
            $params['lastId'] = $lastHexId;
        }

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildMediaPath(array $row): string
    {
        $name = (string) ($row['file_name'] ?? '');
        $ext = $row['file_extension'] !== null ? '.' . (string) $row['file_extension'] : '';
        return 'media/' . $name . $ext;
    }

    private function resolveGraceCutoff(): DateTimeImmutable
    {
        $days = (int) ($this->systemConfigService->get(self::GRACE_CONFIG_KEY) ?? self::DEFAULT_GRACE_DAYS);
        if ($days < 0) {
            $days = self::DEFAULT_GRACE_DAYS;
        }
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days');
    }

    private function parseDate(string $raw): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (Exception) {
            return new DateTimeImmutable('@0', new DateTimeZone('UTC'));
        }
    }
}
