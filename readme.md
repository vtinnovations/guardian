# Guardian

**Update-, Backup- & Recovery-Bundle für Contao 5** von [V&T Innovations](https://v-t.one)

Guardian hält deine Contao-Installation aktuell und sorgt dafür, dass du jederzeit zurück kannst — selbst wenn ein Update die Seite komplett zerlegt. Composer-Updates, automatische Backups und ein Standalone-Recovery-Panel, das auch dann noch funktioniert, wenn Contao selbst nicht mehr bootet.

## Funktionsübersicht

| Funktion | Free | Pro |
|---|---|---|
| Manuelles Backup (DB, composer.json/lock, vendor/, templates/, files/, assets/) | ✅ | ✅ |
| Update-Jobs (Composer full / patch / selektiv) | — | ✅ |
| Restore / Rollback aus Snapshots | — | ✅ |
| Geplante Backups (Mini + Full, Cron/Web-Cron) | — | ✅ |
| Standalone-Recovery-Panel | — | ✅ |
| E-Mail-Benachrichtigungen (Backup-Status, Recovery-Infos) | — | ✅ |

Ohne Lizenz sind nur Dashboard und Einstellungen erreichbar. Lizenzen (Free & Pro) gibt es auf [v-t.one](https://v-t.one).

## Anforderungen

- PHP ≥ 8.2 (CLI-Binary erforderlich, konfigurierbar)
- Contao ≥ 5.3
- `composer.phar` erreichbar (Projekt-Root, Plesk-Pfad oder konfiguriert)
- Für DB-Backups: `mysqldump`/`mysql` CLI-Tools
- Für Datei-Backups: `tar`

## Installation

```bash
composer require vtinnovations/guardian
```

Danach Cache leeren und Datenbank aktualisieren:

```bash
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
```

Der Menüpunkt **Guardian** erscheint im Contao-Backend unter „Inhalte".

## Erste Schritte

1. **Lizenz aktivieren:** Einstellungen → Pro-Lizenz → Schlüssel eintragen → „Lizenz prüfen & aktivieren". Der Schlüssel wird gegen den V&T-Lizenzserver geprüft und an die Domain gebunden.
2. **PHP-CLI-Pfad setzen** (falls Auto-Erkennung fehlschlägt): Einstellungen → PHP-CLI-Einstellungen. Bei Plesk z. B. `/opt/plesk/php/8.3/bin/php`.
3. **Erstes Backup:** Backup-Tab → Komponenten wählen → „Backup jetzt erstellen".
4. **Recovery-Panel aktivieren** (Pro, empfohlen vor riskanten Updates) — siehe unten.

## Update-Modi

| Modus | Verhalten |
|---|---|
| **Full** | `composer update` — alles innerhalb der composer.json-Constraints |
| **Patch** | `--prefer-stable`, konservativ innerhalb der Constraints |
| **Selektiv** | Nur ausgewählte Pakete (+ deren Abhängigkeiten) |

Jeder echte Update-Job läuft als Pipeline in einem Background-Worker:

```
Backup → Wartungsmodus AN → composer update → Cache leeren → Migrationen → Wartungsmodus AUS
```

Schlägt ein Update fehl, steht der Pre-Update-Snapshot für einen Ein-Klick-Rollback bereit.

## Geplante Backups (Pro)

- **Mini-Backup:** DB + composer-Dateien — schnell, täglich sinnvoll
- **Full-Backup:** zusätzlich vendor/, templates/, files/, assets/ (je Komponente wählbar)
- Frequenz (täglich/wöchentlich/monatlich), Uhrzeit, Aufbewahrung (Retention) pro Typ
- Ausführung über Contao-Cron; für verlässliche Zeiten echten Server-Cron oder Web-Cron einrichten (Anleitung im Backup-Tab für Plesk/cPanel/DirectAdmin/SSH)
- Optional E-Mail-Benachrichtigung bei Erfolg/Fehlschlag

## Standalone-Recovery-Panel (Pro)

Eine einzelne PHP-Datei ohne Framework-Abhängigkeiten, die direkt vom Webserver ausgeliefert wird — funktioniert auch, wenn Symfony/Contao nach einem kaputten Update nicht mehr bootet. Kann Backups auflisten, Komponenten selektiv wiederherstellen (inkl. DB-Import) und den Wartungsmodus steuern.

**Aus Sicherheitsgründen ist das Deployment opt-in.** In `.env.local`:

```
VTINNOVATIONS_GUARDIAN_DEPLOY_RECOVERY_PANEL=1
```

Danach Cache leeren — beim nächsten Kernel-Boot wird die Datei nach `public/` kopiert. Flag entfernen + Cache leeren löscht die Datei wieder automatisch.

- **Dateiname konfigurierbar** (Recovery-Tab): Standard `_updater-recovery.php`, eigener Name erschwert Scannern das Auffinden. Beim Umbenennen wird die alte Datei automatisch entfernt.
- **Authentifizierung:** HTTP Basic Auth (beliebiger Benutzername, Token als Passwort) oder `Authorization: Bearer`. Token-Quellen: `VTINNOVATIONS_GUARDIAN_TOKEN` in `.env.local` (bevorzugt) oder auto-generiert in `var/updater/access.token` (96 Zeichen, rotierbar im Backend).
- **Brute-Force-Schutz:** 8 Fehlversuche pro IP in 15 Minuten → 15 Minuten Sperre.
- **Empfehlung:** Panel nur für die Dauer riskanter Updates aktivieren und zusätzlich auf Webserver-Ebene schützen (IP-Allowlist / HTTP-Auth).
- **Recovery-E-Mail:** Vor einem Update kann Guardian die Panel-URL + Token an eine konfigurierte Adresse mailen — so kommst du auch dann ins Panel, wenn das Backend nicht mehr erreichbar ist. E-Mail nach erfolgreichem Update löschen.

## Lizenzierung

- Prüfung gegen den V&T-Lizenzserver (`v-t.one`), Produkt `vt-guardian`
- Schlüssel wird bei Aktivierung an die Domain gebunden
- Ergebnis wird lokal gecacht (`var/updater/license.json`); 7 Tage Grace bei Netzwerkausfall
- Free-Schlüssel schalten das Manuelle Backup frei, Pro-Schlüssel den vollen Funktionsumfang

## Architektur (Kurzüberblick)

```
src/
├── Guardian.php                  Bundle-Klasse; deployt/entfernt Recovery-Panel beim Boot
├── ContaoManager/Plugin.php      Contao-Manager-Integration (Bundle + Routen)
├── Controller/                   Backend-API (Jobs, Backups, Lizenz, Schedule, Panel, Runtime)
├── Job/                          UpdateJob, JobRunner, Steps (Backup, Composer, Migrate, …)
├── Backup/  Restore/             BackupManager / RestoreManager
├── Schedule/                     Geplante Backups (Config, State, Lock, Runner, Evaluator)
├── Security/                     Admin-Gate, CSRF, LicenseGuard/-Manager/-Verifier, PanelAuth
├── Service/                      RuntimeConfig, PlatformChecker, CompatibilityAnalyzer, …
├── Notifier/                     Backup- & Recovery-E-Mails
└── Command/RunJobCommand.php     CLI-Worker (guardian:run-job)

public/_updater-recovery.php      Standalone-Recovery-Panel (zero dependencies)
templates/backend/…twig           Backend-Oberfläche (Tabs: Dashboard, Update, Backup, Recovery, Einstellungen)
```

Arbeitsdaten liegen unter `var/updater/` (Jobs, Logs, Backups, Runtime-Config, Lizenz-Cache, Token).

## Sicherheit

- Alle Backend-Endpunkte erfordern einen **Contao-Admin** (`ROLE_ADMIN`)
- Same-Origin-CSRF-Check für alle schreibenden Requests
- Lizenz-Gates serverseitig durchgesetzt (UI-Sperren sind nur Komfort)
- Composer läuft immer als `<konfiguriertes PHP> composer.phar` — nie über Shell-Wrapper (verhindert Extension-Mismatch zwischen CLI- und Web-PHP, typisch bei Plesk)
- DB-Passwörter via `MYSQL_PWD`-Env, nie in argv
- tar-Restores validieren jeden Archiv-Eintrag (kein Zip-Slip, kein Ausbruch aus dem Zielordner)

## Lizenz

LGPL-3.0-or-later · © V&T Innovations
