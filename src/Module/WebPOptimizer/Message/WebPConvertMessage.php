<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Message;

/**
 * Ein Bild, das umgewandelt werden soll — der Auftrag für den Worker.
 *
 * Er trägt den Pfad, nicht die Bilddaten: Die Nachricht liegt unter Umständen lange in der
 * Warteschlange, und ein paar Kilobyte je Auftrag summieren sich über einen ganzen Bestand.
 * Die Vorgangskennung reist mit, damit die Protokollzeilen des Workers zum auslösenden Lauf
 * zurückführen.
 */
final class WebPConvertMessage
{
    public function __construct(
        public readonly string $sourcePath,
        public readonly ?string $correlationId = null,
    ) {
    }
}
