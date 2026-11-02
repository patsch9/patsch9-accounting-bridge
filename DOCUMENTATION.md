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

## Release-Korrektur vom 14.10.2026

Der Tag-Lauf `v2026.10.6` stoppte wegen vier `WordPress.Security.NonceVerification.Missing`-Warnungen: Die Nonce-Prüfung lag außerhalb des Sperr-Callbacks, während dieser auf `$_POST` zugriff. Ab 2026.10.7 werden die Felder nach Prüfung im äußeren Scope gelesen und bereinigt. Bestellsperre und erneutes Laden der Bestelldaten bleiben erhalten. PRs nach `main` prüfen nun Quellcode und gebautes Release-Paket mit `strict: true`.

`tests/test-upgrade-locks.php` prüft zusätzlich fehlende, falsche und fremde Bestell-Nonces, fehlende Berechtigungen, Array-Eingaben, gültige und ungültige Verknüpfungen, Sperrkonflikte sowie den Erhalt bestehender Rechnungsdaten.


## Zahlungsarten-Auswahl

`PATSACBR_Payment_Policy` prüft die Optionen `patsacbr_payment_scope` (`all`/`selected`) und `patsacbr_payment_methods` (Gateway-IDs). Fehlen die Optionen, bleiben alle Zahlungsarten erlaubt. Die Settings API bereinigt beide Werte; Optionen werden bei vollständiger Deinstallation mit entfernt.

Prüfpunkte: Einreihen von Erstellungs-/Aktualisierungsaufträgen, erneute Prüfung im Worker vor Kontaktabgleich und Belegerstellung, vor der Gutschrift eines zu ersetzenden Belegs sowie direkt am API-Erstellungseinstieg. Gutschriften vorhandener Rechnungen umgehen die Auswahl bewusst. Manuelle Aktionen geben einen eindeutigen Fehler zurück; Sammelaktionen zählen nur tatsächlich eingereihte Aufträge.

`php tests/test-payment-policy.php`: 14 Assertions ohne externe API-Zugriffe, unter anderem Bestandskompatibilität, leere Auswahl, unbekannte Gateways, alte Queueaufträge, API-Direktaufruf und Gutschriften.
