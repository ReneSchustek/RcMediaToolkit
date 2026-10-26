<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMediaToolkit;

use Shopware\Core\Framework\Plugin;

/**
 * Die Plugin-Klasse.
 *
 * Bewusst leer: Die Module hängen ausschließlich an der Dienst-Verdrahtung und an ihren
 * Schaltern, nicht an Lebenszyklus-Eingriffen. Tabellen legen die Migrationen an, und die
 * bleiben bei der Deinstallation bestehen — Befunde und Prüfspuren sind Daten des Betreibers,
 * kein Zubehör des Plugins.
 */
final class RcMediaToolkit extends Plugin
{
}
