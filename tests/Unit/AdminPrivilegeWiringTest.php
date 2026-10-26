<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Hält zusammen, was in der Rollen-Verwaltung nur gemeinsam etwas taugt.
 *
 * Drei Stellen müssen übereinstimmen, und keine davon merkt es, wenn eine der anderen
 * abweicht: Die Endpunkte fordern ein Recht (`_acl`), das Admin-Modul meldet es zur
 * Vergabe an, und die Sprachdatei gibt ihm einen Namen. Weicht eine ab, ist der Schaden
 * still — 403 ohne Zuweisungsmöglichkeit, oder eine Zeile im Rechte-Raster, die ihren
 * eigenen Schlüssel anzeigt.
 *
 * Beides ist schnell passiert: Liegt die Sprachdatei auf oberster Ebene statt unter
 * `sw-privileges.permissions`, zeigt die Zeile ihren Schlüssel. Heißt ein Recht etwa
 * `manager`, kennt das Rechte-Raster den Namen nicht, weil es genau vier feste Spalten hat
 * (`viewer`, `editor`, `creator`, `deleter`) — einzeln anhakbar ist es dann nicht.
 */
final class AdminPrivilegeWiringTest extends TestCase
{
    private const ADMIN_SOURCE = __DIR__ . '/../../src/Resources/app/administration/src';
    private const CONTROLLER_DIR = __DIR__ . '/../../src/Controller/Admin';

    /** Die vier Spalten, die das Rechte-Raster des Cores kennt. */
    private const GRID_COLUMNS = ['viewer', 'editor', 'creator', 'deleter'];

    #[Test]
    public function everyPrivilegeDemandedByAnEndpointIsOfferedForAssignment(): void
    {
        $offered = $this->privilegesOfferedByTheAdminModule();

        foreach ($this->privilegesDemandedByEndpoints() as $privilege => $file) {
            self::assertContains(
                $privilege,
                $offered,
                \sprintf(
                    '%s fordert "%s", aber das Admin-Modul bietet dieses Recht nicht zur Vergabe an. '
                    . 'Wer keine Administrator-Rolle hat, bekommt 403 und niemand kann es beheben.',
                    basename($file),
                    $privilege,
                ),
            );
        }
    }

    #[Test]
    public function everyOfferedRoleFitsAColumnOfThePermissionGrid(): void
    {
        foreach ($this->rolesOfferedByTheAdminModule() as $role) {
            self::assertContains(
                $role,
                self::GRID_COLUMNS,
                \sprintf(
                    'Die Rolle "%s" hat im Rechte-Raster keine Spalte — es kennt nur %s. '
                    . 'Sie wäre nur über "Alle" zu vergeben, alles oder nichts.',
                    $role,
                    implode(', ', self::GRID_COLUMNS),
                ),
            );
        }
    }

    /**
     * Die Beschriftung muss dort stehen, wo der Core sie sucht.
     *
     * Gesucht wird unter `sw-privileges.permissions.<key>`; liegt sie woanders, zeigt das
     * Raster den rohen Schlüssel an — für den Betreiber sieht das aus wie ein Programmfehler.
     */
    #[Test]
    public function bothLanguageFilesNameThePrivilegeWhereTheGridLooksForIt(): void
    {
        foreach (['de-DE', 'en-GB'] as $language) {
            $snippets = $this->snippets($language);
            $entry = $snippets['sw-privileges']['permissions']['rc_media_toolkit'] ?? null;

            self::assertIsArray(
                $entry,
                \sprintf(
                    '%s.json hat keinen Eintrag unter sw-privileges.permissions.rc_media_toolkit — '
                    . 'das Raster zeigt dann den Schlüssel statt eines Namens.',
                    $language,
                ),
            );

            foreach (['label', ...$this->rolesOfferedByTheAdminModule()] as $needed) {
                self::assertArrayHasKey(
                    $needed,
                    $entry,
                    \sprintf('%s.json: "%s" fehlt unter sw-privileges.permissions.rc_media_toolkit.', $language, $needed),
                );
                self::assertNotSame('', trim((string) $entry[$needed]));
            }
        }
    }

    /**
     * @return array<string, string> Recht => Datei, die es fordert
     */
    private function privilegesDemandedByEndpoints(): array
    {
        $demanded = [];

        foreach (glob(self::CONTROLLER_DIR . '/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("~'_acl'\\s*=>\\s*\\['([^']+)'\\]~", $source, $matches);
            foreach ($matches[1] as $privilege) {
                $demanded[$privilege] = $file;
            }
        }

        self::assertNotSame([], $demanded, 'Kein einziger Endpunkt fordert ein Recht — der Test prüft dann nichts.');

        return $demanded;
    }

    /**
     * @return list<string>
     */
    private function privilegesOfferedByTheAdminModule(): array
    {
        preg_match_all("~'(rc_media_toolkit\\.[a-z]+)'~", $this->aclSource(), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<string>
     */
    private function rolesOfferedByTheAdminModule(): array
    {
        // Die Rollennamen stehen als Schlüssel im `roles`-Block, jeweils am Zeilenanfang.
        preg_match_all('~^\s{8}([a-zA-Z]+):\s*\{~m', $this->aclSource(), $matches);
        $roles = array_values(array_unique($matches[1]));

        self::assertNotSame([], $roles, 'Im Admin-Modul ist keine Rolle zu finden — der Test prüft dann nichts.');

        return $roles;
    }

    private function aclSource(): string
    {
        $file = self::ADMIN_SOURCE . '/acl/index.js';
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /**
     * @return array<string, mixed>
     */
    private function snippets(string $language): array
    {
        $file = self::ADMIN_SOURCE . '/module/rc-media-toolkit/snippet/' . $language . '.json';
        self::assertFileExists($file);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
