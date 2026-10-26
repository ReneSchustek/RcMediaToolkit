<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service;

use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Exception\InvalidExclusionPatternException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Prüft Dateinamen gegen die in der Plugin-Config hinterlegten Ausschluss-Regex-Patterns.
 *
 * Muster werden zeilenweise gelesen, leere Zeilen verworfen; Muster ohne eigene Delimiter werden
 * als Literal behandelt und in `/.../` gefasst.
 *
 * Jedes Muster wird **einmal beim Laden** auf Gültigkeit geprüft. Ein ungültiges Muster bricht den
 * Lauf ab, statt einfach nie zu greifen: Wer ein Muster einträgt, will Dateien schützen — würde ein
 * Tippfehler nur dazu führen, dass nichts ausgeschlossen wird, landeten genau diese Dateien in der
 * Quarantäne, ohne dass es jemandem auffällt.
 */
final class ExclusionMatcher
{
    private const CONFIG_KEY = 'RcMediaToolkit.config.gcExclusionPatterns';

    /** @var list<string>|null */
    private ?array $compiledPatterns = null;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function isExcluded(string $fileName): bool
    {
        foreach ($this->patterns() as $pattern) {
            if (preg_match($pattern, $fileName) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reset für Tests oder bei Config-Änderung zur Laufzeit.
     */
    public function refresh(): void
    {
        $this->compiledPatterns = null;
    }

    /**
     * @return list<string>
     */
    private function patterns(): array
    {
        if ($this->compiledPatterns !== null) {
            return $this->compiledPatterns;
        }

        $rawValue = $this->systemConfigService->get(self::CONFIG_KEY);
        $raw = is_string($rawValue) ? $rawValue : '';
        $lines = preg_split('/\r?\n/', $raw) ?: [];

        $patterns = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $patterns[] = $this->assertUsable($this->ensureDelimiter($trimmed), $trimmed);
        }

        return $this->compiledPatterns = $patterns;
    }

    /**
     * Prüft das fertige Muster gegen eine leere Zeichenkette — der billigste Weg, einen ungültigen
     * regulären Ausdruck zu erkennen. Die Warnung von `preg_match` wird dabei nicht unterdrückt,
     * sondern aufgefangen und in die Ausnahme übernommen: sie sagt am genauesten, was am Muster
     * nicht stimmt.
     */
    private function assertUsable(string $compiled, string $original): string
    {
        $reason = null;
        set_error_handler(static function (int $severity, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });

        try {
            $valid = preg_match($compiled, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (!$valid) {
            throw InvalidExclusionPatternException::forPattern($original, $reason);
        }

        return $compiled;
    }

    private function ensureDelimiter(string $pattern): string
    {
        // Wenn das Pattern bereits Regex-Delimiter trägt (z. B. /foo/i), unverändert lassen.
        if (preg_match('/^[\/#~].+[\/#~][a-zA-Z]*$/', $pattern) === 1) {
            return $pattern;
        }
        // Sonst als Literal-Substring-Match behandeln (mit u-Flag für Unicode).
        return '/' . preg_quote($pattern, '/') . '/u';
    }
}
