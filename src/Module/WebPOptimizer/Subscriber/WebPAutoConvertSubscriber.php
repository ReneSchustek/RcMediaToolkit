<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Subscriber;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message\WebPConvertMessage;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\Content\Media\Event\MediaThumbnailDeletedEvent;
use Shopware\Core\Content\Media\Event\MediaUploadedEvent;
use Shopware\Core\Content\Media\Event\ThumbnailGeneratedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Hält die WebP-Fassungen mit dem Bestand Schritt: neue Bilder werden umgewandelt, entfernte
 * Vorschaubilder nehmen ihre Fassung mit.
 *
 * Angestoßen wird an den Ereignissen, die der Kern nach dem Schreiben der Datei feuert. Das
 * Datenbank-Ereignis der Vorschaubilder taugt dafür nicht: Der Kern legt die Zeilen an, bevor er
 * die Dateien schreibt.
 *
 * Die Umwandlung selbst läuft in der Warteschlange, damit ein Upload in der Verwaltung nicht auf
 * die Umwandlung von sieben Vorschaubildern wartet.
 */
final class WebPAutoConvertSubscriber implements EventSubscriberInterface
{
    private const CONVERTIBLE_EXTENSIONS = ['jpeg', 'jpg', 'png'];

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly SystemConfigService $systemConfigService,
        private readonly MessageBusInterface $messageBus,
        private readonly Connection $connection,
        private readonly FilesystemOperator $publicFilesystem,
        private readonly FilesystemAdapterInterface $filesystem,
        private readonly MediaToolkitLogger $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ThumbnailGeneratedEvent::class => 'onThumbnailGenerated',
            MediaUploadedEvent::class => 'onMediaUploaded',
            MediaThumbnailDeletedEvent::EVENT_NAME => 'onThumbnailsDeleted',
        ];
    }

    public function onThumbnailGenerated(ThumbnailGeneratedEvent $event): void
    {
        // Vorschaubilder privater Medien liegen in einem anderen Dateisystem und werden nie
        // öffentlich ausgeliefert.
        if ($event->getFilesystem() !== $this->publicFilesystem || !$this->isEnabled()) {
            return;
        }

        $this->dispatch($event->getPath());
    }

    public function onMediaUploaded(MediaUploadedEvent $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $path = $this->connection->fetchOne(
                'SELECT path FROM media WHERE id = UNHEX(:id) AND private = 0',
                ['id' => $event->getMediaId()],
            );
        } catch (Throwable $e) {
            $this->logger->withModule('WebPOptimizer')->warning(
                'Pfad des hochgeladenen Mediums nicht lesbar',
                ['mediaId' => $event->getMediaId(), 'exception' => $e],
            );

            return;
        }

        if (is_string($path) && $path !== '') {
            $this->dispatch($path);
        }
    }

    /**
     * Räumt unabhängig vom Schalter auf: Eine Fassung ohne Vorschaubild wäre eine Datei, die
     * nichts mehr anzeigt, aber weiter Platz belegt.
     */
    public function onThumbnailsDeleted(MediaThumbnailDeletedEvent $event): void
    {
        if (!$this->moduleRegistry->isActive(ModuleRegistry::MODULE_WEBP_OPTIMIZER)) {
            return;
        }

        foreach ($event->getThumbnails() as $thumbnail) {
            $path = $thumbnail->getPath();
            if ($path === '' || !$this->isConvertible($path)) {
                continue;
            }

            $webpPath = $path . '.webp';
            try {
                if ($this->filesystem->exists($webpPath)) {
                    $this->filesystem->delete($webpPath);
                }
            } catch (Throwable $e) {
                $this->logger->withModule('WebPOptimizer')->warning(
                    'WebP-Fassung eines gelöschten Vorschaubilds nicht entfernbar',
                    ['path' => $webpPath, 'exception' => $e],
                );
            }
        }
    }

    private function isEnabled(): bool
    {
        return $this->moduleRegistry->isActive(ModuleRegistry::MODULE_WEBP_OPTIMIZER)
            && (bool) $this->systemConfigService->get('RcMediaToolkit.config.webpAutoConvertOnUpload');
    }

    private function dispatch(string $path): void
    {
        if (!$this->isConvertible($path)) {
            return;
        }

        $this->messageBus->dispatch(new WebPConvertMessage($path));
    }

    private function isConvertible(string $path): bool
    {
        return in_array(strtolower((string) pathinfo($path, \PATHINFO_EXTENSION)), self::CONVERTIBLE_EXTENSIONS, true);
    }
}
