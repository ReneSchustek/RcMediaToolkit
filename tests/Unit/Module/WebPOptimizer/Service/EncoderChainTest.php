<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder\WebPEncoderInterface;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\EncoderChain;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(EncoderChain::class)]
final class EncoderChainTest extends TestCase
{
    #[Test]
    public function selectsFirstAvailableInPreferenceOrder(): void
    {
        $cwebp = $this->encoder('cwebp', false);
        $libvips = $this->encoder('libvips', true);
        $gd = $this->encoder('gd', true);

        $chain = $this->buildChain('cwebp,libvips,gd', [$cwebp, $libvips, $gd]);

        self::assertSame('libvips', $chain->selectEncoder()->name());
    }

    #[Test]
    public function skipsUnavailableAndFallsBackToLastOption(): void
    {
        $cwebp = $this->encoder('cwebp', false);
        $libvips = $this->encoder('libvips', false);
        $imagick = $this->encoder('imagick', false);
        $gd = $this->encoder('gd', true);

        $chain = $this->buildChain('cwebp,libvips,imagick,gd', [$cwebp, $libvips, $imagick, $gd]);

        self::assertSame('gd', $chain->selectEncoder()->name());
    }

    #[Test]
    public function throwsWhenNoEncoderAvailable(): void
    {
        $gd = $this->encoder('gd', false);
        $chain = $this->buildChain('gd', [$gd]);

        $this->expectException(RuntimeException::class);
        $chain->selectEncoder();
    }

    #[Test]
    public function availabilityMapShowsAll(): void
    {
        $cwebp = $this->encoder('cwebp', false);
        $gd = $this->encoder('gd', true);

        $chain = $this->buildChain('cwebp,gd', [$cwebp, $gd]);

        self::assertSame(['cwebp' => false, 'gd' => true], $chain->availabilityMap());
    }

    #[Test]
    public function versionMapShowsVersionsForAvailableEncodersOnly(): void
    {
        $cwebp = $this->encoder('cwebp', false, 'cwebp 1.3');
        $gd = $this->encoder('gd', true, 'GD 2.3');

        $chain = $this->buildChain('cwebp,gd', [$cwebp, $gd]);

        self::assertSame(['cwebp' => null, 'gd' => 'GD 2.3'], $chain->versionMap());
    }

    /**
     * @param list<WebPEncoderInterface> $encoders
     */
    private function buildChain(string $preference, array $encoders): EncoderChain
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($preference);

        $toolkitLogger = new MediaToolkitLogger(new NullLogger());

        return new EncoderChain($encoders, $config, $toolkitLogger);
    }

    private function encoder(string $name, bool $available, ?string $version = null): WebPEncoderInterface
    {
        return new class ($name, $available, $version) implements WebPEncoderInterface {
            public function __construct(
                private readonly string $n,
                private readonly bool $a,
                private readonly ?string $v,
            ) {
            }
            public function name(): string
            {
                return $this->n;
            }
            public function isAvailable(): bool
            {
                return $this->a;
            }
            public function version(): ?string
            {
                return $this->v;
            }
            public function encode(string $sourceBytes, string $sourceFormat, int $quality, bool $lossless, bool $stripMetadata): string
            {
                return $sourceBytes;
            }
        };
    }
}
