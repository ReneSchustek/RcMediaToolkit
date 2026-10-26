<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit\Module\WebPOptimizer\Service;

/**
 * Bildet aus der Adresse eines Originalbildes die Adresse seiner umgewandelten Fassung.
 *
 * Klingt nach „`.webp` anhängen" und ist genau deshalb zweimal schiefgegangen: Shopware
 * hängt an jede Medien-Adresse einen Zeitstempel (`MediaUrlGenerator`, sobald das Medium ein
 * `updated_at` hat — im Bestand sind das alle). Wer stumpf anhängt, erhält
 * `bild.jpg?ts=1784098309.webp`; die Endung sitzt dann im Abfrageteil, der Pfad zeigt
 * unverändert auf das Original, und der Server antwortet mit 200 und dem JPEG. Ein Fehler,
 * den man nicht sieht — das Bild erscheint ja.
 *
 * Deshalb steht die Regel an genau einer Stelle: Die Endung gehört ans Ende des **Pfades**,
 * alles ab dem ersten `?` oder `#` bleibt unangetastet dahinter.
 */
final class WebPUrl
{
    /**
     * Reine Funktion — kein Zustand, kein Dateizugriff. Ob es die Datei gibt, entscheidet der
     * Aufrufer; diese Stelle beantwortet nur, wie ihre Adresse lautet.
     */
    public static function variantOf(string $url): string
    {
        $pathLength = strcspn($url, '?#');

        return substr($url, 0, $pathLength) . '.webp' . substr($url, $pathLength);
    }
}
