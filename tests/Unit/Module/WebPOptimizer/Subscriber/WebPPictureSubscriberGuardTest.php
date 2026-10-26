<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Subscriber\WebPPictureSubscriber;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Der Umschreiber greift in **jede ausgelieferte Seite** ein.
 *
 * Deshalb zählt hier weniger, dass er umschreibt, als dass er es **unterlässt**, wo er nicht
 * hingehört: in Antworten der Schnittstellen, im Backoffice, bei Fehlerseiten, bei allem, was
 * kein HTML ist. Jede dieser Bremsen fehlt genau einmal — und dann steht sie in Antworten, in
 * denen niemand mit einem Eingriff rechnet.
 */
#[CoversClass(WebPPictureSubscriber::class)]
final class WebPPictureSubscriberGuardTest extends TestCase
{
    private const BODY_WITH_IMAGE = '<html><body><img src="/media/aa/bb/bild.jpg"></body></html>';

    #[Test]
    public function aSubRequestIsLeftAlone(): void
    {
        $response = $this->handle(
            $this->event(self::BODY_WITH_IMAGE, requestType: HttpKernelInterface::SUB_REQUEST),
        );

        self::assertSame(self::BODY_WITH_IMAGE, $response->getContent());
    }

    #[Test]
    public function aSwitchedOffModuleChangesNothing(): void
    {
        $response = $this->handle($this->event(self::BODY_WITH_IMAGE), moduleActive: false);

        self::assertSame(self::BODY_WITH_IMAGE, $response->getContent());
    }

    #[Test]
    public function aSwitchedOffSettingChangesNothing(): void
    {
        $response = $this->handle($this->event(self::BODY_WITH_IMAGE), settingEnabled: false);

        self::assertSame(self::BODY_WITH_IMAGE, $response->getContent());
    }

    /**
     * Fehlerseiten und Weiterleitungen bleiben unberührt. Ein Eingriff dort verändert eine
     * Antwort, die der Shop gerade als Ausnahmezustand ausliefert.
     */
    #[Test]
    public function onlySuccessfulResponsesAreTouched(): void
    {
        foreach ([301, 404, 500] as $status) {
            $response = $this->handle($this->event(self::BODY_WITH_IMAGE, status: $status));

            self::assertSame(self::BODY_WITH_IMAGE, $response->getContent(), 'Status ' . $status);
        }
    }

    /**
     * Ohne HTML gibt es keine Bildmarken — wohl aber Antworten, in denen die Zeichenfolge
     * zufällig vorkommt: JSON einer Schnittstelle etwa. Ein Umschreiben zerstörte sie.
     */
    #[Test]
    public function onlyHtmlIsTouched(): void
    {
        $response = $this->handle(
            $this->event('{"html":"<img src=\"/media/aa/bb/bild.jpg\">"}', contentType: 'application/json'),
        );

        self::assertStringContainsString('<img', (string) $response->getContent());
        self::assertStringNotContainsString('<picture', (string) $response->getContent());
    }

    /**
     * Backoffice und Schnittstellen sind ausgenommen. Dort hängen Oberflächen an genauem
     * Markup; ein zusätzliches Element bricht sie.
     */
    #[Test]
    public function excludedPathsAreLeftAlone(): void
    {
        foreach (['/api/media', '/_action/anything', '/admin', '/admin/dashboard', '/_profiler/x'] as $path) {
            $response = $this->handle($this->event(self::BODY_WITH_IMAGE, path: $path));

            self::assertSame(self::BODY_WITH_IMAGE, $response->getContent(), 'Pfad ' . $path);
        }
    }

    #[Test]
    public function aBodyWithoutAnImageIsLeftAlone(): void
    {
        $body = '<html><body><p>ohne Bild</p></body></html>';

        self::assertSame($body, $this->handle($this->event($body))->getContent());
    }

    /**
     * Sehr große Antworten werden übersprungen — der Ausdruck über den ganzen Rumpf wäre dort
     * teurer als der Nutzen. **Das Überspringen wird protokolliert**, sonst verschwände der
     * Grund, warum ausgerechnet diese Seite keine WebP-Quellen trägt.
     */
    #[Test]
    public function anOversizedBodyIsSkippedAndLogged(): void
    {
        $body = '<img src="/media/aa/bb/bild.jpg">' . str_repeat('x', 6 * 1024 * 1024);

        $response = $this->handle($this->event($body));

        self::assertSame($body, $response->getContent());
    }

    private function handle(
        ResponseEvent $event,
        bool $moduleActive = true,
        bool $settingEnabled = true,
    ): Response {
        $this->subscriber($moduleActive, $settingEnabled)->onKernelResponse($event);

        return $event->getResponse();
    }

    private function subscriber(bool $moduleActive, bool $settingEnabled): WebPPictureSubscriber
    {
        // Beide sind `final` und werden deshalb echt gebaut. Die Verzweigung steuert die
        // Einstellungs-Doublette darunter: Der Modul-Schalter und der Schalter des Umschreibers
        // liegen unter verschiedenen Schlüsseln.
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key): bool => str_contains($key, 'webpSubscriberEnabled')
                ? $settingEnabled
                : $moduleActive,
        );

        $registry = new ModuleRegistry($config);

        // **Die WebP-Fassung muss vorhanden sein, sonst prüfen diese Fälle nichts.**
        // Fehlte sie, bliebe der Rumpf ohnehin unverändert — und die Zusicherung „unverändert"
        // wäre auch dann erfüllt, wenn jede Bremse entfernt würde: grüne Tests ohne jede
        // Aussage.
        $filesystem = new FakeFilesystem(['media/aa/bb/bild.jpg.webp' => true]);

        $cache = new ArrayAdapter();

        return new WebPPictureSubscriber(
            $registry,
            $config,
            $filesystem,
            new MediaToolkitLogger(new NullLogger()),
            $cache,
        );
    }

    private function event(
        string $body,
        int $status = 200,
        string $contentType = 'text/html; charset=UTF-8',
        string $path = '/',
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): ResponseEvent {
        $response = new Response($body, $status, ['Content-Type' => $contentType]);

        return new ResponseEvent(
            $this->createMock(KernelInterface::class),
            Request::create($path),
            $requestType,
            $response,
        );
    }

    /**
     * **Die Gegenprobe zu allen Fällen oben.** Ohne sie wäre nicht belegt, dass der Umschreiber
     * überhaupt zugreift — und dann prüften die Bremsen nichts.
     */
    #[Test]
    public function anOrdinaryPageWithAKnownWebpIsRewritten(): void
    {
        $response = $this->handle($this->event(self::BODY_WITH_IMAGE));

        self::assertStringContainsString('<picture', (string) $response->getContent());
        self::assertStringContainsString('bild.jpg.webp', (string) $response->getContent());
    }
}
