# Änderungsverlauf

Alle stabilen Releases ab Oktober 2026 verwenden das in [VERSIONING.md](VERSIONING.md) dokumentierte Schema `YYYY.M.PATCH`.

## Noch nicht veröffentlicht

Keine Änderungen.

## 2026.10.1 – Noch nicht veröffentlicht

- Historischer, rein lesender Abgleich bestehender WooCommerce-Bestellungen mit bereits vorhandenen Lexware-Rechnungen.
- Eindeutige Zuordnung nur bei exakter Bestellnummer im Lexware-Einleitungstext und identischem Bruttobetrag.
- Hintergrundverarbeitung in kleinen Batches unter Beachtung des Lexware-API-Limits.
- Fail-closed-Verhalten bei API-Fehlern, Rechnungsentwürfen oder mehrdeutigen Treffern zum Schutz vor Doppelbelegen.
- Zusätzlicher Abgleich unmittelbar vor einer Rechnungserstellung für historische Bestellungen.
- Bewusst gelöschte Rechnungsverknüpfungen werden nicht automatisch erneut hergestellt.

## 2026.10.0 – 2026-10-06

Erstes stabiles öffentliches Release.

### Enthalten

- Rechnungserstellung und Gutschriften über die Lexware Office Public API.
- Kontaktzuordnung ohne destruktives Überschreiben bestehender Lexware-Kontakte.
- Queue mit Deduplizierung, atomarem Claiming und Action-Scheduler-Unterstützung.
- Konservative Behandlung unklarer API-Schreibantworten zum Schutz vor Doppelbelegen.
- Gutschriften auf Grundlage des ursprünglichen Lexware-Rechnungssnapshots.
- HPOS-Kompatibilität und WooCommerce-CRUD für Bestelldaten.
- Geschützter Rechnungsdownload und WooCommerce-Kundenbereich.
- Standardmäßig datenerhaltende Deinstallation mit explizitem Opt-in für vollständige Bereinigung.
- Vereinheitlichte deutsche Projekt- und WordPress.org-Dokumentation.

## Interne Vorabphase

Die Entwicklungsstände bis einschließlich `1.3.6` waren interne Vorabversionen. Ihre Funktionen und Korrekturen sind in `2026.10.0` konsolidiert. Ab `2026.10.0` gilt ausschließlich das neue öffentliche Versionsschema.
