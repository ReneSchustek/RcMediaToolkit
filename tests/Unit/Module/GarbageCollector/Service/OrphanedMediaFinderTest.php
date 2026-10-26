<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Module\GarbageCollector\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\ExclusionMatcher;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\MediaUsageIndexer;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\OrphanedMediaFinder;
use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Service\UsageMapFactory;
use Ruhrcoder\RcMediaToolkit\Service\MediaReferenceSources;
use Ruhrcoder\RcMediaToolkit\Service\MediaToolkitLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Die Stelle, an der entschieden wird, welches Medium als verwaist gilt.
 *
 * Am Ende dieser Kette steht das Löschen. **Ein falsches Ja hier ist ein Datenverlust**, und zwar
 * einer, der erst Wochen später auffällt — wenn jemand eine alte Seite aufruft und dort eine
 * Lücke findet. Deshalb liegen vor dem Fund drei Filter, und alle drei sind Schutzfilter: die
 * Merkliste der benutzten Medien, die Ausschlussmuster des Betreibers und die Schonfrist für
 * frisch Hochgeladenes.
 *
 * Die Schonfrist ist der unauffälligste und zugleich wichtigste. Ein Bild, das gerade
 * hochgeladen und noch nicht eingebaut wurde, sieht für jede Prüfung wie eine Waise aus.
 */
#[CoversClass(OrphanedMediaFinder::class)]
final class OrphanedMediaFinderTest extends TestCase
{
    /**
     * Was: Ein altes, nirgends benutztes Medium.
     * Warum: Der Zweck der Klasse. Der Fund trägt Pfad, Größe und Namen — ohne sie ließe sich im
     *        Bericht nicht entscheiden, ob das Löschen zu verantworten ist.
     */
    #[Test]
    public function anOldUnusedMediumIsFound(): void
    {
        $finder = $this->finder([
            $this->mediaRow('aa', 'altes-bild', 'jpg', size: 4096, createdAt: '2020-01-01 10:00:00'),
        ]);

        $orphans = iterator_to_array($finder->findOrphans(), false);

        self::assertCount(1, $orphans);
        self::assertSame('aa', $orphans[0]->mediaId);
        self::assertSame('media/altes-bild.jpg', $orphans[0]->path);
        self::assertSame('altes-bild.jpg', $orphans[0]->fileName);
        self::assertSame(4096, $orphans[0]->size);
        self::assertSame(OrphanedMedia::SOURCE_DB_ORPHAN, $orphans[0]->sourceType);
    }

    /**
     * Was: Ein frisch hochgeladenes Medium.
     * Warum: **Die Schonfrist.** Zwischen dem Hochladen und dem Einbauen liegen oft Tage; in
     *        dieser Zeit ist das Bild von einer echten Waise nicht zu unterscheiden. Ohne die
     *        Frist löschte der erste nächtliche Lauf die Arbeit des Vortags.
     */
    #[Test]
    public function aFreshlyUploadedMediumIsProtectedByTheGracePeriod(): void
    {
        $finder = $this->finder([
            $this->mediaRow('aa', 'gestern-hochgeladen', 'jpg', createdAt: 'gestern'),
        ]);

        self::assertSame([], iterator_to_array($finder->findOrphans(), false));
    }

    /**
     * Die Gegenprobe zur Schonfrist: Wird sie auf null gesetzt, greift sie nicht mehr. So lässt
     * sich ein Bestand bewusst sofort aufräumen — und der vorige Fall beweist nicht bloß, dass
     * nie etwas gefunden wird.
     */
    #[Test]
    public function aGracePeriodOfZeroLetsRecentMediaThrough(): void
    {
        $finder = $this->finder(
            [$this->mediaRow('aa', 'gestern-hochgeladen', 'jpg', createdAt: 'gestern')],
            graceDays: 0,
        );

        self::assertCount(1, iterator_to_array($finder->findOrphans(), false));
    }

    /**
     * Was: Eine unsinnige Schonfrist.
     * Warum: Eine negative Angabe im Backend führte sonst zu einem Stichtag in der Zukunft —
     *        und damit gälte **der gesamte Medienbestand** als verwaist. Der Rückfall auf den
     *        Vorgabewert ist an dieser Stelle kein Schönheitsfehler, sondern ein Schutz.
     */
    #[Test]
    public function anImpossibleGracePeriodFallsBackToTheDefault(): void
    {
        $finder = $this->finder(
            [$this->mediaRow('aa', 'gestern-hochgeladen', 'jpg', createdAt: 'gestern')],
            graceDays: -10,
        );

        self::assertSame([], iterator_to_array($finder->findOrphans(), false));
    }

    /**
     * Was: Ein Name, der einem Ausschlussmuster entspricht.
     * Warum: Der Betreiber kennt Bestände, die kein Verweis erreicht und die trotzdem gebraucht
     *        werden — Vorlagen, Musterbilder, Ausfuhren. Das Muster ist seine einzige Möglichkeit,
     *        sie zu schützen.
     */
    #[Test]
    public function anExcludedNameIsNeverReported(): void
    {
        $finder = $this->finder(
            [
                $this->mediaRow('aa', 'logo-vorlage', 'png', createdAt: '2020-01-01 10:00:00'),
                $this->mediaRow('bb', 'altes-bild', 'jpg', createdAt: '2020-01-01 10:00:00'),
            ],
            exclusionPatterns: 'logo-',
        );

        $orphans = iterator_to_array($finder->findOrphans(), false);

        self::assertCount(1, $orphans);
        self::assertSame('bb', $orphans[0]->mediaId);
    }

    /**
     * Was: Ein benutztes Medium.
     * Warum: Der Hauptfilter. Die Merkliste kommt aus dem Verweisverzeichnis; steht die Kennung
     *        darin, ist das Medium eingebaut und damit tabu — unabhängig von Alter und Namen.
     */
    #[Test]
    public function aReferencedMediumIsSkipped(): void
    {
        $finder = $this->finder(
            [$this->mediaRow('aabbccddeeff00112233445566778899', 'eingebaut', 'jpg', createdAt: '2020-01-01 10:00:00')],
            referencedIds: ['aabbccddeeff00112233445566778899'],
        );

        self::assertSame([], iterator_to_array($finder->findOrphans(), false));
    }

    /**
     * Was: Die Obergrenze.
     * Warum: Der Bericht wird meist mit einer Grenze aufgerufen, damit ein erster Blick nicht
     *        über den ganzen Bestand läuft. Griffe sie nicht, liefe der Aufruf durch und die
     *        Grenze wäre eine Zusage, auf die kein Verlass ist.
     */
    #[Test]
    public function theLimitStopsTheSearch(): void
    {
        $rows = [];
        for ($i = 0; $i < 10; ++$i) {
            $rows[] = $this->mediaRow(sprintf('%02d', $i), 'bild' . $i, 'jpg', createdAt: '2020-01-01 10:00:00');
        }

        $finder = $this->finder($rows);

        self::assertCount(3, iterator_to_array($finder->findOrphans(limit: 3), false));
    }

    /**
     * Eine Blockgröße unter eins wird abgelehnt. Ohne die Grenze läse der Lauf nichts, stellte
     * fest, dass nichts fehlt, und begänne von vorn.
     */
    #[Test]
    public function anImpossibleBatchSizeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($this->finder([])->findOrphans(batchSize: 0), false);
    }

    /**
     * Was: Ein unbrauchbares Anlagedatum.
     * Warum: Es darf den Lauf nicht anhalten — aber es darf auch nicht dazu führen, dass das
     *        Medium durch die Schonfrist fällt. Ausgewichen wird deshalb auf den frühestmöglichen
     *        Zeitpunkt: Das Medium gilt als alt und wird gemeldet, statt still übergangen zu
     *        werden.
     */
    #[Test]
    public function anUnreadableCreationDateDoesNotBreakTheRun(): void
    {
        $finder = $this->finder([
            $this->mediaRow('aa', 'ohne-datum', 'jpg', createdAt: 'kein Datum'),
        ]);

        $orphans = iterator_to_array($finder->findOrphans(), false);

        self::assertCount(1, $orphans);
        self::assertSame('1970-01-01', $orphans[0]->createdAt->format('Y-m-d'));
    }

    /**
     * Was: Das Blättern über den Bestand.
     * Warum: Gelesen wird mit einem Zeiger auf die zuletzt gesehene Kennung, nicht mit einem
     *        Versatz — bei Millionen Medien überspringt die Datenbank sonst auf jeder Seite
     *        wieder alles Vorherige. Der Fall hält fest, dass ein voller Block den nächsten nach
     *        sich zieht und dabei die letzte Kennung mitgibt.
     */
    #[Test]
    public function pagingUsesTheLastSeenIdAsCursor(): void
    {
        $first = [];
        for ($i = 0; $i < 2; ++$i) {
            $first[] = $this->mediaRow(sprintf('%02d', $i), 'bild' . $i, 'jpg', createdAt: '2020-01-01 10:00:00');
        }

        $seenCursors = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($first, &$seenCursors): array {
                if (!str_contains($sql, 'file_size')) {
                    return [];
                }
                $seenCursors[] = $params['lastId'] ?? null;

                return ($params['lastId'] ?? null) === null ? $first : [];
            },
        );

        $finder = $this->finderWithConnection($connection);
        iterator_to_array($finder->findOrphans(batchSize: 2), false);

        self::assertSame([null, '01'], $seenCursors, 'Die zweite Seite setzt hinter der letzten Kennung an.');
    }

    /**
     * Was: Der Weg über die Ablagetabelle.
     * Warum: Er ist für Bestände gedacht, bei denen die Merkliste nicht mehr in den
     *        Arbeitsspeicher passt — dort wird der Abgleich der Datenbank überlassen. **Beide
     *        Wege müssen dieselben Waisen finden**, sonst hinge es an einem Häkchen im Backend,
     *        welche Medien gelöscht werden.
     */
    #[Test]
    public function theTableBackedPathFindsTheSameOrphans(): void
    {
        $zeilen = [$this->mediaRow('aa', 'altes-bild', 'jpg', createdAt: '2020-01-01 10:00:00')];

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shopware');
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($zeilen): array {
                if (str_contains($sql, 'INFORMATION_SCHEMA')) {
                    return [];
                }
                if (str_contains($sql, 'LEFT JOIN')) {
                    return ($params['lastId'] ?? null) === null ? $zeilen : [];
                }

                return [];
            },
        );

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'RcMediaToolkit.config.gcUseTempTableUsageMap' => true,
                'RcMediaToolkit.config.gcGracePeriodDays' => 30,
                default => null,
            },
        );

        $finder = new OrphanedMediaFinder(
            $connection,
            new MediaUsageIndexer(
                $connection,
                new MediaToolkitLogger(new NullLogger()),
                new MediaReferenceSources($connection),
            ),
            new ExclusionMatcher($config),
            $config,
            new UsageMapFactory($connection, $config),
        );

        $waisen = iterator_to_array($finder->findOrphans(), false);

        self::assertCount(1, $waisen);
        self::assertSame('aa', $waisen[0]->mediaId);
        self::assertSame('media/altes-bild.jpg', $waisen[0]->path);
    }

    /**
     * Auch auf diesem Weg gelten die Ausschlussmuster. Sie liegen nicht in der Datenbank, sondern
     * in den Einstellungen — griffen sie hier nicht, verlöre der Betreiber seinen Schutz genau
     * bei dem Bestand, der am größten ist.
     */
    #[Test]
    public function theTableBackedPathStillHonoursTheExclusions(): void
    {
        $zeilen = [$this->mediaRow('aa', 'logo-vorlage', 'png', createdAt: '2020-01-01 10:00:00')];

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shopware');
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`',
        );
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($zeilen): array {
                if (str_contains($sql, 'LEFT JOIN')) {
                    return ($params['lastId'] ?? null) === null ? $zeilen : [];
                }

                return [];
            },
        );

        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'RcMediaToolkit.config.gcUseTempTableUsageMap' => true,
                'RcMediaToolkit.config.gcExclusionPatterns' => 'logo-',
                'RcMediaToolkit.config.gcGracePeriodDays' => 30,
                default => null,
            },
        );

        $finder = new OrphanedMediaFinder(
            $connection,
            new MediaUsageIndexer(
                $connection,
                new MediaToolkitLogger(new NullLogger()),
                new MediaReferenceSources($connection),
            ),
            new ExclusionMatcher($config),
            $config,
            new UsageMapFactory($connection, $config),
        );

        self::assertSame([], iterator_to_array($finder->findOrphans(), false));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function mediaRow(
        string $id,
        string $name,
        string $extension,
        int $size = 1024,
        string $createdAt = '2020-01-01 10:00:00',
        array $overrides = [],
    ): array {
        if ($createdAt === 'gestern') {
            $createdAt = (new DateTimeImmutable('-1 day', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }

        return array_merge([
            'id' => $id,
            'file_name' => $name,
            'file_extension' => $extension,
            'file_size' => $size,
            'created_at' => $createdAt,
            'media_folder_id' => null,
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $mediaRows
     * @param list<string>               $referencedIds
     */
    private function finder(
        array $mediaRows,
        ?int $graceDays = 30,
        ?string $exclusionPatterns = null,
        array $referencedIds = [],
    ): OrphanedMediaFinder {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('shopware');
        $connection->method('fetchAllAssociative')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use ($mediaRows, $referencedIds): array {
                // Das Verweisverzeichnis fragt zuerst die Datenbank, welche Spalten überhaupt auf
                // Medien zeigen. Eine einzige genügt hier — geprüft wird, was danach passiert.
                if (str_contains($sql, 'INFORMATION_SCHEMA')) {
                    return [['TABLE_NAME' => 'product_media', 'COLUMN_NAME' => 'media_id']];
                }

                // Der Bestandslauf ist an den Spalten zu erkennen; alles andere sind die Abfragen
                // des Verweisverzeichnisses, das hier nur die benutzten Kennungen liefern soll.
                if (str_contains($sql, 'file_size')) {
                    return ($params['lastId'] ?? null) === null ? $mediaRows : [];
                }

                // Das Verweisverzeichnis liest Fremdschlüssel als Hexdarstellung; alles andere
                // sind Textspalten, aus denen hier nichts kommen soll.
                if ($referencedIds === [] || !str_contains($sql, 'LOWER(HEX(')) {
                    return [];
                }

                return array_map(static fn (string $id): array => ['id' => $id], $referencedIds);
            },
        );

        return $this->finderWithConnection($connection, $graceDays, $exclusionPatterns);
    }

    private function finderWithConnection(
        Connection $connection,
        ?int $graceDays = 30,
        ?string $exclusionPatterns = null,
    ): OrphanedMediaFinder {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'RcMediaToolkit.config.gcGracePeriodDays' => $graceDays,
                'RcMediaToolkit.config.gcExclusionPatterns' => $exclusionPatterns,
                default => null,
            },
        );

        return new OrphanedMediaFinder(
            $connection,
            new MediaUsageIndexer(
                $connection,
                new MediaToolkitLogger(new NullLogger()),
                new MediaReferenceSources($connection),
            ),
            new ExclusionMatcher($config),
            $config,
            new UsageMapFactory($connection, $config),
        );
    }
}
