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

## Automatisierte GitHub-Releases

### Dauerhaft benötigte Workflows

| Workflow | Auslöser | Aufgabe |
| --- | --- | --- |
| `static-checks.yml` | PRs nach `main`, Pushes nach `main` | PHP 8.2/8.3/8.4, JavaScript, Übersetzungskataloge und WordPress Plugin Check. |
| `release.yml` | `v*`-Tags; PRs mit Änderungen an Release-Workflow oder `.distignore` | Installierbares ZIP prüfen; ausschließlich Tag-Läufe veröffentlichen ein GitHub Release. |
| `wordpress-org-deploy.yml` | Erfolgreicher Release-Tag-Lauf; manuell mit expliziter Tag-Eingabe | Verifiziertes stabiles GitHub-Release nach WordPress.org übertragen. |

Dependabot bleibt für wöchentliche Updates der verwendeten Actions aktiv. Die
Updates werden in einem gemeinsamen PR gebündelt; sämtliche vorgeschriebenen
Prüfungen laufen weiterhin. Action-Versionen bleiben auf Commit-SHAs fixiert.

Feature-Branches werden durch den PR geprüft. Dadurch laufen identische Prüfungen
nicht zusätzlich für jeden Branch-Push. Tag-Pushes starten nur den Release-Bau;
dieser prüft das tatsächlich ausgelieferte Paket. Neuere PR-Commits ersetzen noch
laufende ältere Prüfungen. Veröffentlichungs- und Deploy-Läufe werden nicht
automatisch wegen neuerer Commits abgebrochen.

Die Job-Namen `syntax (8.2)`, `syntax (8.3)`, `syntax (8.4)` und
`wordpress-plugin-check` bleiben unverändert, da die Repository-Regeln sie für
`main` verlangen. Die PR-Prüfung hat deshalb keine Dateipfadfilter, die erforderliche
Checks dauerhaft auf „Pending“ halten könnten.

### Historische einmalige Workflows

Stand der Prüfung: 2026-10-12. Folgende Dateien sind bereits aus `main` entfernt,
werden aber von GitHub noch als historische Workflow-Einträge geführt:

- `apply-historical-reconciliation.yml`
- `apply-plugin-check-fixes.yml`
- `build-reconciliation-test-package.yml`
- `fix-reconciliation-race.yml`
- `fix-release-db-warnings.yml`
- `harden-reconciliation-restart.yml`
- `prepare-reconciliation-control-version.yml`
- `tune-historical-reconciliation.yml`
- `wporg-review-fix.yml`

Sie sind für Entwicklung, Release und Deployment nicht mehr erforderlich. Ihre
Workflow-Dateien dürfen nicht für neue Änderungen reaktiviert werden. Künftige
Codekorrekturen erfolgen über reguläre Branches und PRs.

Der Zustand „active“ in GitHubs historischem Workflow-Verzeichnis beweist nicht,
dass eine entsprechende Datei noch in `main` vorhanden ist. Eine zusätzliche
Deaktivierung ist über GitHubs Workflow-Verwaltung möglich. Die Laufhistorie wird
bei dieser Bereinigung erhalten; ihr Löschen ist ein eigener, nicht rückgängig
zu machender Verwaltungsschritt.

Referenzen:
- https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax
- https://docs.github.com/en/actions/how-tos/manage-workflow-runs/disable-and-enable-workflows

### Ablauf

GitHub Releases werden durch `.github/workflows/release.yml` automatisch aus Git-Tags erzeugt.

1. Release-fertigen Code zuerst nach `main` mergen.
2. Einen Tag wie `v2026.10.0` auf den gewünschten `main`-Commit setzen und pushen.
3. Der Workflow prüft, dass der Tag auf `main` liegt und mit Plugin-Header sowie Versionskonstante übereinstimmt.
4. Bei stabilen Releases werden zusätzlich `Stable tag` und der Changelog-Eintrag geprüft.
5. Aus `.distignore` wird ein bereinigtes WordPress-Paket gebaut.
6. PHP-/JavaScript-Syntax und WordPress Plugin Check laufen auf dem tatsächlichen Paketinhalt.
7. Erst nach erfolgreichen Prüfungen werden ZIP und SHA-256-Prüfsumme als GitHub Release veröffentlicht.

Tags mit `-alpha.N`, `-beta.N` oder `-rc.N` werden automatisch als GitHub-Pre-Release markiert. Bei Vorabversionen bleibt der WordPress.org-`Stable tag` auf der letzten stabilen Version.

## WordPress.org-Veröffentlichung

WordPress.org wird ausschließlich als Release-Ziel verwendet. Der Quellcode und die Entwicklung bleiben in GitHub; in das WordPress.org-SVN werden nur fertig geprüfte stabile Releases übernommen.

Der Workflow `.github/workflows/wordpress-org-deploy.yml` akzeptiert ausschließlich stabile CalVer-Tags und prüft vor dem SVN-Commit:

1. Der Tag entspricht `vYYYY.M.PATCH` und enthält keinen Pre-Release-Suffix.
2. Der Tag zeigt auf einen Commit, der in `main` enthalten ist.
3. Zu dem Tag existiert ein veröffentlichtes GitHub Release, das weder Draft noch Pre-Release ist.
4. Git-Tag, Plugin-Header, `PATSACBR_VERSION` und `Stable tag` stimmen überein.
5. Das bereits vom Release-Workflow erzeugte ZIP und seine SHA-256-Prüfsumme werden von GitHub Releases geladen und verifiziert.
6. Genau der Inhalt dieses geprüften ZIPs wird als SVN-`trunk` und als Versions-Tag nach WordPress.org übertragen.

Für den SVN-Zugriff müssen in GitHub unter `Settings → Secrets and variables → Actions` die Repository-Secrets `SVN_USERNAME` und `SVN_PASSWORD` hinterlegt sein. `SVN_USERNAME` ist der WordPress.org-Benutzername; `SVN_PASSWORD` ist das separate WordPress.org-SVN-Passwort.

Nach einem erfolgreichen zukünftigen stabilen GitHub-Release wird der WordPress.org-Deploy automatisch angestoßen. Bereits vorhandene Releases können über `Actions → Deploy to WordPress.org → Run workflow` mit ihrem bestehenden Tag manuell veröffentlicht werden.

WordPress.org-spezifische Bilder wie Icons, Banner und Screenshots gehören im GitHub-Repository nach `.wordpress-org/`. Dieser Ordner wird nicht in das installierbare Plugin-ZIP aufgenommen, sondern vom Deploy-Workflow in das SVN-`assets/`-Verzeichnis übertragen.

## Interne Schema-Versionen

Datenbank- oder Speicherschema-Versionen sind ausdrücklich von der öffentlichen Plugin-Version getrennt. Sie werden nur erhöht, wenn sich das persistierte Schema tatsächlich ändert. Eine reine Code-, Dokumentations- oder Sicherheitskorrektur erhöht daher nicht automatisch die interne Datenbankversion.

## Frühere Entwicklungsstände

Alle Versionsnummern vor `2026.10.0` gehören zur internen Vorabphase. Sie bleiben aus Gründen der Nachvollziehbarkeit in Git-Historie, Migrationen und technischen Kommentaren erhalten, sind aber nicht Teil des neuen öffentlichen Release-Schemas.
