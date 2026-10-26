---
tags: anleitung, shopware, tests
---

# Integration-Tests

Integration-Tests benötigen eine echte Shopware-Test-Datenbank (`IntegrationTestBehaviour`)
und sind deshalb nicht im Standard-Unit-Lauf enthalten.

Erst innerhalb einer Shopware-Installation lauffähig:

```bash
# Aus dem Shopware-Root, nicht aus dem Plugin-Verzeichnis
APP_ENV=test \
DATABASE_URL=mysql://app:app@localhost:3306/shopware_test \
vendor/bin/phpunit \
    -c custom/plugins/RcMediaToolkit/phpunit.xml.dist \
    --testsuite=Integration
```

Geplante Tests:

- `MediaQuarantineServiceIntegrationTest` — Quarantine-Restore-Round-Trip mit File-Hash-Check.
- `HealthCheckRunnerIntegrationTest` — Fixture mit 5 referenzierten / 5 unreferenzierten Medien;
  Re-Run muss Idempotenz und Auto-Resolve nachweisen.
- `RepairExecutorIntegrationTest` — Apply + Rollback eines `relink_by_name`-Proposals; Audit-Row
  muss vorhanden sein, FK-Updates müssen rückgängig sein.
- `WebPConverterIntegrationTest` — kleines JPEG konvertieren, Originale-mtime unverändert,
  `.webp`-Variante hat plausible Größe.

Solange diese Tests fehlen, sichern die Unit-Tests (`tests/Unit/`) die Service-Verträge
ab. Die Integration-Schicht wird gesondert nachgezogen.
