<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Ein Bild, das umgewandelt werden soll — der Auftrag für den Worker.
 *
 * Er trägt den Pfad, nicht die Bilddaten: Die Nachricht liegt unter Umständen lange in der
 * Warteschlange, und ein paar Kilobyte je Auftrag summieren sich über einen ganzen Bestand.
 * Die Vorgangskennung reist mit, damit die Protokollzeilen des Workers zum auslösenden Lauf
 * zurückführen.
 *
 * Asynchron gekennzeichnet, damit der Kern ihn in die Warteschlange legt. Ohne die Kennzeichnung
 * führt der Bus ihn sofort aus, und ein Upload in der Verwaltung wartet auf jede Umwandlung.
 */
final class WebPConvertMessage implements AsyncMessageInterface
{
    public function __construct(
        public readonly string $sourcePath,
        public readonly ?string $correlationId = null,
    ) {
    }
}
