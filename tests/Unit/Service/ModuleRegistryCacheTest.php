<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Verifiziert das Request-Scope-Caching der ModuleRegistry — Twig-Hot-Paths
 * dürfen pro Request nicht mehrfach den SystemConfig-Read auslösen.
 */
#[CoversClass(ModuleRegistry::class)]
final class ModuleRegistryCacheTest extends TestCase
{
    #[Test]
    public function isActiveQueriesSystemConfigOnlyOncePerModule(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(self::once())
            ->method('get')
            ->with('RcMediaToolkit.config.garbageCollectorActive')
            ->willReturn(true);

        $registry = new ModuleRegistry($config);
        self::assertTrue($registry->isActive(ModuleRegistry::MODULE_GARBAGE_COLLECTOR));
        self::assertTrue($registry->isActive(ModuleRegistry::MODULE_GARBAGE_COLLECTOR));
        self::assertTrue($registry->isActive(ModuleRegistry::MODULE_GARBAGE_COLLECTOR));
    }

    #[Test]
    public function refreshClearsCache(): void
    {
        $callCount = 0;
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static function () use (&$callCount): bool {
            $callCount++;
            return true;
        });

        $registry = new ModuleRegistry($config);
        $registry->isActive(ModuleRegistry::MODULE_HEALTH_CHECK);
        $registry->isActive(ModuleRegistry::MODULE_HEALTH_CHECK);
        $registry->refresh();
        $registry->isActive(ModuleRegistry::MODULE_HEALTH_CHECK);

        self::assertSame(2, $callCount);
    }
}
