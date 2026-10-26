<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Report;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report\JsonReportFormatter;

#[CoversClass(JsonReportFormatter::class)]
final class JsonReportFormatterTest extends TestCase
{
    #[Test]
    public function rendersEmptyArrayForNoCandidates(): void
    {
        $formatter = new JsonReportFormatter();
        $result = $formatter->render([]);

        self::assertSame([], json_decode($result, true));
    }

    #[Test]
    public function rendersAllFieldsForSingleCandidate(): void
    {
        $formatter = new JsonReportFormatter();
        $candidate = new OrphanedMedia(
            mediaId: 'abc123',
            path: 'media/x.jpg',
            size: 1024,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            fileName: 'x.jpg',
            sourceType: OrphanedMedia::SOURCE_DB_ORPHAN,
        );

        $decoded = json_decode($formatter->render([$candidate]), true);

        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertSame('abc123', $decoded[0]['mediaId']);
        self::assertSame(1024, $decoded[0]['size']);
        self::assertSame('db_orphan', $decoded[0]['sourceType']);
    }
}
