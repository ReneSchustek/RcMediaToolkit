<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ModuleRegistry::class)]
final class ModuleRegistryTest extends TestCase
{
    #[Test]
    public function statusMapReturnsAllThreeModules(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnMap([
            ['RcMediaToolkit.config.garbageCollectorActive', null, true],
            ['RcMediaToolkit.config.healthCheckActive', null, false],
            ['RcMediaToolkit.config.webPOptimizerActive', null, true],
        ]);

        $registry = new ModuleRegistry($config);

        self::assertSame(
            [
                'garbageCollector' => true,
                'healthCheck' => false,
                'webPOptimizer' => true,
            ],
            $registry->statusMap(),
        );
    }

    #[Test]
    public function isActiveDefaultsToFalseWhenConfigMissing(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn(null);

        $registry = new ModuleRegistry($config);

        self::assertFalse($registry->isActive(ModuleRegistry::MODULE_GARBAGE_COLLECTOR));
    }
}
