<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;

#[CoversClass(MediaReferenceSources::class)]
final class MediaReferenceSourcesTest extends TestCase
{
    #[Test]
    public function foreignKeyColumnsComeFromTheSchema(): void
    {
        $sources = new MediaReferenceSources($this->connectionWithForeignKeys([
            ['TABLE_NAME' => 'theme_media', 'COLUMN_NAME' => 'media_id'],
            ['TABLE_NAME' => 'document', 'COLUMN_NAME' => 'document_media_file_id'],
            ['TABLE_NAME' => 'moorl_marker', 'COLUMN_NAME' => 'marker_id'],
        ]));

        self::assertSame(
            [
                ['table' => 'theme_media', 'column' => 'media_id'],
                ['table' => 'document', 'column' => 'document_media_file_id'],
                ['table' => 'moorl_marker', 'column' => 'marker_id'],
            ],
            $sources->foreignKeyColumns(),
        );
    }

    /**
     * Ohne diesen Ausschluss würde jedes Medium über seine eigenen Kindtabellen als „verwendet"
     * gelten — der Garbage Collector fände dann nie eine Waise.
     */
    #[Test]
    public function mediasOwnChildTablesDoNotCountAsUsage(): void
    {
        $sources = new MediaReferenceSources($this->connectionWithForeignKeys([
            ['TABLE_NAME' => 'media_thumbnail', 'COLUMN_NAME' => 'media_id'],
            ['TABLE_NAME' => 'media_translation', 'COLUMN_NAME' => 'media_id'],
            ['TABLE_NAME' => 'media_tag', 'COLUMN_NAME' => 'media_id'],
            ['TABLE_NAME' => 'product_media', 'COLUMN_NAME' => 'media_id'],
        ]));

        self::assertSame([['table' => 'product_media', 'column' => 'media_id']], $sources->foreignKeyColumns());
    }

    #[Test]
    public function schemaIsQueriedOnlyOncePerRun(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shop');
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([['TABLE_NAME' => 'category', 'COLUMN_NAME' => 'media_id']]);

        $sources = new MediaReferenceSources($connection);

        self::assertSame($sources->foreignKeyColumns(), $sources->foreignKeyColumns());
    }

    #[Test]
    public function textColumnsAreFilteredToExistingColumns(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shop');
        $connection->method('fetchAllAssociative')->willReturn([
            ['TABLE_NAME' => 'cms_slot_translation', 'COLUMN_NAME' => 'config'],
            ['TABLE_NAME' => 'theme', 'COLUMN_NAME' => 'base_config'],
        ]);

        self::assertSame(
            [
                ['table' => 'cms_slot_translation', 'column' => 'config'],
                ['table' => 'theme', 'column' => 'base_config'],
            ],
            (new MediaReferenceSources($connection))->textColumns(),
        );
    }

    #[Test]
    public function customFieldsTablesComeFromTheSchema(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shop');
        $connection->method('fetchFirstColumn')->willReturn(['order_line_item', 'product']);

        self::assertSame(['order_line_item', 'product'], (new MediaReferenceSources($connection))->customFieldsTables());
    }

    #[Test]
    public function withoutDatabaseThereAreNoSources(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn(null);
        $connection->expects(self::never())->method('fetchAllAssociative');

        $sources = new MediaReferenceSources($connection);

        self::assertSame([], $sources->foreignKeyColumns());
        self::assertSame([], $sources->textColumns());
        self::assertSame([], $sources->customFieldsTables());
    }

    /**
     * @param list<array{TABLE_NAME: string, COLUMN_NAME: string}> $rows
     *
     * @return Connection&MockObject
     */
    private function connectionWithForeignKeys(array $rows): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shop');
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return $connection;
    }
}
