<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\WebPOptimizer\Subscriber;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message\WebPConvertMessage;
use Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Subscriber\WebPAutoConvertSubscriber;
use Ruhrcoder\RcMediaToolkit\Service\FilesystemAdapterInterface;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Ruhrcoder\RcMediaToolkit\Service\ModuleRegistry;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailCollection;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailEntity;
use Shopware\Core\Content\Media\Event\MediaThumbnailDeletedEvent;
use Shopware\Core\Content\Media\Event\MediaUploadedEvent;
use Shopware\Core\Content\Media\Event\ThumbnailGeneratedEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Neue Bilder bekommen ihre Fassung von selbst, entfernte Vorschaubilder nehmen sie mit.
 *
 * Ohne diesen Zuhörer gäbe es WebP nur für den Bestand am Tag des Massenlaufs. Jedes später
 * hochgeladene Bild, und jedes Vorschaubild einer neuen Stufe, käme wieder als PNG an.
 */
#[CoversClass(WebPAutoConvertSubscriber::class)]
final class WebPAutoConvertSubscriberTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    private FilesystemOperator $publicFilesystem;

    protected function setUp(): void
    {
        $this->dispatched = [];
        $this->publicFilesystem = $this->createStub(FilesystemOperator::class);
    }

    /**
     * Was: Ein Vorschaubild ist geschrieben.
     * Warum: Erst dieses Ereignis kommt nach der Datei; die Umwandlung geht in die Warteschlange,
     *        damit der Upload nicht auf sie wartet.
     */
    #[Test]
    public function aGeneratedThumbnailIsQueuedForConversion(): void
    {
        $subscriber = $this->subscriber(enabled: true);

        $subscriber->onThumbnailGenerated($this->generated('thumbnail/a/bild_600x338.png', $this->publicFilesystem));

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(WebPConvertMessage::class, $this->dispatched[0]);
        self::assertSame('thumbnail/a/bild_600x338.png', $this->dispatched[0]->sourcePath);
        self::assertInstanceOf(AsyncMessageInterface::class, $this->dispatched[0], 'Der Auftrag muss in die Warteschlange.');
    }

    /**
     * Was: Vorschaubild eines privaten Mediums, eines WebP-Bildes, oder Schalter aus.
     * Warum: Private Medien liegen woanders und werden nie öffentlich gezeigt; ein WebP braucht
     *        keine Fassung; und wer den Schalter ausschaltet, will keine Umwandlung.
     */
    #[Test]
    public function nothingIsQueuedForPrivateFilesOtherFormatsOrWhenSwitchedOff(): void
    {
        $this->subscriber(enabled: true)->onThumbnailGenerated(
            $this->generated('thumbnail/a/rechnung_400x400.png', $this->createStub(FilesystemOperator::class)),
        );
        $this->subscriber(enabled: true)->onThumbnailGenerated($this->generated('thumbnail/a/x_800x400.webp', $this->publicFilesystem));
        $this->subscriber(enabled: false)->onThumbnailGenerated($this->generated('thumbnail/a/bild_600x338.png', $this->publicFilesystem));

        self::assertSame([], $this->dispatched);
    }

    /**
     * Was: Ein Original wird hochgeladen.
     * Warum: Bilder ohne `srcset` zeigen das Original; der Pfad kommt aus der Datenbank, und nur
     *        für öffentliche Medien gibt es einen.
     */
    #[Test]
    public function anUploadedOriginalIsQueuedWithItsStoredPath(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')
            ->with(self::stringContains('private = 0'), ['id' => 'abc'])
            ->willReturn('media/a/foto.jpg');

        $this->subscriber(enabled: true, connection: $connection)
            ->onMediaUploaded(new MediaUploadedEvent('abc', Context::createDefaultContext()));

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(WebPConvertMessage::class, $this->dispatched[0]);
        self::assertSame('media/a/foto.jpg', $this->dispatched[0]->sourcePath);
    }

    /**
     * Was: Vorschaubilder werden gelöscht, etwa beim Neuerzeugen mit anderen Stufen.
     * Warum: Bliebe die Fassung liegen, belegte sie Platz für ein Bild, das niemand mehr anfragt.
     */
    #[Test]
    public function deletedThumbnailsTakeTheirWebpWithThem(): void
    {
        $deleted = [];
        $filesystem = $this->createMock(FilesystemAdapterInterface::class);
        $filesystem->method('exists')->willReturnCallback(static fn (string $path): bool => $path === 'thumbnail/a/b_400x400.png.webp');
        $filesystem->method('delete')->willReturnCallback(static function (string $path) use (&$deleted): void {
            $deleted[] = $path;
        });

        $this->subscriber(enabled: false, filesystem: $filesystem)->onThumbnailsDeleted(new MediaThumbnailDeletedEvent(
            new MediaThumbnailCollection([
                $this->thumbnail('1', 'thumbnail/a/b_400x400.png'),
                $this->thumbnail('2', 'thumbnail/a/b_800x800.png'),
                $this->thumbnail('3', 'thumbnail/a/c_400x400.webp'),
            ]),
            Context::createDefaultContext(),
        ));

        self::assertSame(['thumbnail/a/b_400x400.png.webp'], $deleted, 'Auch bei ausgeschaltetem Upload-Schalter wird aufgeräumt.');
    }

    private function subscriber(
        bool $enabled,
        ?Connection $connection = null,
        ?FilesystemAdapterInterface $filesystem = null,
    ): WebPAutoConvertSubscriber {
        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): bool => $key === 'RcMediaToolkit.config.webpAutoConvertOnUpload' ? $enabled : true,
        );

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new WebPAutoConvertSubscriber(
            new ModuleRegistry($systemConfig),
            $systemConfig,
            $bus,
            $connection ?? $this->createStub(Connection::class),
            $this->publicFilesystem,
            $filesystem ?? $this->createStub(FilesystemAdapterInterface::class),
            new MediaToolkitLogger(new NullLogger()),
        );
    }

    private function generated(string $path, FilesystemOperator $filesystem): ThumbnailGeneratedEvent
    {
        return new ThumbnailGeneratedEvent('media-id', 'thumb-id', $path, 'image/png', $filesystem, Context::createDefaultContext());
    }

    private function thumbnail(string $id, string $path): MediaThumbnailEntity
    {
        $thumbnail = new MediaThumbnailEntity();
        $thumbnail->setUniqueIdentifier($id);
        $thumbnail->setPath($path);

        return $thumbnail;
    }
}
