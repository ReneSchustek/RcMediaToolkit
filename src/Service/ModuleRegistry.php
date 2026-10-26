<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Liest die drei Modul-Toggles aus der Plugin-Config.
 *
 * Per Default alle Module deaktiviert — der Shopbetreiber muss aktivieren, was er
 * braucht. Schützt vor versehentlichen Side-Effects nach Installation.
 *
 * Caching: request-scoped Lazy-Cache, damit Hot-Path-Aufrufe (z. B. Twig-Filter
 * `rc_webp_url`) pro Request nicht mehrfach den SystemConfig-Read auslösen.
 * `refresh()` ist für Tests und Long-Running-Worker (Messenger) verfügbar.
 */
final class ModuleRegistry
{
    public const MODULE_GARBAGE_COLLECTOR = 'garbageCollector';
    public const MODULE_HEALTH_CHECK = 'healthCheck';
    public const MODULE_WEBP_OPTIMIZER = 'webPOptimizer';

    private const CONFIG_PREFIX = 'RcMediaToolkit.config.';

    /** @var array<string, bool> */
    private array $activeCache = [];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function isActive(string $module): bool
    {
        if (array_key_exists($module, $this->activeCache)) {
            return $this->activeCache[$module];
        }
        $key = self::CONFIG_PREFIX . $module . 'Active';
        return $this->activeCache[$module] = (bool) $this->systemConfigService->get($key);
    }

    /**
     * @return array<string, bool>
     */
    public function statusMap(): array
    {
        return [
            self::MODULE_GARBAGE_COLLECTOR => $this->isActive(self::MODULE_GARBAGE_COLLECTOR),
            self::MODULE_HEALTH_CHECK => $this->isActive(self::MODULE_HEALTH_CHECK),
            self::MODULE_WEBP_OPTIMIZER => $this->isActive(self::MODULE_WEBP_OPTIMIZER),
        ];
    }

    /**
     * Räumt den Request-Scope-Cache — relevant für Long-Running-Worker, die nach
     * SystemConfig-Änderung einen neuen Wert sehen sollen.
     */
    public function refresh(): void
    {
        $this->activeCache = [];
    }
}
