# Änderungsverlauf

Alle stabilen Releases ab Oktober 2026 verwenden das in [VERSIONING.md](VERSIONING.md) dokumentierte Schema `YYYY.M.PATCH`.

## 2026.10.7 – Noch nicht veröffentlicht

- Manuelle Rechnungsdaten werden direkt nach Nonce- und Berechtigungsprüfung bereinigt und als Werte an den Bestellsperr-Callback übergeben. Array-Eingaben werden abgefangen.
- WordPress Plugin Check läuft auch in PRs streng; jeder PR nach `main` prüft zusätzlich das tatsächlich gebaute Release-Paket.
- Regressionstests für Nonces, Berechtigungen, Eingabevalidierung und manuelle Verknüpfungen unter Bestellsperre ergänzt.
- Enthält die folgenden Migrations- und Sperrkorrekturen aus dem blockierten Release 2026.10.6.

## 2026.10.6 – Veröffentlichung am 2026-10-14 durch Plugin Check blockiert

- Fehlgeschlagene Optionsmigrationen und abweichende Alt-/Neuwerte stoppen die Initialisierung; alte Werte werden erst nach verifizierter Übernahme entfernt.
- Alte Queue-Tabellen werden unter Sperre atomar umbenannt und anschließend per dbDelta aktualisiert; zwei belegte Tabellen bleiben zur manuellen Konfliktlösung erhalten.
- Datenbankschema und Deduplizierungsindex werden vor dem Versionsmarker geprüft; unterbrochene Upgrades bleiben wiederaufnehmbar.
- Gemeinsame Bestellsperre für alle Rechnungsaktionen sowie manuelle Verknüpfungsänderungen; neue Metadaten werden nach Sperrerwerb geladen.
- Unklare Schreibzustände sperren auch andere Aktionen derselben Bestellung; konkurrierende Requests verbrauchen keine Wiederholungsversuche.
- Integrationstests mit echten Datenbankfehlern und zweiter Verbindung ergänzt.

## 2026.10.5 – 2026-10-12

- Rental-Metadaten unter `_rmwc_*` werden bei Kautionen und Zahlungsbedingungen erkannt; historische `_clr_*`-Bestellungen bleiben als Fallback unterstützt.
- Rückzahlbare Kautionen bleiben informative Textpositionen und erhöhen den Lexware-Rechnungsbetrag nicht.

## Noch nicht veröffentlicht

- GitHub Actions auf PR-/Main-Prüfungen und Release-Tags begrenzt; doppelte Branch-/Tag-Prüfläufe vermieden.
- Veraltete PR-Prüfläufe werden abgebrochen; Veröffentlichungs- und Deploy-Läufe bleiben geschützt.
- Automatischer WordPress.org-Deploy nur nach erfolgreichem Release-Tag-Lauf aus diesem Repository; manuelle Tag-Eingabe ohne veralteten Standardwert.
- Dependabot-Updates der Actions gebündelt; historische Einmal-Workflows und dauerhaft benötigte Workflows dokumentiert.

## 2026.10.4 – Noch nicht veröffentlicht

- Englische Originaltexte für Administration, Kundenbereich, E-Mails, API-Fehler und Bestellnotizen.
- Vollständige deutsche `de_DE`-Übersetzung als PO/MO-Dateien im Plugin; Sprachdateien werden ab `init` berücksichtigt.
- POT-Datei vollständig mit WP-CLI aus dem Quellcode generiert, einschließlich Pluralformen und Plugin-Metadaten.
- Rechnungsvorgaben bei Neuinstallationen und fehlenden Einstellungen übersetzbar; gespeicherte Texte bleiben unverändert.
- Standard-Betreff und Standard-Überschrift von Rechnungs-E-Mails bleiben zur Versandzeit übersetzbar.
- Übersetzte Bestätigungstexte werden als HTML-Datenattribute verarbeitet, nicht als eingebetteter JavaScript-Code.
- Übersetzungsablauf und verbleibende Freigabeschritte auf WordPress.org dokumentiert.

## 2026.10.3 – Noch nicht veröffentlicht

- WordPress.org-Review: Admin-JavaScript und CSS über WordPress-Enqueue-APIs eingebunden.
- Eindeutiger `PATSACBR_`/`patsacbr_`-Präfix für globale Deklarationen, Hooks und gespeicherte Plugin-Daten.
- Kompatibilitätsmigration für Einstellungen, Queue und WooCommerce-Bestellmetadaten aus Vorabständen.
- Admin-Hinweise auf relevante Plugin-/WooCommerce-Seiten begrenzt.
- WordPress.org-Mindestversionsangabe auf `6.9` normalisiert.

## 2026.10.2 – Noch nicht veröffentlicht

- Manueller Start und Neustart des historischen Lexware-Rechnungsabgleichs in der Accounting-Bridge-Administration.
- Sichtbarer Status mit Batch-Fortschritt, Treffern, Nicht-Treffern und Fehlern.
- Manuelle Neustarts prüfen auch bereits ohne Treffer geprüfte Bestellungen erneut, verändern aber keine bestehenden Rechnungsverknüpfungen.
- Action-Scheduler-Aufräumung berücksichtigt die eigene Scheduler-Gruppe korrekt.

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
