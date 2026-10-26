<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Throwable;

/**
 * Echter Round-Trip-Test gegen ein temporäres Verzeichnis und einen In-Memory-FilesystemAdapter.
 * Verifiziert, dass createRun → quarantine (mit Status-Tracking) → restore die Originaldatei
 * byte-genau wiederherstellt.
 *
 * Bewusst ohne Shopware-Bootstrap — der DBAL-Aufruf wird mit einem manuellen Connection-Mock
 * gesteuert, das Filesystem wird durch einen In-Memory-Adapter ersetzt.
 */
final class MediaQuarantineRoundTripTest extends TestCase
{
    private string $tmpProjectDir;

    protected function setUp(): void
    {
        $this->tmpProjectDir = sys_get_temp_dir() . '/rc-media-toolkit-int-' . bin2hex(random_bytes(4));
        mkdir($this->tmpProjectDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpProjectDir);
    }

    #[Test]
    public function roundTripPreservesFileBytes(): void
    {
        $mediaId = bin2hex(random_bytes(16));
        $originalPath = 'media/products/test.jpg';
        $originalBytes = "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01" . random_bytes(2048);

        $filesystem = new InMemoryFilesystemAdapter([$originalPath => $originalBytes]);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => $mediaId,
            'file_name' => 'test',
            'file_extension' => 'jpg',
            'path' => $originalPath,
        ]);
        $connection->method('executeStatement')->willReturn(1);

        $service = new MediaQuarantineService(
            $connection,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $this->tmpProjectDir,
        );

        $runId = $service->createRun();
        self::assertMatchesRegularExpression(
            '/^\d{8}-\d{6}-[a-f0-9]{8}$/',
            $runId,
            'createRun muss einen Pattern-konformen runId liefern',
        );

        $candidate = new \Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia(
            mediaId: $mediaId,
            path: $originalPath,
            size: strlen($originalBytes),
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            fileName: 'test.jpg',
            sourceType: \Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia::SOURCE_DB_ORPHAN,
        );

        $service->quarantine($runId, $candidate);

        // Original aus dem FilesystemAdapter ist weg
        self::assertFalse(
            $filesystem->fileExists($originalPath),
            'Original muss nach Quarantine aus dem Filesystem entfernt sein',
        );

        // Manifest enthält Status=quarantined
        $manifest = $service->loadManifest($runId);
        self::assertCount(1, $manifest);
        self::assertSame('quarantined', $manifest[0]['status']);
        self::assertSame($mediaId, $manifest[0]['mediaId']);

        // Restore zurück in den Adapter
        $restored = $service->restore($runId);
        self::assertSame(1, $restored);

        self::assertTrue(
            $filesystem->fileExists($originalPath),
            'Original muss nach Restore wieder vorhanden sein',
        );
        self::assertSame(
            $originalBytes,
            $filesystem->read($originalPath),
            'Restore muss die ursprünglichen Bytes liefern',
        );
    }

    #[Test]
    public function manifestStaysIntactWhenQuarantineFails(): void
    {
        $mediaId = bin2hex(random_bytes(16));
        $originalPath = 'media/products/test.jpg';

        // Adapter wirft beim Read — simuliert Storage-Ausfall
        $filesystem = new InMemoryFilesystemAdapter([$originalPath => 'bytes']);
        $filesystem->throwOnRead = true;

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => $mediaId,
            'file_name' => 'test',
            'file_extension' => 'jpg',
            'path' => $originalPath,
        ]);

        $service = new MediaQuarantineService(
            $connection,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $this->tmpProjectDir,
        );

        $runId = $service->createRun();
        $candidate = new \Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia(
            mediaId: $mediaId,
            path: $originalPath,
            size: 5,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            fileName: 'test.jpg',
            sourceType: \Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia::SOURCE_DB_ORPHAN,
        );

        try {
            $service->quarantine($runId, $candidate);
            self::fail('Expected Exception');
        } catch (Throwable) {
            // erwartet
        }

        // Manifest muss den Fehlschlag tracken — Status=failed
        $manifest = $service->loadManifest($runId);
        self::assertCount(1, $manifest);
        self::assertSame('failed', $manifest[0]['status']);
        self::assertArrayHasKey('error', $manifest[0]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
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

/**
 * In-Memory-Filesystem zum Round-Trip-Test. Reduziert auf das, was MediaQuarantineService nutzt.
 */
final class InMemoryFilesystemAdapter implements FilesystemAdapterInterface
{
    public bool $throwOnRead = false;

    /** @param array<string, string> $files */
    public function __construct(private array $files = [])
    {
    }

    public function fileExists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function read(string $path): string
    {
        if ($this->throwOnRead) {
            throw new RuntimeException('Storage-Ausfall (simuliert)');
        }
        if (!isset($this->files[$path])) {
            throw new RuntimeException('Nicht gefunden: ' . $path);
        }
        return $this->files[$path];
    }

    public function write(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function size(string $path): int
    {
        return isset($this->files[$path]) ? strlen($this->files[$path]) : 0;
    }

    public function lastModified(string $path): int
    {
        return isset($this->files[$path]) ? time() : 0;
    }

    public function move(string $source, string $destination): void
    {
        if (!isset($this->files[$source])) {
            throw new RuntimeException('Move-Quelle nicht vorhanden');
        }
        $this->files[$destination] = $this->files[$source];
        unset($this->files[$source]);
    }

    public function makeDirectory(string $path): void
    {
        unset($path);
    }

    public function listContents(string $path, bool $recursive = false): array
    {
        unset($recursive);
        $prefix = rtrim($path, '/') . '/';
        return array_values(array_filter(
            array_keys($this->files),
            static fn (string $key): bool => str_starts_with($key, $prefix),
        ));
    }
}
