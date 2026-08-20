# Guardian

*[🇬🇧 Read this in English](README.en.md)*

**Update-, Backup- & Recovery-Bundle für Contao 5** von [V&T Innovations](https://v-t.one)

Guardian hält deine Contao-Installation aktuell und sorgt dafür, dass du jederzeit zurück kannst — selbst wenn ein Update die Seite komplett zerlegt. Composer-Updates als Hintergrund-Job, automatische wie manuelle Backups und ein Standalone-Recovery-Panel, das auch dann noch funktioniert, wenn Contao selbst nicht mehr bootet.

## Status

Version 1.0.0, produktiv einsetzbar. Alle unten beschriebenen Funktionen sind vollständig implementiert (Backend-Controller, CLI-Hintergrund-Worker, Standalone-Recovery-Panel) und durch eine PHPUnit-Testsuite abgedeckt.

## Funktionsübersicht

| Funktion | Free | Pro |
|---|---|---|
| Manuelles Backup (DB, composer.json/lock, vendor/, templates/, files/, assets/) | ✅ | ✅ |
| Vorabprüfung (Pre-Update-Analyse) & Paketübersicht | ✅ | ✅ |
| Update-Jobs (Composer Full / Konservativ / Selektiv) | — | ✅ |
| Restore / Rollback aus Snapshots | — | ✅ |
| Geplante Backups (Mini + Full, Cron/Web-Cron) | — | ✅ |
| Standalone-Recovery-Panel | — | ✅ |
| Recovery-E-Mail-Benachrichtigungen | — | ✅ |

Ohne aktivierte Lizenz sind nur Dashboard und Einstellungen erreichbar — auch Free und Trial erfordern einen von V&T signierten Lizenzschlüssel. Lizenzen gibt es auf [v-t.one](https://v-t.one).

## Anforderungen

- PHP ≥ 8.2 (CLI-Binary erforderlich, Pfad im Backend konfigurierbar)
- Contao ≥ 5.3
- PHP-Erweiterungen `json` und `sodium` (Lizenzprüfung schlägt ohne `sodium` fehl-geschlossen fehl — siehe [Sicherheitsmodell](#sicherheitsmodell))
- `composer.phar` erreichbar (Projekt-Root, Plesk-Pfad oder konfiguriert)
- Für Datenbank-Backups/-Restores: `mysqldump`/`mysql`-CLI-Tools empfohlen (ein PHP-basierter Fallback greift, falls diese fehlen)
- Für Datei-Backups/-Restores: `tar` (ein `ZipArchive`-Fallback greift, falls `tar` nicht verfügbar ist)

## Installation

```bash
composer require vtinnovations/guardian
```

Der Contao Manager registriert das Bundle automatisch (`ContaoManager\Plugin`). Danach Cache leeren und Datenbank aktualisieren:

```bash
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
```

Der Menüpunkt **Guardian** erscheint im Contao-Backend unter „Inhalte" (zeigt die Anzahl verfügbarer Paket-Updates in Klammern an, sobald welche vorliegen).

## Erste Schritte

1. **Lizenz aktivieren:** Contao → Einstellungen → **Guardian Licence management** → Schlüssel eintragen → Einstellungen speichern. Der Schlüssel wird gegen den V&T-Lizenzserver geprüft; das signierte Ergebnis wird lokal abgelegt und an die konfigurierte(n) Domain(s) gebunden.
2. **PHP-CLI-Pfad setzen** (falls Auto-Erkennung fehlschlägt): Einstellungen-Tab → PHP-CLI-Einstellungen. Bei Plesk z. B. `/opt/plesk/php/8.3/bin/php`. Updates und Restores laufen als eigener Hintergrundprozess und brauchen diesen Pfad.
3. **Erstes Backup:** Backup-Tab → Komponenten wählen → „Backup jetzt erstellen".
4. **Recovery-Panel aktivieren** (Pro, empfohlen vor riskanten Updates) — siehe [Standalone-Recovery-Panel](#standalone-recovery-panel-pro).

## Backend-Oberfläche

Zugriff erfordert einen angemeldeten Contao-Administrator (`ROLE_ADMIN`). Die Oberfläche gliedert sich in fünf Tabs, in dieser Reihenfolge:

1. **Dashboard** — Statuskennzahlen (Contao-Version, installierte Pakete, verfügbare Backups), Status der letzten Operation, Pre-Update-Analyse, Paketübersicht.
2. **Update** *(Pro)* — Update-Job starten (Dry-Run oder echtes Update), Live-Fortschritt, Job-Historie.
3. **Backup** *(Free für manuelle Backups, Pro für geplante)* — Manuelles Backup, Liste vorhandener Backups, geplante Mini-/Full-Backups.
4. **Recovery** *(Pro)* — Standalone-Recovery-Panel-Einstellungen, Access-Token-Verwaltung.
5. **Einstellungen** — Link zur Lizenzverwaltung, Recovery-E-Mail-Konfiguration, PHP-CLI-Einstellungen.

Ohne Lizenz sind Update, Backup und Recovery gesperrt (nur Dashboard + Einstellungen). Mit einer Free-Lizenz ist zusätzlich das manuelle Backup nutzbar; Update, geplante Backups, Restore und das Recovery-Panel bleiben Pro vorbehalten.

## Vorabprüfung (Pre-Update-Analyse)

Rein lesend, verändert nichts. Prüft: PHP-Version (≥ 8.2), ob eine `composer`-Binary auffindbar ist, Schreibrechte auf `vendor/`, `var/` und `public/`, freien Speicherplatz (warnt unter 500 MB), verlassene („abandoned") Composer-Pakete, ob `DATABASE_URL` in `.env`/`.env.local` gesetzt ist, und ob Legacy-Contao-3-Module unter `system/modules/` existieren.

## Paketübersicht

Zeigt alle installierten Composer-Pakete und fragt auf Wunsch **direkt bei Packagist** die jeweils neueste stabile Version ab — unabhängig von den aktuellen `composer.json`-Constraints, damit auch Updates sichtbar werden, die die bestehenden Constraints (noch) nicht erlauben. Ergebnisse werden 24 Stunden gecacht; ein „Updates prüfen"-Button erzwingt eine frische Abfrage. Ob ein angezeigtes Update tatsächlich installierbar ist, hängt von Abhängigkeits-Constraints anderer Pakete ab (Tag „blockiert").

## Backup

Backups liegen unter `var/updater/backup/<Zeitstempel>` und werden **nie überschrieben** — kollidiert ein Zeitstempel, schlägt der Vorgang mit einer Fehlermeldung fehl, statt etwas zu ersetzen.

| Komponente | Enthalten | Hinweis |
|---|---|---|
| `composer.json` + `composer.lock` + Datenbank (gzip) | immer | klein, wenige MB |
| `vendor/` | Standard: an | 100–500 MB typisch |
| `templates/` + `contao/templates/` | Standard: an | eigene Twig/HTML5-Templates, klein |
| `files/` | Standard: aus | Uploads, kann sehr groß sein |
| `assets/` | Standard: aus | meist wieder aufbaubar |

Verzeichnisse werden per `tar` (gzip-komprimiert) archiviert; ist `tar`/`exec()` nicht verfügbar, springt ein `ZipArchive`-Fallback ein. Datenbank-Dumps laufen über `mysqldump`, mit einem PHP-basierten Fallback, falls das Tool fehlt. Schlägt `DATABASE_URL` nicht auf, wird nur der Datenbank-Teil als „übersprungen" markiert — das restliche Backup läuft trotzdem durch.

## Update-Modi

| Modus | Verhalten |
|---|---|
| **Full** | `composer update` mit allen Abhängigkeiten — alles innerhalb der composer.json-Constraints |
| **Konservativ** | Wie Full, zusätzlich `--prefer-stable`. Kein echter Patch-only-Modus im semantischen Sinn — für striktes Patch-only in `composer.json` selbst auf `~X.Y.Z` pinnen |
| **Selektiv** | Nur ausgewählte Pakete (+ deren Abhängigkeiten) |

Jeder echte Update-Job läuft in einem eigenen, vom Webserver losgelösten Hintergrundprozess:

```
Backup → Wartungsmodus AN → composer update → Cache leeren → Migrationen → Wartungsmodus AUS
```

Der Browser-Tab muss dafür nicht offen bleiben; Fortschritt und Live-Log werden über Polling nachgeladen. **Dry-Run** erstellt vorab ein echtes Backup (ohne `vendor/`) als Sicherheitsnetz und ruft anschließend `composer update --dry-run` sowie `contao:migrate --dry-run` real auf — der Cache-Leeren-Schritt wird dabei simuliert, nichts wird tatsächlich geleert.

Schlägt ein echtes Update fehl, steht der zuvor erstellte Pre-Update-Snapshot für einen Rollback per Klick bereit — dieser ist ein bewusster, manueller Schritt, kein automatischer Vorgang. Ob der Snapshot das `vendor/`-Verzeichnis enthält, hängt von der beim Start gewählten Option ab (Standard: enthalten); ohne `vendor/` im Snapshot umfasst ein Rollback nur Composer-Dateien und Datenbank. Der Restore-Vorgang selbst arbeitet die gewählten Komponenten nacheinander ab; bricht ein Schritt fehl, werden bereits abgeschlossene Schritte **nicht automatisch zurückgenommen** — in diesem Fall hilft das [Standalone-Recovery-Panel](#standalone-recovery-panel-pro) weiter.

Ein „Abbruch erzwingen"-Button markiert einen hängenden Job als abgebrochen, beendet aber nicht zwingend den dahinterliegenden Hintergrundprozess — nur verwenden, wenn der Job tatsächlich nicht mehr reagiert.

## Geplante Backups (Pro)

- **Mini-Backup:** nur Datenbank + Composer-Dateien — schnell, täglich sinnvoll
- **Full-Backup:** zusätzlich wählbar: vendor/, templates/, files/, assets/
- Frequenz (5-/15-minütig und stündlich für Tests, täglich/wöchentlich/monatlich für den Produktivbetrieb), Uhrzeit, Aufbewahrung (Retention) je Typ
- Ausführung über Contaos eingebautes Cron-System (Web-Cron ohne Einrichtung, oder echter Server-Cron für pünktliche Ausführung — Anleitung für Plesk/cPanel/DirectAdmin/SSH direkt im Backup-Tab)
- Ein Datei-Lock verhindert, dass zwei geplante bzw. manuell über den Zeitplan gestartete Läufe gleichzeitig laufen
- Optionale E-Mail-Benachrichtigung bei Erfolg/Fehlschlag

**Zur Aufbewahrung (Retention):** Sie entfernt ausschließlich ältere Backups **desselben Zeitplan-Typs** (Mini oder Full). Manuell über den Backup-Tab erstellte Backups sowie automatische Pre-Update-/Pre-Restore-Snapshots zählen nicht dazu und müssen bei Bedarf selbst gelöscht werden.

## Standalone-Recovery-Panel (Pro)

Eine einzelne PHP-Datei ohne Framework-Abhängigkeiten, die direkt vom Webserver ausgeliefert wird — funktioniert auch, wenn Symfony/Contao nach einem kaputten Update nicht mehr bootet. Kann Backups auflisten, Komponenten selektiv wiederherstellen (inkl. Datenbank-Import) und den Wartungsmodus steuern. `contao/templates/` lässt sich hier — anders als im regulären Restore im Backend — nicht getrennt von `templates/` wiederherstellen.

**Aus Sicherheitsgründen ist das Deployment opt-in.** In `.env.local`:

```
VTINNOVATIONS_GUARDIAN_DEPLOY_RECOVERY_PANEL=1
```

Danach Cache leeren — beim nächsten Kernel-Boot wird die Datei nach `public/` kopiert. Das Deployment ist zusätzlich an eine gültige Pro-Lizenz gekoppelt: Fehlt die Berechtigung oder wird das Flag entfernt, verschwindet die Datei beim nächsten Boot automatisch wieder aus dem Webroot.

- **Dateiname konfigurierbar** (Recovery-Tab): Standard `_updater-recovery.php`, ein eigener Name erschwert Scannern das Auffinden. Beim Umbenennen wird die alte Datei automatisch entfernt.
- **Authentifizierung:** HTTP Basic Auth (beliebiger Benutzername, Token als Passwort) oder `Authorization: Bearer`. Ein Token im URL-Query-String wird bewusst nicht akzeptiert (Leak-Risiko über Logs/Referer). Token-Quellen in dieser Reihenfolge: `VTINNOVATIONS_GUARDIAN_TOKEN` in `.env.local` (bevorzugt) oder ein automatisch generierter 96-stelliger Hex-Token in `var/updater/access.token`, rotierbar im Backend.
- **Brute-Force-Schutz:** 8 Fehlversuche je IP-Adresse innerhalb von 15 Minuten → 15 Minuten Sperre. Bei Zugriffen über einen gemeinsamen Proxy/CDN mit fester Absender-IP teilen sich alle dahinterliegenden Clients dasselbe Sperr-Kontingent.
- **Verzeichnis-Traversal-Schutz:** Archiveinträge werden vor dem Entpacken geprüft (keine absoluten Pfade, kein `..`, kein Verlassen des Zielverzeichnisses).
- **Empfehlung:** Panel nur für die Dauer riskanter Updates aktivieren und zusätzlich auf Webserver-Ebene schützen (IP-Allowlist / HTTP-Auth). **Teste den Zugriff vor einem Update** — im Ernstfall lässt sich das nicht mehr nachholen.
- **Recovery-E-Mail:** Vor dem Start eines echten Updates kann Guardian die Panel-URL und den vollständigen Access-Token an eine konfigurierte Adresse mailen. Schlägt der Versand fehl, wird das Update aus Sicherheitsgründen **nicht gestartet**. Diese E-Mail ist gleichwertig zu einem vollen Zugriffsschlüssel auf die Seite — nach erfolgreichem Update löschen, nicht weiterleiten.

## Lizenzierung

Zentrale Oberfläche: **Contao → Einstellungen → Guardian Licence management** (server-gerendert, innerhalb von Contaos eigenem Einstellungen-Formular, ohne eigene Route). Drei Buttons: „Lizenz prüfen & aktivieren", „Lizenz aktualisieren", „Lizenz entfernen" — Letzterer fragt per Bestätigungsdialog nach.

- Prüfung gegen `https://www.v-t.one/api/v1/verify`, Produkt `vt-guardian`, Projekt-Slug `guardian`.
- **Jede** Stufe (Trial, Free, Pro) benötigt einen aktivierten, signierten Lizenzschlüssel. Es gibt keinen anonymen Free-Modus und keine lokal startbare Testphase — Laufzeiten kommen ausschließlich aus signierten Server-Daten und lassen sich durch Neuinstallation, Cache- oder Dateilöschung nicht zurücksetzen.
- Der Lizenzdatensatz wird als exakte Bytes unter `var/updater/registration.json` abgelegt, zusammen mit dem signierten Integritäts-Envelope (`registration.seal`) und der gebundenen Domain (`registration.scope`). Jede Änderung an einer dieser Dateien führt beim nächsten Lesen zu „unlizenziert" — es gibt keinen Lesepfad an der Signaturprüfung vorbei.
- Bindung erfolgt exakt an Hostnamen aus den auf den Website-Root-Seiten konfigurierten Domains (signiert). Kein Wildcard, kein Suffix-Matching, kein automatisches `www`/Apex-Äquivalent. Für Installationen ohne gesetzte Root-Domain lässt sich die Domain-Inventur über `VTINNOVATIONS_GUARDIAN_DOMAINS` (Komma-Liste) ergänzen.
- Netzwerkfehler, Timeouts und 5xx **löschen niemals** eine gültige Lizenz. Nur eine erfolgreich verifizierte Antwort oder eine ausdrückliche Entfernung durch den Administrator ändern den Zustand.
- Signaturen: Ed25519, gepinnter Schlüssel `vtone-2026a`. Ohne PHP-`sodium` kann nichts verifiziert werden — dann bleibt die Installation unlizenziert (fail closed).

### Effektive Lizenzzustände

| Zustand | Verfügbare Funktionen |
|---|---|
| Keine Lizenz aktiviert | Nur Dashboard und Einstellungen |
| Trial aktiv | Voller Funktionsumfang (zeitlich befristet) |
| Free aktiv | Nur manuelles Backup |
| Pro aktiv | Voller Funktionsumfang |
| Pro abgelaufen, Free-Fallback erlaubt | Nur manuelles Backup — ausschließlich, wenn der signierte Datensatz das ausdrücklich zulässt |
| Lizenz abgelaufen (ohne Fallback) | Alle lizenzierten Funktionen deaktiviert |
| Lizenz noch nicht gültig | Alle lizenzierten Funktionen deaktiviert |
| Domain nicht in der Lizenz enthalten | Alle lizenzierten Funktionen deaktiviert |
| Lizenz vom Server zurückgezogen / Lizenzpaket nicht unterstützt / ungültig | Alle lizenzierten Funktionen deaktiviert |

Trial und Pro sind entitlement-technisch identisch (voller Funktionsumfang); der Unterschied liegt ausschließlich in der zeitlichen Befristung des signierten Datensatzes.

### Server-initiierte Updates

V&T kann ein neues Lizenzpaket aktiv zustellen an:

```
POST /rest/api/v1/guardian-license-updater
```

Der Endpunkt liegt bewusst außerhalb des Backend-Logins (Server-zu-Server) und ist stattdessen kryptografisch authentifiziert: signierte Methode, Pfad, Request-ID, Timestamp, Nonce und Body-Hash. Wiedereinspielungen werden über ein Replay-Ledger (`var/updater/exchange.journal`) abgewehrt; ein exakter Retry liefert `already_processed`, ältere Versionen werden abgelehnt.

> **Mehrknoten-Betrieb:** Ledger und Lizenzzustand liegen im Dateisystem unter `var/`. Für mehrere Knoten ohne gemeinsames `var/` ist ein transaktionaler, geteilter Speicher erforderlich.

### Signale an v-t.one

- pro Backend-Request höchstens einmal: `{"project":"Guardian","domain":"<host>"}`
- einmal pro authentifizierter Backend-Session beim ersten Öffnen der Lizenzverwaltung: `{"domain":"<host>","key":"<schlüssel>"}`

Beide gehen ausschließlich server-seitig an `https://www.v-t.one/rest/api/v1/log-envoke`, nach dem Senden der Antwort an den Browser — sie verzögern keine Seite. Der Schlüssel verlässt den Server nur in diesem einen Signal und in der Aktivierung/Aktualisierung — nie an den Browser, nie in Logs. Beide Signale sind best-effort: Ihr Erfolg oder Misserfolg hat keinerlei Einfluss auf die Lizenzprüfung.

## Architektur (Kurzüberblick)

```
src/
├── Guardian.php                  Bundle-Klasse; deployt/entfernt Recovery-Panel beim Boot
├── ContaoManager/Plugin.php      Contao-Manager-Integration (Bundle + Routen)
├── Controller/                   Backend-API (Jobs, Backups, Schedule, Panel, Runtime) + Registrierungs-Aktionen
├── Job/                          UpdateJob, JobRunner, Steps (Backup, Composer, Migrate, …)
├── Backup/  Restore/             BackupManager / RestoreManager
├── Schedule/  Cron/              Geplante Backups (Config, State, Lock, Runner, Evaluator) + Contao-Cronjob
├── Checker/                      Pre-Update-Checks, Paket-Verifikation, Vertrauensanker der Lizenzprüfung
├── External/                     Endpunkte, Registry-Transport, Signale, Replay-Ledger, Panel-Token-Verwaltung
├── Security/                     Admin-Gate, CSRF, Request-Authentifizierung
├── Service/                      Laufzeitkonfiguration, Plattformprüfung, Registrierungszustand, Paketübersicht, …
├── EventListener/                Backend-Menü, Systemmeldungen, DCA-Panel, Nutzungssignal-Auslieferung
├── Notifier/                     Backup- & Recovery-E-Mails
└── Command/RunJobCommand.php     CLI-Worker (`guardian:run-job`, intern; auch manuell für Diagnosezwecke aufrufbar)

contao/dca/tl_settings.php        Lizenz-Abschnitt in Contao → Einstellungen
public/_updater-recovery.php      Standalone-Recovery-Panel (zero dependencies)
templates/backend/…twig           Backend-Oberfläche (Tabs: Dashboard, Update, Backup, Recovery, Einstellungen)
```

Arbeitsdaten liegen unter `var/updater/` (Jobs, Logs, Backups, Laufzeitkonfiguration, Registrierungszustand, Replay-Ledger, Recovery-Token, Zeitplan-Zustand, Backup-Lock).

## Sicherheitsmodell

- Alle Backend-Endpunkte erfordern einen **Contao-Admin** (`ROLE_ADMIN`).
- Same-Origin-CSRF-Check (Origin, mit Referer-Fallback) für alle schreibenden Requests; der Updater-Endpunkt ist davon ausgenommen und stattdessen signaturbasiert authentifiziert.
- Lizenz-Gates werden serverseitig an jeder Funktionsgrenze durchgesetzt — inkl. CLI-Worker, Cron-Runner, Recovery-Mails und Panel-Deployment beim Bundle-Boot. UI-Sperren sind nur Komfort.
- Keine privaten Signaturschlüssel und keine wiederverwendbaren Shared Secrets im Paket; ausschließlich der öffentliche Verifikationsschlüssel ist eingebettet.
- Lizenzschlüssel, Payloads, Digests, Signaturen und Nonces erscheinen nie in Logs oder Browser-Antworten (durch eine dedizierte Testsuite strukturell abgesichert).
- Composer läuft immer als `<konfiguriertes PHP> composer.phar` — nie über Shell-Wrapper (verhindert Extension-Mismatch zwischen CLI- und Web-PHP, typisch bei Plesk).
- Datenbank-Passwörter werden über die `MYSQL_PWD`-Umgebungsvariable übergeben, nie als Kommandozeilen-Argument.
- Sowohl der reguläre Restore als auch das Standalone-Recovery-Panel validieren jeden Archiv-Eintrag vor dem Entpacken (kein Zip-Slip, kein Ausbruch aus dem Zielordner).
- Restore/Rollback ist **best-effort**, nicht transaktional: Die Komponenten werden nacheinander wiederhergestellt; bricht ein Schritt ab, wird kein bereits abgeschlossener Schritt automatisch zurückgenommen.

## Laufzeitverzeichnisse

Alles unter `var/updater/`, unter anderem:

- `registration.json` / `.seal` / `.scope` — Lizenzdatensatz, Integritäts-Envelope, gebundene Domain
- `exchange.journal` — Replay-Ledger für server-initiierte Updates
- `job.json` / `job.log` — aktueller Update-/Restore-Job und dessen Live-Log
- `backup/<Zeitstempel>/` — Backup-Archive und Manifest
- `access.token` — Access-Token des Standalone-Recovery-Panels (nur, falls nicht über `.env` gesetzt)
- `runtime.json` — PHP-CLI-Pfad, Recovery-Panel-Dateiname und weitere Laufzeiteinstellungen

## Protokollierung

Wichtige Ereignisse aus Updates und geplanten Backups werden zusätzlich in Contaos Systemlog geschrieben (System → Systemlog, Aktion `VTINNOVATIONS_GUARDIAN`). Sicherheitsrelevante Werte (Lizenzschlüssel, Signaturen, Tokens, Nonces) werden dabei nie mitgeschrieben.

## Deployment

Standard-Deployment eines Contao-Bundles über Composer (siehe [Installation](#installation)). Das Standalone-Recovery-Panel ist ein separater, expliziter Opt-in-Schritt (siehe oben) und wird bewusst nicht automatisch mitinstalliert.

## Cache leeren

```bash
vendor/bin/contao-console cache:clear
```

## Tests

Die PHPUnit-Testsuite (`phpunit.xml.dist`, Suite „Guardian") deckt `src/` über `tests/Audit`, `tests/Checker`, `tests/Controller`, `tests/External`, `tests/Security` und `tests/Service` ab, unter anderem strukturelle Prüfungen gegen versehentliches Logging sensibler Daten und gegen eine gemeinsame „ein Schalter schaltet alles frei"-Schwachstelle in der Lizenzprüfung. Test- und Konfigurationsdateien werden über `.gitattributes` (`export-ignore`) aus dem verteilten Composer-Paket ausgeschlossen.

```bash
vendor/bin/phpunit
```

## Fehlerbehebung

- **Update/Restore startet nicht, Worker-Fehler:** PHP-CLI-Pfad unter Einstellungen → PHP-CLI-Einstellungen prüfen und ggf. manuell setzen (z. B. `/opt/plesk/php/8.4/bin/php` bei Plesk).
- **Recovery-E-Mail schlägt fehl, Update startet nicht:** Mail-Konfiguration unter Einstellungen → Recovery-E-Mail korrigieren, oder die Option „Recovery-URLs per E-Mail senden" im Update-Dialog für diesen Lauf abwählen.
- **Datenbank-Backup wird übersprungen:** `DATABASE_URL` fehlt in `.env`/`.env.local` — die Pre-Update-Analyse zeigt das als Warnung an.
- **Ein Job wirkt hängen geblieben:** Erst „Stale-Job aufräumen" versuchen (erkennt automatisch beendete/hängende Worker); „Abbruch erzwingen" nur nutzen, wenn sicher ist, dass der Worker wirklich nicht mehr reagiert — der zugrundeliegende Prozess wird dadurch nicht zwingend beendet.
- **Standalone-Recovery-Panel nicht erreichbar:** Deployment-Flag und Lizenz-Berechtigung prüfen (siehe [Standalone-Recovery-Panel](#standalone-recovery-panel-pro)); nach Änderungen Cache leeren, damit der nächste Boot das Panel neu ausliefert.

## Bekannte Einschränkungen

- Restore/Rollback ist sequentiell und best-effort, nicht transaktional — ein fehlgeschlagener Schritt nimmt bereits abgeschlossene Schritte nicht automatisch zurück.
- Rollback nach einem fehlgeschlagenen Update ist ein manueller Schritt (Ein-Klick-Button), kein automatischer Vorgang.
- Der „Konservativ"-Update-Modus ist kein echter Patch-only-Modus im semantischen Sinn (`composer update --prefer-stable` innerhalb bestehender Constraints, keine Beschränkung auf Patch-Versionen).
- Wartungsmodus wird bei einem Restore nur automatisch aktiviert, wenn `vendor/` Teil der wiederherzustellenden Komponenten ist.
- Die Aufbewahrung (Retention) geplanter Backups bereinigt ausschließlich Backups desselben Zeitplan-Typs; manuelle Backups und Pre-Update-/Pre-Restore-Snapshots müssen bei Bedarf selbst gelöscht werden.
- „Abbruch erzwingen" markiert einen Job als abgebrochen, beendet aber nicht zwingend den zugehörigen Hintergrundprozess.
- Das Standalone-Recovery-Panel kann `contao/templates/` nicht getrennt von `templates/` wiederherstellen; der reguläre Restore im Backend unterstützt beide getrennt.
- Für Mehrknoten-Betrieb ohne gemeinsames `var/`-Verzeichnis ist die Registrierungs-, Job- und Replay-Ablage nicht ausgelegt.

## Lizenz

LGPL-3.0-or-later · © V&T Innovations

---

*[🇬🇧 English version: README.en.md](README.en.md)*
