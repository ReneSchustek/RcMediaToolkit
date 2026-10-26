<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Wählt die UsageMap-Implementation basierend auf Plugin-Config.
 *
 * Default: InMemoryUsageMap (passend für 99 % der Shops).
 * Bei `gcUseTempTableUsageMap = true`: TempTableUsageMap (für >5M Medien).
 */
final class UsageMapFactory
{
    private const CONFIG_KEY = 'RcMediaToolkit.config.gcUseTempTableUsageMap';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function create(): UsageMapInterface
    {
        if ((bool) $this->systemConfigService->get(self::CONFIG_KEY)) {
            return new TempTableUsageMap($this->connection);
        }
        return new InMemoryUsageMap();
    }
}
