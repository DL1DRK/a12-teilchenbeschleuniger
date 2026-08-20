<p align="center">
  <img src="app/logo.png" alt="Logo A12-Teilchenbeschleuniger" width="260">
</p>

# A12-Teilchenbeschleuniger

Eine schlanke, selbst gehostete Bauteil- und Lagerbestandsverwaltung für den Apothekerschrank eines Amateurfunk-Clubs. Sie läuft mit PHP und einer lokalen SQLite-Datei – ein externer Datenbankserver ist nicht erforderlich.

## Funktionen

- Bauteile, Hersteller, Werte, Kategorien und Schubladen verwalten
- Bestände einlagern, entnehmen und lückenlos protokollieren
- Datenblatt-Link anzeigen oder automatisch eine passende PDF-Suche vorbereiten
- Rollen für Administrator, Lagerist und Mitglied
- DARC-Blau, responsive Oberfläche und Dark-Mode
- JSON-Export für Sicherungen und Weiterverarbeitung
- Hintergrundprüfung auf neue GitHub-Releases im Admin-Bereich
- Ein-Datei-Installer und Ein-Datei-Updater

## Installation

1. [`installer.php` aus dem neuesten Release herunterladen](https://github.com/DL1DRK/a12-teilchenbeschleuniger/releases/latest/download/installer.php).
2. Die Datei per FTP/SFTP in das gewünschte Webverzeichnis laden.
3. Die Datei im Browser öffnen, zum Beispiel `https://a12.example.org/installer.php`.
4. Installationsordner, Administratorname und ein Passwort mit mindestens zehn Zeichen eintragen.
5. **A12 installieren** wählen und über den angezeigten Link anmelden.

Der Installer enthält alle Laufzeitdateien und lädt während der Installation nichts nach. Er legt die gemeinsame SQLite-Datenbank möglichst außerhalb des öffentlichen Webordners ab. Ist das beim Hosting nicht möglich, wird ein zufällig benannter, zusätzlich geschützter Datenordner verwendet.

### Servervoraussetzungen

- PHP 8.1 oder neuer
- PHP-Erweiterungen `PDO`, `pdo_sqlite` und Zlib
- PHP-Sessions und Passwortfunktionen
- Schreibzugriff im Installations- und Datenordner

Für die spätere Updateprüfung benötigt PHP zusätzlich cURL oder aktiviertes `allow_url_fopen`. Fehlt beides, funktioniert die Anwendung weiter; lediglich die automatische Prüfung ist nicht verfügbar.

## Rollen

| Rolle | Berechtigungen |
| --- | --- |
| Administrator | Voller Zugriff auf Bauteile, Bestände, Benutzer, Export und Updates |
| Lagerist | Bauteile anlegen sowie Bestände ein- und auslagern |
| Mitglied | Bestand ansehen und Bauteile entnehmen; Rücklagerung über Lagerist oder Administrator |

Administratoren verwalten Konten nach der Anmeldung unter **Benutzer**. Der letzte aktive Administrator und das eigene Administratorkonto sind vor versehentlicher Deaktivierung geschützt.

## Updates

Die Anwendung prüft für Administratoren höchstens alle sechs Stunden das neueste GitHub-Release. Ist eine neuere Version vorhanden, erscheint ein Hinweis im Kopfbereich und unter **System → GitHub Updates** ein Download-Link.

1. Als Administrator anmelden.
2. Den angebotenen `updater.php` herunterladen.
3. Die Datei in denselben Webordner wie `index.php` und `config.php` laden.
4. `https://a12.example.org/updater.php` öffnen und **Update installieren** wählen.

Der Updater migriert die SQLite-Datenbank transaktional und ersetzt nur Anwendungsdateien. Bauteile, Bestände, Bewegungen, Benutzer und Passwort-Hashes bleiben erhalten. Vor jedem Update empfiehlt sich trotzdem eine Sicherung der SQLite-Datei.

## Datensicherung und Sicherheit

- Das Download-Symbol in der Bestandsansicht erzeugt einen vollständigen JSON-Export.
- Sichern Sie zusätzlich regelmäßig den vom Installer angezeigten privaten Datenordner.
- Sessions, Passwort-Hashing, CSRF-Schutz und transaktionale Bestandsänderungen sind integriert.
- Das Bewegungsjournal bleibt auch nach dem Löschen eines Bauteils erhalten.
- Sicherheitsprobleme bitte nicht öffentlich melden; siehe [SECURITY.md](SECURITY.md).

## Entwicklung

Die auszuliefernde Anwendung liegt in `app/`. Die beiden Einzeldateien werden daraus gebaut:

```powershell
node .\tools\build-installer.mjs
node .\tools\build-updater.mjs
node .\tools\verify-installer.mjs
node .\tools\verify-updater.mjs
python .\tools\verify-schema.py
python .\tools\verify-migration.py
```

Eine neue Versionsnummer auf `main` startet den Release-Workflow. Er prüft das Projekt, erstellt den passenden Tag, baut `installer.php` und `updater.php` und hängt beide Dateien an ein GitHub-Release. Existiert das Release bereits, bleibt ein normaler Commit ohne neue Veröffentlichung. Details stehen in [CONTRIBUTING.md](CONTRIBUTING.md) und [CHANGELOG.md](CHANGELOG.md).

## Lizenz

Für dieses Repository wurde noch keine Open-Source-Lizenz festgelegt. Ohne ausdrückliche Lizenz bleiben alle Rechte beim Rechteinhaber.
