<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Tests\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcMediaToolkit\Service\MediaRepositoryWrapper;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Der Zugang zum Medienbestand.
 *
 * Die Hülle hat zwei Aufgaben, und beide sind Vorsichtsmaßnahmen. Die erste: **Der Medienbestand
 * ist die eine Tabelle dieses Plugins, die in echten Shops sechsstellig wird.** Wer sie in ein
 * Array lädt, bringt jeden Lauf genau dort um, wo er gebraucht wird — deshalb geben die
 * Massenzugriffe Generatoren zurück und lesen in Blöcken.
 *
 * Die zweite: Eine leere Anfrage darf nicht zur Anfrage über alles werden. Ein `IN ()` ohne
 * Werte ist der klassische Weg, aus „nichts gesucht" ein „alles gefunden" zu machen.
 */
#[CoversClass(MediaRepositoryWrapper::class)]
final class MediaRepositoryWrapperTest extends TestCase
{
    /**
     * Was: Eine leere Kennungsliste.
     * Warum: Sie fragt **gar nicht** nach. Der Fall entsteht immer dann, wenn ein Aufrufer nichts
     *        gefunden hat; ginge die Anfrage trotzdem hinaus, hinge das Ergebnis an der Frage,
     *        wie die Abstraktionsschicht eine leere Bedingung auslegt — und das ist keine Frage,
     *        auf die man sich verlassen möchte.
     */
    #[Test]
    public function anEmptyIdListNeverReachesTheDatabase(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('search');

        $result = (new MediaRepositoryWrapper($repository))->findByIds([], Context::createDefaultContext());

        self::assertCount(0, $result);
    }

    /**
     * Was: Eine Kennung, zu der es nichts gibt.
     * Warum: Dann kommt `null` heraus und keine Ausnahme. Die Kennungen stammen aus Befunden über
     *        kaputte Verweise — dass das gesuchte Medium fehlt, ist dort der Regelfall und kein
     *        Ausnahmezustand.
     */
    #[Test]
    public function aMissingMediumIsReportedAsNothingFound(): void
    {
        $wrapper = new MediaRepositoryWrapper($this->repositoryReturning([new MediaCollection()]));

        self::assertNull($wrapper->findById('aabbccddeeff00112233445566778899', Context::createDefaultContext()));
    }

    /**
     * Die Gegenprobe: Gibt es das Medium, kommt es heraus.
     */
    #[Test]
    public function anExistingMediumIsReturned(): void
    {
        $entity = $this->media('aabbccddeeff00112233445566778899');
        $wrapper = new MediaRepositoryWrapper($this->repositoryReturning([new MediaCollection([$entity])]));

        self::assertSame($entity, $wrapper->findById('aabbccddeeff00112233445566778899', Context::createDefaultContext()));
    }

    /**
     * Was: Der Durchlauf über den gesamten Bestand.
     * Warum: **Der Fall, für den es die Hülle gibt.** Gelesen wird in Blöcken; ein voller Block
     *        zieht den nächsten nach sich, ein angebrochener beendet den Durchlauf. Bräche er zu
     *        früh ab, verarbeitete jeder Lauf nur den ersten Block — und meldete trotzdem
     *        „fertig".
     */
    #[Test]
    public function theFullPassReadsBlockByBlockUntilTheStockEnds(): void
    {
        $repository = $this->repositoryReturning([
            new MediaCollection([$this->media('a'), $this->media('b')]),
            new MediaCollection([$this->media('c')]),
        ]);

        $ids = [];
        foreach ((new MediaRepositoryWrapper($repository))->iterateAll(Context::createDefaultContext(), 2) as $media) {
            $ids[] = $media->getId();
        }

        self::assertSame(['a', 'b', 'c'], $ids);
    }

    /**
     * Ein leerer Bestand ergibt einen Durchlauf ohne einen einzigen Schritt — und keine
     * Endlosschleife.
     */
    #[Test]
    public function anEmptyStockEndsThePassImmediately(): void
    {
        $repository = $this->repositoryReturning([new MediaCollection()]);

        $count = 0;
        foreach ((new MediaRepositoryWrapper($repository))->iterateAll(Context::createDefaultContext(), 50) as $ignored) {
            ++$count;
        }

        self::assertSame(0, $count);
    }

    /**
     * Was: Eine Blockgröße von null oder darunter.
     * Warum: Sie wird abgelehnt. Ohne die Grenze liefe der Durchlauf endlos: Er liest nichts,
     *        stellt fest, dass nichts fehlt, und beginnt von vorn — ein Prozess, der Last erzeugt
     *        und nie fertig wird.
     */
    #[Test]
    public function anImpossibleBlockSizeIsRefused(): void
    {
        $wrapper = new MediaRepositoryWrapper($this->createMock(EntityRepository::class));

        $this->expectException(InvalidArgumentException::class);

        iterator_to_array($wrapper->iterateAll(Context::createDefaultContext(), 0), false);
    }

    /**
     * Die Gesamtzahl kommt aus der Zählung der Abstraktionsschicht, nicht aus der Länge einer
     * geladenen Liste — sonst müsste zum Zählen der ganze Bestand in den Arbeitsspeicher.
     */
    #[Test]
    public function theTotalIsCountedWithoutLoadingTheStock(): void
    {
        $repository = $this->repositoryReturning([new MediaCollection([$this->media('a')])], total: 12345);

        self::assertSame(12345, (new MediaRepositoryWrapper($repository))->countAll(Context::createDefaultContext()));
    }

    private function media(string $id): MediaEntity
    {
        $entity = new MediaEntity();
        $entity->setId($id);
        $entity->setUniqueIdentifier($id);

        return $entity;
    }

    /**
     * @param list<MediaCollection> $pages
     *
     * @return EntityRepository<MediaCollection>&\PHPUnit\Framework\MockObject\MockObject
     */
    private function repositoryReturning(array $pages, ?int $total = null): EntityRepository
    {
        $context = Context::createDefaultContext();
        $call = 0;

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static function (Criteria $criteria) use ($pages, &$call, $context, $total): EntitySearchResult {
                $collection = $pages[$call] ?? new MediaCollection();
                ++$call;

                return new EntitySearchResult(
                    'media',
                    $total ?? $collection->count(),
                    $collection,
                    null,
                    $criteria,
                    $context,
                );
            },
        );

        return $repository;
    }
}
