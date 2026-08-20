# Mitwirken

Fehlerberichte und Verbesserungsvorschläge sind willkommen. Bitte zuerst prüfen, ob bereits ein passendes Issue existiert, und anschließend die jeweilige GitHub-Vorlage verwenden.

## Lokale Entwicklung

Benötigt werden Node.js, Python 3 und PHP 8.1 oder neuer mit PDO-SQLite. Die Laufzeitdateien liegen in `app/`; `installer.php` und `updater.php` werden nicht von Hand bearbeitet.

Vor einem Beitrag bitte ausführen:

```powershell
node .\tools\build-installer.mjs
node .\tools\build-updater.mjs
node .\tools\verify-installer.mjs
node .\tools\verify-updater.mjs
python .\tools\verify-schema.py
python .\tools\verify-migration.py
```

PHP- und JavaScript-Dateien sollten zusätzlich syntaktisch geprüft werden. Der CI-Workflow führt alle Prüfungen auf GitHub aus.

## Pull Requests

- Eine Änderung pro Pull Request beschreiben.
- Benutzeroberflächentexte auf Deutsch halten.
- Datenbankänderungen als aufwärtskompatible Migration ergänzen.
- Neue Versionen in `CHANGELOG.md`, `VERSION`, `app/bootstrap.php` sowie den Installer- und Updater-Vorlagen synchron halten.
- Keine Passwörter, Tokens, SQLite-Datenbanken oder erzeugten Konfigurationsdateien einchecken.

## Releases

Ein Maintainer setzt nach bestandener CI einen Tag `vX.Y.Z`. Der Release-Workflow baut daraufhin die beiden Einzeldateien und veröffentlicht sie als Release-Anhänge. Die Anwendung wertet ausschließlich das jeweils neueste GitHub-Release aus.
