# Versionierung und Releases

Für neue Releases gilt der Git-Tag als einzige autoritative Release-Version. Versionsfelder im Quellstand müssen deshalb vor einem Release nicht mehr manuell auf die neue Nummer gesetzt werden.

## Schema

Erlaubt sind ausschließlich:

- Stable/Hauptrelease: `YYYY.MM.N`, z. B. `2026.11.1`
- Alpha: `YYYY.MM.N-alphaN`, z. B. `2026.11.2-alpha1`
- Beta: `YYYY.MM.N-betaN`, z. B. `2026.11.2-beta1`

Regeln:

- `YYYY` ist das vierstellige Jahr.
- `MM` ist der zweistellige Monat von `01` bis `12`.
- `N` ist die fortlaufende positive Release-Nummer und beginnt bei `1`.
- Alpha-/Beta-Zähler sind ebenfalls positiv und beginnen bei `1`.
- Neue Tags haben **kein** führendes `v`.
- `rc`, `-alpha.1`, `-beta.1` und andere Suffixe werden nicht akzeptiert.

Historische Releases und Tags bleiben unverändert; die neue Tag-Regel gilt für neue Veröffentlichungen.

## Tag als Versionsquelle

`.github/workflows/release.yml` übernimmt die Release-Version direkt aus dem Tag. Der Workflow schreibt die Version ausschließlich in die temporäre Release-Arbeitskopie und verändert `main` nicht.

Für jedes Release werden im gebauten Paket automatisch gesetzt:

1. `Version` im Plugin-Header.
2. `PATSACBR_VERSION`.
3. `Stable tag` in `readme.txt`.
4. Versionsnummer im ZIP-Dateinamen und in der SHA-256-Datei.
5. Titel und Versionsnummer des GitHub Releases.

Auch bei Alpha- und Beta-Releases entspricht `Stable tag` innerhalb des **GitHub-Prerelease-Pakets** der Tag-Version. Das ist notwendig, damit das Paket intern konsistent bleibt und der strikte WordPress Plugin Check keinen Metadatenfehler meldet. Diese Pakete werden niemals zu WordPress.org übertragen und verändern dort daher keinen Stable-Kanal.

Changelog-Inhalte bleiben redaktionelle Projektdokumentation und werden nicht als Versionsquelle für den Release-Build verwendet.

## Release-Ablauf

1. Release-fertigen Code über einen geprüften Pull Request nach `main` mergen.
2. Den gewünschten `main`-Commit taggen, z. B. `2026.11.1`, `2026.11.2-alpha1` oder `2026.11.2-beta1`.
3. Der Release-Workflow validiert das Tag-Schema und prüft, dass der Tag-Commit in `main` enthalten ist.
4. Aus `.distignore` wird eine saubere Release-Arbeitskopie erstellt.
5. Die Action injiziert die Tag-Version in Plugin-Header, `PATSACBR_VERSION` und `Stable tag`.
6. PHP-/JavaScript-Syntax und WordPress Plugin Check laufen auf genau diesem erzeugten Paket.
7. Erst nach erfolgreichen Prüfungen werden ZIP und SHA-256-Prüfsumme als GitHub Release veröffentlicht.
8. Alpha/Beta werden als GitHub Pre-release markiert und nicht als neuestes stabiles Release gesetzt.

Ein fehlgeschlagener Release-Tag wird nicht nachträglich auf einen anderen Commit verschoben. Nach einer Codekorrektur wird eine neue Release-Version verwendet.

## WordPress.org

Nur Stable-Tags im Format `YYYY.MM.N` dürfen nach WordPress.org veröffentlicht werden.

Der Workflow `.github/workflows/wordpress-org-deploy.yml` startet nach einem erfolgreichen Stable-Release automatisch oder kann für ein bestehendes Stable-Release manuell gestartet werden. Alpha- und Beta-Releases werden nicht deployt.

Vor dem SVN-Deploy wird geprüft:

1. Der Tag entspricht exakt `YYYY.MM.N` ohne Suffix.
2. Der Tag-Commit ist in `main` enthalten.
3. Das GitHub Release ist veröffentlicht, kein Draft und kein Pre-release.
4. ZIP und SHA-256-Datei werden direkt aus dem GitHub Release geladen und die Prüfsumme wird verifiziert.
5. **Die Versionsmetadaten werden im verifizierten Release-ZIP geprüft**, nicht im Git-Quellstand: Tag, Plugin-Header, `PATSACBR_VERSION` und `Stable tag` müssen identisch sein.
6. Genau dieses verifizierte Paket wird nach WordPress.org übertragen.

Damit kann ein Quellstand auf `main` weiterhin die zuletzt verwendete Entwicklungs-/Stable-Version enthalten; die tatsächlich veröffentlichte Version entsteht reproduzierbar aus dem Release-Tag.

## Interne Schema-Versionen

Datenbank- oder Speicherschema-Versionen sind von der öffentlichen Plugin-Version getrennt. Sie werden nur erhöht, wenn sich das persistierte Schema tatsächlich ändert.

## Sicherheit

- Actions bleiben auf Commit-SHAs gepinnt.
- Release-Tags müssen auf `main` zeigen.
- Das veröffentlichte Paket wird vor Veröffentlichung geprüft.
- WordPress.org erhält nur verifizierte Stable-Pakete.
- Vorabversionen können den WordPress.org-Stable-Kanal nicht auslösen.
