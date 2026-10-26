<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Exception;
use InvalidArgumentException;
use JsonException;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Shopware\Core\Framework\Uuid\Uuid;
use Throwable;

/**
 * Phase 3 — Quarantine. Verschiebt Kandidaten in ein run-spezifisches Quarantäne-Verzeichnis
 * und erlaubt späteren Restore oder finalen Purge.
 *
 * Layout: <projectDir>/var/media_quarantine/<runId>/<files…> + manifest.json
 *
 * Crash-Recovery-Strategie: Manifest-Eintrag wird vor jeglicher Filesystem-/DB-Mutation
 * geschrieben — wenn der Worker zwischen den Schritten stirbt, ist der Quarantine-Stand
 * vollständig rekonstruierbar. Falsch-positive Einträge (Move schlug fehl) werden im
 * Restore-Pfad erkannt und protokolliert.
 */
final class MediaQuarantineService
{
    private const QUARANTINE_BASE = 'var/media_quarantine';
    private const MANIFEST = 'manifest.json';

    /**
     * Ein Medium, dessen Datei schon vor dem Einlagern fehlte. Es gibt nichts
     * zurückzuschreiben — der Datensatz bleibt trotzdem bis zum Ende der Frist.
     */
    private const STATUS_FILE_MISSING = 'file-missing';
    private const RUN_ID_PATTERN = '/^\d{8}-\d{6}-[a-f0-9]{8}$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly FilesystemAdapterInterface $filesystem,
        private readonly MediaToolkitLogger $logger,
        private readonly string $projectDir,
    ) {
    }

    public function createRun(): string
    {
        $runId = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd-His') . '-' . substr(Uuid::randomHex(), 0, 8);
        $this->ensureLocalDirectory($this->runPath($runId));
        $this->writeManifestFile($runId, ['runId' => $runId, 'createdAt' => date(DateTimeInterface::ATOM), 'items' => []]);
        return $runId;
    }

    /**
     * Path-Traversal-Schutz: jeder von außen kommende runId muss exakt dem internen Schema folgen.
     */
    private function assertValidRunId(string $runId): void
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            throw new InvalidArgumentException(sprintf('Ungültige runId: "%s"', $runId));
        }
    }

    /**
     * @throws DbalException
     */
    public function quarantine(string $runId, OrphanedMedia $candidate): void
    {
        $this->assertValidRunId($runId);

        if ($candidate->mediaId === null) {
            throw new InvalidArgumentException('Filesystem-Waisen ohne Media-ID können nicht in Quarantäne wandern');
        }

        $mediaRow = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, file_name, file_extension, path FROM media WHERE id = UNHEX(:id)',
            ['id' => $candidate->mediaId],
        );

        if ($mediaRow === false || !isset($mediaRow['file_name'])) {
            $this->logger->warning('Media nicht mehr in DB — Quarantine übersprungen', [
                'mediaId' => $candidate->mediaId,
            ]);
            return;
        }

        $sourcePath = $this->resolveStoragePath($mediaRow);
        if ($sourcePath === null || !$this->filesystem->exists($sourcePath)) {
            // Kein Rückweg ohne Datei — aber auch kein Grund, den Datensatz wegzuwerfen. Löschte
            // der Dienst hier die Zeilen, käme gar nichts in die Quarantäne: Von diesem Medium
            // bliebe nichts, nicht einmal ein Eintrag im Manifest. So wird es vermerkt und
            // wandert mit dem Lauf in die Frist.
            $this->logger->warning('Quell-Datei fehlt — Medium wird ohne Datei vermerkt', [
                'mediaId' => $candidate->mediaId,
                'path' => (string) $sourcePath,
            ]);

            $this->appendToManifest($runId, [
                'mediaId' => $candidate->mediaId,
                'fileName' => (string) $mediaRow['file_name'],
                'fileExtension' => $mediaRow['file_extension'] !== null ? (string) $mediaRow['file_extension'] : null,
                'originalPath' => (string) $sourcePath,
                'quarantinedPath' => null,
                'quarantinedAt' => date(DateTimeInterface::ATOM),
                'size' => $candidate->size,
                'status' => self::STATUS_FILE_MISSING,
            ]);
            $this->markQuarantined($candidate->mediaId, $runId, (string) $sourcePath, null);

            return;
        }

        $targetRelative = $this->runPath($runId) . '/' . $candidate->mediaId . '_' . basename($sourcePath);

        $this->ensureLocalDirectory($this->runPath($runId));

        // Schritt 1: Manifest-Eintrag mit status=pending — wird sofort persistiert, sodass
        // bei einem Worker-Crash die nachgelagerten Schritte rekonstruierbar bleiben.
        $manifestItem = [
            'mediaId' => $candidate->mediaId,
            'fileName' => (string) $mediaRow['file_name'],
            'fileExtension' => $mediaRow['file_extension'] !== null ? (string) $mediaRow['file_extension'] : null,
            'originalPath' => $sourcePath,
            'quarantinedPath' => $targetRelative,
            'quarantinedAt' => date(DateTimeInterface::ATOM),
            'size' => $candidate->size,
            'status' => 'pending',
        ];
        $this->appendToManifest($runId, $manifestItem);

        try {
            // Schritt 2: Datei aus Public-Storage lesen + Kopie in lokale Quarantäne schreiben.
            $contents = $this->filesystem->read($sourcePath);
            $this->writeLocalFile($targetRelative, $contents);

            // Schritt 3: Original aus dem Speicher entfernen und das Medium kennzeichnen.
            //
            // **Die Datenbankzeilen bleiben stehen**, nur die Datei wandert. Eine Quarantäne
            // ist ein Versprechen: Was hier hineingeht, kommt wieder heraus. Verschwänden Datei
            // und Zeilen, käme beim Wiederherstellen nur die Datei zurück — das Medium wäre für
            // Shopware weg, und die Zählung meldete trotzdem Erfolg. Endgültig gelöscht wird
            // erst beim Ausräumen der Frist.
            $this->filesystem->delete($sourcePath);
            $this->markQuarantined($candidate->mediaId, $runId, $sourcePath, $targetRelative);

            // Schritt 4: Manifest-Status aktualisieren — quarantined.
            $this->updateManifestItemStatus($runId, $candidate->mediaId, 'quarantined');
        } catch (Throwable $e) {
            $this->updateManifestItemStatus($runId, $candidate->mediaId, 'failed', $e->getMessage());
            $this->logger->error('Quarantine-Move fehlgeschlagen', [
                'mediaId' => $candidate->mediaId,
                'runId' => $runId,
                'exception' => $e,
            ]);
            throw new RuntimeException(sprintf('Quarantäne-Move für Media %s fehlgeschlagen', $candidate->mediaId), 0, $e);
        }

        $this->logger->info('Medium in Quarantäne verschoben', [
            'mediaId' => $candidate->mediaId,
            'runId' => $runId,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadManifest(string $runId): array
    {
        $this->assertValidRunId($runId);
        $manifest = $this->readManifest($runId);
        return $manifest['items'] ?? [];
    }

    /**
     * @return list<string> Liste vorhandener Run-IDs
     */
    public function listRuns(): array
    {
        $base = $this->absolutePath(self::QUARANTINE_BASE);
        if (!is_dir($base)) {
            return [];
        }

        $entries = scandir($base);
        if ($entries === false) {
            return [];
        }

        $runs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            // Anti-Hostile-FS-Schutz: nur Verzeichnis-Namen akzeptieren, die dem runId-Pattern entsprechen
            if (preg_match(self::RUN_ID_PATTERN, $entry) !== 1) {
                continue;
            }
            $full = $base . '/' . $entry;
            if (is_dir($full) && is_file($full . '/' . self::MANIFEST)) {
                $runs[] = $entry;
            }
        }
        sort($runs);
        return $runs;
    }

    public function restore(string $runId, ?string $mediaId = null): int
    {
        $this->assertValidRunId($runId);
        if ($mediaId !== null && preg_match('/^[a-f0-9]{32}$/', $mediaId) !== 1) {
            throw new InvalidArgumentException(sprintf('Ungültige mediaId: "%s"', $mediaId));
        }
        $manifest = $this->readManifest($runId);
        $items = $manifest['items'] ?? [];
        if ($items === []) {
            // Kein Manifest oder leeres Manifest — nichts zu restorieren, nichts zu schreiben.
            return 0;
        }
        $restored = 0;

        $expectedPrefix = $this->runPath($runId) . '/';

        foreach ($items as $item) {
            if ($mediaId !== null && ($item['mediaId'] ?? null) !== $mediaId) {
                continue;
            }
            if (($item['status'] ?? 'quarantined') !== 'quarantined') {
                continue;
            }

            // Defense-in-Depth: das Manifest könnte durch lokalen FS-Access manipuliert sein.
            // quarantinedPath muss strikt unter runPath/ liegen, originalPath muss unter "media/" beginnen
            // und darf keinen Traversal-Anteil enthalten.
            $quarantinedPath = is_string($item['quarantinedPath'] ?? null) ? $item['quarantinedPath'] : '';
            $originalPath = is_string($item['originalPath'] ?? null) ? $item['originalPath'] : '';

            if (!$this->isQuarantinedPathSafe($quarantinedPath, $expectedPrefix)) {
                $this->logger->warning('Manifest-Eintrag mit verdächtigem quarantinedPath — übersprungen', [
                    'mediaId' => $item['mediaId'] ?? null,
                    'runId' => $runId,
                ]);
                continue;
            }
            if (!$this->isOriginalPathSafe($originalPath)) {
                $this->logger->warning('Manifest-Eintrag mit verdächtigem originalPath — übersprungen', [
                    'mediaId' => $item['mediaId'] ?? null,
                    'runId' => $runId,
                ]);
                continue;
            }

            $absoluteQuarantined = $this->absolutePath($quarantinedPath);
            if (!is_file($absoluteQuarantined)) {
                $this->logger->warning('Quarantäne-Datei fehlt — Restore übersprungen', [
                    'mediaId' => $item['mediaId'] ?? null,
                    'runId' => $runId,
                ]);
                continue;
            }

            $contents = file_get_contents($absoluteQuarantined);
            if ($contents === false) {
                continue;
            }

            $this->filesystem->write($originalPath, $contents);
            unlink($absoluteQuarantined);
            // Erst wenn die Datei wieder liegt, gilt das Medium als zurück. Andersherum stünde
            // ein Medium ohne Kennzeichen da, dessen Datei noch in der Quarantäne liegt — und
            // der nächste Durchlauf griffe es erneut auf.
            $this->clearQuarantineMark((string) ($item['mediaId'] ?? ''));
            $restored++;
        }

        if ($mediaId !== null) {
            $manifest['items'] = array_values(array_filter(
                $items,
                static fn (array $item): bool => $item['mediaId'] !== $mediaId,
            ));
        } else {
            $manifest['items'] = [];
        }
        $this->writeManifestFile($runId, $manifest);

        return $restored;
    }

    /**
     * Räumt einen Lauf endgültig aus — **hier** verschwinden die Datenbankzeilen.
     *
     * Das Löschen gehört ans Ende der Frist, wenn niemand widersprochen hat. Fiele es schon beim
     * Einlagern an, wäre das Medium verloren, bevor jemand prüfen konnte. Vom Zeitpunkt des
     * Einlagerns bis hierher ist jedes Medium vollständig zurückholbar.
     */
    public function purge(string $runId): int
    {
        $this->assertValidRunId($runId);
        $manifest = $this->readManifest($runId);
        $items = $manifest['items'] ?? [];
        $count = count($items);

        foreach ($items as $item) {
            $mediaId = is_string($item['mediaId'] ?? null) ? $item['mediaId'] : '';
            if (preg_match('/^[a-f0-9]{32}$/', $mediaId) !== 1) {
                continue;
            }

            try {
                $this->deleteMediaDbRows($mediaId);
                $this->clearQuarantineMark($mediaId);
            } catch (Throwable $e) {
                // Ein Medium, das sich nicht löschen lässt, darf den Lauf nicht anhalten —
                // aber es darf auch nicht stillschweigend als geräumt gelten.
                $this->logger->error('Endgültiges Löschen fehlgeschlagen', [
                    'mediaId' => $mediaId,
                    'runId' => $runId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $runDir = $this->absolutePath($this->runPath($runId));
        if (is_dir($runDir)) {
            $this->removeDirectory($runDir);
        }

        return $count;
    }

    /**
     * @return array<string, int> [runId => purgedItems]
     */
    public function purgeOlderThan(int $olderThanDays): array
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $olderThanDays . ' days');
        $result = [];

        foreach ($this->listRuns() as $runId) {
            $manifest = $this->readManifest($runId);
            $createdAt = $manifest['createdAt'] ?? null;
            if ($createdAt === null) {
                continue;
            }
            try {
                $createdDate = new DateTimeImmutable($createdAt, new DateTimeZone('UTC'));
            } catch (Exception) {
                continue;
            }
            if ($createdDate < $cutoff) {
                $result[$runId] = $this->purge($runId);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $mediaRow
     */
    private function resolveStoragePath(array $mediaRow): ?string
    {
        if (isset($mediaRow['path']) && is_string($mediaRow['path']) && $mediaRow['path'] !== '') {
            return $mediaRow['path'];
        }
        $name = is_string($mediaRow['file_name'] ?? null) ? $mediaRow['file_name'] : null;
        if ($name === null) {
            return null;
        }
        $ext = isset($mediaRow['file_extension']) && is_string($mediaRow['file_extension'])
            ? '.' . $mediaRow['file_extension']
            : '';
        return 'media/' . $name . $ext;
    }

    /**
     * Vermerkt, dass dieses Medium eingelagert ist.
     *
     * **Warum eine eigene Tabelle und kein Zusatzfeld:** `custom_fields` liegt bei Shopware auf
     * `media_translation`, also je Sprache. Eine Einlagerung ist keine übersetzbare Eigenschaft
     * — sie gälte sonst in einer Sprache und in der anderen nicht.
     *
     * `REPLACE` statt `INSERT`: Wird ein Medium ein zweites Mal aufgegriffen — etwa nach einem
     * Absturz mitten im Lauf —, soll der neuere Vermerk gelten und nicht der Aufruf scheitern.
     *
     * @throws DbalException
     */
    private function markQuarantined(
        string $hexMediaId,
        string $runId,
        string $originalPath,
        ?string $quarantinedPath,
    ): void {
        $this->connection->executeStatement(
            'REPLACE INTO rc_media_quarantine (media_id, run_id, original_path, quarantined_path, quarantined_at)
             VALUES (UNHEX(:id), :runId, :originalPath, :quarantinedPath, :at)',
            [
                'id' => $hexMediaId,
                'runId' => $runId,
                'originalPath' => $originalPath,
                'quarantinedPath' => $quarantinedPath,
                // Ausdrücklich UTC: Der Vermerk trägt eine Frist und wird womöglich Wochen
                // später gelesen — dann darf nicht die Zeitzone entscheiden, was er bedeutet.
                'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
            ],
        );
    }

    /**
     * Nimmt den Vermerk zurück — beim Wiederherstellen wie beim endgültigen Löschen.
     *
     * @throws DbalException
     */
    private function clearQuarantineMark(string $hexMediaId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $hexMediaId) !== 1) {
            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM rc_media_quarantine WHERE media_id = UNHEX(:id)',
            ['id' => $hexMediaId],
        );
    }

    /**
     * @throws DbalException
     */
    private function deleteMediaDbRows(string $hexMediaId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM media_thumbnail WHERE media_id = UNHEX(:id)',
            ['id' => $hexMediaId],
        );
        $this->connection->executeStatement(
            'DELETE FROM media WHERE id = UNHEX(:id)',
            ['id' => $hexMediaId],
        );
    }

    private function runPath(string $runId): string
    {
        return self::QUARANTINE_BASE . '/' . $runId;
    }

    private function absolutePath(string $relative): string
    {
        return rtrim($this->projectDir, '/\\') . '/' . ltrim($relative, '/');
    }

    private function ensureLocalDirectory(string $relative): void
    {
        $absolute = $this->absolutePath($relative);
        if (!is_dir($absolute) && !mkdir($absolute, 0o775, true) && !is_dir($absolute)) {
            throw new RuntimeException(sprintf('Konnte Quarantäne-Verzeichnis "%s" nicht anlegen', $absolute));
        }
    }

    private function writeLocalFile(string $relative, string $contents): void
    {
        $absolute = $this->absolutePath($relative);
        if (file_put_contents($absolute, $contents) === false) {
            throw new RuntimeException(sprintf('Konnte Quarantäne-Datei "%s" nicht schreiben', $absolute));
        }
    }

    /**
     * @return array{runId?: string, createdAt?: string, items?: list<array<string, mixed>>}
     */
    private function readManifest(string $runId): array
    {
        $path = $this->absolutePath($this->runPath($runId) . '/' . self::MANIFEST);
        if (!is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
            return $decoded;
        } catch (JsonException $e) {
            // Korruptes Manifest darf nicht zu Daten-Verlust führen — laut und sichtbar protokollieren.
            $this->logger->warning('Korruptes Quarantine-Manifest, behandle als leer', [
                'runId' => $runId,
                'exception' => $e,
            ]);
            return [];
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeManifestFile(string $runId, array $payload): void
    {
        $path = $this->absolutePath($this->runPath($runId) . '/' . self::MANIFEST);
        try {
            $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('Manifest konnte nicht serialisiert werden', 0, $e);
        }
        // Atomarer Schreibvorgang: erst tmp, dann rename — schützt vor halbgeschriebenen Manifesten.
        $tmpPath = $path . '.tmp';
        if (file_put_contents($tmpPath, $json) === false) {
            throw new RuntimeException(sprintf('Konnte Manifest "%s" nicht schreiben', $tmpPath));
        }
        if (!rename($tmpPath, $path)) {
            // Aufräumen darf die Ausnahme nicht verdecken — aber auch nicht unsichtbar
            // scheitern: Eine liegengebliebene `.tmp` neben einem Manifest sieht bei der
            // nächsten Durchsicht wie ein halber Lauf aus.
            if (is_file($tmpPath) && !unlink($tmpPath)) {
                $this->logger->withModule('GarbageCollector')->warning(
                    'Halb geschriebenes Manifest ließ sich nicht entfernen',
                    ['path' => $tmpPath],
                );
            }

            throw new RuntimeException(sprintf('Konnte Manifest "%s" nicht atomar ersetzen', $path));
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function appendToManifest(string $runId, array $item): void
    {
        $manifest = $this->readManifest($runId);
        $items = $manifest['items'] ?? [];
        $items[] = $item;
        $manifest['items'] = $items;
        $this->writeManifestFile($runId, $manifest);
    }

    private function updateManifestItemStatus(string $runId, string $mediaId, string $newStatus, ?string $errorMessage = null): void
    {
        $manifest = $this->readManifest($runId);
        $items = $manifest['items'] ?? [];
        foreach ($items as $idx => $item) {
            if (($item['mediaId'] ?? null) === $mediaId) {
                $items[$idx]['status'] = $newStatus;
                if ($errorMessage !== null) {
                    $items[$idx]['error'] = $errorMessage;
                }
            }
        }
        $manifest['items'] = $items;
        $this->writeManifestFile($runId, $manifest);
    }

    /**
     * quarantinedPath darf nur unterhalb des erwarteten Run-Verzeichnisses liegen
     * und keine Traversal-Bestandteile enthalten. Schützt vor manipuliertem Manifest.
     */
    private function isQuarantinedPathSafe(string $path, string $expectedPrefix): bool
    {
        if ($path === '' || !str_starts_with($path, $expectedPrefix)) {
            return false;
        }
        if (str_contains($path, '..') || str_contains($path, "\x00")) {
            return false;
        }
        return true;
    }

    /**
     * originalPath darf nur unter media/ und keine Traversal-Anteile/NUL-Bytes haben.
     */
    private function isOriginalPathSafe(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\x00")) {
            return false;
        }
        // Absolute Pfade sind verdächtig — der FilesystemAdapter arbeitet relativ.
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return false;
        }
        // Aktuell schreiben wir nur unter media/. Wenn das Plugin später andere Buckets nutzt,
        // muss diese Liste erweitert werden.
        return str_starts_with($path, 'media/');
    }

    private function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $directory . '/' . $entry;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                unlink($full);
            }
        }
        rmdir($directory);
    }
}
