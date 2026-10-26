<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service\Encoder;

use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Nutzt das cwebp-Binary für höchste Qualität/Geschwindigkeit. Liefert
 * Color-Profile-Erhaltung (-metadata icc) und Lossless-Encoding (-lossless).
 *
 * Sicherheits-Härte: der konfigurierbare Binary-Pfad wird gegen eine
 * Allowlist üblicher System-Bin-Verzeichnisse geprüft. So kann ein Admin den
 * Pfad zwar anpassen, aber nicht auf willkürliche Skripte zeigen.
 */
final class CwebpBinaryEncoder implements WebPEncoderInterface
{
    private const CONFIG_KEY_PATH = 'RcMediaToolkit.config.webpCwebpPath';
    private const CONFIG_KEY_TIMEOUT = 'RcMediaToolkit.config.webpCwebpTimeoutSeconds';
    private const DEFAULT_PATH = '/usr/bin/cwebp';
    private const DEFAULT_TIMEOUT = 60;
    private const MIN_TIMEOUT = 5;
    private const MAX_TIMEOUT = 600;
    private const ALLOWED_PATH_PREFIXES = [
        '/usr/bin/',
        '/usr/local/bin/',
        '/opt/',
        '/usr/sbin/',
        '/snap/bin/',
        // Windows-Defaults für Dev-Umgebungen
        'C:\\Program Files\\',
        'C:\\ProgramData\\chocolatey\\bin\\',
    ];

    private ?bool $availabilityCache = null;
    private ?string $versionCache = null;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly MediaToolkitLogger $logger,
    ) {
    }

    public function name(): string
    {
        return 'cwebp';
    }

    public function isAvailable(): bool
    {
        if ($this->availabilityCache !== null) {
            return $this->availabilityCache;
        }
        if (!class_exists(Process::class)) {
            return $this->availabilityCache = false;
        }
        try {
            $path = $this->checkedBinaryPath();
        } catch (RuntimeException) {
            return $this->availabilityCache = false;
        }
        if (!is_executable($path)) {
            return $this->availabilityCache = false;
        }
        try {
            $process = new Process([$path, '-version']);
            $process->setTimeout(5);
            $process->run();
            if (!$process->isSuccessful()) {
                return $this->availabilityCache = false;
            }
            $this->versionCache = 'cwebp ' . trim($process->getOutput() . $process->getErrorOutput());
            return $this->availabilityCache = true;
        } catch (Throwable) {
            return $this->availabilityCache = false;
        }
    }

    public function version(): ?string
    {
        if ($this->versionCache !== null) {
            return $this->versionCache;
        }
        if ($this->isAvailable()) {
            return $this->versionCache;
        }
        return null;
    }

    public function encode(string $sourceBytes, string $sourceFormat, int $quality, bool $lossless, bool $stripMetadata): string
    {
        $tmpIn = tempnam(sys_get_temp_dir(), 'rcwebp-in-');
        $tmpOut = tempnam(sys_get_temp_dir(), 'rcwebp-out-');
        if ($tmpIn === false || $tmpOut === false) {
            throw new RuntimeException('Konnte temporäre Datei für cwebp nicht anlegen');
        }

        try {
            if (file_put_contents($tmpIn, $sourceBytes) === false) {
                throw new RuntimeException('cwebp-Eingabedatei konnte nicht geschrieben werden');
            }

            // Geprüft wird hier, nicht nur in `isAvailable()`. Auf die Reihenfolge allein ist
            // kein Verlass: Die Kette fragt erst nach Verfügbarkeit, und deren Ergebnis liegt
            // zwischengespeichert — während der Pfad bei jedem Lauf frisch aus der
            // Konfiguration kommt. In einem lang laufenden Worker liegt dazwischen ein
            // Zeitfenster, in dem ein geänderter Pfad ungeprüft gestartet würde.
            $args = [$this->checkedBinaryPath()];
            if ($lossless) {
                $args[] = '-lossless';
            } else {
                $args[] = '-q';
                $args[] = (string) max(0, min(100, $quality));
            }
            $args[] = '-metadata';
            $args[] = $stripMetadata ? 'none' : 'icc';
            $args[] = $tmpIn;
            $args[] = '-o';
            $args[] = $tmpOut;

            $process = new Process($args);
            $process->setTimeout($this->encodeTimeoutSeconds());
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException(sprintf(
                    'cwebp-Encoding fehlgeschlagen: %s',
                    $process->getErrorOutput(),
                ));
            }

            $bytes = file_get_contents($tmpOut);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('cwebp lieferte leeren Output');
            }
            unset($sourceFormat);
            return $bytes;
        } finally {
            // Aufräumen darf den Ablauf nicht abbrechen — aber auch nicht unsichtbar
            // scheitern. Bleiben die temporären Dateien liegen, läuft irgendwann das
            // Dateisystem voll, und niemand weiß, woher es kommt.
            $this->removeTempFile($tmpIn);
            $this->removeTempFile($tmpOut);
        }
    }

    private function removeTempFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        if (!unlink($path)) {
            $this->logger->withModule('WebPOptimizer')->warning(
                'Temporäre cwebp-Datei ließ sich nicht entfernen',
                ['path' => $path],
            );
        }
    }

    private function binaryPath(): string
    {
        $path = $this->systemConfigService->get(self::CONFIG_KEY_PATH);
        return is_string($path) && $path !== '' ? $path : self::DEFAULT_PATH;
    }

    /**
     * Der konfigurierte Pfad — oder eine Ausnahme.
     *
     * Die eine Stelle, die beide Wege bedient: Verfügbarkeitsprüfung und Ausführung. Ein
     * stiller Rückfall auf den Standardpfad wäre hier das Gefährlichste: Wer einen Pfad
     * einträgt, der nicht erlaubt ist, bekäme dann klaglos ein anderes Programm gestartet.
     */
    private function checkedBinaryPath(): string
    {
        $path = $this->binaryPath();

        if ($path === '' || !$this->isPathAllowed($path)) {
            throw new RuntimeException(sprintf(
                'cwebp-Pfad ist nicht zugelassen: %s',
                $path === '' ? '(leer)' : $path,
            ));
        }

        return $path;
    }

    private function encodeTimeoutSeconds(): int
    {
        $raw = $this->systemConfigService->get(self::CONFIG_KEY_TIMEOUT);
        $timeout = is_numeric($raw) ? (int) $raw : self::DEFAULT_TIMEOUT;
        return max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, $timeout));
    }

    /**
     * Sicherheits-Check: verhindert, dass Admins beliebige Skripte als „cwebp-Binary"
     * konfigurieren können (z. B. /tmp/exploit.sh).
     */
    private function isPathAllowed(string $path): bool
    {
        // Realpath verhindert Symlink-Tricks
        $real = realpath($path);
        $check = $real !== false ? $real : $path;

        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($check, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
