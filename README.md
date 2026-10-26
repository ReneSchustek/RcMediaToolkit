---
tags: anleitung, shopware, doku
---

# RcMediaToolkit

Wartungs-Tools für Shopware-Medien in einem einzigen Plugin. Adressiert Shopbetreiber-Admins, kein Storefront-Footprint per Default.

## Module

| Modul              | Zweck                                                                                     | Status     |
|--------------------|-------------------------------------------------------------------------------------------|------------|
| GarbageCollector   | Findet verwaiste Medien (3-Phasen: Index → Compare → Quarantine) und löscht nach Retention | implementiert |
| HealthCheck        | Konsistenz-Check Media ↔ Filesystem, persistierte Findings, Read-only                     | implementiert |
| WebPOptimizer      | JPEG/PNG → WebP via Encoder-Chain (cwebp/libvips/Imagick/GD), Original unangetastet        | implementiert |
| Repair (Smart-Fix) | Manueller Reparatur-Workflow mit Audit + Rollback (opt-in!)                               | implementiert |

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+
- Optional für volle Encoder-Wahl: `cwebp`-Binary, `vips`-Extension oder `imagick` mit WebP-Support. GD-Fallback ist immer aktiv.

## Installation

```bash
# Plugin nach custom/plugins/RcMediaToolkit/ legen
bin/console plugin:refresh
bin/console plugin:install --activate RcMediaToolkit
```

Nach der Installation sind **alle Module deaktiviert**. Aktivierung über die Plugin-Konfiguration im Admin oder per `SystemConfig`:

```bash
# Modul-Aktivierung
bin/console system:config:set RcMediaToolkit.config.garbageCollectorActive true
bin/console system:config:set RcMediaToolkit.config.healthCheckActive true
bin/console system:config:set RcMediaToolkit.config.webPOptimizerActive true
```

## CLI-Übersicht

### Status

```bash
bin/console rc:media:status
```

Zeigt geladene Sub-Module und ihren Aktivierungsstatus.

### Garbage Collector

```bash
# Read-only Scan (Default)
bin/console rc:media:gc:scan
bin/console rc:media:gc:scan --format=json --limit=100 --output=/tmp/gc-report.json

# Verschieben in Quarantine (mit Bestätigungsprompt)
bin/console rc:media:gc:scan --quarantine

# Quarantine-Übersicht und Restore
bin/console rc:media:gc:restore --list
bin/console rc:media:gc:restore <runId>
bin/console rc:media:gc:restore <runId> --media-id=<hexId>

# Endgültiges Löschen
bin/console rc:media:gc:purge <runId>
bin/console rc:media:gc:purge --older-than=14
```

**Sicherheitshinweis:** `rc:media:gc:purge` ist irreversibel. Vor produktivem Einsatz Backup empfohlen.

### Health Check

```bash
# Synchroner Lauf aller aktivierten Checks
bin/console rc:media:health:scan

# Einzelner Check
bin/console rc:media:health:scan --check=missing_file

# Async via Messenger
bin/console rc:media:health:scan --async

# Report
bin/console rc:media:health:report
bin/console rc:media:health:report --format=json|csv|md
```

Verfügbare Check-Typen: `missing_file`, `orphaned_file`, `mime_mismatch`, `zero_byte`, `broken_thumbnail`, `broken_cms_reference`.

### WebP-Optimizer

```bash
# Bestand und Encoder-Verfügbarkeit
bin/console rc:media:webp:status

# Dry-Run
bin/console rc:media:webp:convert --dry-run --limit=50

# Synchron konvertieren
bin/console rc:media:webp:convert --limit=100

# Asynchron via Queue dispatchen
bin/console rc:media:webp:convert --async --limit=500
```

Originale werden **niemals** überschrieben. WebP-Variante landet als `<original>.webp` neben dem Original.

Twig-Filter für eigene Templates:

```twig
<picture>
    <source srcset="{{ media|rc_webp_url }}" type="image/webp">
    <img src="{{ media.url }}" alt="{{ media.alt }}">
</picture>
```

### Smart-Fix Repair (opt-in)

```bash
# Repair erst aktivieren (Default false!)
bin/console system:config:set RcMediaToolkit.config.repairEnabled true

# Vorschläge zu einem Finding listen
bin/console rc:media:repair:list <findingId>

# Vorschlag anwenden (mit Bestätigungsprompt)
bin/console rc:media:repair:apply <findingId> <proposalIndex>

# Rollback eines früheren Repairs
bin/console rc:media:repair:rollback <auditId>
```

**Sicherheitshinweis:** Repair bricht den Read-only-Vertrag des HealthCheck. Vor Anwendung Backup empfohlen.

## Scheduled Tasks

| Task                                          | Default-Intervall | Aktivierung                                              |
|-----------------------------------------------|-------------------|----------------------------------------------------------|
| `rc_media_toolkit.garbage_collector`          | wöchentlich       | `gcCronEnabled` + optional `gcCronAutoQuarantine`        |
| `rc_media_toolkit.health_check_scan`          | täglich           | `healthCronEnabled`                                      |
| `rc_media_toolkit.webp_bulk_convert`          | alle 15 Minuten   | `webpBulkCronEnabled`                                    |

Alle Cron-Tasks sind per Default **deaktiviert**. Aktivierung über Plugin-Config.

## Admin-API

Drei Endpunkte unter `/api/_action/rc-media-toolkit/`:

- `GET /status` — Modul-Status, Health-Severity-Counts, WebP-Bestand, Encoder-Verfügbarkeit
- `GET /gc/scan`, `GET /gc/runs`, `POST /gc/runs/{runId}/restore`, `DELETE /gc/runs/{runId}/purge`
- `GET /health/findings`, `POST /health/scan`

ACL: `rc_media_toolkit.viewer` (lesend) und `rc_media_toolkit.editor` (auslösend, darunter
destruktive Läufe). In der Rollen-Verwaltung stehen beide unter „Content" in der Zeile
„Medien-Werkzeugkasten": **Ansehen** vergibt das lesende Recht, **Bearbeiten** das auslösende.
Die zweite Stufe heißt `editor`, weil das Rechte-Raster nur die vier Spalten `viewer`,
`editor`, `creator` und `deleter` kennt — ein anderer Name wäre nur über „Alle" zu vergeben.

## Architektur

```
src/
├── RcMediaToolkit.php
├── Command/StatusCommand.php
├── Controller/Admin/{Status,GarbageCollector,HealthCheck}Controller.php
├── Migration/Migration1715000001-3_*.php
├── Module/
│   ├── GarbageCollector/{Service,Command,Report,Dto,ScheduledTask}
│   ├── HealthCheck/{Service,Command,Check,Message,Repository,ScheduledTask,Dto}
│   ├── WebPOptimizer/{Service,Command,Message,Subscriber,Twig,ScheduledTask}
│   └── Repair/{Service,Command,Dto}
├── Service/{MediaRepositoryWrapper,FilesystemAdapter,MediaToolkitLogger,ModuleRegistry}
└── Resources/
    ├── app/administration/   — Vue-Admin-Modul
    └── config/{services.xml,config.xml,routes.xml}
```

Geteilte Services im Plugin-Wurzel-Service-Verzeichnis: alle Sub-Module brauchen Filesystem-Zugriff, Media-Lookup und Logging — Triplettierung wurde vermieden.

## Tests

```bash
composer install
composer test:unit
composer phpstan
composer cs-check
composer quality
```

Unit-Tests laufen ohne Shopware-Bootstrap. Integration-Tests sind als Erweiterung möglich, sobald eine Test-DB verfügbar ist.

## Sicherheits- und DSGVO-Hinweise

- **Logging:** Media-IDs werden nach 8 Zeichen abgeschnitten, Pfade nach 32 Zeichen — Kundenuploads können personenbezogene Daten enthalten.
- **Quarantine:** Im Plugin-Verzeichnis (`var/media_quarantine/`) landen Original-Dateien. Bei DSGVO-Auskunfts- oder Löschanfragen muss die Quarantine ggf. mit aufgeräumt werden (`rc:media:gc:purge`).
- **Audit:** Repair-Audit-Tabelle (`rc_media_repair_audit`) wird **nicht** automatisch gelöscht. Manuelles Aufräumen bei DSGVO-Pflichten erforderlich.
- **WebP-Metadata-Stripping:** EXIF/IPTC werden per Default entfernt (Geo-Tags in Kameradaten!).

## Lizenz

Proprietär – siehe [composer.json](composer.json).
