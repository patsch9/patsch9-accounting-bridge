# Sicherheitsrichtlinie

## Unterstützte Versionen

Sicherheitskorrekturen werden grundsätzlich für die jeweils aktuelle stabile Version bereitgestellt. Ältere Versionen sollten vor einer Meldung aktualisiert werden, sofern die ältere Version nicht zur Beschreibung eines Upgrade-Problems erforderlich ist.

## Sicherheitslücke melden

Bitte vermutete Sicherheitslücken nicht vor einer Korrektur als öffentliches GitHub-Issue veröffentlichen.

Bevorzugt ist GitHubs **Private Vulnerability Reporting / Security Advisory** des jeweiligen Repositories. Eine Meldung sollte mindestens enthalten:

- betroffene Plugin-Version;
- WordPress-, WooCommerce- und PHP-Version;
- nachvollziehbare Reproduktionsschritte;
- benötigte Benutzerrolle bzw. Authentifizierungsstatus;
- erwartetes und tatsächliches Verhalten;
- relevante Request-/Response-Informationen ohne Geheimnisse oder personenbezogene Produktivdaten.

Bitte niemals produktive API-Keys, Session-Cookies, Nonces, Kundendaten oder vollständige Datenbank-Dumps mitsenden.

## Grundsätze

Das Plugin folgt den WordPress-Sicherheitskonventionen: Berechtigungsprüfungen für privilegierte Aktionen, Nonces gegen CSRF wo anwendbar, Validierung und Sanitization bei Eingaben, kontextbezogenes spätes Escaping bei Ausgaben sowie vorbereitete Datenbankabfragen.

## Plugin-spezifische Hinweise

- Der Lexware API-Key ist ein Geheimnis und darf niemals in öffentlich erreichbare Ausgaben oder Logs gelangen. Für produktive Systeme kann er über `LEXWARE_CONNECTOR_API_KEY` in `wp-config.php` außerhalb der WordPress-Optionen hinterlegt werden.
- Schreibende API-Aufrufe werden bei einem unklaren Ergebnis nicht automatisch wiederholt. Dadurch soll verhindert werden, dass eine bereits erfolgreiche, aber lokal nicht bestätigte Anfrage einen doppelten Buchhaltungsbeleg erzeugt.
- Queue-Einträge verwenden Deduplizierung und atomare Claims, um parallele Doppelverarbeitung zu reduzieren.
- Rechnungsdownloads prüfen Nonce, Benutzerzuordnung und Bestellkontext.
