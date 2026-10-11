Übersetzungen für Patsch9 Accounting Bridge

Originalsprache: Englisch (en_US).
Textdomain und WordPress.org-Slug: patsch9-accounting-bridge.
Mitgelieferte Übersetzung: Deutsch (de_DE), vollständig als PO/MO.

Dateien
- patsch9-accounting-bridge.pot: aus dem gesamten PHP-Quellcode generierte Vorlage.
- patsch9-accounting-bridge-de_DE.po: bearbeitbarer deutscher Katalog / GlotPress-Import.
- patsch9-accounting-bridge-de_DE.mo: kompilierter Katalog für WordPress.

Die Sprachdateien werden über WordPress ab init registriert. WordPress.org-
Sprachpakete haben Vorrang vor dem mitgelieferten Katalog. Englisch benötigt
keine Sprachdatei. JavaScript erhält übersetzte Meldungen über wp_localize_script;
es enthält keine eigenen gettext-Texte und benötigt deshalb keinen JSON-Katalog.

Bestehende Rechnungstitel, Einleitungen, Zahlungsbedingungen, Schlusstexte und
gespeicherte WooCommerce-E-Mail-Einstellungen werden nicht umgeschrieben.
Standardwerte für neue Installationen verwenden die aktuelle Website-Sprache.
Später gespeicherte Rechnungstexte sind Einstellungen, keine dynamischen
Übersetzungen. Bei einem Sprachwechsel können sie im Backend angepasst werden.

Reproduzierbare Pflege (im Repository-Hauptverzeichnis, WP-CLI 2.12.0)

  wp i18n make-pot . languages/patsch9-accounting-bridge.pot \
    --domain=patsch9-accounting-bridge --slug=patsch9-accounting-bridge \
    --exclude=.github,tests
  wp i18n update-po languages/patsch9-accounting-bridge.pot \
    languages/patsch9-accounting-bridge-de_DE.po

Neue/angepasste msgid-Texte in der PO-Datei übersetzen; Platzhalter unverändert
lassen, translator-Kommentare beachten und fuzzy-Markierungen nach Prüfung
entfernen. Anschließend kompilieren und prüfen:

  wp i18n make-mo languages/patsch9-accounting-bridge-de_DE.po
  msgfmt --check --check-format -o /dev/null languages/patsch9-accounting-bridge-de_DE.po
  python3 tests/check-translations.py

WordPress.org

https://translate.wordpress.org/projects/wp-plugins/patsch9-accounting-bridge/

Nach Veröffentlichung des neuen Releases erscheinen die englischen Texte in
Stable. Die deutsche PO-Datei kann dort importiert werden. Für ein offizielles
Sprachpaket müssen die Übersetzungen durch berechtigte Übersetzungsredakteure
freigegeben werden. Die mitgelieferte MO-Datei funktioniert bereits davor;
sie ändert den Übersetzungsstatus im WordPress.org-Verzeichnis nicht.

Die englische readme.txt wird separat unter Stable Readme übersetzt. Die deutsche
GitHub-README.md bleibt die Projekt- und Bedienungsdokumentation. Dieses PR
veröffentlicht kein Release und importiert keine Texte in GlotPress.

Referenzen
https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/
https://developer.wordpress.org/reference/functions/load_plugin_textdomain/
https://developer.wordpress.org/cli/commands/i18n/make-pot/
https://make.wordpress.org/plugins/2025/07/28/requiring-the-readme-to-be-written-in-english/
