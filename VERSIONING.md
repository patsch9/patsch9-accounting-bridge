# Versionierung

Beide Patsch9-WooCommerce-Plugins verwenden ab dem ersten stabilen öffentlichen Release dasselbe CalVer-Schema.

## Schema

`YYYY.M.PATCH`

- `YYYY`: Jahr des stabilen Releases.
- `M`: Monat des stabilen Releases ohne führende Null.
- `PATCH`: fortlaufende Release-Nummer innerhalb desselben Monats, beginnend bei `0`.

Beispiel: `2026.10.0` ist das erste stabile Release im Oktober 2026. Ein weiteres stabiles Release im selben Monat wäre `2026.10.1`. Das erste stabile Release im November 2026 wäre `2026.11.0`.

## Vorabversionen

Vorabversionen werden nur außerhalb des stabilen WordPress.org-Kanals verwendet:

- `2026.11.0-alpha.1`
- `2026.11.0-beta.1`
- `2026.11.0-rc.1`

Auf WordPress.org wird als `Stable tag` ausschließlich eine stabile numerische Version ohne Suffix verwendet.

## Release-Artefakte

Für ein stabiles Release müssen diese Angaben übereinstimmen:

1. `Version` im Plugin-Header.
2. öffentliche Versionskonstante des Plugins.
3. `Stable tag` in `readme.txt`.
4. Versionsnummer im aktuellen Eintrag von `CHANGELOG.md`.
5. Git-Tag `vYYYY.M.PATCH`.
6. Versionsnummer im ZIP-Dateinamen.

## Interne Schema-Versionen

Datenbank- oder Speicherschema-Versionen sind ausdrücklich von der öffentlichen Plugin-Version getrennt. Sie werden nur erhöht, wenn sich das persistierte Schema tatsächlich ändert. Eine reine Code-, Dokumentations- oder Sicherheitskorrektur erhöht daher nicht automatisch die interne Datenbankversion.

## Frühere Entwicklungsstände

Alle Versionsnummern vor `2026.10.0` gehören zur internen Vorabphase. Sie bleiben aus Gründen der Nachvollziehbarkeit in Git-Historie, Migrationen und technischen Kommentaren erhalten, sind aber nicht Teil des neuen öffentlichen Release-Schemas.
