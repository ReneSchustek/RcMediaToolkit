<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service;

/**
 * Default-Implementation der UsageMap — hält Hex-IDs in einem assoziativen Array.
 *
 * Speicher-Footprint: ~32 Byte pro Eintrag, ergibt ~32 MB bei 1M Einträgen und ~320 MB bei 10M.
 * Bei sehr großen Stores zur TempTable-Implementation wechseln (in 1.x geplant).
 */
final class InMemoryUsageMap implements UsageMapInterface
{
    /** @var array<string, true> */
    private array $set = [];

    public function add(string $hexId): void
    {
        $this->set[$hexId] = true;
    }

    public function has(string $hexId): bool
    {
        return isset($this->set[$hexId]);
    }

    public function size(): int
    {
        return count($this->set);
    }
}
