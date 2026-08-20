<?php

declare(strict_types=1);

/*
 * Guardian
 *
 * Package: vtinnovations/guardian
 * Copyright: V&T Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://www.v-t.one
 */

// Backend dashboard UI strings (contao_guardian domain). Placeholders use
// Symfony's %name% convention so both PHP (Translator::trans) and the
// server-rendered JS i18n object can substitute the same way.

$lang = &$GLOBALS['TL_LANG'];

$lang['tabs']['dashboard'] = '📊 Dashboard';
$lang['tabs']['update']    = '🔄 Update';
$lang['tabs']['backup']    = '💾 Backup';
$lang['tabs']['recovery']  = '🛟 Recovery';
$lang['tabs']['settings']  = '⚙️ Einstellungen';

// ── Dashboard ────────────────────────────────────────────────────────
$lang['dashboard']['plan_badge_pro']  = '⭐ Pro-Paket aktiv';
$lang['dashboard']['plan_badge_free'] = '🆓 Free-Paket aktiv';
$lang['dashboard']['plan_badge_none'] = '🚫 Keine Lizenz';
$lang['dashboard']['plan_tagline_pro']  = 'Voller Funktionsumfang freigeschaltet — Updates, Restore/Recovery, geplante Backups und das Standalone-Recovery-Panel.';
$lang['dashboard']['plan_tagline_free'] = 'Free-Paket: <strong>Manuelles Backup</strong> verfügbar. Für Updates, Restore/Recovery, geplante Backups und Standalone-Recovery-Panel braucht es eine Pro-Lizenz.';
$lang['dashboard']['plan_tagline_none'] = 'Ohne Lizenz sind alle Funktionen gesperrt. Trage einen Lizenzschlüssel unter <strong>Contao → Einstellungen → Guardian Licence management</strong> ein. Free-Lizenzen schalten das Manuelle Backup frei, Pro-Lizenzen den vollen Funktionsumfang.';
$lang['dashboard']['feature_backup']   = 'Manuelles Backup';
$lang['dashboard']['feature_updates']  = 'Update-Jobs (Composer)';
$lang['dashboard']['feature_restore']  = 'Restore / Recovery';
$lang['dashboard']['feature_schedule'] = 'Geplante Backups (Mini + Full)';
$lang['dashboard']['feature_panel']    = 'Standalone-Recovery-Panel';
$lang['dashboard']['upgrade_to_pro']  = '⭐ Auf Pro upgraden';
$lang['dashboard']['enter_license']   = '🔑 Lizenz eintragen';
$lang['dashboard']['stat_current_version']    = 'Aktuelle Contao-Version';
$lang['dashboard']['stat_installed_packages'] = 'Installierte Pakete';
$lang['dashboard']['stat_available_backups']  = 'Verfügbare Backups';
$lang['dashboard']['status_title']       = 'Aktueller Status';
$lang['dashboard']['status_idle']        = '✓ Idle — keine Operation läuft';
$lang['dashboard']['status_running']     = '⏳ Läuft — Operation in Bearbeitung';
$lang['dashboard']['status_success']     = '✓ Letzte Operation erfolgreich';
$lang['dashboard']['status_error']       = '⚠️ Letzte Operation fehlgeschlagen';
$lang['dashboard']['status_idle_hint']   = 'Aktuell läuft keine Operation. Die nächste Aktion (Update, Backup oder Restore), die du startest, erscheint hier.';
$lang['dashboard']['status_updated_at']  = 'Letzte Aktualisierung: %date%';
$lang['dashboard']['analysis_title']     = 'Vorabprüfung (Pre-Update-Analyse)';
$lang['dashboard']['analysis_desc']      = 'Prüft die Voraussetzungen für ein Update — vollständig nur lesend, verändert nichts.';
$lang['dashboard']['analysis_start']     = '🔍 Analyse starten';
$lang['dashboard']['analysis_rerun']     = '🔍 Analyse erneut ausführen';
$lang['dashboard']['analysis_running']   = '⏳ Analyse läuft...';
$lang['dashboard']['analysis_wait']      = 'Bitte warten...';
$lang['dashboard']['analysis_summary']   = '✅ %ok% OK · ⚠️ %warnings% Warnungen · ❌ %errors% Fehler';
$lang['dashboard']['packages_title'] = 'Installierte Pakete &amp; verfügbare Updates';
$lang['dashboard']['packages_desc'] = 'Zeigt alle installierten Composer-Pakete. Klicke auf „Updates prüfen", um Packagist direkt nach '
    . 'den neuesten stabilen Versionen jedes Pakets abzufragen — unabhängig von den aktuellen composer.json-Constraints. '
    . '<br><strong>Hinweis:</strong> „Update verfügbar" bedeutet nur, dass auf Packagist eine neuere Version existiert. '
    . 'Ob sie tatsächlich installiert werden kann, hängt von Dependency-Constraints ab und erfordert unter Umständen, '
    . 'mehrere Pakete gemeinsam zu aktualisieren (z. B. bei einem Contao-Major-Upgrade). '
    . 'Die Ergebnisse werden 24 Stunden gecacht.';
$lang['dashboard']['packages_load']    = '📦 Pakete laden';
$lang['dashboard']['packages_refresh'] = '🔄 Updates prüfen';
$lang['dashboard']['packages_filter_placeholder'] = 'Nach Name filtern...';
$lang['dashboard']['packages_only_updates']       = 'Nur Pakete mit Updates anzeigen';
$lang['dashboard']['packages_loading']         = 'Installierte Pakete werden geladen...';
$lang['dashboard']['packages_refreshing']      = '⏳ Packagist wird abgefragt...';
$lang['dashboard']['packages_loading_short']   = '⏳ Lädt...';
$lang['dashboard']['packages_reload']          = '📦 Pakete neu laden';
$lang['dashboard']['packages_refresh_done']    = '🔄 Verfügbare Updates aktualisieren';
$lang['dashboard']['packages_no_match']        = 'Keine Pakete passen zum aktuellen Filter.';
$lang['dashboard']['packages_cached_note']     = ' (gecacht — für Refresh „Updates prüfen" klicken)';
$lang['dashboard']['packages_check_incomplete'] = '⚠️ Update-Prüfung unvollständig: %error%';
$lang['dashboard']['packages_meta'] = 'Gesamt: <strong>%total%</strong> Pakete · Updates verfügbar: <strong>%updates%</strong> · Abandoned: <strong>%abandoned%</strong>';
$lang['dashboard']['packages_th_package']   = 'Paket';
$lang['dashboard']['packages_th_current']   = 'Aktuell';
$lang['dashboard']['packages_th_available'] = 'Verfügbar';
$lang['dashboard']['packages_th_status']    = 'Status';
$lang['dashboard']['packages_blocked_title'] = 'Auf Packagist existiert eine neuere Version, die aber wegen Constraints in anderen Paketen '
    . '(z. B. Symfony-Versionsanforderungen) nicht installierbar ist. Contao zu updaten braucht meist ein Update von '
    . 'contao/manager-bundle, das alles nachzieht.';
$lang['dashboard']['packages_tag_blocked_short'] = '⚠ blockiert';
$lang['dashboard']['packages_tag_blocked']       = 'blockiert';
$lang['dashboard']['packages_tag_blocked_title'] = 'Update existiert, ist aber durch Constraints anderer Pakete blockiert';
$lang['dashboard']['packages_tag_update']        = 'Update';
$lang['dashboard']['packages_tag_uptodate']      = 'aktuell';
$lang['dashboard']['packages_tag_abandoned']     = 'abandoned';

// ── Backup tab ───────────────────────────────────────────────────────
$lang['backup']['title'] = 'Backups';
$lang['backup']['desc'] = 'Ein vollständiges Backup enthält <code>composer.json</code>, <code>composer.lock</code>, die Datenbank (gzip-komprimiert) '
    . 'sowie die Verzeichnisse <code>vendor/</code> und <code>templates/</code>. Backups liegen unter '
    . '<code>var/updater/backup/&lt;timestamp&gt;</code> und werden nie überschrieben.';
$lang['backup']['components_legend'] = 'Backup-Inhalte';
$lang['backup']['comp_core_title'] = 'composer.json + composer.lock + Datenbank';
$lang['backup']['comp_core_desc']  = 'Immer enthalten — klein (wenige MB).';
$lang['backup']['comp_vendor_title'] = '<strong>vendor/</strong>-Verzeichnis';
$lang['backup']['comp_vendor_desc']  = 'Composer-Abhängigkeiten. Empfohlen. Typischerweise 100–500 MB.';
$lang['backup']['comp_templates_title'] = '<strong>templates/</strong> + <strong>contao/templates/</strong>';
$lang['backup']['comp_templates_desc']  = 'Eigene Twig- und HTML5-Templates. Klein.';
$lang['backup']['comp_files_title']   = '<strong>files/</strong>-Verzeichnis';
$lang['backup']['comp_files_warning'] = '(kann sehr groß sein!)';
$lang['backup']['comp_files_desc']    = 'Uploads der Nutzer — Bilder, PDFs, Videos. Oft mehrere GB. Ohne dieses Verzeichnis kann ein Rollback '
    . 'zu inkonsistenten DB-Referenzen und fehlenden Mediendateien führen.';
$lang['backup']['comp_assets_title'] = '<strong>assets/</strong>-Verzeichnis';
$lang['backup']['comp_assets_desc']  = 'Generierte Bilder, Webfonts und JS/CSS-Asset-Cache. Meist wieder aufbaubar.';
$lang['backup']['create_now']  = '💾 Backup jetzt erstellen';
$lang['backup']['create_more'] = '💾 Weiteres Backup erstellen';
$lang['backup']['creating']    = 'Backup wird erstellt...';
$lang['backup']['running_wait'] = 'Backup läuft. Bitte warten — das läuft synchron, die Seite lädt erst neu, wenn es fertig ist.';
$lang['backup']['confirm']              = 'Backup jetzt erstellen?\n\nEs läuft synchron — lasse diesen Tab offen, bis es fertig ist.';
$lang['backup']['confirm_files_warning'] = '\n\n⚠️ files/ ist ausgewählt — das kann bei vielen Uploads mehrere Minuten dauern.';
$lang['backup']['failed']  = 'Backup fehlgeschlagen: %error%';
$lang['backup']['created'] = 'Backup erstellt: <strong>%name%</strong> · %size%';
$lang['backup']['row_database']    = 'Datenbank';
$lang['backup']['row_vendor']      = 'vendor/';
$lang['backup']['row_templates']   = 'templates/';
$lang['backup']['row_contao_tpl']  = 'contao/templates/';
$lang['backup']['row_files']       = 'files/';
$lang['backup']['row_assets']      = 'assets/';
$lang['backup']['empty'] = 'Noch keine Backups. Nutze „Backup jetzt erstellen" oder warte, bis vor dem nächsten Update automatisch eines angelegt wird.';
$lang['backup']['delete'] = 'Löschen';
$lang['backup']['delete_confirm'] = 'Backup „%name%" löschen?\nDas kann nicht rückgängig gemacht werden.';
$lang['backup']['delete_failed']  = 'Löschen fehlgeschlagen: %error%';

// ── Scheduled backups ────────────────────────────────────────────────
$lang['sched']['title'] = 'Geplante Backups';
$lang['sched']['lock_notice'] = '🔒 Das System für geplante Backups benötigt eine gültige <strong>Pro-Lizenz</strong>. '
    . 'Trage deinen Code unter <strong>Contao → Einstellungen → Guardian Licence management</strong> ein, um es freizuschalten. '
    . 'Manuelle Backups oben bleiben verfügbar.';
$lang['sched']['desc'] = 'Führt Backups automatisch über Contaos Cron-System aus. Konfiguriere zwei unabhängige Zeitpläne: '
    . '<strong>Mini</strong>-Backups (nur Datenbank + Composer-Dateien — schnell und klein) und '
    . '<strong>Full</strong>-Backups (Datenbank + ausgewählte Verzeichnisse — größer und langsamer). '
    . 'Alte Backups werden automatisch gelöscht, sobald das Retention-Limit erreicht ist.';
$lang['sched']['mini_title'] = '🗄️ Mini-Backup (nur DB)';
$lang['sched']['mini_desc']  = 'Nur die Datenbank und Composer-Dateien. Schnell (wenige Sekunden).';
$lang['sched']['mini_enable'] = 'Geplante Mini-Backups aktivieren';
$lang['sched']['full_title'] = '📦 Full-Backup';
$lang['sched']['full_desc']  = 'Datenbank + ausgewählte Verzeichnisse. Langsamer (Minuten bis zehn Minuten).';
$lang['sched']['full_enable'] = 'Geplante Full-Backups aktivieren';
$lang['sched']['frequency']       = 'Häufigkeit';
$lang['sched']['optgroup_test']   = '🧪 Testen (intervallbasiert)';
$lang['sched']['optgroup_prod']   = 'Produktion';
$lang['sched']['freq_5min']   = 'Alle 5 Minuten';
$lang['sched']['freq_15min']  = 'Alle 15 Minuten';
$lang['sched']['freq_hourly'] = 'Stündlich';
$lang['sched']['freq_daily']   = 'Täglich';
$lang['sched']['freq_weekly']  = 'Wöchentlich';
$lang['sched']['freq_monthly'] = 'Monatlich';
$lang['sched']['time']    = 'Uhrzeit';
$lang['sched']['weekday'] = 'Wochentag';
$lang['sched']['weekday_0'] = 'Sonntag';
$lang['sched']['weekday_1'] = 'Montag';
$lang['sched']['weekday_2'] = 'Dienstag';
$lang['sched']['weekday_3'] = 'Mittwoch';
$lang['sched']['weekday_4'] = 'Donnerstag';
$lang['sched']['weekday_5'] = 'Freitag';
$lang['sched']['weekday_6'] = 'Samstag';
$lang['sched']['day_of_month'] = 'Tag im Monat';
$lang['sched']['retention']      = 'Behalte letzte';
$lang['sched']['retention_unit'] = 'Backups';
$lang['sched']['run_mini_now'] = '▶ Mini jetzt ausführen';
$lang['sched']['run_full_now'] = '▶ Full jetzt ausführen';
$lang['sched']['full_components_legend'] = 'In Full-Backup einschließen:';
$lang['sched']['full_comp_vendor']    = 'vendor/';
$lang['sched']['full_comp_templates'] = 'templates/ + contao/templates/';
$lang['sched']['full_comp_files']     = 'files/';
$lang['sched']['full_comp_files_warn'] = '(kann riesig sein — riskant bei Web-Cron!)';
$lang['sched']['files_warning'] = '⚠️ <strong>Warnung:</strong> <code>files/</code> in geplanten Backups ohne echten Cron ist riskant. '
    . 'Beim Web-Cron läuft das Backup während eines Seitenaufrufs (im Hintergrund) — dauert es länger als '
    . 'ein paar Minuten und der Hoster killt den PHP-Worker, ist das Backup unvollständig. Teste einmal '
    . 'mit „▶ Full jetzt ausführen", um die Größe abzuschätzen, bevor du es planst.';
$lang['sched']['full_comp_assets'] = 'assets/';
$lang['sched']['storage_notify_title'] = 'Speicherort &amp; Benachrichtigungen';
$lang['sched']['storage_path']        = 'Speicherpfad';
$lang['sched']['storage_placeholder'] = '(Standard: var/updater/backup)';
$lang['sched']['storage_hint'] = 'Absoluter Pfad zur Ablage der Backups. Leer lassen für den Standard. '
    . 'Vermeide <code>vendor/</code>, <code>public/</code> und <code>files/</code>.';
$lang['sched']['recipient']             = 'Empfänger';
$lang['sched']['recipient_placeholder'] = 'admin@example.com';
$lang['sched']['sender_email']             = 'Absender-E-Mail';
$lang['sched']['sender_email_placeholder'] = '(optional — fällt auf Contao-Admin-E-Mail zurück)';
$lang['sched']['sender_name']             = 'Absender-Name';
$lang['sched']['sender_name_placeholder'] = 'Guardian';
$lang['sched']['sender_hint'] = 'Leer lassen, um Contaos <strong>System → Einstellungen → Administrator-E-Mail-Adresse</strong> zu nutzen. '
    . 'Nur überschreiben, wenn dein Hosting einen bestimmten Absender verlangt (z. B. eine im Plesk/cPanel '
    . 'eingerichtete Mailbox wie <code>noreply@deine-domain.de</code>) und du die Contao-Admin-E-Mail nicht ändern kannst.';
$lang['sched']['notify_success'] = 'Bei erfolgreichem Backup benachrichtigen';
$lang['sched']['notify_failure'] = 'Bei fehlgeschlagenem Backup benachrichtigen';
$lang['sched']['send_test']      = '✉️ Test-E-Mail jetzt senden';
$lang['sched']['send_test_hint'] = 'Speichert erst, dann wird eine Test-Mail mit den aktuellen Einstellungen versendet.';
$lang['sched']['save']        = '💾 Zeitplan speichern';
$lang['sched']['saving']      = 'Speichern...';
$lang['sched']['saved']       = '✅ Gespeichert';
$lang['sched']['save_failed'] = 'Speichern fehlgeschlagen';
$lang['sched']['run_now_confirm']   = 'Ein %type%-Backup jetzt ausführen?\n\nDies ignoriert den Zeitplan und stößt sofort ein Backup an.';
$lang['sched']['running_now']       = '⏳ Läuft...';
$lang['sched']['never_ran']         = 'Noch nie ausgeführt.';
$lang['sched']['running_since']     = '<strong>Läuft...</strong> gestartet %date% · vergangen: %elapsed%';
$lang['sched']['last_run']          = '<strong>Letzter Lauf:</strong> %date% · %status%';
$lang['sched']['next_run']          = '<strong>Nächster Lauf:</strong> %date%';
$lang['sched']['next_run_approx']   = ' (ungefähr — feuert beim ersten Seitenaufruf nach diesem Zeitpunkt)';
$lang['sched']['test_email_sending']         = 'Konfiguration wird gespeichert und Mail versendet...';
$lang['sched']['test_email_save_failed']     = 'Speichern fehlgeschlagen: %error%';
$lang['sched']['test_email_sent']            = '✅ Test-Mail erfolgreich gesendet';
$lang['sched']['test_email_sender_used']     = 'Verwendeter Absender: %sender%';
$lang['sched']['test_email_check_inbox']     = 'Postfach von %recipient% prüfen';
$lang['sched']['test_email_failed']          = '❌ Test-Mail fehlgeschlagen';
$lang['sched']['test_email_sender_attempted'] = 'Versuchter Absender: %sender%';

// ── Cron documentation (long-form reference content) ────────────────
$lang['cron']['how_title'] = '⚙️ Wie geplante Backups ausgelöst werden';
$lang['cron']['how_intro'] = 'Dieses Bundle klinkt sich in Contaos eingebautes Cron-System ein. Du musst dich nicht zwischen den '
    . 'Methoden entscheiden — sie funktionieren zusammen und du kannst später ohne Änderungen am Bundle wechseln.';
$lang['cron']['option1_summary'] = 'Option 1 — Web-Cron';
$lang['cron']['option1_summary_note'] = '(Standard, keine Einrichtung)';
$lang['cron']['option1_badge'] = 'aktuell aktiv';
$lang['cron']['option1_body'] = '<p>Wenn du nichts tust, führt Contao Cronjobs am Ende jedes Frontend- oder Backend-Seitenaufrufs aus, '
    . 'nachdem die Antwort an den Nutzer gesendet wurde. Der Besucher wartet nicht.</p>'
    . '<p><strong>Trade-offs:</strong></p>'
    . '<ul>'
    . '<li>✅ Kein Setup nötig.</li>'
    . '<li>✅ Der Nutzer wartet nie — Backups laufen in <code>kernel.terminate</code>.</li>'
    . '<li>⚠️ <strong>Zeitplan ist ungefähr:</strong> Ein für 03:00 geplantes Backup startet erst beim '
    . 'ersten Seitenaufruf nach 03:00. Ohne nächtlichen Traffic kann es auch erst um 08:30 laufen.</li>'
    . '<li>⚠️ Lang laufende Backups (besonders mit <code>files/</code>) können PHP-FPM-Limits im Shared Hosting reißen.</li>'
    . '</ul>'
    . '<p style="background:var(--updater-bg-stat);padding:.6rem .8rem;border-radius:3px;font-size:.8rem;">'
    . '🧪 <strong>Test-Tipp:</strong> Die Frequenzen „Alle 5 Minuten / 15 Minuten / Stündlich" sind '
    . 'intervallbasiert — sie feuern, sobald diese Zeit seit dem letzten Lauf vergangen ist, unabhängig '
    . 'von der Uhrzeit. Beim Web-Cron hängt das trotzdem daran, dass jemand die Seite aufruft und '
    . 'damit Contaos Cron-Framework auslöst. Ohne Traffic reicht ein Reload einer beliebigen Backend-Seite.'
    . '</p>';
$lang['cron']['option2_summary'] = 'Option 2 — Echter Cron';
$lang['cron']['option2_summary_note'] = '(für Produktion empfohlen)';
$lang['cron']['option2_badge'] = 'zusätzliches Setup';
$lang['cron']['option2_intro'] = '<p>Richte dein Hosting so ein, dass <code>contao:cron</code> zeitgesteuert aufgerufen wird. '
    . 'Backups laufen dann pünktlich, auch ohne Besucher, und teilen keine Ressourcen mit Web-Requests.</p>'
    . '<p><strong>Der Befehl:</strong></p>';
$lang['cron']['option2_command_hint'] = '<p style="font-size:.75rem;color:var(--updater-text-muted);">'
    . 'Passe den PHP-Pfad an, falls nötig. Viele Hoster erkennen ihn automatisch — oft reicht auch <code>contao-console contao:cron</code>.'
    . '</p>';
$lang['cron']['plesk_title']    = '🔵 Plesk';
$lang['cron']['plesk_body'] = '<li>Öffne <em>Domains → deine Domain → Geplante Aufgaben</em></li>'
    . '<li>Klick auf <strong>Aufgabe hinzufügen</strong></li>'
    . '<li>Aufgabentyp: <strong>Befehl ausführen</strong></li>'
    . '<li>Befehl:<pre class="updater-cron-cmd">vendor/bin/contao-console contao:cron</pre></li>'
    . '<li>Ausführen: <strong>Cron-Stil</strong> → <code class="updater-cron-inline">*/5 * * * *</code> (alle 5 Minuten)</li>'
    . '<li>Speichern. Plesk führt das Kommando standardmäßig vom Domain-Root aus.</li>';
$lang['cron']['cpanel_title'] = '🟠 cPanel';
$lang['cron']['cpanel_body'] = '<li>Öffne <em>Advanced → Cron Jobs</em></li>'
    . '<li>Common Settings: <strong>Alle 5 Minuten</strong> (<code>*/5 * * * *</code>)</li>'
    . '<li>Befehl:<pre class="updater-cron-cmd">cd ~/yoursite.com && /usr/local/bin/php vendor/bin/contao-console contao:cron &gt;/dev/null 2&gt;&amp;1</pre></li>'
    . '<li>Ersetze <code>~/yoursite.com</code> durch deinen tatsächlichen Document-Root.</li>'
    . '<li>Speichern.</li>';
$lang['cron']['directadmin_title'] = '🟢 DirectAdmin';
$lang['cron']['directadmin_body'] = '<li>Öffne <em>Account Manager → Cron Jobs</em></li>'
    . '<li>Setze die Zeitplan-Felder manuell: '
    . 'Minute=<code class="updater-cron-inline">*/5</code>, '
    . 'Stunde=<code class="updater-cron-inline">*</code>, Tag=<code class="updater-cron-inline">*</code>, '
    . 'Monat=<code class="updater-cron-inline">*</code>, Wochentag=<code class="updater-cron-inline">*</code>'
    . '</li>'
    . '<li>Befehl:<pre class="updater-cron-cmd">cd /home/USER/domains/yoursite.com/public_html && php vendor/bin/contao-console contao:cron</pre></li>';
$lang['cron']['ssh_title'] = '⚫ SSH / direktes crontab';
$lang['cron']['ssh_body'] = '<li>Per SSH einloggen</li>'
    . '<li><code>crontab -e</code> ausführen</li>'
    . '<li>Einfügen:<pre class="updater-cron-cmd">*/5 * * * * /usr/bin/php /full/path/to/site/vendor/bin/contao-console contao:cron &gt;/dev/null 2&gt;&amp;1</pre></li>'
    . '<li>Speichern und beenden (<code>:wq</code> in vim, <code>Strg+X Y</code> in nano).</li>';
$lang['cron']['option2_frequency_hint'] = '<p style="font-size:.8rem;margin-top:.8rem;"><strong>Frequenz-Empfehlung:</strong> '
    . 'Alle 5 Minuten ist völlig ok. Der Scheduler rate-limitet unser Backup-Job intern auf „stündlich" — '
    . 'häufigere Cron-Läufe verringern nur den Drift.</p>';
$lang['cron']['both_summary'] = 'Beides gleichzeitig?';
$lang['cron']['both_summary_note'] = 'Ja — sie vertragen sich problemlos.';
$lang['cron']['both_body'] = '<p>Wenn du einen echten Cron einrichtest <em>und</em> Web-Traffic hast, kommt das Bundle mit beidem klar:</p>'
    . '<ul>'
    . '<li>Contao merkt sich den letzten Ausführungszeitpunkt jedes Cronjobs. Wer zuerst feuert, gewinnt — '
    . 'der zweite sieht „schon in dieser Stunde gelaufen, überspringe".</li>'
    . '<li>Ein zusätzlicher File-Lock (<code>flock()</code> auf <code>var/updater/backup.lock</code>) verhindert '
    . 'zusätzlich, dass zwei Backups parallel laufen.</li>'
    . '<li>Du kannst den echten Cron später wieder entfernen — das Web-Fallback übernimmt automatisch. '
    . 'Oder umgekehrt: Cron nachträglich einrichten, es funktioniert einfach.</li>'
    . '</ul>'
    . '<p><strong>Empfehlung:</strong> Richte einen echten Cron ein, wenn dein Hosting das erlaubt. Das '
    . 'Web-Fallback wird dann zum Sicherheitsnetz — Backups laufen auch, wenn der Cron-Dienst mal ausfällt.</p>';
$lang['cron']['reference'] = 'Referenz: <a href="https://docs.contao.org/5.x/manual/en/performance/cronjobs/" target="_blank" rel="noopener">Contao Cronjobs-Dokumentation</a>';

// ── Update tab ───────────────────────────────────────────────────────
$lang['update']['run_title'] = '🔄 Update ausführen';
$lang['update']['run_desc'] = 'Updates laufen als Background-Jobs in einem separaten PHP-Prozess — der Browser muss nicht offen bleiben. '
    . 'Fortschritt und Live-Log aktualisieren sich automatisch. Starte mit <strong>Dry-Run</strong>, um zu sehen, '
    . 'was <em>passieren würde</em>, ohne etwas zu ändern.';
$lang['update']['syslog_hint'] = '💡 Wichtige Ereignisse aus Updates und geplanten Backups werden zusätzlich in Contaos '
    . '<strong>Systemlog</strong> geschrieben (System → Systemlog, Aktion <code>VTINNOVATIONS_GUARDIAN</code>). '
    . 'So kannst du auch nachträglich nachvollziehen, was passiert ist, wenn das Live-Log hier weg ist.';
$lang['update']['dry_run']     = '🧪 Dry-Run (sichere Simulation)';
$lang['update']['real_update'] = '▶ Echtes Update…';
$lang['update']['pre_snapshot_hint'] = 'Vor jedem Update wird ein Pre-Update-Snapshot erstellt. Schlägt das Update fehl, ist ein Rollback mit einem Klick möglich.';
$lang['update']['modal_title'] = '▶ Echtes Update starten';
$lang['update']['modal_intro'] = 'Dies verändert deine Live-Seite. Zuvor wird ein Pre-Update-Snapshot erstellt, damit du zurückrollen kannst.';
$lang['update']['mode_title'] = 'Update-Modus';
$lang['update']['mode_full_label']  = 'Full — alles innerhalb der composer.json-Constraints aktualisieren';
$lang['update']['mode_full_desc']   = 'Aktualisiert alle Pakete auf die höchsten von composer.json erlaubten Versionen. Häufigste Wahl.';
$lang['update']['mode_patch_label'] = 'Konservativ — stabile Releases bevorzugen';
$lang['update']['mode_patch_desc']  = 'Wie Full, aber ohne Pre-Release-Versionen. Für strikt Patch-only in composer.json auf <code>~X.Y.Z</code> pinnen.';
$lang['update']['mode_selective_label'] = 'Selektiv — einzelne Pakete auswählen';
$lang['update']['mode_selective_desc']  = 'Wähle exakt aus, welche Pakete aktualisiert werden. Abhängigkeiten werden automatisch mitgezogen.';
$lang['update']['packages_loading'] = 'Pakete werden geladen…';
$lang['update']['snapshot_title'] = 'Pre-Update-Snapshot';
$lang['update']['snapshot_vendor_label'] = '<code>vendor/</code> in den Snapshot aufnehmen (empfohlen)';
$lang['update']['snapshot_vendor_desc']  = 'Erlaubt einen vollständigen Rollback. Ohne dies umfasst der Rollback nur Composer-Dateien + DB, '
    . 'du müsstest <code>composer install</code> manuell nachziehen.';
$lang['update']['recovery_email_title'] = 'Recovery-E-Mail';
$lang['update']['recovery_email_label'] = '📧 Recovery-URLs + Access-Token <strong>vor</strong> dem Update per E-Mail senden';
$lang['update']['recovery_email_desc'] = 'Falls etwas schiefgeht, ist diese Mail deine Rettungsleine. Empfänger konfigurierst du unter '
    . '<a href="#tab=settings" onclick="updaterSwitchTab(\'settings\', true)" style="color:var(--updater-text-link);">Einstellungen → Recovery-E-Mail</a>. '
    . '<strong>Lösche die E-Mail nach erfolgreichem Update</strong> — sie enthält deinen vollständigen Access-Token.';
$lang['update']['cancel']     = 'Abbrechen';
$lang['update']['start_now']  = '▶ Update jetzt starten';
$lang['update']['job_history_summary'] = '▸ Letzte Job-Historie';
$lang['update']['job_history_loading'] = 'Lädt...';
$lang['update']['no_previous_jobs']    = 'Noch keine früheren Jobs.';
$lang['update']['dry_run_confirm'] = 'DRY-RUN starten?\n\nDas simuliert ein Update — composer läuft mit --dry-run, contao:migrate ebenso. '
    . 'Ein ECHTES Backup wird trotzdem angelegt (Sicherheitsnetz). Sonst wird nichts verändert.';
$lang['update']['real_confirm'] = 'ECHTES Update starten?\n\nModus: %mode%\n%snapshot_note%\n\nDies verändert deine Live-Seite. Sie geht während des Updates in den Wartungsmodus.';
$lang['update']['mode_label_full']      = 'FULL (alle Pakete innerhalb der composer.json-Constraints)';
$lang['update']['mode_label_patch']     = 'KONSERVATIV (stabile Releases bevorzugen)';
$lang['update']['mode_label_selective'] = 'SELEKTIV (%count% Paket(e))';
$lang['update']['snapshot_note_with_vendor']    = 'Pre-Snapshot enthält vendor/ — voller Rollback möglich.';
$lang['update']['snapshot_note_without_vendor'] = 'Pre-Snapshot enthält nur Composer + DB — Rollback erfordert manuelles `composer install`.';
$lang['update']['select_at_least_one'] = 'Bitte mindestens ein Paket zum Aktualisieren auswählen.';
$lang['update']['packages_all_current'] = 'Alle Pakete sind aktuell — nichts zu aktualisieren.';
$lang['update']['packages_updates_available'] = 'Für %count% Paket(e) sind Updates verfügbar. Wähle aus, welche aktualisiert werden sollen:';
$lang['update']['select_all']   = 'Alle auswählen';
$lang['update']['deselect_all'] = 'Auswahl aufheben';
$lang['update']['load_error']   = 'Fehler: %error%';
$lang['update']['worker_failed_title'] = '❌ Worker konnte nicht gestartet werden';
$lang['update']['worker_failed_hint'] = 'Öffne unten den Bereich „PHP-CLI-Einstellungen" und trage den korrekten Pfad zur PHP-CLI-Binary ein '
    . '(z. B. /opt/plesk/php/8.4/bin/php bei Plesk), dann erneut versuchen.';
$lang['update']['email_failed_title'] = '❌ Recovery-E-Mail konnte nicht gesendet werden';
$lang['update']['email_failed_body'] = 'Das Update wurde NICHT gestartet. Optionen:\n'
    . '  • Mail-Konfiguration korrigieren (Einstellungen → Recovery-E-Mail) und erneut versuchen, oder\n'
    . '  • Häkchen „Recovery-URLs per E-Mail senden" im Update-Dialog entfernen und ohne E-Mail fortfahren';
$lang['update']['email_failed_hint'] = '\n\nHinweis: %hint%';
$lang['update']['blocked_title'] = 'Ein anderer Job steht im Weg:';
$lang['update']['blocked_hint'] = 'Nutze am aktiven Job den Button „⛔ Abbruch erzwingen" oder „🗑 Stale-Job aufräumen", um die Queue freizugeben.';
$lang['update']['job_start_failed'] = 'Job konnte nicht gestartet werden: %error%';

// ── Job runner (client-rendered) ────────────────────────────────────
$lang['job']['live_log'] = 'Live-Log';
$lang['job']['finished_hint'] = 'Job beendet. Seite neu laden zum Zurücksetzen oder direkt einen weiteren starten.';
$lang['job']['update_failed_rollback_title'] = '⚠️ Update fehlgeschlagen — Rollback verfügbar';
$lang['job']['update_failed_rollback_body'] = 'Vor den Änderungen wurde ein Pre-Update-Snapshot erstellt (%snapshot%). '
    . 'Klick unten, um ihn wiederherzustellen und die Seite auf den Stand vor dem fehlgeschlagenen Update zurückzusetzen.';
$lang['job']['auto_rollback_button'] = '↩️ Automatischer Rollback auf Pre-Snapshot';
$lang['job']['auto_rollback_fallback_hint'] = 'Wenn dieser Button nicht funktioniert (z. B. weil das fehlgeschlagene Update Contao selbst zerlegt hat), '
    . 'nutze das <strong>Standalone-Recovery-Panel</strong> aus dem Recovery-Tab — es funktioniert ohne Contao oder Symfony.';
$lang['job']['stale_warning'] = '⚠️ <strong>Dieser Job wirkt stale.</strong> %reason%';
$lang['job']['clear_stale_button'] = '🗑 Stale-Job aufräumen';
$lang['job']['force_abort_button'] = '⛔ Abbruch erzwingen';
$lang['job']['force_abort_hint'] = '(nutzen, wenn du sicher weißt, dass der Job hängt und du nicht warten kannst)';
$lang['job']['rollback_confirm'] = 'Zum Pre-Update-Snapshot zurückrollen?\n\n'
    . 'Composer-Dateien, Datenbank und (falls enthalten) das vendor/-Verzeichnis werden auf den Stand '
    . 'vor dem fehlgeschlagenen Update zurückgesetzt.\n\n'
    . 'Die Seite geht während des Rollbacks in den Wartungsmodus.';
$lang['job']['rollback_started'] = 'Rollback gestartet. Verfolge den Live-Log unten.';
$lang['job']['rollback_failed']  = 'Rollback fehlgeschlagen: %error%';
$lang['job']['rollback_request_failed'] = 'Rollback-Anfrage fehlgeschlagen: %error%';
$lang['job']['clear_stale_confirm_force'] = 'Diesen Job zwangsabbrechen?\n\n'
    . 'Der Job wurde noch nicht als stale erkannt, aber du brichst ihn trotzdem ab. '
    . 'Nur nutzen, wenn du sicher weißt, dass der Worker wirklich hängt. Teilweise erledigte Arbeit wird NICHT zurückgenommen.';
$lang['job']['clear_stale_confirm'] = 'Stale-Job aufräumen?\n\n'
    . 'Das bricht den vorherigen Job ab (der offenbar gecrasht ist oder nie startete), damit du einen neuen starten kannst. '
    . 'Es macht KEINE bereits erledigte Arbeit rückgängig — es gibt nur den Queue-Slot frei.';
$lang['job']['clear_stale_failed'] = 'Aufräumen fehlgeschlagen: %error%';
$lang['job']['clear_stale_request_failed'] = 'Fehlgeschlagen: %error%';
$lang['job']['job_label'] = 'Job';

// ── Settings tab ─────────────────────────────────────────────────────
$lang['settings']['license_title'] = '🔑 Lizenz';
$lang['settings']['license_desc'] = 'Die Lizenzverwaltung liegt zentral unter '
    . '<strong>Contao → Einstellungen → Guardian Licence management</strong>. '
    . 'Dort aktivierst du deinen Schlüssel, aktualisierst ihn und entfernst ihn wieder. '
    . 'Ohne gültige Lizenz ist nur das Modul <strong>Manuelles Backup</strong> verfügbar '
    . '(Free) bzw. gar nichts.';
$lang['settings']['license_link'] = '🔗 Lizenz beziehen unter';
$lang['settings']['license_free_pro'] = '(Free &amp; Pro)';
$lang['settings']['license_goto'] = '→ Zur Lizenzverwaltung';
$lang['settings']['recovery_email_title'] = '📧 Recovery-E-Mail-Benachrichtigungen';
$lang['settings']['recovery_email_desc'] = 'Beim Start eines echten Updates kann das Panel Recovery-URLs und Access-Token '
    . '<strong>vor</strong> Beginn des Updates an eine konfigurierte Adresse mailen. Das ist essenziell — '
    . 'zerlegt dir das Update die Seite, kannst du dich über die URLs in der Mail retten, auch ohne Zugriff auf dieses Backend.';
$lang['settings']['recipient']             = 'Empfänger';
$lang['settings']['recipient_placeholder'] = 'admin@example.com';
$lang['settings']['sender_optional']       = 'Absender (optional)';
$lang['settings']['sender_placeholder']    = 'leer lassen, um Empfängeradresse zu verwenden';
$lang['settings']['sender_hint'] = 'Lasse das Absender-Feld leer, außer dein Mailserver verlangt eine bestimmte From-Adresse. Bei Plesk/cPanel '
    . 'verlangen die meisten Shared-Hoster, dass From eine echte Mailbox auf dem Server ist — schlägt die Test-Mail fehl, '
    . 'setze Absender auf eine in Plesk angelegte, echte Mailbox-Adresse.';
$lang['settings']['save']           = 'Speichern';
$lang['settings']['send_test_mail'] = 'Test-Mail senden';
$lang['settings']['recipient_invalid'] = '⚠️ Ungültiges Empfänger-E-Mail-Format';
$lang['settings']['sender_invalid']    = '⚠️ Ungültiges Absender-E-Mail-Format';
$lang['settings']['saved_short']    = '✓ Gespeichert';
$lang['settings']['save_failed']    = '❌ %error%';
$lang['settings']['request_failed'] = '❌ %error%';
$lang['settings']['no_recipient']   = '⚠️ Erst einen Empfänger eintragen und speichern';
$lang['settings']['sending_test']   = '⏳ Wird gesendet…';
$lang['settings']['test_sent']      = '✓ Test-Mail an %recipient% gesendet';
$lang['settings']['test_failed_short'] = '❌ Fehlgeschlagen';
$lang['settings']['test_failed_alert'] = 'Test-Mail fehlgeschlagen:\n\n%error%%hint%';
$lang['settings']['test_failed_hint']  = '\n\nHinweis: %hint%';
$lang['settings']['php_title'] = '⚙️ PHP-CLI-Einstellungen';
$lang['settings']['php_desc'] = 'Updates und Restores laufen in einem Background-PHP-Prozess. Wir brauchen den <strong>absoluten Pfad zur PHP-CLI-Binary</strong> '
    . '(nicht die FPM/Web-Version). Bei Plesk sieht das typischerweise so aus: '
    . '<code>/opt/plesk/php/8.4/bin/php</code> — der gleiche Pfad, den auch der Contao Manager verwendet. '
    . 'Leer lassen für Auto-Erkennung.';
$lang['settings']['php_binary_label']       = 'PHP-Binary';
$lang['settings']['php_binary_placeholder'] = '(Auto-Erkennung, leer lassen, wenn unsicher)';
$lang['settings']['php_test'] = '🔍 Testen';
$lang['settings']['php_save'] = '💾 Speichern';
$lang['settings']['php_no_candidates'] = 'Keine üblichen PHP-Pfade auf diesem Server gefunden.';
$lang['settings']['php_suggestions']   = 'Vorschläge: ';
$lang['settings']['php_testing']       = 'Test läuft...';
$lang['settings']['php_test_failed']   = '❌ Test fehlgeschlagen';
$lang['settings']['php_saving']        = 'Speichern...';
$lang['settings']['php_saved']         = '✅ Gespeichert';
$lang['settings']['php_save_failed']   = '❌ %error%';

// ── Recovery tab ─────────────────────────────────────────────────────
$lang['recovery']['filename_title'] = '🛟 Recovery-Panel-Dateiname';
$lang['recovery']['filename_desc'] = 'Das Standalone-Recovery-Panel wird beim Kernel-Boot nach <code>public/&lt;Dateiname&gt;</code> kopiert. '
    . 'Ein eigener Dateiname erschwert das Auffinden durch Scanner (Security through Obscurity zusätzlich '
    . 'zur Token-Authentifizierung). Muss auf <code>.php</code> enden, nur Buchstaben/Zahlen/<code>._-</code>, '
    . 'max. 60 Zeichen.';
$lang['recovery']['filename_label']       = 'Dateiname';
$lang['recovery']['filename_placeholder'] = '_updater-recovery.php';
$lang['recovery']['filename_hint'] = 'Beispiel: <code>secret-panel-xyz.php</code> → erreichbar unter <code>https://deine-site/secret-panel-xyz.php</code>. '
    . 'Beim Umbenennen wird die vorherige Datei automatisch beim nächsten Boot entfernt.';
$lang['recovery']['filename_save'] = '💾 Speichern';
$lang['recovery']['filename_invalid'] = '❌ Ungültiger Dateiname (nur A-Z, 0-9, ._- ; muss auf .php enden)';
$lang['recovery']['filename_saving'] = 'Speichern...';
$lang['recovery']['filename_saved'] = '✅ Gespeichert — Panel unter %filename% (Cache leeren, damit neuer Boot deployt)';
$lang['recovery']['filename_save_failed']    = '❌ %error%';
$lang['recovery']['filename_request_failed'] = '❌ %error%';
$lang['recovery']['why_title'] = '↩️ Wie Restore in diesem Bundle funktioniert';
$lang['recovery']['why_intro'] = 'Restore ist eine <strong>Out-of-Band-Operation</strong> — bewusst wird sie <em>nicht</em> aus '
    . 'diesem Contao-Backend heraus ausgelöst. Zwei Gründe:';
$lang['recovery']['why_reason1'] = 'Wenn Contao läuft, brauchst du meistens keinen Restore. Wenn du einen brauchst, ist Contao wahrscheinlich '
    . 'kaputt — und du kommst ohnehin nicht mehr an diesen Tab.';
$lang['recovery']['why_reason2'] = '<code>vendor/</code> wiederherzustellen, während Symfony aus demselben Verzeichnis liest, ist gefährlich — '
    . 'das Backend würde mitten im Restore abstürzen.';
$lang['recovery']['why_outro'] = 'Nutze eines der beiden Recovery-Panels unten. Beide haben ihre eigene tokenbasierte Authentifizierung und '
    . 'laufen unabhängig vom Contao-Backend.';
$lang['recovery']['standalone_title'] = '🆘 Standalone-Recovery-Panel (funktioniert auch bei defektem Contao)';
$lang['recovery']['standalone_desc'] = 'Das ist ein separates <strong>Single-File-PHP-Skript</strong>, das automatisch unter '
    . '<code>public/_updater-recovery.php</code> installiert wird. Es läuft <strong>ohne Symfony, Composer oder Contao</strong> — '
    . 'selbst wenn ein verpfuschtes Update das ganze Framework zerlegt, erreichst du es noch über den Webserver '
    . 'und kannst aus einem Backup wiederherstellen.';
$lang['recovery']['standalone_howto_intro'] = '<strong>So nutzt du es im Notfall:</strong>';
$lang['recovery']['standalone_howto_step1'] = 'Öffne %link% im Browser';
$lang['recovery']['standalone_howto_step2'] = 'Der Browser zeigt einen Basic-Auth-Dialog → BELIEBIGEN Benutzernamen und deinen Access-Token (siehe Token-Bereich unten) als Passwort eintragen';
$lang['recovery']['standalone_howto_step3'] = 'Backup auswählen und direkt wiederherstellen';
$lang['recovery']['standalone_save_warning'] = '<strong>⚠️ Speichere URL + Token JETZT außerhalb von Contao</strong> (Passwortmanager, Notizzettel, egal wo). '
    . 'Wenn Contao ausfällt, kannst du sie von dieser Seite nicht mehr kopieren.';
$lang['recovery']['standalone_regenerated_hint'] = 'Die Datei wird bei jedem Bundle-Update neu erzeugt — du musst nichts manuell pflegen. '
    . 'Sie nutzt den unten angezeigten Access-Token.';
$lang['recovery']['token_title'] = '🔑 Access-Token';
$lang['recovery']['token_desc'] = 'Das Standalone-Recovery-Panel nutzt HTTP Basic Auth. Der Benutzername ist beliebig; '
    . 'das Passwort ist der hier verwaltete Access-Token.';
$lang['recovery']['token_loading'] = 'Lädt…';
$lang['recovery']['token_env_fix_summary'] = '▸ Token per <code>.env.local</code> fixieren';
$lang['recovery']['token_env_fix_intro'] = '<p>Für einen stabilen, versionierbaren Token diese Zeile in <code>.env.local</code> einfügen:</p>';
$lang['recovery']['token_env_fix_command_hint'] = '<p>Das Bundle bevorzugt env vor der auto-generierten Datei. Nach dem Bearbeiten von <code>.env.local</code> den Symfony-Cache leeren.</p>';
$lang['recovery']['token_env_fix_warning'] = '⚠️ <strong>Teste das Panel VOR einem Update.</strong> Wenn du es jetzt nicht erreichst, '
    . 'erreichst du es im Ernstfall auch nicht.';
$lang['recovery']['token_source_env']       = 'aus .env';
$lang['recovery']['token_source_generated'] = 'auto-generiert';
$lang['recovery']['token_source_label'] = 'Quelle: %badge%';
$lang['recovery']['token_rotate_button'] = '🔄 Token rotieren, um eine neue Kopie zu erhalten';
$lang['recovery']['token_env_note'] = 'Der Token ist in <code>.env.local</code> gesetzt. Zum Rotieren dort den Wert von <code>VTINNOVATIONS_GUARDIAN_TOKEN</code> '
    . 'ändern und den Symfony-Cache leeren.';
$lang['recovery']['token_file_note'] = 'Aus Sicherheitsgründen wird hier nur eine Preview des Tokens angezeigt. Um den vollständigen Token zu sehen '
    . '(z. B. zum Bookmarken oder Speichern im Passwortmanager), klick auf <strong>Token rotieren</strong> — ein '
    . 'frischer Token wird erzeugt und einmalig angezeigt. Der vorherige Token wird sofort ungültig.';
$lang['recovery']['token_masked_alert'] = 'Der hier angezeigte Token ist eine maskierte Preview. Klick auf „Token rotieren", '
    . 'um einen frischen Token zu erzeugen und einmalig anzuzeigen.';
$lang['recovery']['token_copied'] = '✅ Kopiert';
$lang['recovery']['token_rotate_confirm'] = 'Access-Token rotieren?\n\nDer alte Token wird sofort ungültig. '
    . 'Der neue Token wird einmalig angezeigt — jetzt kopieren und speichern. '
    . 'Nach dem Schließen des Dialogs oder Reload ist nur noch eine maskierte Preview sichtbar.';
$lang['recovery']['token_rotate_failed'] = 'Rotation fehlgeschlagen';
$lang['recovery']['token_new_warning_title'] = '⚠️ Neuer Token erzeugt — jetzt speichern';
$lang['recovery']['token_new_warning_body'] = 'Er wird nur dieses eine Mal angezeigt. Nach Klick auf „Ich habe ihn gespeichert" '
    . 'oder beim Verlassen der Seite ist nur noch eine maskierte Preview verfügbar.';
$lang['recovery']['token_copy_button']    = '📋 Token kopieren';
$lang['recovery']['token_dismiss_button'] = 'Ich habe ihn gespeichert — ausblenden';

// ── Upgrade modal ────────────────────────────────────────────────────
$lang['upgrade']['title_pro']  = '🔒 Diese Funktion braucht das Pro-Paket';
$lang['upgrade']['title_none'] = '🔒 Für diese Funktion brauchst du mindestens eine Free-Lizenz';
$lang['upgrade']['body_none'] = 'Ohne Lizenz sind alle Funktionen außer Dashboard und Einstellungen gesperrt. '
    . 'Eine <strong>kostenlose Free-Lizenz</strong> schaltet das Manuelle Backup frei; '
    . 'die <strong>Pro-Lizenz</strong> zusätzlich Updates, Restore/Recovery, geplante '
    . 'Backups und das Standalone-Recovery-Panel.';
$lang['upgrade']['body_pro_intro'] = 'Du bist gerade im <strong>Free-Paket</strong>. Damit steht dir das '
    . '<strong>Manuelle Backup</strong> zur Verfügung.';
$lang['upgrade']['body_pro_from_free'] = 'Du bist gerade im <strong>Free-Paket</strong>. Damit steht dir das '
    . '<strong>Manuelle Backup</strong> zur Verfügung. Für Updates, Restore/Recovery, geplante '
    . 'Backups und das Standalone-Recovery-Panel brauchst du die Pro-Lizenz.';
$lang['upgrade']['body_pro_unlocks'] = 'Mit der <strong>Pro-Lizenz</strong> schaltest du frei:';
$lang['upgrade']['feature_updates']  = 'Update-Jobs (Composer full / patch / selektiv)';
$lang['upgrade']['feature_restore']  = 'Restore / Recovery aus Snapshots';
$lang['upgrade']['feature_schedule'] = 'Geplante Backups (Mini + Full mit E-Mail-Benachrichtigung)';
$lang['upgrade']['feature_panel']    = 'Standalone-Recovery-Panel für den Notfall';
$lang['upgrade']['get_license']  = 'Lizenz beziehbar über';
$lang['upgrade']['activate_at']  = 'Aktivierung unter <strong>Contao → Einstellungen → Guardian Licence management</strong>.';
$lang['upgrade']['close']        = 'Schließen';
$lang['upgrade']['goto_license'] = '→ Zur Lizenzverwaltung';

// ── Shared/misc ──────────────────────────────────────────────────────
$lang['msc']['error_generic'] = 'Unbekannter Fehler';
$lang['msc']['generic_error'] = 'Fehler: %error%';

// ── Backend API responses (JSON error/message strings) ──────────────
$lang['api']['backup_name_missing']        = 'Backup-Name fehlt';
$lang['api']['backup_not_found']           = 'Backup nicht gefunden oder konnte nicht gelöscht werden';
$lang['api']['invalid_payload']            = 'Ungültige Anfrage';
$lang['api']['storage_path_invalid']       = 'Speicherpfad ist ungültig: %errors%';
$lang['api']['invalid_backup_type']        = 'Ungültiger Backup-Typ';
$lang['api']['job_id_missing']             = 'Job-ID fehlt';
$lang['api']['job_not_found_in_archive']   = 'Job nicht im Archiv gefunden: %id%';
$lang['api']['no_pre_snapshot']            = 'Kein Pre-Update-Snapshot zu diesem Job vorhanden. Manueller Restore erforderlich.';
$lang['api']['rollback_started_from']      = 'Rollback gestartet aus Snapshot %snapshot%';
$lang['api']['unknown_job_type']           = 'Unbekannter Job-Typ: %type%';
$lang['api']['recovery_email_send_failed'] = 'Recovery-E-Mail konnte nicht gesendet werden: %error%. '
    . 'Deaktiviere die E-Mail-Option oder korrigiere die Mail-Konfiguration, bevor du das Update startest.';
$lang['api']['no_active_job']              = 'Kein aktiver Job';
$lang['api']['job_not_stale_yet']          = 'Job wird noch nicht als stale erkannt. force=true übergeben, um trotzdem abzubrechen.';
$lang['api']['job_force_cleared']          = 'Manuell abgebrochen (durch Administrator ausgelöst)';
$lang['api']['job_cleared_as_stale']       = 'Als stale abgebrochen';
$lang['api']['php_binary_check_failed']    = 'PHP-Binary-Prüfung fehlgeschlagen: %error%';
$lang['api']['composer_phar_absolute']     = 'Composer-Phar muss ein absoluter Pfad sein (beginnend mit /)';
$lang['api']['composer_phar_extension']    = 'Composer-Phar sollte eine .phar-Datei sein';
$lang['api']['token_from_env']             = 'Der Token stammt aus .env. Bearbeite VTINNOVATIONS_GUARDIAN_TOKEN in .env.local, um ihn zu rotieren.';
$lang['api']['unknown_error']              = 'Unbekannter Fehler';

// ── Licence management panel (Contao → Settings) ─────────────────────
$lang['license']['activated']            = 'Die Guardian-Lizenz wurde erfolgreich aktiviert.';
$lang['license']['refreshed']            = 'Die Guardian-Lizenz wurde erfolgreich aktualisiert.';
$lang['license']['removed']              = 'Die Guardian-Lizenz wurde entfernt. Lizenzierte Funktionen sind deaktiviert, bis eine Lizenz erneut aktiviert wird.';
$lang['license']['no_crypto']            = 'Guardian kann auf diesem Server keine Lizenzen prüfen: die PHP-Sodium-Erweiterung fehlt. '
    . 'Kontaktiere deinen Hoster oder V&T Innovations.';
$lang['license']['legacy_key_found']     = 'Guardian hat einen Lizenzschlüssel aus einer früheren Version gefunden, der noch keine signierten Lizenzdatensätze kennt '
    . 'und daher nicht authentifiziert werden konnte. Klicke auf „Lizenz aktualisieren", um ihn erneut zu authentifizieren. '
    . 'Lizenzierte Funktionen bleiben deaktiviert, bis das gelingt.';
$lang['license']['no_domain_configured'] = 'Für diese Installation ist keine Domain konfiguriert. Setze die Domain auf einer Website-Root-Seite '
    . '(oder über die Umgebungsvariable VTINNOVATIONS_GUARDIAN_DOMAINS), bevor du eine Lizenz aktivierst.';
$lang['license']['detail_key']            = 'Schlüssel: %value%';
$lang['license']['detail_package']        = 'Paket: %value%';
$lang['license']['detail_valid_from']     = 'Gültig ab: %value%';
$lang['license']['detail_valid_until']    = 'Gültig bis: %value%';
$lang['license']['detail_unlimited']      = 'unbefristet';
$lang['license']['detail_last_verified']  = 'Zuletzt geprüft: %value%';
$lang['license']['detail_configured_domains'] = 'Konfigurierte Domains: %value%';
$lang['license']['server_unreachable']    = 'Der Lizenzserver war nicht erreichbar. Es wurde nichts geändert — '
    . 'eine bestehende Lizenz bleibt aktiv. Bitte später erneut versuchen.';
$lang['license']['state_pro_active']      = 'Pro-Lizenz aktiv. Alle Funktionen freigeschaltet.';
$lang['license']['state_trial_active']    = 'Trial-Lizenz aktiv. Alle Funktionen freigeschaltet, bis die Testphase endet.';
$lang['license']['state_free_active']     = 'Free-Lizenz aktiv. Nur manuelles Backup.';
$lang['license']['state_paid_fallback']   = 'Pro-Lizenz abgelaufen. Läuft im Free-Funktionsumfang (nur manuelles Backup).';
$lang['license']['state_expired']              = 'Lizenz abgelaufen. Alle lizenzierten Funktionen sind deaktiviert.';
$lang['license']['state_not_yet_valid']        = 'Lizenz ist noch nicht gültig. Alle lizenzierten Funktionen sind deaktiviert.';
$lang['license']['state_host_not_authorised']  = 'Lizenz ist für keine der auf dieser Installation konfigurierten Domains gültig.';
$lang['license']['state_issuer_withheld']      = 'Lizenz ist nicht mehr gültig. Klicke auf „Lizenz aktualisieren", oder kontaktiere V&T Innovations.';
$lang['license']['state_tier_not_accepted']    = 'Lizenzpaket wird von diesem Produkt nicht unterstützt.';
$lang['license']['state_absent']               = 'Keine Lizenz aktiviert. Nur Dashboard und Einstellungen sind verfügbar.';
$lang['license']['state_default']              = 'Keine gültige Lizenz. Alle lizenzierten Funktionen sind deaktiviert.';
$lang['license']['explain_key_missing']            = 'Bitte einen Lizenzschlüssel eingeben.';
$lang['license']['explain_no_configured_domain']   = 'Für diese Installation ist keine Domain konfiguriert. '
    . 'Setze die Domain auf einer Website-Root-Seite, bevor du eine Lizenz aktivierst.';
$lang['license']['explain_host_not_authorised']    = 'Diese Lizenz ist für die Domain dieser Installation nicht gültig.';
$lang['license']['explain_registry_denied']        = 'Der Lizenzschlüssel wurde nicht akzeptiert. Bitte den Schlüssel prüfen oder V&T Innovations kontaktieren.';
$lang['license']['explain_no_crypto']              = 'Guardian kann auf diesem Server keine Lizenzen prüfen: '
    . 'die PHP-Sodium-Erweiterung wird benötigt.';
$lang['license']['explain_default']                = 'Die Lizenz konnte nicht geprüft werden. Bitte V&T Innovations kontaktieren, falls das bestehen bleibt.';

// ── Licence panel (Contao → Settings, button-based section) ──────────
$lang['license']['panel_key_label']       = 'Lizenzschlüssel';
$lang['license']['panel_key_placeholder'] = 'XXXXX-XXXXX-XXXXX-XXXXX';
$lang['license']['panel_activate']        = 'Lizenz prüfen & aktivieren';
$lang['license']['panel_refresh']         = 'Lizenz aktualisieren';
$lang['license']['panel_remove']          = 'Lizenz entfernen';
$lang['license']['panel_remove_confirm']  = 'Lizenz entfernen?\n\nLizenzierte Funktionen werden sofort deaktiviert; Backups, Jobs und Einstellungen bleiben erhalten.';
$lang['license']['panel_hint']            = 'Trage den von V&T Innovations ausgestellten Lizenzschlüssel ein und klicke auf „Lizenz prüfen & aktivieren". '
    . 'Der Schlüssel wird als signierter Datensatz abgelegt, nicht in der Contao-Konfiguration.';
$lang['license']['panel_unavailable']     = 'Lizenz-Panel derzeit nicht verfügbar.';

// ── Pre-update analysis checks ────────────────────────────────────────
$lang['checker']['label_php_version']    = 'PHP-Version';
$lang['checker']['php_version_ok']       = 'PHP %current% ist kompatibel mit Contao 5';
$lang['checker']['php_version_too_old']  = 'PHP %current% ist zu alt — Contao 5 benötigt mindestens PHP %required%';
$lang['checker']['label_composer']       = 'Composer';
$lang['checker']['composer_found']       = 'Composer gefunden: %path%';
$lang['checker']['composer_not_found']   = 'Composer wurde in den üblichen Pfaden nicht gefunden — Updates müssen ggf. anders ausgeführt werden';
$lang['checker']['label_write_permissions'] = 'Schreibrechte';
$lang['checker']['permissions_ok']       = 'Alle wichtigen Verzeichnisse sind beschreibbar';
$lang['checker']['permissions_issues']   = 'Kein Schreibzugriff: %paths%';
$lang['checker']['label_disk_space']     = 'Speicherplatz';
$lang['checker']['disk_space_unknown']   = 'Freier Speicherplatz konnte nicht ermittelt werden';
$lang['checker']['disk_space_ok']        = '%free% MB frei — ausreichend für Backup und Update';
$lang['checker']['disk_space_low']       = 'Nur %free% MB frei — mindestens %required% MB empfohlen';
$lang['checker']['label_composer_packages'] = 'Composer-Pakete';
$lang['checker']['installed_json_missing']  = 'vendor/composer/installed.json nicht gefunden';
$lang['checker']['installed_json_unexpected'] = 'installed.json hat ein unerwartetes Format';
$lang['checker']['packages_abandoned']   = '%count% verlassene(s) Paket(e): %names%';
$lang['checker']['packages_ok']          = '%total% Pakete installiert (%contao% Contao-Pakete) — keine verlassen';
$lang['checker']['label_database']       = 'Datenbank-Konfiguration';
$lang['checker']['database_url_set']     = 'DATABASE_URL ist in %filename% gesetzt';
$lang['checker']['database_url_missing'] = 'DATABASE_URL nicht in .env / .env.local gefunden — Backup muss übersprungen werden';
$lang['checker']['label_legacy_modules'] = 'Legacy-Module (system/modules/)';
$lang['checker']['legacy_none_found']    = 'Kein Legacy-Modulverzeichnis gefunden — Installation ist vollständig bundle-basiert';
$lang['checker']['legacy_dir_empty']     = 'system/modules/ existiert, ist aber leer';
$lang['checker']['legacy_modules_found'] = '%count% Legacy-Modul(e) gefunden: %names%. Diese verwenden das alte Contao-3-Extension-Format '
    . 'und sollten vor einem Upgrade auf Composer/Symfony-Bundles migriert werden.';
$lang['checker']['summary_warnings']     = 'Update grundsätzlich möglich — bitte Warnungen prüfen';
$lang['checker']['summary_ready']        = 'Alles bereit — Update kann gestartet werden';
$lang['checker']['summary_critical']     = 'Kritische Probleme gefunden — bitte zuerst beheben';

// ── Recovery e-mail notifier ──────────────────────────────────────────
$lang['notifier']['not_entitled']       = 'Recovery-E-Mails erfordern eine gültige Guardian-Lizenz.';
$lang['notifier']['mailer_unavailable_config'] = 'Symfony Mailer ist in dieser Installation nicht verfügbar. '
    . 'Konfiguriere MAILER_DSN in .env.local, um E-Mail-Benachrichtigungen zu aktivieren.';
$lang['notifier']['mailer_unavailable']  = 'Symfony Mailer ist nicht verfügbar. Konfiguriere zuerst MAILER_DSN in .env.local.';
$lang['notifier']['recipient_not_configured'] = 'Recovery-E-Mail-Adresse ist nicht konfiguriert. Öffne Einstellungen → Recovery-E-Mail '
    . 'und trage eine Adresse ein, bevor du E-Mail-Benachrichtigungen bei Updates aktivierst.';
$lang['notifier']['recipient_invalid']   = 'Konfigurierte Recovery-E-Mail ist keine gültige Adresse: %recipient%';
$lang['notifier']['no_valid_recipient']  = 'Keine gültige Recovery-E-Mail konfiguriert. Trage zuerst eine unter Einstellungen ein.';

unset($lang);
