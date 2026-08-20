# Changelog

Alle wesentlichen Änderungen an A12-Teilchenbeschleuniger werden in dieser Datei dokumentiert. Die Versionsnummern folgen [Semantic Versioning](https://semver.org/lang/de/).

## [2.3.2] – 2026-08-20

### Hinzugefügt

- Direkter Link zum vollständigen Changelog auf der Admin-Seite **System & Updates**

## [2.3.1] – 2026-08-20

### Hinzugefügt

- Kompaktes Favicon aus dem runden A12-Logo für Browser-Tabs und Startbildschirm-Verknüpfungen

## [2.3.0] – 2026-08-20

### Hinzugefügt

- Admin-Schaltfläche **Update vorbereiten** ohne FTP-Upload
- Serverseitiger Download ausschließlich aus dem konfigurierten GitHub-Release-Kanal
- Prüfung von HTTPS-Ziel, Downloadgröße, GitHub-SHA-256 und interner Updater-Version
- Atomisches Ablegen von `updater.php` mit Wiederherstellung einer vorhandenen Datei bei Fehlern
- Manueller Download bleibt als Ausweichweg verfügbar

## [2.2.0] – 2026-08-20

### Hinzugefügt

- Öffentlicher GitHub-Release-Kanal für Installer und Updater
- Hintergrundprüfung auf neue Releases im Admin-Bereich
- Menüpunkt **System** mit Versionsstand, Release-Notizen und Updater-Download
- Hinweis-Banner und Statuspunkt, wenn eine neue Version verfügbar ist
- Sechs-Stunden-Cache und manuelle Updateprüfung mit Schutz vor zu häufigen GitHub-Anfragen
- GitHub Actions für Prüfung, Paketbau und automatische Releases
- README, Beitragsrichtlinien, Sicherheitsrichtlinie und Issue-Vorlagen

### Geändert

- Datenbankschema auf Version 3 erweitert
- Installer und Updater auf Version 2.2.0 angehoben
- Updater-Texte für allgemeine Versionsaktualisierungen überarbeitet

## [2.1.2] – 2026-08-19

### Behoben

- Abbrechen und Schließen in Pflichtfeld-Dialogen funktioniert ohne Browservalidierung
- Versionsabhängige Updater-Sperre erlaubt nachfolgende Updates

## [2.1.1] – 2026-08-19

### Behoben

- SQLite-Sperrkonflikt während der Rollen-Migration beseitigt
- Längere Wartezeit bei vorübergehend gesperrter Datenbank

## [2.1.0] – 2026-08-19

### Hinzugefügt

- Rollen Administrator, Lagerist und Mitglied
- Benutzerverwaltung für Administratoren
- Zuordnung von Bestandsbewegungen zu Benutzerkonten

## [2.0.0] – 2026-08-18

### Hinzugefügt

- PHP-Anwendung mit gemeinsamer SQLite-Datenbank
- Ein-Datei-Installer und Ein-Datei-Updater
- Anmeldung, Bestandshistorie, Export und geschützter Datenordner

## [1.0.0] – 2026-08-18

### Hinzugefügt

- Erster lokaler Oberflächenprototyp für die Bauteilverwaltung

[2.3.2]: https://github.com/DL1DRK/a12-teilchenbeschleuniger/releases/tag/v2.3.2
[2.3.1]: https://github.com/DL1DRK/a12-teilchenbeschleuniger/releases/tag/v2.3.1
[2.3.0]: https://github.com/DL1DRK/a12-teilchenbeschleuniger/releases/tag/v2.3.0
[2.2.0]: https://github.com/DL1DRK/a12-teilchenbeschleuniger/releases/tag/v2.2.0
[2.1.2]: https://github.com/DL1DRK/a12-teilchenbeschleuniger/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/DL1DRK/a12-teilchenbeschleuniger/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/DL1DRK/a12-teilchenbeschleuniger/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/DL1DRK/a12-teilchenbeschleuniger/releases/tag/v2.0.0
[1.0.0]: https://github.com/DL1DRK/a12-teilchenbeschleuniger/releases/tag/v1.0.0
