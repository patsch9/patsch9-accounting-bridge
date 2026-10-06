# Migration

## Zielversion

Dieses Dokument beschreibt die Migration auf **2026.10.0**, das erste stabile Release im neuen öffentlichen Versionsschema.

## Von internen Vorabversionen

Die letzte interne Vorabversion dieser Plugin-Linie war `1.3.6`. `2026.10.0` verwendet denselben öffentlichen Plugin-Slug `patsch9-accounting-bridge`; bestehende Installationen können daher regulär aktualisiert werden.

1. Vollständiges Backup von Datenbank und `wp-content` erstellen.
2. API-Konfiguration und aktuellen Queue-Zustand dokumentieren.
3. Plugin auf `2026.10.0` aktualisieren.
4. Einstellungen, Trigger-Status und vorhandene Belegzuordnungen kontrollieren.
5. In einem Lexware-Testkonto oder Staging-System einen Testkontakt und einen Testbeleg erzeugen.
6. Rechnungsdownload, E-Mail-Integration sowie Queue-/Fehlerbehandlung prüfen.

Die interne Datenbankschema-Version ist ab `2026.10.0` ausdrücklich von der öffentlichen Plugin-Version getrennt. Vorhandene `WLC_*`-/`wlc_*`-Bezeichner, Queue-Daten und WooCommerce-Metadaten bleiben aus Gründen der Abwärtskompatibilität bestehen.

## Von älteren öffentlichen Namen/Ordnern

Falls noch ein älterer Plugin-Ordner vor `patsch9-accounting-bridge` verwendet wird, darf nicht gleichzeitig die alte und die neue Plugin-Datei aktiv sein.

1. Backup erstellen.
2. Alte Connector-Version deaktivieren.
3. Alten Plugin-Ordner aus `wp-content/plugins` entfernen oder außerhalb dieses Verzeichnisses sichern, **ohne die alte Uninstall-Routine auszuführen**.
4. `patsch9-accounting-bridge` installieren und aktivieren.
5. API-Key, Einstellungen, Queue und vorhandene Belegzuordnungen kontrollieren.

## Versionswechsel

Alle Versionen vor `2026.10.0` gehören zur Vorabphase. Ab `2026.10.0` gilt ausschließlich das in [VERSIONING.md](VERSIONING.md) dokumentierte Schema `YYYY.M.PATCH`.
