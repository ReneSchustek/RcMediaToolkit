<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Service;

use Generator;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;

/**
 * Dünner Wrapper über media.repository — entkoppelt Sub-Module von der DAL-Konkretion
 * und vereinfacht Tests durch eine austauschbare Fassade.
 */
interface MediaRepositoryWrapperInterface
{
    public function findById(string $mediaId, Context $context): ?MediaEntity;

    /**
     * @param list<string> $mediaIds
     */
    public function findByIds(array $mediaIds, Context $context): MediaCollection;

    /**
     * Cursor-basierte Iteration über alle Medien — Speicher-schonend für große Bestände.
     *
     * @return Generator<int, MediaEntity>
     */
    public function iterateAll(Context $context, int $batchSize = 100): Generator;

    public function countAll(Context $context): int;
}
