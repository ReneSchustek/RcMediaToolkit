<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Report;

use Ruhrcoder\RcMediaToolkit\Module\GarbageCollector\Dto\OrphanedMedia;

/**
 * Der Vertrag für eine Ausgabeform des Waisenberichts.
 *
 * `render()` nimmt ein `iterable`, keine Liste: Der Bericht entsteht während des Laufs,
 * und die Kandidatenmenge kann fünfstellig werden. Wer hier ein Array verlangt, zwingt den
 * Aufrufer, erst alles zu sammeln.
 */
interface ReportFormatterInterface
{
    public function format(): string;

    /**
     * @param iterable<OrphanedMedia> $candidates
     */
    public function render(iterable $candidates): string;
}
