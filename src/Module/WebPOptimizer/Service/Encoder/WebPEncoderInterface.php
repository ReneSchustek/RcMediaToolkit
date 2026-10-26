<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder;

/**
 * Der Vertrag für einen WebP-Umwandler.
 *
 * `isAvailable()` ist der Grund, warum es diese Schnittstelle gibt: Welche der vier
 * Umwandler auf einem Server bereitstehen, weiß man erst dort. Die Kette fragt der Reihe
 * nach und nimmt den ersten, der antwortet.
 *
 * `encode()` bekommt und liefert Bytes, keine Pfade — damit bleibt der Umwandler unabhängig
 * davon, ob die Datei lokal liegt oder auf einem entfernten Speicher.
 */
interface WebPEncoderInterface
{
    /**
     * Eindeutige Identifikation (z. B. "cwebp", "libvips", "imagick", "gd").
     * Wird für die Encoder-Reihenfolge aus der Plugin-Config genutzt.
     */
    public function name(): string;

    /**
     * Prüft, ob der Encoder zur Laufzeit verfügbar ist (Binary installiert,
     * PHP-Extension geladen, …).
     */
    public function isAvailable(): bool;

    /**
     * Encodiert Bild-Bytes zu WebP. Wirft RuntimeException bei Fehlschlägen.
     *
     * @param 'jpeg'|'png' $sourceFormat
     */
    public function encode(string $sourceBytes, string $sourceFormat, int $quality, bool $lossless, bool $stripMetadata): string;

    /**
     * Liefert eine Versions-Kennung (z. B. "cwebp 1.3.2", "Imagick 3.7.0").
     * `null` wenn nicht zuverlässig ermittelbar — wird im Encoder-Auswahl-Log
     * geloggt, sodass Output-Drift bei Binary-Updates nachvollziehbar bleibt.
     */
    public function version(): ?string;
}
