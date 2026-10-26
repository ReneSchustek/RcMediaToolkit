<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service;

/**
 * Abstraktion über die Usage-Map. Default-Implementation hält die Hex-IDs im PHP-Array.
 * Für sehr große Stores (10M+ Medien) wäre eine Temp-Tabelle-Implementation der nächste
 * Schritt — Architektur-Hook bewusst vorhanden, Default-In-Memory ist KISS für die
 * überwiegende Zahl von Shops.
 */
interface UsageMapInterface
{
    public function add(string $hexId): void;

    public function has(string $hexId): bool;

    public function size(): int;
}
