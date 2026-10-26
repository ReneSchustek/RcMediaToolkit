<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Ruhrcoder\RcMediaToolkit\Module\HealthCheck\Dto\Finding;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * DBAL-basiertes Repository für rc_media_health_report. Bewusst kein DAL-Entity —
 * der Report ist Plugin-intern, hat keine Beziehung zu Storefront/Admin-APIs und
 * profitiert von Bulk-Inserts/Updates.
 */
final class HealthReportRepository
{
    private const NULL_SENTINEL = '_NULL_';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Idempotenter Upsert. Atomar über INSERT … ON DUPLICATE KEY UPDATE,
     * dedupliziert über die `dedup_key`-Spalte (mit Sentinel für NULL-Werte).
     *
     * Race-Condition-frei auch bei parallelen Messenger-Workers.
     *
     * @throws DbalException
     */
    public function upsert(Finding $finding, DateTimeImmutable $seenAt): void
    {
        $errorDetail = $finding->errorDetail === [] ? null : json_encode(
            $finding->errorDetail,
            \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );

        $dedupKey = $this->buildDedupKey($finding);
        $seenFormatted = $seenAt->format('Y-m-d H:i:s.v');

        $this->connection->executeStatement(
            'INSERT INTO rc_media_health_report '
            . '(id, media_id, check_type, severity, source_type, source_reference, dedup_key, '
            . ' error_detail, url_path, first_seen_at, last_seen_at, resolved_at) '
            . 'VALUES (UNHEX(:id), :mediaIdBin, :checkType, :severity, :sourceType, :sourceRef, '
            . ' :dedupKey, :errorDetail, :urlPath, :seen, :seen, NULL) '
            . 'ON DUPLICATE KEY UPDATE '
            . ' last_seen_at = :seen, '
            . ' resolved_at = NULL, '
            . ' severity = :severity, '
            . ' error_detail = :errorDetail, '
            . ' url_path = :urlPath',
            [
                'id' => Uuid::randomHex(),
                'mediaIdBin' => $finding->mediaId !== null ? hex2bin($finding->mediaId) : null,
                'checkType' => $finding->checkType,
                'severity' => $finding->severity,
                'sourceType' => $finding->sourceType,
                'sourceRef' => $finding->sourceReference,
                'dedupKey' => $dedupKey,
                'errorDetail' => $errorDetail,
                'urlPath' => $finding->urlPath,
                'seen' => $seenFormatted,
            ],
        );
    }

    /**
     * Markiert nicht mehr beobachtete Findings desselben Check-Typs als resolved.
     *
     * @throws DbalException
     */
    public function autoResolveStaleFindings(string $checkType, DateTimeImmutable $runStartedAt): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE rc_media_health_report '
            . 'SET resolved_at = :now '
            . 'WHERE check_type = :checkType '
            . 'AND resolved_at IS NULL '
            . 'AND last_seen_at < :runStarted',
            [
                'now' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
                'checkType' => $checkType,
                'runStarted' => $runStartedAt->format('Y-m-d H:i:s.v'),
            ],
        );
    }

    /**
     * @throws DbalException
     */
    public function purgeResolvedOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days');
        return (int) $this->connection->executeStatement(
            'DELETE FROM rc_media_health_report WHERE resolved_at IS NOT NULL AND resolved_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s.v')],
        );
    }

    /**
     * @return array<string, int> [severity => count]
     * @throws DbalException
     */
    public function countOpenBySeverity(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT severity, COUNT(*) AS cnt FROM rc_media_health_report WHERE resolved_at IS NULL GROUP BY severity',
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['severity']] = (int) $row['cnt'];
        }
        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listOpenFindings(?string $checkType = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(media_id)) AS media_id, check_type, severity, '
            . 'source_type, source_reference, error_detail, url_path, first_seen_at, last_seen_at '
            . 'FROM rc_media_health_report WHERE resolved_at IS NULL';

        $params = ['limit' => $limit, 'offset' => $offset];
        $types = ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER];

        if ($checkType !== null) {
            $sql .= ' AND check_type = :checkType';
            $params['checkType'] = $checkType;
        }

        $sql .= ' ORDER BY severity, last_seen_at DESC LIMIT :limit OFFSET :offset';

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findById(string $hexId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(media_id)) AS media_id, check_type, severity, '
            . 'source_type, source_reference, error_detail, url_path, first_seen_at, last_seen_at, resolved_at '
            . 'FROM rc_media_health_report WHERE id = UNHEX(:id)',
            ['id' => $hexId],
        );
        return $row === false ? null : $row;
    }

    /**
     * Konstruiert den Sentinel-basierten Deduplikations-Schlüssel.
     * Identische Logik wie in Migration1715000004AddDedupKeyToHealthReport.
     */
    private function buildDedupKey(Finding $finding): string
    {
        $mediaPart = $finding->mediaId ?? self::NULL_SENTINEL;
        $sourcePart = $finding->sourceReference ?? self::NULL_SENTINEL;
        return $finding->checkType . '|' . $mediaPart . '|' . $sourcePart;
    }
}
