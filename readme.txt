=== Patsch9 Accounting Bridge for WooCommerce ===
Contributors: patsch9
Tags: woocommerce, lexware, rechnung, buchhaltung, api
Requires at least: 6.9.5
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 2026.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verbindet WooCommerce mit der Lexware Office Public API für Rechnungen, Gutschriften, Kundenbezug und Belegzugriff.

== Description ==

Patsch9 Accounting Bridge verbindet WooCommerce mit der Lexware Office Public API. Das Plugin erstellt und verwaltet Rechnungen bzw. Gutschriften aus WooCommerce-Bestellungen, verarbeitet Übertragungen über eine Queue und kann Rechnungsdokumente im Kundenbereich bereitstellen.

**Markenhinweis:** WooCommerce® ist eine Marke von Automattic Inc. Lexware® und Lexware Office sind Marken bzw. Produktbezeichnungen der Haufe-Lexware GmbH & Co. KG. Dieses Plugin ist eine unabhängige Drittanbieter-Erweiterung und wird weder von Automattic noch von Haufe-Lexware herausgegeben, gesponsert oder unterstützt.

== Funktionen ==

* Automatische oder manuelle Rechnungserstellung.
* Sichere Kontaktzuordnung anhand exakter E-Mail-Adressen.
* Queue-basierte Verarbeitung mit Deduplizierung und Sperrmechanismus.
* Action Scheduler mit WP-Cron-Fallback.
* Gutschriften auf Basis des ursprünglichen Lexware-Rechnungssnapshots.
* Rechnungsdownload und WooCommerce-Kundenbereich.
* WooCommerce-E-Mail-Integration.
* Optionale Verarbeitung von Mietkautions-Metadaten ohne harte Abhängigkeit von einem Vermietungsplugin.
* HPOS-Kompatibilität.

== Voraussetzungen ==

* WordPress 6.9.5 oder neuer.
* PHP 8.2 oder neuer.
* WooCommerce 10.9.4 oder neuer.
* Lexware Office Konto mit freigeschaltetem Public-API-Zugang.
* Gültiger Lexware API-Key.

== Installation ==

1. WooCommerce installieren und aktivieren.
2. Plugin-ZIP hochladen und aktivieren.
3. Lexware API-Key konfigurieren.
4. Trigger-Status und gewünschte Synchronisationsoptionen festlegen.

== Konfiguration ==

Für höhere Sicherheit kann der API-Key außerhalb der WordPress-Datenbank in `wp-config.php` gesetzt werden:

`define( 'LEXWARE_CONNECTOR_API_KEY', 'DEIN_API_KEY' );`

Die Konstante hat Vorrang vor einem in WordPress gespeicherten Schlüssel.

== External Services ==

= Lexware Office Public API =

Das Plugin nutzt die Lexware Office Public API unter `https://api.lexware.io/v1/`. Diese externe Verbindung ist Kernfunktion des Plugins. Je nach ausgeführter Aktion werden insbesondere Kundenname, Rechnungs-/Lieferadresse, E-Mail-Adresse, Bestellpositionen, Preise, Steuern, Versand-/Gebührenpositionen und Bestellreferenzen an Lexware übertragen. Der API-Key wird zur Authentifizierung übermittelt.

Entwicklerportal: https://developers.lexware.io/
API-Dokumentation: https://developers.lexware.io/docs/
Public-API-Bedingungen: https://agb.lexware.de/lexware-office/public-api-lizenz--und-nutzungsbedingungen
Datenschutz: https://www.lexware.de/datenschutz/

Der Website-Betreiber ist für die korrekte Datenschutzinformation und die Einhaltung der anwendbaren Lexware-Bedingungen verantwortlich.

== Datenschutz ==

API-Logging ist bei Neuinstallationen standardmäßig deaktiviert. Bei aktiviertem Logging werden bekannte personenbezogene Felder maskiert und vollständige API-Antwortkörper nicht dauerhaft gespeichert.

== Kompatibilität ==

* WooCommerce HPOS wird deklariert.
* Bestelldaten werden über WooCommerce-CRUD verarbeitet.
* Action Scheduler wird verwendet, wenn verfügbar; WP-Cron dient als Fallback.
* Historische interne `WLC_*`- und `wlc_*`-Bezeichner bleiben aus Gründen der Abwärtskompatibilität erhalten.

== Frequently Asked Questions ==

= Benötige ich einen Lexware-Account? =

Ja. Ein aktiver Lexware-Account mit freigeschaltetem API-Zugang ist erforderlich.

= Welche Daten werden übertragen? =

Die für Kontakt- und Belegerstellung notwendigen Kunden-, Bestell-, Positions-, Preis-, Steuer- und Referenzdaten werden an die Lexware Office Public API übertragen.

= Werden Daten beim Löschen des Plugins entfernt? =

Standardmäßig nein. Eine vollständige Datenbereinigung bei Deinstallation muss ausdrücklich in den Einstellungen aktiviert werden.

== Changelog ==

= 2026.10.0 =
* Erstes stabiles öffentliches Release im neuen projektweiten Versionsschema `YYYY.M.PATCH`.
* Enthält den konsolidierten Funktions- und Sicherheitsstand aller bisherigen internen Vorabversionen bis einschließlich 1.3.6.
* Dokumentation vollständig vereinheitlicht und auf Deutsch aktualisiert.
* API-Schreibvorgänge, Queue-Sperren, Gutschriftlogik, HPOS-Pfade und geschützte Rechnungsdownloads erneut verifiziert.
* Buchhaltungs- und Queue-Daten bleiben bei Deinstallation standardmäßig erhalten; destruktive Bereinigung erfordert ein ausdrückliches Opt-in.

== Upgrade Notice ==

= 2026.10.0 =
Erstes stabiles Release der neuen öffentlichen Versionslinie. Bestehende Installationen aus der Vorabphase sollten vor dem Update gesichert und anschließend mit einem Testbeleg geprüft werden.
