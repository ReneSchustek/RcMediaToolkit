<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Service;

use Generator;
use InvalidArgumentException;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Der Zugang zum Medienbestand über Shopwares eigene Abstraktionsschicht.
 *
 * Bewusst eine eigene Hülle statt der Ablage im Programmtext verteilter Suchkriterien: Sie
 * liegt hinter einer Schnittstelle und ist damit in Tests ersetzbar, ohne dass ein Kern
 * hochgefahren werden muss.
 *
 * Die Massenzugriffe geben Generatoren zurück. Der Medienbestand ist die eine Tabelle
 * dieses Plugins, die in echten Shops sechsstellig wird — wer sie in ein Array lädt,
 * bringt jeden Lauf genau dort um, wo er gebraucht wird.
 */
final class MediaRepositoryWrapper implements MediaRepositoryWrapperInterface
{
    /**
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    public function __construct(
        private readonly EntityRepository $mediaRepository,
    ) {
    }

    public function findById(string $mediaId, Context $context): ?MediaEntity
    {
        // Die Kennung gehört in den Criteria-Aufbau, nicht in einen Filter: Shopware löst sie
        // dann über den Primärschlüssel auf, statt eine Bedingung über die Tabelle zu legen.
        $criteria = new Criteria([$mediaId]);
        $criteria->setLimit(1);

        $entity = $this->mediaRepository->search($criteria, $context)->getEntities()->first();

        return $entity instanceof MediaEntity ? $entity : null;
    }

    public function findByIds(array $mediaIds, Context $context): MediaCollection
    {
        if ($mediaIds === []) {
            /** @var MediaCollection $empty */
            $empty = new MediaCollection();
            return $empty;
        }

        $criteria = new Criteria($mediaIds);

        /** @var MediaCollection $collection */
        $collection = $this->mediaRepository->search($criteria, $context)->getEntities();

        return $collection;
    }

    public function iterateAll(Context $context, int $batchSize = 100): Generator
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('batchSize muss >= 1 sein');
        }

        $offset = 0;
        do {
            $criteria = new Criteria();
            $criteria->setLimit($batchSize);
            $criteria->setOffset($offset);
            $criteria->addSorting(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting('id'));

            $result = $this->mediaRepository->search($criteria, $context);
            $count = $result->count();

            // Die Sammlung führt laut Typangabe nur Mediendateien — eine Prüfung darauf kann
            // nicht fehlschlagen.
            foreach ($result->getEntities() as $entity) {
                yield $entity;
            }

            $offset += $batchSize;
        } while ($count === $batchSize);
    }

    public function countAll(Context $context): int
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);

        return $this->mediaRepository->search($criteria, $context)->getTotal();
    }
}
