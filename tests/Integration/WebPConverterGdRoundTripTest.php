<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\GdEncoder;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\EncoderChain;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPConverter;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\WebPSettingsResolver;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SplFileInfo;

/**
 * Integration-Test: echtes JPEG → WebP über GdEncoder.
 *
 * Skippt automatisch, wenn GD mit WebP-Support fehlt.
 */
final class WebPConverterGdRoundTripTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            self::markTestSkipped('GD-Extension mit WebP-Support nicht verfügbar');
        }
    }

    #[Test]
    public function convertProducesValidWebpFromJpeg(): void
    {
        $tmpDir = sys_get_temp_dir() . '/rc-webp-int-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o775, true);

        try {
            // Erzeuge ein echtes JPEG mit GD
            $image = imagecreatetruecolor(200, 200);
            self::assertNotFalse($image);
            $bg = imagecolorallocate($image, 50, 100, 150);
            imagefilledrectangle($image, 0, 0, 200, 200, (int) $bg);

            $jpegPath = $tmpDir . '/media/products/test.jpg';
            mkdir(dirname($jpegPath), 0o775, true);
            imagejpeg($image, $jpegPath, 85);
            imagedestroy($image);

            self::assertFileExists($jpegPath);
            $jpegSize = (int) filesize($jpegPath);
            self::assertGreaterThan(1024, $jpegSize, 'JPEG sollte > 1 KB sein');

            $filesystem = new ProjectRootFilesystemAdapter($tmpDir);

            $config = $this->createStub(SystemConfigService::class);
            $config->method('get')->willReturnMap([
                ['RcMediaToolkit.config.webpDefaultQuality', null, null, 82],
                ['RcMediaToolkit.config.webpMinFileSizeKb', null, null, 1],
                ['RcMediaToolkit.config.webpFormatWhitelist', null, null, 'jpeg,jpg,png'],
                ['RcMediaToolkit.config.webpFolderSettingsJson', null, null, ''],
                ['RcMediaToolkit.config.webpDefaultLossless', null, null, false],
                ['RcMediaToolkit.config.webpDefaultStripMetadata', null, null, true],
                ['RcMediaToolkit.config.webpEncoderPreference', null, null, 'gd'],
            ]);

            $logger = new MediaToolkitLogger(new NullLogger());
            $encoderChain = new EncoderChain([new GdEncoder()], $config, $logger);
            $resolver = new WebPSettingsResolver($config, $logger);

            $converter = new WebPConverter($encoderChain, $filesystem, $config, $logger, $resolver);

            $result = $converter->convert('media/products/test.jpg');

            self::assertSame('converted', $result['status']);
            // targetPath und sizeBytes sind im Rückgabe-Shape optional (bei 'failed'/'skipped'
            // fehlen sie). Erst auf Vorhandensein prüfen, dann auf den Wert — sonst läuft der
            // Test bei einem Fehlschlag in einen Zugriff auf einen nicht gesetzten Schlüssel.
            self::assertArrayHasKey('targetPath', $result);
            self::assertArrayHasKey('sizeBytes', $result);
            self::assertSame('media/products/test.jpg.webp', $result['targetPath'] ?? null);
            self::assertGreaterThan(0, $result['sizeBytes'] ?? 0);

            $webpPath = $tmpDir . '/media/products/test.jpg.webp';
            self::assertFileExists($webpPath);

            // WebP-Magic-Bytes: "RIFF...WEBP"
            $webpBytes = (string) file_get_contents($webpPath);
            self::assertSame('RIFF', substr($webpBytes, 0, 4));
            self::assertSame('WEBP', substr($webpBytes, 8, 4));

            // Original bleibt unangetastet
            self::assertFileExists($jpegPath);
            self::assertSame($jpegSize, (int) filesize($jpegPath));
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    #[Test]
    public function convertIsIdempotentOnSecondCall(): void
    {
        $tmpDir = sys_get_temp_dir() . '/rc-webp-int-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o775, true);

        try {
            $image = imagecreatetruecolor(100, 100);
            self::assertNotFalse($image);
            $jpegPath = $tmpDir . '/media/test.jpg';
            mkdir(dirname($jpegPath), 0o775, true);
            imagejpeg($image, $jpegPath, 85);
            imagedestroy($image);

            $filesystem = new ProjectRootFilesystemAdapter($tmpDir);

            $config = $this->createStub(SystemConfigService::class);
            $config->method('get')->willReturnMap([
                ['RcMediaToolkit.config.webpDefaultQuality', null, null, 82],
                ['RcMediaToolkit.config.webpMinFileSizeKb', null, null, 0],
                ['RcMediaToolkit.config.webpFormatWhitelist', null, null, 'jpeg,jpg,png'],
                ['RcMediaToolkit.config.webpFolderSettingsJson', null, null, ''],
                ['RcMediaToolkit.config.webpDefaultLossless', null, null, false],
                ['RcMediaToolkit.config.webpDefaultStripMetadata', null, null, true],
                ['RcMediaToolkit.config.webpEncoderPreference', null, null, 'gd'],
            ]);

            $logger = new MediaToolkitLogger(new NullLogger());
            $encoderChain = new EncoderChain([new GdEncoder()], $config, $logger);
            $resolver = new WebPSettingsResolver($config, $logger);

            $converter = new WebPConverter($encoderChain, $filesystem, $config, $logger, $resolver);

            $first = $converter->convert('media/test.jpg');
            self::assertSame('converted', $first['status']);

            // mtime des WebP künstlich nach vorn schieben, damit Idempotenz greift
            touch($tmpDir . '/media/test.jpg.webp', time() + 10);

            $second = $converter->convert('media/test.jpg');
            self::assertSame('skipped', $second['status']);
            self::assertSame('already_up_to_date', $second['reason'] ?? null);
        } finally {
            $this->removeDirectory($tmpDir);
        }
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
 * Echter Filesystem-Adapter relativ zu einem Project-Root — für Integration-Tests.
 */
final class ProjectRootFilesystemAdapter implements \Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface
{
    public function __construct(private readonly string $root)
    {
    }

    public function exists(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function read(string $path): string
    {
        $contents = @file_get_contents($this->absolute($path));
        if ($contents === false) {
            throw new RuntimeException('Konnte ' . $path . ' nicht lesen');
        }
        return $contents;
    }

    public function write(string $path, string $contents): void
    {
        $absolute = $this->absolute($path);
        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        if (file_put_contents($absolute, $contents) === false) {
            throw new RuntimeException('Konnte ' . $path . ' nicht schreiben');
        }
    }

    public function delete(string $path): void
    {
        @unlink($this->absolute($path));
    }

    public function size(string $path): int
    {
        $size = @filesize($this->absolute($path));
        return $size === false ? 0 : $size;
    }

    public function lastModified(string $path): int
    {
        $time = @filemtime($this->absolute($path));
        return $time === false ? 0 : $time;
    }

    public function move(string $source, string $destination): void
    {
        rename($this->absolute($source), $this->absolute($destination));
    }

    public function makeDirectory(string $path): void
    {
        $absolute = $this->absolute($path);
        if (!is_dir($absolute)) {
            mkdir($absolute, 0o775, true);
        }
    }

    public function listContents(string $path, bool $recursive = false): array
    {
        $absolute = $this->absolute($path);
        if (!is_dir($absolute)) {
            return [];
        }
        $iterator = $recursive
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS))
            : new FilesystemIterator($absolute, FilesystemIterator::SKIP_DOTS);
        $results = [];
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isFile()) {
                $results[] = ltrim(substr($item->getPathname(), strlen($this->root)), '/\\');
            }
        }
        return $results;
    }

    private function absolute(string $path): string
    {
        return rtrim($this->root, '/\\') . '/' . ltrim($path, '/');
    }
}
