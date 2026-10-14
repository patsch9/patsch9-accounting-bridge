# Dokumentation

- [README.md](README.md) – Projektübersicht und Bedien-/Integrationshinweise für GitHub.
- [readme.txt](readme.txt) – WordPress.org-kompatible Plugin-Beschreibung.
- [CHANGELOG.md](CHANGELOG.md) – Öffentlicher Änderungsverlauf.
- [VERSIONING.md](VERSIONING.md) – Verbindliches Versionsschema und Release-Regeln.
- [MIGRATION.md](MIGRATION.md) – Hinweise für Updates aus älteren Entwicklungsständen.
- [SECURITY.md](SECURITY.md) – Sicherheitsrichtlinie und Meldeweg.
- [LICENSE](LICENSE) – Lizenztext.
- [languages/README.txt](languages/README.txt) – Sprachdateien, WP-CLI-Befehle und WordPress.org-Übersetzungsablauf.


## Prüfungen der Korrekturen vom 12.10.2026

`tests/test-upgrade-locks.php` prüft Options-/Tabellenmigrationen mit gezielt ausgelösten Datenbankfehlern, Wiederaufnahme, Konflikterhalt und Bestellsperren mit einer zweiten echten Datenbankverbindung. Nur in der ausdrücklich freigegebenen wegwerfbaren CI-Testdatenbank ausführen. Externe API-Schreibvorgänge sind kein Bestandteil dieses Tests.
