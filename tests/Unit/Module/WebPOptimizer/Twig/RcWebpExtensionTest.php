<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Twig\RcWebpExtension;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Der Filter, den der Klassenkommentar „den sauberen Weg für eigene Templates" nennt.
 *
 * Ohne Test liefert er unbemerkt das Original aus, sobald die Adressbildung kippt: Hängt er
 * `.webp` stumpf an `media.url`, landet die Endung hinter Shopwares Zeitstempel im Abfrageteil
 * statt im Pfad. Der Server antwortet dann mit 200 und dem JPEG in voller Größe, ausgegeben
 * als `image/webp`. Der eingebaute Rückfall („der Browser nimmt bei 404 das Original") greift
 * nie, weil es kein 404 gibt.
 */
#[CoversClass(RcWebpExtension::class)]
final class RcWebpExtensionTest extends TestCase
{
    /**
     * Was: Eine Medien-Adresse mit dem Zeitstempel, den Shopware selbst anhängt.
     * Warum: **Der Normalfall im Betrieb.** In dieser Form liefert Shopware jedes Medium mit
     *        `updated_at` aus.
     * Erwartet: Die Endung sitzt im Pfad, der Zeitstempel bleibt dahinter stehen — die Adresse
     *           trifft damit die umgewandelte Datei.
     */
    #[Test]
    public function theExtensionGoesIntoThePathAndNotIntoTheQuery(): void
    {
        $extension = new RcWebpExtension($this->activeRegistry());

        self::assertSame(
            '/media/ab/cd/ef/1784098309/bild.jpg.webp?ts=1784098309',
            $extension->webpUrl($this->media('/media/ab/cd/ef/1784098309/bild.jpg?ts=1784098309')),
        );
    }

    /**
     * Was: Eine Adresse ohne Abfrageteil.
     * Warum: Die gibt es weiterhin — etwa bei Bildern ohne `updated_at` und bei Adressen, die
     *        ein Template selbst zusammensetzt. Der Eingriff darf diesen Fall nicht kaputt
     *        machen.
     * Erwartet: schlicht angehängt.
     */
    #[Test]
    public function anAddressWithoutAQueryKeepsItsPlainForm(): void
    {
        $extension = new RcWebpExtension($this->activeRegistry());

        self::assertSame(
            '/media/ab/cd/ef/bild.png.webp',
            $extension->webpUrl($this->media('/media/ab/cd/ef/bild.png')),
        );
    }

    /**
     * Was: Ist das Modul aus, bleibt die Adresse, wie sie war.
     * Warum: Der Filter darf nicht auf eine Datei zeigen, die niemand erzeugt hat.
     * Erwartet: unverändert, samt Zeitstempel.
     */
    #[Test]
    public function aDeactivatedModuleReturnsTheOriginalAddress(): void
    {
        $extension = new RcWebpExtension($this->inactiveRegistry());

        self::assertSame(
            '/media/ab/cd/ef/bild.jpg?ts=1784098309',
            $extension->webpUrl($this->media('/media/ab/cd/ef/bild.jpg?ts=1784098309')),
        );
    }

    #[Test]
    public function withoutAMediumThereIsNoAddress(): void
    {
        self::assertNull((new RcWebpExtension($this->activeRegistry()))->webpUrl(null));
    }

    private function media(string $url): MediaEntity
    {
        $media = new MediaEntity();
        $media->setUrl($url);

        return $media;
    }

    private function activeRegistry(): ModuleRegistry
    {
        return new ModuleRegistry($this->config(true));
    }

    private function inactiveRegistry(): ModuleRegistry
    {
        return new ModuleRegistry($this->config(false));
    }

    private function config(bool $active): SystemConfigService
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($active);

        return $config;
    }
}
