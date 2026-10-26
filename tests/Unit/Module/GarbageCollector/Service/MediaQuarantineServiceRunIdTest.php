<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Service;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaQuarantineService;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;

/**
 * Tests gegen Path-Traversal in MediaQuarantineService.
 *
 * Bestätigt, dass `restore`, `purge` und `loadManifest` Eingaben mit Traversal-Tricks
 * (`..`, absolute Pfade, NULL-Bytes) ablehnen, bevor sie auf das Filesystem zugreifen.
 */
#[CoversClass(MediaQuarantineService::class)]
final class MediaQuarantineServiceRunIdTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function invalidRunIds(): array
    {
        return [
            'leerer String' => [''],
            'Pfad-Traversal' => ['../../etc'],
            'absoluter Unix-Pfad' => ['/etc/passwd'],
            'absoluter Windows-Pfad' => ['C:\\Windows'],
            'NULL-Byte-Injection' => ["20260511-120000-abcdef01\x00/../etc"],
            'Backslash-Pfad' => ['20260511\\..\\..\\etc'],
            'Slash-Pfad' => ['20260511/../etc'],
            'falsches Datum' => ['nope-foo-bar'],
            'zu kurz' => ['20260511-120000-abc'],
            'falscher Hex' => ['20260511-120000-ZZZZZZZZ'],
            'whitespace' => ['20260511-120000-aaaaaaaa '],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validRunIds(): array
    {
        return [
            'normaler Run' => ['20260511-120000-abcdef01'],
            'Mitternacht' => ['20260101-000000-00000000'],
        ];
    }

    #[Test]
    #[DataProvider('invalidRunIds')]
    public function restoreRejectsInvalidRunId(string $runId): void
    {
        $service = $this->serviceWithTempDir();
        $this->expectException(InvalidArgumentException::class);
        $service->restore($runId);
    }

    #[Test]
    #[DataProvider('invalidRunIds')]
    public function purgeRejectsInvalidRunId(string $runId): void
    {
        $service = $this->serviceWithTempDir();
        $this->expectException(InvalidArgumentException::class);
        $service->purge($runId);
    }

    #[Test]
    #[DataProvider('invalidRunIds')]
    public function loadManifestRejectsInvalidRunId(string $runId): void
    {
        $service = $this->serviceWithTempDir();
        $this->expectException(InvalidArgumentException::class);
        $service->loadManifest($runId);
    }

    #[Test]
    #[DataProvider('validRunIds')]
    public function restoreAcceptsValidRunIdAndReturnsZeroWhenManifestMissing(string $runId): void
    {
        $service = $this->serviceWithTempDir();
        $restored = $service->restore($runId);
        self::assertSame(0, $restored);
    }

    #[Test]
    public function restoreRejectsInvalidMediaId(): void
    {
        $service = $this->serviceWithTempDir();
        $this->expectException(InvalidArgumentException::class);
        $service->restore('20260511-120000-abcdef01', '../../etc');
    }

    private function serviceWithTempDir(): MediaQuarantineService
    {
        $tmp = sys_get_temp_dir() . '/rc-media-toolkit-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0o775, true);

        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $logger = new MediaToolkitLogger(new NullLogger());

        return new MediaQuarantineService($connection, $filesystem, $logger, $tmp);
    }
}
