# Patsch9 Accounting Bridge for WooCommerce

Entwicklungsstand: **2026.10.2**

Patsch9 Accounting Bridge verbindet WooCommerce mit der Lexware Office Public API und unterstützt die Erstellung und Verwaltung von Rechnungen und Gutschriften aus WooCommerce-Bestellungen.

## Funktionsumfang

- Automatische oder manuelle Rechnungserstellung aus WooCommerce-Bestellungen.
- Automatischer read-only Abgleich historischer Bestellungen mit bereits vorhandenen Lexware-Rechnungen anhand Bestellnummer im Einleitungstext und identischem Bruttobetrag.
- Manueller Start bzw. Neustart des historischen Rechnungsabgleichs mit sichtbarem Status und Fortschrittswerten.
- Bereits verknüpfte Rechnungen werden beim historischen Abgleich nicht überschrieben; frühere Prüfungen ohne Treffer können bei einem manuellen Neustart erneut geprüft werden.
- Sichere Kontaktzuordnung anhand exakter E-Mail-Adressen.
- Erstellung von Lexware-Kontakten, ohne bestehende Kontakte automatisch destruktiv zu überschreiben.
- Queue-basierte Verarbeitung mit Deduplizierung und Sperrmechanismus.
- Action Scheduler mit WP-Cron-Fallback.
- Gutschriften auf Basis des ursprünglichen Lexware-Rechnungssnapshots.
- Rechnungsdownload und Integration in den WooCommerce-Kundenbereich.
- WooCommerce-E-Mail-Integration.
- Optionale Berücksichtigung von Mietkautions-Metadaten ohne harte Abhängigkeit vom Rental-Plugin.
- HPOS-Kompatibilität.

## Voraussetzungen

- WordPress **6.9.5 oder neuer**.
- PHP **8.2 oder neuer**.
- WooCommerce **10.9.4 oder neuer**.
- Lexware Office Konto mit freigeschaltetem Public-API-Zugang.
- Gültiger Lexware API-Key.

`Tested up to` wird bewusst nur auf tatsächlich getestete WordPress-Versionen angehoben.

## Installation

1. WooCommerce installieren und aktivieren.
2. Das ZIP-Paket dieses Plugins unter **Plugins → Installieren → Plugin hochladen** installieren.
3. Plugin aktivieren.
4. API-Key, Trigger-Status und gewünschte Synchronisationsoptionen konfigurieren.
5. Nach Konfiguration des API-Keys startet der historische Rechnungsabgleich im Hintergrund. Wegen des Lexware-API-Limits wird er in kleinen Batches ausgeführt.
6. Der historische Abgleich kann bei Bedarf im WooCommerce-Administrationsbereich der Accounting Bridge manuell gestartet oder neu gestartet werden.

## Konfiguration

Die Einstellungen befinden sich im WooCommerce-Administrationsbereich des Plugins. Für höhere Sicherheit kann der Lexware API-Key statt in der WordPress-Datenbank in `wp-config.php` hinterlegt werden:

```php
define( 'LEXWARE_CONNECTOR_API_KEY', 'DEIN_API_KEY' );
```

Die Konstante hat Vorrang vor einem in WordPress gespeicherten Schlüssel.

## Historischer Rechnungsabgleich

Der historische Rechnungsabgleich dient dazu, bereits vor der Installation oder Aktivierung des Plugins vorhandene WooCommerce-Bestellungen mit bestehenden Lexware-Rechnungen zu verknüpfen.

Der Abgleich arbeitet gegenüber Lexware **read-only**. Er erstellt, verändert oder storniert bei diesem Vorgang keine Rechnungen. Eine Zuordnung erfolgt nur, wenn die Bestellnummer im Lexware-Beleg gefunden wird und der Bruttobetrag zur WooCommerce-Bestellung passt.

Seit Version **2026.10.2** kann der Abgleich im WooCommerce-Administrationsbereich manuell gestartet oder neu gestartet werden. Die Statusanzeige zeigt dabei unter anderem:

- aktuellen Status des Abgleichs,
- Batch-/Fortschrittsinformationen,
- Anzahl geprüfter Bestellungen,
- gefundene Zuordnungen,
- Bestellungen ohne Treffer,
- bereits vorhandene Verknüpfungen,
- aufgetretene Fehler.

Ein manueller Neustart setzt einen neuen Stichtag und prüft aktuell vorhandene, noch nicht verknüpfte Bestellungen erneut. Bereits verknüpfte Lexware-Rechnungen werden nicht überschrieben. Auch Bestellungen, die bei einem früheren Lauf ohne Treffer geblieben sind, können erneut geprüft werden.

Um parallele Scanner zu vermeiden, schützt ein kurzlebiger Lock den Hintergrundprozess. Ein bereits aktiver Lauf wird nicht durch einen zweiten manuellen Lauf überlagert; veraltete Locks können nach Ablauf der vorgesehenen TTL selbstheilend bereinigt werden.

Für die Hintergrundverarbeitung wird bevorzugt der WooCommerce Action Scheduler verwendet. Falls dieser nicht verfügbar ist, dient WP-Cron als Fallback.

## Externe Dienste & Datenschutz

Die Verbindung zur **Lexware Office Public API** ist Kernbestandteil dieses Plugins. Die API wird unter `https://api.lexware.io/v1/` angesprochen. Je nach Aktion werden insbesondere Kunden-, Adress-, E-Mail-, Bestell-, Positions-, Preis-, Steuer-, Versand- und Belegdaten übertragen.

- Entwicklerportal: https://developers.lexware.io/
- API-Dokumentation: https://developers.lexware.io/docs/
- Public-API-Bedingungen: https://agb.lexware.de/lexware-office/public-api-lizenz--und-nutzungsbedingungen
- Datenschutz: https://www.lexware.de/datenschutz/

API-Logging ist bei Neuinstallationen standardmäßig deaktiviert. Bekannte personenbezogene Felder werden bei aktiviertem Logging maskiert; vollständige API-Antwortkörper werden nicht dauerhaft protokolliert.

Der Website-Betreiber ist für die korrekte Datenschutzinformation und die Einhaltung der anwendbaren Lexware-Bedingungen verantwortlich.

## Kompatibilität

- WooCommerce HPOS (`custom_order_tables`) wird deklariert.
- WooCommerce-Bestelldaten werden über die WooCommerce-CRUD-API verarbeitet.
- Action Scheduler wird verwendet, wenn er verfügbar ist; WP-Cron dient als Fallback.
- Bestehende interne Schlüssel wie `WLC_*`, `wlc_*` und vorhandene Queue-/Meta-Daten bleiben aus Gründen der Abwärtskompatibilität bestehen.

## Versionsschema

Ab 2026.10.0 gilt projektweit `YYYY.M.PATCH`. Details stehen in [VERSIONING.md](VERSIONING.md).

## Releases

Installierbare ZIP-Dateien werden automatisch als [GitHub Releases](https://github.com/patsch9/patsch9-accounting-bridge/releases) bereitgestellt. Ein Release wird nur erzeugt, wenn der entsprechende `v...`-Tag zur Plugin-Version passt und die Paketprüfungen erfolgreich sind.

## Migration

Hinweise für Installationen aus der Vorabphase stehen in [MIGRATION.md](MIGRATION.md).

## Sicherheit

Sicherheitslücken bitte **nicht öffentlich als GitHub-Issue veröffentlichen**. Vorgehen und benötigte Angaben stehen in [SECURITY.md](SECURITY.md).

## Entwicklung & Tests

Das Repository enthält automatisierte statische Prüfungen für unterstützte PHP-Versionen und WordPress Plugin Check. Schreibende API-Aufrufe werden bewusst konservativ behandelt: Bei unklarem Ergebnis wird ein Vorgang nicht automatisch erneut erzeugt, um doppelte Buchhaltungsbelege zu vermeiden.

## Projekt unterstützen

Wenn dir das Plugin hilft, kannst du die Weiterentwicklung unterstützen:

- [GitHub Sponsors](https://github.com/sponsors/patsch9)
- [Buy Me a Coffee](https://buymeacoffee.com/patsch09)

Die Links sind zusätzlich über GitHubs Sponsor-Funktion (`.github/FUNDING.yml`) hinterlegt.

## Markenhinweise

WooCommerce® ist eine Marke von Automattic Inc. Lexware® und Lexware Office sind Marken bzw. Produktbezeichnungen der Haufe-Lexware GmbH & Co. KG. Dieses Plugin ist eine unabhängige Drittanbieter-Erweiterung und wird weder von Automattic noch von Haufe-Lexware herausgegeben, gesponsert oder unterstützt.

## Lizenz

GPL-2.0-or-later. Siehe [LICENSE](LICENSE).
