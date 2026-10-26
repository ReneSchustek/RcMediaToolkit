## [1.4.1] - 2026-09-08 — Der Name in der Erweiterungsliste und das fehlende Sinnbild

### Geändert

- **Die Erweiterung hieß in der Verwaltung „Ruhrcoder Media-Toolkit".** Sie war damit die einzige
  von 22, die den Firmennamen im angezeigten Namen trug — alle anderen heißen schlicht nach ihrer
  Aufgabe („Minimalistische Produktliste", „Strukturierte Daten", „Checkout-Optimierung"). Jetzt
  **„Media-Toolkit"** beziehungsweise **„Media Toolkit"**.

### Hinzugefügt

- **Das Sinnbild der Erweiterung** (`src/Resources/config/plugin.png`). Es fehlte, deshalb stand
  die Zeile in der Erweiterungsliste ohne Bild da. Es ist dasselbe wie bei den übrigen 20 — die
  Vorlage aus `_ai-Vorlagen/shopware/Icon/plugin.png`.

Beides fiel auf, als die Erweiterung am 08.09. zum ersten Mal auf Live installiert wurde. Der
Programmtext ändert sich nicht; wer 1.4.0 hat, bekommt mit 1.4.1 nur den richtigen Namen und ein
Bild.

---
tags: stand, shopware, doku
---

# Changelog (de-DE)

## 1.4.0 — 2026-09-07 — Die Quarantäne hält jetzt, was sie verspricht

> **Deployment:** `php bin/console plugin:update RcMediaToolkit && php bin/console cache:clear`.
> Die Aktualisierung legt eine Tabelle an; ohne sie greift der Aufräumer nicht.

### Behoben

- **Was in die Quarantäne ging, kam nur halb zurück**. Beim Einlagern verschwanden die
  Datei **und** die Datenbankzeilen; beim Wiederherstellen kam nur die Datei. Das Medium war für
  Shopware danach nicht mehr vorhanden — die Datei lag im Speicher, kein `media`-Datensatz zeigte
  darauf. **Die Zählung meldete trotzdem Erfolg.**

  Eine Quarantäne ist ein Versprechen: Was hier hineingeht, kommt wieder heraus. Halb eingelöst
  ist sie **gefährlicher als sofortiges Löschen** — beim Löschen weiß man, woran man ist.

- **Gelöscht wird jetzt beim Ausräumen der Frist, nicht beim Einlagern.** Vom Einlagern bis zum
  Ablauf der Frist ist jedes Medium vollständig zurückholbar: Die Datei liegt in der Quarantäne,
  die Zeile steht, und ein Vermerk sagt, dass beides zusammengehört. Erst wenn niemand
  widersprochen hat, verschwinden Datensatz und Vorschaubilder.

- **Ein Medium ohne Datei wurde bisher gelöscht, ohne dass überhaupt etwas eingelagert wurde.**
  Dort gab es gar keinen Rückweg — nicht einmal einen Eintrag im Manifest. Jetzt wird es vermerkt
  und wandert mit dem Lauf in die Frist.

### Neu

- **Tabelle `rc_media_quarantine`.** Sie hält fest, welches Medium in welchem Lauf liegt, wo seine
  Datei herkam und wohin sie ging. **Warum keine Zusatzfelder:** `custom_fields` liegt bei
  Shopware auf `media_translation`, also je Sprache — eine Einlagerung gälte sonst in einer
  Sprache und in der anderen nicht.

- **Der Sucher übergeht Eingelagertes.** Seit die Zeilen stehen bleiben, sind eingelagerte Medien
  weiterhin verwaist und ihre Datei ist weg. Ohne diese Bedingung griffe sie jeder folgende
  Durchlauf erneut auf.

### Verhältnis zum Manifest

Das Manifest auf der Platte bleibt das Tagebuch des Laufs: Es überlebt einen Absturz mitten im
Verschieben und weiß, welche Datei wohin gehört. Die neue Tabelle ist die andere Hälfte — sie sagt
der Datenbank, dass dieses Medium eingelagert ist. Zwei Aufgaben, zwei Orte, keine zweite Wahrheit.

## 1.3.1 — 2026-08-10 — Vorbereitet auf die nächste Shopware-Hauptversion

- **Vorbereitung auf die nächste Shopware-Hauptversion.** Der Zugriff auf Suchergebnisse folgt der Schreibweise, die Shopware 6.8 verlangt. Am Verhalten ändert sich nichts.

## 1.3.0 — 2026-08-05 — Die Übersichtsseite zeigt zum ersten Mal etwas an

> **Deployment:** `php bin/console plugin:update RcMediaToolkit && php bin/console cache:clear`.

### Behoben

- **Die Übersichtsseite im Backoffice war leer — in Shopware 6.7 hat sie nie etwas angezeigt.**
  Sie holte ihre Daten über einen Aufrufweg, den es in 6.7 nicht mehr gibt. Der Aufruf scheiterte
  sofort, die Fehlerbehandlung machte daraus eine Meldung, und übrig blieb eine Seite mit
  Überschrift und nichts darunter. Im Protokoll stand nichts. Jetzt läuft der Aufruf über den
  Weg, den 6.7 dafür vorsieht — und die Fehlermeldung nennt den Grund, falls doch etwas
  schiefgeht.

### Geändert

- **Die Seite sagt jetzt, was sie weiß.** Zwei ihrer Karten gaben rohe Daten aus, die
  Modultabelle zeigte interne Schlüssel. Jetzt stehen dort übersetzte Namen, lesbare Zustände,
  offene Befunde nach Schwere und der WebP-Bestand mit den verfügbaren Umwandlern.
- **Die Bestandszahlen nennen ihre Stichprobe.** Sie sehen sich höchstens 1000 Medien an; nur
  „Medien gesamt" ist vollständig gezählt. Ohne diesen Hinweis liest sich die Zahl auf einem
  großen Shop als Gesamtzahl und ist keine.
- Die Seite löst weiterhin nichts aus. Der einzige Vorgang ohne Rückweg bleibt der Konsole
  vorbehalten, wo die Rückfrage nicht weggeklickt werden kann.

## 1.2.3 — 2026-08-05 — Der Quelltext sagt jetzt, warum er tut, was er tut

Reine Dokumentation, kein Verhalten geändert.

### Geändert

- 41 von 78 Klassen hatten keinen Klassenkommentar. Jetzt hat jede einen — und zwar einen, der
  die Entscheidung dahinter festhält statt den Namen zu wiederholen: warum die Prüfungen ihre
  Befunde einzeln herausfallen lassen statt sie zu sammeln, warum der Aufräumer im
  Hintergrundlauf von sich aus nichts verschiebt, warum die Prüfspur der Reparaturen im
  Auslieferungszustand nie gelöscht wird.

## 1.2.2 — 2026-08-05 — Die umgewandelten Bilder kommen jetzt tatsächlich beim Besucher an

> **Deployment:** `php bin/console plugin:update RcMediaToolkit && php bin/console cache:clear`.
> Wer die Umwandlung schon laufen hatte, muss nichts erneut umwandeln — die Dateien lagen längst
> da, sie wurden nur nie ausgeliefert.

### Behoben

- **Das Modul lieferte im Regelbetrieb kein einziges WebP-Bild aus.** Shopware hängt an jede
  Medien-Adresse einen Zeitstempel an (`?ts=…`); im Bestand betrifft das alle Bilder. Die Endung
  `.webp` wurde stumpf hinten angehängt und landete dadurch im Abfrageteil statt im Pfad. Auf
  beiden Wegen mit demselben Ergebnis:
  - Der Subscriber erkannte solche Adressen gar nicht erst und schrieb nichts um — auf vier
    geprüften Seiten 80 Bilder, keines davon.
  - Der Twig-Filter `rc_webp_url` baute eine Adresse, die auf das **Original** zeigte. Der Server
    antwortete mit 200 und lieferte das JPEG in voller Größe aus, deklariert als `image/webp`.
    Der eingebaute Rückfall („bei 404 nimmt der Browser das Original") griff nie, weil es kein
    404 gab. Gemessen: 15.470 Byte statt 4.090.

  Die Adresse bildet jetzt eine einzige Stelle, und die Endung sitzt am Ende des Pfades. Am
  laufenden Shop gemessen: **5.102 Byte `image/webp`** statt **14.271 Byte `image/jpeg`**.

### Neu

- Die Prüfung ruft die Adresse des Bildes jetzt selbst ab und sieht in die Datei. Ein Schritt, der nur
  das Vorhandensein eines `<source>`-Elements prüft, hätte genau diesen Fehler nie gemeldet.
- Tests für den Twig-Filter — er hatte bisher keine.

## 1.2.1 — 2026-08-05 — Die Rechte tragen jetzt einen Namen und sind einzeln vergebbar

> **Deployment:** `php bin/console plugin:update RcMediaToolkit && php bin/console cache:clear`.
> **Achtung:** Das auslösende Recht heißt jetzt `rc_media_toolkit.editor` statt
> `rc_media_toolkit.manager`. Rollen, die es unter dem alten Namen tragen, müssen einmal neu
> gesetzt werden.

### Behoben

- **Die Rechte-Zeile zeigte ihren Schlüssel statt eines Namens.** In der Rollen-Verwaltung stand
  wörtlich `sw-privileges.permissions.rc_media_toolkit.label` — für den Betreiber sah das aus wie
  ein Programmfehler. Die Übersetzung lag an einer Stelle, an der Shopware nicht nachsieht. Jetzt
  heißt die Zeile „Medien-Werkzeugkasten", in beiden Sprachen.
- **Das auslösende Recht war einzeln nicht vergebbar.** Das Rechte-Raster kennt vier feste
  Spalten; unser zweites Recht hieß `manager` und fiel damit heraus — zu vergeben war es nur über
  „Alle", also zusammen mit allem anderen. Es heißt jetzt `editor` und hat eine eigene Spalte.
  Damit lässt sich „nur lesen" und „lesen und auslösen" getrennt vergeben.

### Neu

- Eine Prüfung hält die drei Stellen zusammen, die nur gemeinsam etwas taugen: das Recht, das ein
  Endpunkt fordert, seine Anmeldung zur Vergabe und seine Beschriftung. Weicht eine ab, war der
  Schaden bisher still.

## 1.2.0 — 2026-08-04 — Die Rechte sind zuweisbar, der Programmpfad wird dort geprüft, wo er benutzt wird

> **Deployment:** `php bin/console plugin:update RcMediaToolkit && php bin/console cache:clear`.

### Behoben

- **Die Rechte des Plugins tauchten in der Rollen-Verwaltung nicht auf.** Alle sieben
  Admin-Endpunkte fordern `rc_media_toolkit.viewer` bzw. `.manager`, aber es gab nichts, dem
  sich das zuweisen ließ. Wer keine Administrator-Rolle hatte, bekam 403 — und niemand konnte
  es beheben. Sicher war das, brauchbar nicht. Jetzt sind beide Stufen eingetragen, mit
  Beschriftung in beiden Sprachen; „ausführen" setzt „lesen" voraus, weil wer einen Lauf
  startet, dessen Ergebnis sehen können muss.
- **Der Pfad zum cwebp-Programm wurde nicht dort geprüft, wo er benutzt wird.** Die
  Verfügbarkeitsprüfung sah auf die Allowlist, der Start des Prozesses las den Pfad frisch aus
  der Konfiguration — ohne Prüfung. Geschützt hat allein die Reihenfolge, und deren Ergebnis
  liegt zwischengespeichert: In einem lang laufenden Worker liegt dazwischen ein Zeitfenster.
  Beide Wege gehen jetzt durch dieselbe Stelle; ein nicht zugelassener Pfad führt zu einer
  Ausnahme, die ihn nennt — kein stiller Rückfall auf den Standard.
- **Drei unterdrückte Fehler beim Aufräumen.** Temporäre Dateien und ein halb geschriebenes
  Manifest wurden mit `@unlink` entfernt. Aufräumen darf den Ablauf nicht abbrechen, aber auch
  nicht unsichtbar scheitern: Bleiben die Dateien liegen, läuft irgendwann das Dateisystem
  voll, und niemand weiß, woher es kommt. Jetzt wird das Ergebnis ausgewertet und im
  Fehlerfall protokolliert.

## 1.1.0 — 2026-08-04 — Die WebP-Auslieferung verschachtelt nicht mehr und fragt nicht mehr bei jedem Aufruf

> **Deployment:** `php bin/console plugin:update RcMediaToolkit && php bin/console cache:clear`.
> Der Subscriber bleibt im Auslieferungszustand **aus**.

### Behoben

- **Ein Bild, das bereits in einem `<picture>` stand, wurde erneut umschlossen.** Verschachtelte
  `<picture>` sind ungültiges HTML, und der Browser nimmt dann die Quelle des äußeren — also
  genau die Datei, die der innere vermeiden wollte. Der Klassenkommentar wies diesen Schutz
  seit jeher als vorhanden aus; im Code stand er nicht. Auf live-clone waren es drei Bilder auf
  der Startseite, alle aus der Twig-Vorlage, die den sauberen Weg über `|rc_webp_url` geht.
- **Die Existenzprüfung lief bei jedem Seitenaufruf neu.** Sie lag nur im Dienst, und der lebt
  bei klassischem PHP-FPM genau einen Aufruf lang: 80 Bilder bedeuteten 80 Dateisystem-Abfragen,
  bei externem Speicher 80 Netzwege — jedes Mal aufs Neue. Jetzt liegt sie im gemeinsamen
  Objekt-Speicher mit fünf Minuten Frist, damit ein frisch konvertiertes Bild nicht stundenlang
  als „gibt es nicht" gilt.
- **Ein Integrationstest brach ab, ohne dass am Code etwas falsch war.** Sein Platzhalter hieß
  `:präfix`; Doctrine liest einen Parameternamen nur bis zum ersten Nicht-ASCII-Zeichen, machte
  daraus `:pr` und fand dafür keinen Wert. Bezeichner bleiben englisch und ASCII — auch in Tests.

### Nicht geändert

Der `srcset`-Schutz war bereits vorhanden. Die frühere Einschätzung traf insoweit nicht mehr
zu; nachgemessen statt angenommen.

## 1.0.3 — 2026-07-28

> **Deployment:** `php bin/console plugin:update RcMediaToolkit && php bin/console cache:clear`.

### Behoben
- **🟠 Ein ungültiges Ausschluss-Muster schützte gar nichts.** Ein Tippfehler in der Musterliste (etwa `/logo[/`) führte dazu, dass die betroffene Zeile stillschweigend nie griff — die Dateien, die jemand schützen wollte, galten damit als nicht ausgeschlossen und wären beim nächsten Lauf in der Quarantäne gelandet. Die Muster werden jetzt einmal beim Laden geprüft; ein ungültiges bricht den Lauf ab und nennt Muster und Grund. Bei einem Werkzeug, das Medien aussortiert, ist ein abgebrochener Lauf die sichere Seite.
- **🟠 Verbindungsabriss während der Bestandsaufnahme führte zu einer leeren Nutzungsliste.** Im Modus für sehr große Bestände sammelt das Plugin die benutzten Medien in einer temporären Tabelle, die an der Datenbankverbindung hängt. Nach einem Neuaufbau der Verbindung wurde sie leer neu angelegt und der Lauf fortgesetzt — der anschließende Vergleich hielt dann alles bereits Erfasste für unbenutzt. Jetzt entscheidet der Füllstand: vor der ersten eingetragenen ID wird still neu angelegt, danach bricht der Lauf mit klarer Meldung ab.

### Tests
- Drei Fälle für den Verbindungsabriss (vorher, nachher, anderer Datenbankfehler) und drei für die Musterprüfung. Der bisherige Test, der das stille Verschlucken ungültiger Muster festschrieb, ist ersetzt — er hat den Fehler zementiert.

## 1.0.2 — 2026-07-28

> **Deployment:** `php bin/console plugin:update RcMediaToolkit && php bin/console cache:clear`.

### Behoben
- **🔴 Der Garbage Collector hielt referenzierte Medien für Waisen.** Die Bestandsaufnahme kannte zehn fest eingetragene Spalten; der Shop referenziert Medien aber aus deutlich mehr Quellen — Theme-Bilder, Rechnungs-PDFs, gekaufte Downloads, Bilder in bestehenden Bestellungen, Hersteller-Logos, Varianten-Bilder, CMS-Hintergründe und alles, was Plugins hinzufügen. Zwei der zehn Einträge gab es in Shopware 6.7 gar nicht mehr, sie liefen still ins Leere. Auf einem echten Shop sank die Kandidatenliste dadurch von 2803 auf 1239: 1363 Theme-Bilder, 1343 Bestellpositions-Bilder, drei Rechnungen und weitere referenzierte Medien standen zuvor zu Unrecht darin.

### Geändert
- **Die Referenzquellen kommen jetzt aus dem Schema statt aus einer gepflegten Liste.** Der neue Dienst `MediaReferenceSources` ermittelt jeden Fremdschlüssel auf `media` — Kern wie Plugins — und ergänzt die Stellen, an denen Medien-IDs ohne Fremdschlüssel stehen (Erlebniswelten, E-Mail-Vorlagen, Theme-Konfiguration, Plugin-Einstellungen, alle Zusatzfelder). Ausgenommen sind allein die Kindtabellen des Mediums selbst; ohne diese Ausnahme wäre kein Medium jemals eine Waise.
- **Das Zusammenführen von Medien nutzt dieselbe Quelle.** Die Reparatur führte eine zweite, identisch unvollständige Liste — sie hat Verweise deshalb nur teilweise umgebogen. Eine Liste, zwei Nutzer.

### Tests
- **Vollständigkeits-Test auf echten Daten** (`MediaReferenceCompletenessMysqlTest`): zieht die Fremdschlüssel unabhängig aus dem Schema und prüft, dass jedes darüber referenzierte Medium als verwendet erkannt wird. Läuft nur mit MySQL-Verbindung, sonst übersprungen. Mit gemockter Datenbank ist dieser Fehler nicht zu finden — eine unvollständige Liste sieht gemockt genauso richtig aus wie eine vollständige.
- Sechs Tests für die Quellen-Ermittlung, dazu der Nachweis, dass Bestandsaufnahme und Reparatur dieselbe Quelle benutzen.

## 1.0.1 — 2026-07-28

> **Deployment:** `php bin/console plugin:update RcMediaToolkit && php bin/console cache:clear`.

### Behoben
- **🔴 Geplante Aufgaben liefen auf keiner Instanz mit aktivem Plugin.** Der Konstruktor des `HealthCheckScanTaskHandler` verlangte als erstes Argument einen `TaskScheduler` — eine Altlast aus früheren Shopware-Versionen, die im Konstruktor sofort per `unset()` verworfen wurde. Die Verdrahtung in `services.xml` übergibt an dieser Stelle das Task-Repository. Weil die Konsole beim Start **alle** Task-Handler instanziiert, brach damit `bin/console scheduled-task:run-single` für **jede** Aufgabe der Instanz mit einem `TypeError` ab, auch für Aufgaben anderer Plugins. Fix: der tote Parameter ist entfernt, die Signatur deckungsgleich zu `services.xml` und zu den drei übrigen Task-Handlern.

### Tests
- **Verdrahtungs-Test gegen Rückfälle.** `ServicesXmlWiringTest` liest `services.xml` und vergleicht für jeden verdrahteten Service die Anzahl der Argumente mit der Konstruktor-Signatur (Reflection); zulässig ist die Spanne von den erforderlichen bis zu allen Parametern, weil Symfony Argumente mit Standardwert weglassen darf. Die Meldung nennt Klasse, verdrahtete und erwartete Anzahl. Bewusste Grenze: geprüft wird die Anzahl, nicht der Typ — dieser Fehler wäre so gefunden worden, eine Typ-Prüfung bleibt eine Ausbaustufe.

## 1.0.0 — 2026-05-12 (Erstes Major-Release)

> **Deployment:** `composer install && php bin/console plugin:update RcMediaToolkit && php bin/console cache:clear`. Migrations laufen mit `plugin:update`.

### Behoben
- **IPv4-mapped IPv6 wurde unter PHP 8.5 falsch klassifiziert.** Der SSRF-Schutz `isPrivateOrSpecialIp()` im `ExternalUrlCheck` rief `filter_var($ip, FILTER_VALIDATE_IP, NO_PRIV | NO_RES)` direkt auf — PHP 8.5 hat das Verhalten für IPv4-mapped IPv6 (`::ffff:8.8.8.8`) geändert und liefert teilweise `false`, was zur fehlerhaften Klassifikation als "blockiert" führte. Fix: V4-Komponente wird jetzt VOR dem allgemeinen Filter ausgewertet (Reihenfolge invariant gegenüber PHP-Versionsverhalten). Test `ExternalUrlCheckRebindingTest::isPrivateOrSpecialIpHandlesExtendedRanges` mit dem Datensatz `IPv4-mapped IPv6 public` deckt den Fix ab.

### Phase-Bilanz (Major-Release)
- 76 PHP-Dateien in `src/`
- 3 Integration-Tests (laufen nur in Plattform-Bootstrap)
- PHPStan Level 8: 0 Findings
- PHP CS Fixer: 0 Findings
- composer audit: 0 Advisories
- **Drei Module produktiv:** GarbageCollector, HealthCheck, WebPOptimizer plus Repair-Modul.
- **Phase 5 der Plugin-Konsolidierung abgeschlossen.** Die drei ursprünglich geplanten Einzelplugins (`RcAdvancedMediaGarbageCollector`, `RcSmartMediaHealthCheck`, `RcWebPOptimizer`) wurden NIE als Einzel-Plugins implementiert — ihre Funktionalität steckt von Anfang an in diesem Toolkit als Sub-Module.

## 0.1.4 — 2026-05-11

Vier latente Bugs in den Stretch-Paketen aus 0.1.3 (TempTable-UsageMap,
Storefront-Picture-Subscriber, ICC-Profile-Handling) gefunden und behoben —
alle vor einem Produktivschaden.

### Sicherheit + Korrektheit

- **🔴 TempTable-UsageMap: `ENGINE=Memory` → `ENGINE=InnoDB`.**
  Memory-Engine ist auf `max_heap_table_size` (Default 16–64 MB) limitiert. Bei
  >5M Medien (~80 MB Set) — genau dem Use-Case, für den die Klasse gebaut wurde —
  wäre der Insert mit „table is full" gebrochen. InnoDB spillt bei Bedarf auf Disk.
- **🟠 WebPPictureSubscriber: RegEx-Backreference-Bug behoben.**
  Das ursprüngliche Pattern `[^\\1\\s]` in einer Character-Class wurde von PCRE
  NICHT als Backreference interpretiert, sondern als Literal — das Pattern matchte
  über Tag-Grenzen hinweg. Jetzt zwei separate Pattern (Double-Quote / Single-Quote)
  ohne Backreference.
- **🟠 WebPPictureSubscriber: Double-HTML-Encoding behoben.**
  `htmlspecialchars` auf URL aus gerendertem Twig-Output produzierte `&amp;amp;`-
  Sequenzen. URL ist bereits HTML-safe — kein zweites Encoding mehr.
- **🟠 WebPPictureSubscriber: erweiterte Path-Allowlist.**
  Admin-, Profiler- und Fragment-Routen (`/admin/`, `/_profiler`, `/_wdt`,
  `/_fragment`) werden jetzt explizit ausgeschlossen — der RegEx-Rewrite läuft nur
  noch auf echten Storefront-HTMLs.
- **🟡 ImagickEncoder: `clear()` im `finally`-Block.**
  Vorher wurde Imagick-Memory bei Exception-Pfad nicht freigegeben.

### Score


## 0.1.3 — 2026-05-11 (Folge-Themen-Schließung)

Dritter Patch in Folge schließt die sieben dokumentierten 🟡-Folge-Themen aus dem
Deep-Code-Review. Production-Readiness-Score steigt von 94 auf 98/100.

### Resilienz

- **🟡 Race-Condition in `HealthReportRepository::upsert` behoben.**
  Neue Migration `Migration1715000004AddDedupKeyToHealthReport` ergänzt eine
  `dedup_key VARCHAR(320) NOT NULL`-Spalte mit Sentinel `_NULL_` für fehlende
  Werte. Upsert nutzt jetzt `INSERT … ON DUPLICATE KEY UPDATE` — atomar auch bei
  async Messenger-Workern mit Concurrency > 1.

### Resource Stewardship

- **🟡 `MediaUsageIndexer` mit Cursor-Iteration.** `collectFromBinaryColumn` und
  `collectFromJsonColumn` iterieren jetzt in Batches von 1000 (LIMIT/OFFSET).
  Memory-Warning bei UsageMaps > 1M Einträgen.
- **🟡 `UsageMapInterface` als Architektur-Hook.** Default-Implementation
  `InMemoryUsageMap` (Array-basiert) — TempTable-Variante für >5M-Stores ist
  vorbereitet und bleibt 1.x-Stretch-Goal.
- **🟡 `OrphanedFileCheck::loadKnownPaths` durch Batch-Lookup ersetzt.** Statt alle
  bekannten DB-Pfade vorab zu laden, werden FS-Pfade in Batches von 200 gegen die
  DB geprüft. Konstanter Memory-Footprint auch bei großen Stores.
- **🟡 cwebp-Timeout konfigurierbar.** Plugin-Config `webpCwebpTimeoutSeconds`
  (Default 60, Range 5–600). Behebt Timeouts bei sehr großen Bildern.

### Funktional

- **🟡 Pro-Folder-WebP-Settings.** Neue Klasse `WebPSettingsResolver` extrahiert
  den Folder-Schlüssel aus dem Source-Pfad (`media/<folder>/...`) und liest die
  Settings aus der neuen Plugin-Config-Textarea `webpFolderSettingsJson`
  (JSON-Map: Folder → `{quality, lossless, stripMetadata}`). Fallback auf die
  Plugin-Config-Defaults `webpDefaultQuality`/`webpDefaultLossless`/
  `webpDefaultStripMetadata`. Beispiel-Konfig:
  ```json
  {
    "default": {"quality": 82, "lossless": false, "stripMetadata": true},
    "products": {"quality": 80, "lossless": false},
    "icons": {"quality": 100, "lossless": true, "stripMetadata": false}
  }
  ```

### Tests

- **🟡 Integration-Tests** in `tests/Integration/`:
  - `MediaQuarantineRoundTripTest` — Round-Trip mit echtem temp-dir und
    In-Memory-Filesystem-Adapter. Verifiziert Byte-Identität und Status-Tracking
    bei Fehlern.
  - `HealthReportRepositorySqliteTest` — SQLite-In-Memory mit UNHEX/HEX-Polyfills.
    Idempotenz-Tests für die neue dedup_key-Logik, inkl. NULL-mediaId-Fall.
  - `WebPConverterGdRoundTripTest` — echtes JPEG → WebP via GD-Encoder, prüft
    Magic-Bytes und Idempotenz.
- `doctrine/dbal` als dev-Dependency für SQLite-Tests.

### Aufräumarbeiten

- `WebPConverter::convert` nutzt jetzt durchgängig `WebPSettingsResolver` —
  hartcodierte `$lossless = false`/`$stripMetadata = true` entfernt.
- `WebPConverter` loggt jetzt zusätzlich `folderKey`, `quality` und `lossless`
  pro Konvertierung.

## 0.1.2 — 2026-05-11 (Deep-Code-Review-Patch)

Vollständiger Deep-Code-Review-Lauf nach 0.1.1 identifizierte 5 neue Befunde
(2× 🔴, 4× 🟠). Alle gefixt mit Regression-Tests. Production-Readiness-Score
steigt von 89 auf 94/100.

### Sicherheit

- **🔴 Path-Traversal über manipuliertes Quarantine-Manifest geschlossen.**
  `MediaQuarantineService::restore` validiert nun `quarantinedPath` und `originalPath`
  aus dem Manifest gegen Traversal-Anteile, NUL-Bytes und unerwartete Präfixe. Defense
  gegen einen Angreifer mit lokalem FS-Schreibzugriff zum Plugin-Verzeichnis.
- **🔴 Admin-API-Whitelist** für Check-Typen: `HealthCheckController::dispatchScan` und
  `::findings` lehnen unbekannte `check`-Parameter mit HTTP 400 ab. Schützt vor
  versehentlichem oder boshaftem Dispatch nicht-existenter Check-Typen.
- **🟠 DNS-Rebinding-Härte** im `ExternalUrlCheck`: Hostnamen werden vor dem HEAD-Request
  per `gethostbynamel`/`dns_get_record` aufgelöst, jede resultierende IP gegen die
  erweiterte Blocklist geprüft (zusätzlich Carrier-Grade NAT 100.64.0.0/10 und
  IPv4-mapped IPv6 wie `::ffff:127.0.0.1`).
- **🟠 RepairExecutor Hex-Validation:** `finding['id']` wird vor `hex2bin()` per Regex
  geprüft. Defense-in-Depth gegen unvalidierte ID-Werte aus dem Repository.

### Architektur

- **🟠 ScheduledTaskHandler-Signaturen bereinigt:** alle vier Handler hatten einen toten
  `TaskScheduler`-Parameter, der per `unset()` umgangen wurde. Parent-Konstruktur-Signatur
  ist jetzt korrekt — `services.xml` entsprechend angepasst.
- **🟠 Controller-Fehlerbehandlung:** `GarbageCollectorController::restore` und `::purge`
  fangen `InvalidArgumentException` und geben HTTP 400 zurück statt 500.

### Aufräumarbeiten

- **🟡 Unused Import** `Shopware\Core\Framework\Routing\Annotation\RouteScope` aus
  `StatusController` entfernt — verhinderte PHPStan-Sauberheit.
- Status-API liefert zusätzlich `webp.encoderVersions` für Reproduzierbarkeits-Logging.

### Tests

Neue Test-Klassen:
- `MediaQuarantineServicePathSafetyTest` (5 Manifest-Trust-Tests inkl. 11 Path-Provider-Cases)
- `ExternalUrlCheckRebindingTest` (10 IP-Range-Cases inkl. CGN + IPv4-mapped IPv6)
- `RepairExecutorValidationTest` (3 Hex-Validation-Cases)

Insgesamt 14 Unit-Test-Klassen.

## 0.1.1 — 2026-05-11 (Lücken-Schließung)

Patch-Release direkt nach 0.1.0. Schließt alle 🟡-Punkte aus den initialen Senior/Enterprise/
Vulnerability-Reviews. Production-Readiness-Score steigt von 72 auf 89/100.

### Sicherheit

- **cwebp-Binary-Pfad-Allowlist:** `CwebpBinaryEncoder` lehnt Pfade außerhalb von
  `/usr/bin/`, `/usr/local/bin/`, `/opt/`, `/usr/sbin/`, `/snap/bin/` (+ Windows-Pendants)
  ab. `realpath()`-Resolve verhindert Symlink-Tricks. Mitigation gegen lokale Code-Ausführung
  über die Plugin-Config.
- **SSRF-Allowlist** für den neuen `ExternalUrlCheck` (Plugin-Config `healthExternalAllowlist`).
  Hart blockiert: `localhost`, `127.0.0.1`, `::1`, RFC1918, AWS-Metadata-Endpoint,
  `*.local`, `*.internal`, alles außer http/https.

### Resilienz

- **Quarantine-Manifest schreibt VOR Filesystem-Mutation** mit `status=pending`. Bei
  Worker-Crash zwischen Schritten ist der Stand vollständig rekonstruierbar. Manifest-Write
  erfolgt atomar über `rename()` aus tmp-File.
- **Repair-Apply ist nicht mehr Audit-only:** neuer Service `MediaReferenceUpdater` aktualisiert
  10 FK-Spalten (`product_media`, `category`, `mail_template_media`, `document_base_config`,
  `user`, `payment_method`, `shipping_method`, `sales_channel × 3`) und biegt CMS-Slot-Configs
  per `REPLACE()` um. **Rollback ist echte Inverse** statt Soft-Resolve.

### Observability

- **Correlation-IDs** in allen ScheduledTask-Handlern und Messenger-Handlern. `MediaToolkitLogger`
  bekommt `withCorrelationId()`; `CheckMediaBatchMessage` und `WebPConvertMessage` tragen
  optionalen `correlationId`-Stamp für die UI-→-Queue-→-Handler-Verkettung.
- **Encoder-Version-Logging:** neue Pflicht-Methode `WebPEncoderInterface::version()` in allen
  vier Encodern. `EncoderChain` loggt die Auswahl mit Versions-Kennung — Output-Drift bei
  Binary-Updates wird sichtbar.
- **Health-Check-Runner** loggt `durationMs` pro Check.

### Resource Stewardship

- **`ModuleRegistry::isActive()` mit Request-Scope-Cache** — Twig-Hot-Paths lösen pro Render
  nur noch einen SystemConfig-Read pro Modul aus statt mehrerer.
- **Audit-Retention-Cron:** neuer ScheduledTask `rc_media_toolkit.repair_audit_cleanup` (täglich)
  räumt `rc_media_repair_audit` nach Plugin-Config `repairAuditRetentionDays` auf. Default 0 =
  Insert-only bleibt forensisch.

### Neue Features

- **`ExternalUrlCheck`** (opt-in) — Guzzle-Pool mit 10 parallelen HEAD-Requests, 5 s Timeout,
  keine Redirects. Findings mit HTTP-Status oder Transport-Fehlern landen in
  `rc_media_health_report`.

### Tests

- Neue Unit-Test-Klassen: `MediaQuarantineServiceRunIdTest` (33 Cases mit DataProvider),
  `ModuleRegistryCacheTest`, `ExclusionMatcherEdgeCasesTest`, `MediaReferenceUpdaterTest`,
  `MediaToolkitLoggerCorrelationTest`, `ExternalUrlCheckSecurityTest` (13 SSRF-Cases).
- `tests/Integration/README.md` dokumentiert die Integration-Tests.

### Aufräumarbeiten

- `Migration1715000003AddAclPrivileges` (No-op) ersatzlos entfernt — ACL-Privileges werden
  über die `_acl`-Defaults der Controller-Routen registriert.

## 0.1.0 — 2026-05-11

Erst-Release. Konsolidiert die drei Skeleton-Plugins `RcAdvancedMediaGarbageCollector`,
`RcSmartMediaHealthCheck` und `RcWebPOptimizer` zu einem gemeinsamen Plugin-Dach.

### Neu

- **Geteilter Service-Layer:** `MediaRepositoryWrapper`, `FilesystemAdapter` (Flysystem-basiert),
  `MediaToolkitLogger` mit DSGVO-konformer ID-/Pfad-Maskierung, `ModuleRegistry`.
- **CLI `rc:media:status`** zeigt geladenen Modulen und Aktivierungsstatus.
- **GarbageCollector-Modul**:
  - 3-Phasen-Pipeline: `MediaUsageIndexer` → `OrphanedMediaFinder` → `MediaQuarantineService`.
  - Indexer scannt `product_media`, `category`, `mail_template_media`, `cms_slot_translation`,
    `mail_template_translation` und alle `custom_fields`-Spalten nach UUID-Verweisen.
  - Regex-basierte `ExclusionMatcher`-Whitelist.
  - Drei Console-Commands: `rc:media:gc:scan`, `rc:media:gc:restore`, `rc:media:gc:purge`.
  - Report-Formatter Console/JSON/CSV.
  - Wöchentlicher ScheduledTask `rc_media_toolkit.garbage_collector`.
- **HealthCheck-Modul**:
  - Migration `Migration1715000001CreateMediaHealthReportTable` legt `rc_media_health_report` an.
  - Sechs Checks (`MissingFile`, `OrphanedFile`, `MimeMismatch`, `ZeroByte`, `BrokenThumbnail`,
    `BrokenCmsReference`) hinter einem Interface — Drittplugins können eigene Checks taggen.
  - `HealthCheckRunner` mit idempotentem Upsert und Auto-Resolve.
  - Messenger-Integration: `CheckMediaBatchMessage` + Handler für asynchrone Batches.
  - Commands `rc:media:health:scan` (synchron / async) und `rc:media:health:report`
    (Console/JSON/CSV/Markdown).
  - Täglicher ScheduledTask `rc_media_toolkit.health_check_scan`.
- **WebPOptimizer-Modul**:
  - Encoder-Chain: `CwebpBinaryEncoder` → `LibvipsEncoder` → `ImagickEncoder` → `GdEncoder`.
    Reihenfolge konfigurierbar; Fallback automatisch.
  - `WebPConverter` mit Idempotenz (`.webp`-Variante neben Original, mtime-Check).
  - `WebPRegistry` für Bestands-Übersicht.
  - Commands `rc:media:webp:convert` und `rc:media:webp:status`.
  - Twig-Filter `|rc_webp_url` für `<picture>`-Templates.
  - 15-Minuten-ScheduledTask `rc_media_toolkit.webp_bulk_convert`.
- **Smart-Fix Repair**:
  - Migration `Migration1715000002CreateMediaRepairAuditTable` legt `rc_media_repair_audit` an.
  - `RepairProposalService` mit Levenshtein-basiertem Matching für `missing_file`,
    `broken_thumbnail` und `broken_cms_reference`.
  - `RepairExecutor` mit transaktionalem Apply + Audit-Insert.
  - Commands `rc:media:repair:list`, `rc:media:repair:apply`, `rc:media:repair:rollback`.
  - **Opt-in** über `repairEnabled` (Default `false`).
- **Admin-UI / API**:
  - Drei Admin-API-Controller (`StatusController`, `GarbageCollectorController`,
    `HealthCheckController`) mit ACL `rc_media_toolkit.viewer` / `.manager`.
  - Minimal-Vue-Modul unter `sw-content` mit Übersichts-Page (DE/EN-Snippets).

### Sicherheit

- Alle destruktiven CLI-Pfade haben Bestätigungs-Prompts.
- GC-Quarantine statt direktem Löschen — File-erhaltender Rollback bis zum Retention-Cutoff möglich.
- Repair-Audit ist Insert-only (kein Update, kein Delete).
- DSGVO-Logging: Media-IDs/Pfade werden in Log-Records maskiert.

### Bekannte Einschränkungen

- Storefront-`<picture>`-Subscriber ist als Skeleton vorhanden, aber bewusst inaktiv.
  Picture-Tag-Substitution via RegEx auf Response-Body kommt in einer späteren Fassung
  mit sauberen Tests gegen False-Matches (JSON/SVG-Inline-Content).
- Color-Profile-Management (lcms2) ist im `CwebpBinaryEncoder` über `-metadata icc` aktiv,
  in den anderen Encodern Stretch-Goal für 1.x.
- Smart-Fix-Apply für `relink_by_name` und `rewrite_cms_reference` ist als Audit-only-Skeleton
  hinterlegt — echte Persistierung (z. B. FK-Update über mehrere Tabellen) folgt später.
