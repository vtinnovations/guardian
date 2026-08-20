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
$lang['tabs']['settings']  = '⚙️ Settings';

// ── Dashboard ────────────────────────────────────────────────────────
$lang['dashboard']['plan_badge_pro']  = '⭐ Pro package active';
$lang['dashboard']['plan_badge_free'] = '🆓 Free package active';
$lang['dashboard']['plan_badge_none'] = '🚫 No licence';
$lang['dashboard']['plan_tagline_pro']  = 'Full feature set unlocked — updates, restore/recovery, scheduled backups and the standalone recovery panel.';
$lang['dashboard']['plan_tagline_free'] = 'Free package: <strong>manual backup</strong> is available. Updates, restore/recovery, scheduled backups and the standalone recovery panel need a Pro licence.';
$lang['dashboard']['plan_tagline_none'] = 'Without a licence, every feature is locked. Enter a licence key under <strong>Contao → Settings → Guardian Licence management</strong>. Free licences unlock manual backup, Pro licences unlock the full feature set.';
$lang['dashboard']['feature_backup']   = 'Manual backup';
$lang['dashboard']['feature_updates']  = 'Update jobs (Composer)';
$lang['dashboard']['feature_restore']  = 'Restore / recovery';
$lang['dashboard']['feature_schedule'] = 'Scheduled backups (mini + full)';
$lang['dashboard']['feature_panel']    = 'Standalone recovery panel';
$lang['dashboard']['upgrade_to_pro']  = '⭐ Upgrade to Pro';
$lang['dashboard']['enter_license']   = '🔑 Enter licence';
$lang['dashboard']['stat_current_version']    = 'Current Contao version';
$lang['dashboard']['stat_installed_packages'] = 'Installed packages';
$lang['dashboard']['stat_available_backups']  = 'Available backups';
$lang['dashboard']['status_title']       = 'Current status';
$lang['dashboard']['status_idle']        = '✓ Idle — no operation running';
$lang['dashboard']['status_running']     = '⏳ Running — operation in progress';
$lang['dashboard']['status_success']     = '✓ Last operation succeeded';
$lang['dashboard']['status_error']       = '⚠️ Last operation failed';
$lang['dashboard']['status_idle_hint']   = 'No operation is currently running. The next action you start (update, backup or restore) will appear here.';
$lang['dashboard']['status_updated_at']  = 'Last updated: %date%';
$lang['dashboard']['analysis_title']     = 'Pre-flight check (pre-update analysis)';
$lang['dashboard']['analysis_desc']      = 'Checks the prerequisites for an update — entirely read-only, changes nothing.';
$lang['dashboard']['analysis_start']     = '🔍 Start analysis';
$lang['dashboard']['analysis_rerun']     = '🔍 Run analysis again';
$lang['dashboard']['analysis_running']   = '⏳ Analysis running...';
$lang['dashboard']['analysis_wait']      = 'Please wait...';
$lang['dashboard']['analysis_summary']   = '✅ %ok% OK · ⚠️ %warnings% warnings · ❌ %errors% errors';
$lang['dashboard']['packages_title'] = 'Installed packages &amp; available updates';
$lang['dashboard']['packages_desc'] = 'Shows all installed Composer packages. Click "Check updates" to query Packagist directly for '
    . 'the latest stable version of every package — independent of the current composer.json constraints. '
    . '<br><strong>Note:</strong> "Update available" only means a newer version exists on Packagist. '
    . 'Whether it can actually be installed depends on dependency constraints and may require '
    . 'updating several packages together (e.g. for a Contao major upgrade). '
    . 'Results are cached for 24 hours.';
$lang['dashboard']['packages_load']    = '📦 Load packages';
$lang['dashboard']['packages_refresh'] = '🔄 Check updates';
$lang['dashboard']['packages_filter_placeholder'] = 'Filter by name...';
$lang['dashboard']['packages_only_updates']       = 'Show only packages with updates';
$lang['dashboard']['packages_loading']         = 'Loading installed packages...';
$lang['dashboard']['packages_refreshing']      = '⏳ Querying Packagist...';
$lang['dashboard']['packages_loading_short']   = '⏳ Loading...';
$lang['dashboard']['packages_reload']          = '📦 Reload packages';
$lang['dashboard']['packages_refresh_done']    = '🔄 Refresh available updates';
$lang['dashboard']['packages_no_match']        = 'No packages match the current filter.';
$lang['dashboard']['packages_cached_note']     = ' (cached — click "Check updates" to refresh)';
$lang['dashboard']['packages_check_incomplete'] = '⚠️ Update check incomplete: %error%';
$lang['dashboard']['packages_meta'] = 'Total: <strong>%total%</strong> packages · Updates available: <strong>%updates%</strong> · Abandoned: <strong>%abandoned%</strong>';
$lang['dashboard']['packages_th_package']   = 'Package';
$lang['dashboard']['packages_th_current']   = 'Current';
$lang['dashboard']['packages_th_available'] = 'Available';
$lang['dashboard']['packages_th_status']    = 'Status';
$lang['dashboard']['packages_blocked_title'] = 'A newer version exists on Packagist, but it cannot be installed because of constraints in '
    . 'other packages (e.g. Symfony version requirements). Updating Contao usually needs an update of '
    . 'contao/manager-bundle, which pulls everything along.';
$lang['dashboard']['packages_tag_blocked_short'] = '⚠ blocked';
$lang['dashboard']['packages_tag_blocked']       = 'blocked';
$lang['dashboard']['packages_tag_blocked_title'] = 'An update exists but is blocked by constraints from other packages';
$lang['dashboard']['packages_tag_update']        = 'Update';
$lang['dashboard']['packages_tag_uptodate']      = 'up to date';
$lang['dashboard']['packages_tag_abandoned']     = 'abandoned';

// ── Backup tab ───────────────────────────────────────────────────────
$lang['backup']['title'] = 'Backups';
$lang['backup']['desc'] = 'A full backup contains <code>composer.json</code>, <code>composer.lock</code>, the database (gzip-compressed) '
    . 'and the <code>vendor/</code> and <code>templates/</code> directories. Backups are stored under '
    . '<code>var/updater/backup/&lt;timestamp&gt;</code> and are never overwritten.';
$lang['backup']['components_legend'] = 'Backup contents';
$lang['backup']['comp_core_title'] = 'composer.json + composer.lock + database';
$lang['backup']['comp_core_desc']  = 'Always included — small (a few MB).';
$lang['backup']['comp_vendor_title'] = '<strong>vendor/</strong> directory';
$lang['backup']['comp_vendor_desc']  = 'Composer dependencies. Recommended. Typically 100–500 MB.';
$lang['backup']['comp_templates_title'] = '<strong>templates/</strong> + <strong>contao/templates/</strong>';
$lang['backup']['comp_templates_desc']  = 'Your own Twig and HTML5 templates. Small.';
$lang['backup']['comp_files_title']   = '<strong>files/</strong> directory';
$lang['backup']['comp_files_warning'] = '(can be very large!)';
$lang['backup']['comp_files_desc']    = 'User uploads — images, PDFs, videos. Often several GB. Without this directory, a rollback can '
    . 'leave inconsistent database references and missing media files.';
$lang['backup']['comp_assets_title'] = '<strong>assets/</strong> directory';
$lang['backup']['comp_assets_desc']  = 'Generated images, web fonts and the JS/CSS asset cache. Usually rebuildable.';
$lang['backup']['create_now']  = '💾 Create backup now';
$lang['backup']['create_more'] = '💾 Create another backup';
$lang['backup']['creating']    = 'Creating backup...';
$lang['backup']['running_wait'] = 'Backup in progress. Please wait — this runs synchronously, the page won\'t reload until it\'s done.';
$lang['backup']['confirm']              = 'Create a backup now?\n\nThis runs synchronously — keep this tab open until it finishes.';
$lang['backup']['confirm_files_warning'] = '\n\n⚠️ files/ is selected — this can take several minutes with many uploads.';
$lang['backup']['failed']  = 'Backup failed: %error%';
$lang['backup']['created'] = 'Backup created: <strong>%name%</strong> · %size%';
$lang['backup']['row_database']    = 'Database';
$lang['backup']['row_vendor']      = 'vendor/';
$lang['backup']['row_templates']   = 'templates/';
$lang['backup']['row_contao_tpl']  = 'contao/templates/';
$lang['backup']['row_files']       = 'files/';
$lang['backup']['row_assets']      = 'assets/';
$lang['backup']['empty'] = 'No backups yet. Use "Create backup now" or wait for one to be created automatically before the next update.';
$lang['backup']['delete'] = 'Delete';
$lang['backup']['delete_confirm'] = 'Delete backup "%name%"?\nThis cannot be undone.';
$lang['backup']['delete_failed']  = 'Delete failed: %error%';

// ── Scheduled backups ────────────────────────────────────────────────
$lang['sched']['title'] = 'Scheduled backups';
$lang['sched']['lock_notice'] = '🔒 The scheduled backup system requires a valid <strong>Pro licence</strong>. '
    . 'Enter your key under <strong>Contao → Settings → Guardian Licence management</strong> to unlock it. '
    . 'Manual backups above remain available.';
$lang['sched']['desc'] = 'Runs backups automatically via Contao\'s cron system. Configure two independent schedules: '
    . '<strong>mini</strong> backups (database + Composer files only — fast and small) and '
    . '<strong>full</strong> backups (database + selected directories — larger and slower). '
    . 'Old backups are deleted automatically once the retention limit is reached.';
$lang['sched']['mini_title'] = '🗄️ Mini backup (DB only)';
$lang['sched']['mini_desc']  = 'Only the database and Composer files. Fast (a few seconds).';
$lang['sched']['mini_enable'] = 'Enable scheduled mini backups';
$lang['sched']['full_title'] = '📦 Full backup';
$lang['sched']['full_desc']  = 'Database + selected directories. Slower (minutes to ten minutes).';
$lang['sched']['full_enable'] = 'Enable scheduled full backups';
$lang['sched']['frequency']       = 'Frequency';
$lang['sched']['optgroup_test']   = '🧪 Testing (interval-based)';
$lang['sched']['optgroup_prod']   = 'Production';
$lang['sched']['freq_5min']   = 'Every 5 minutes';
$lang['sched']['freq_15min']  = 'Every 15 minutes';
$lang['sched']['freq_hourly'] = 'Hourly';
$lang['sched']['freq_daily']   = 'Daily';
$lang['sched']['freq_weekly']  = 'Weekly';
$lang['sched']['freq_monthly'] = 'Monthly';
$lang['sched']['time']    = 'Time';
$lang['sched']['weekday'] = 'Weekday';
$lang['sched']['weekday_0'] = 'Sunday';
$lang['sched']['weekday_1'] = 'Monday';
$lang['sched']['weekday_2'] = 'Tuesday';
$lang['sched']['weekday_3'] = 'Wednesday';
$lang['sched']['weekday_4'] = 'Thursday';
$lang['sched']['weekday_5'] = 'Friday';
$lang['sched']['weekday_6'] = 'Saturday';
$lang['sched']['day_of_month'] = 'Day of month';
$lang['sched']['retention']      = 'Keep last';
$lang['sched']['retention_unit'] = 'backups';
$lang['sched']['run_mini_now'] = '▶ Run mini now';
$lang['sched']['run_full_now'] = '▶ Run full now';
$lang['sched']['full_components_legend'] = 'Include in full backup:';
$lang['sched']['full_comp_vendor']    = 'vendor/';
$lang['sched']['full_comp_templates'] = 'templates/ + contao/templates/';
$lang['sched']['full_comp_files']     = 'files/';
$lang['sched']['full_comp_files_warn'] = '(can be huge — risky with web cron!)';
$lang['sched']['files_warning'] = '⚠️ <strong>Warning:</strong> including <code>files/</code> in scheduled backups without a real cron is risky. '
    . 'With web cron, the backup runs during a page request (in the background) — if it takes longer than '
    . 'a few minutes and the host kills the PHP worker, the backup will be incomplete. Test once '
    . 'with "▶ Run full now" to estimate the size before scheduling it.';
$lang['sched']['full_comp_assets'] = 'assets/';
$lang['sched']['storage_notify_title'] = 'Storage location &amp; notifications';
$lang['sched']['storage_path']        = 'Storage path';
$lang['sched']['storage_placeholder'] = '(default: var/updater/backup)';
$lang['sched']['storage_hint'] = 'Absolute path for storing backups. Leave empty for the default. '
    . 'Avoid <code>vendor/</code>, <code>public/</code> and <code>files/</code>.';
$lang['sched']['recipient']             = 'Recipient';
$lang['sched']['recipient_placeholder'] = 'admin@example.com';
$lang['sched']['sender_email']             = 'Sender e-mail';
$lang['sched']['sender_email_placeholder'] = '(optional — falls back to the Contao admin e-mail)';
$lang['sched']['sender_name']             = 'Sender name';
$lang['sched']['sender_name_placeholder'] = 'Guardian';
$lang['sched']['sender_hint'] = 'Leave empty to use Contao\'s <strong>System → Settings → Administrator e-mail address</strong>. '
    . 'Only override this if your hosting requires a specific sender (e.g. a mailbox set up in '
    . 'Plesk/cPanel like <code>noreply@your-domain.com</code>) and you cannot change the Contao admin e-mail.';
$lang['sched']['notify_success'] = 'Notify on successful backup';
$lang['sched']['notify_failure'] = 'Notify on failed backup';
$lang['sched']['send_test']      = '✉️ Send test e-mail now';
$lang['sched']['send_test_hint'] = 'Saves first, then sends a test e-mail with the current settings.';
$lang['sched']['save']        = '💾 Save schedule';
$lang['sched']['saving']      = 'Saving...';
$lang['sched']['saved']       = '✅ Saved';
$lang['sched']['save_failed'] = 'Save failed';
$lang['sched']['run_now_confirm']   = 'Run a %type% backup now?\n\nThis ignores the schedule and triggers a backup immediately.';
$lang['sched']['running_now']       = '⏳ Running...';
$lang['sched']['never_ran']         = 'Never run yet.';
$lang['sched']['running_since']     = '<strong>Running...</strong> started %date% · elapsed: %elapsed%';
$lang['sched']['last_run']          = '<strong>Last run:</strong> %date% · %status%';
$lang['sched']['next_run']          = '<strong>Next run:</strong> %date%';
$lang['sched']['next_run_approx']   = ' (approximate — fires on the first page request after this time)';
$lang['sched']['test_email_sending']         = 'Saving configuration and sending mail...';
$lang['sched']['test_email_save_failed']     = 'Save failed: %error%';
$lang['sched']['test_email_sent']            = '✅ Test e-mail sent successfully';
$lang['sched']['test_email_sender_used']     = 'Sender used: %sender%';
$lang['sched']['test_email_check_inbox']     = 'Check the inbox of %recipient%';
$lang['sched']['test_email_failed']          = '❌ Test e-mail failed';
$lang['sched']['test_email_sender_attempted'] = 'Sender attempted: %sender%';

// ── Cron documentation (long-form reference content) ────────────────
$lang['cron']['how_title'] = '⚙️ How scheduled backups are triggered';
$lang['cron']['how_intro'] = 'This bundle hooks into Contao\'s built-in cron system. You don\'t have to choose between the '
    . 'two methods — they work together and you can switch later without changing the bundle.';
$lang['cron']['option1_summary'] = 'Option 1 — Web cron';
$lang['cron']['option1_summary_note'] = '(default, no setup required)';
$lang['cron']['option1_badge'] = 'currently active';
$lang['cron']['option1_body'] = '<p>If you do nothing, Contao runs cron jobs at the end of every frontend or backend page request, '
    . 'after the response has been sent to the user. The visitor does not wait.</p>'
    . '<p><strong>Trade-offs:</strong></p>'
    . '<ul>'
    . '<li>✅ No setup needed.</li>'
    . '<li>✅ The user never waits — backups run in <code>kernel.terminate</code>.</li>'
    . '<li>⚠️ <strong>The schedule is approximate:</strong> a backup scheduled for 03:00 only starts on the '
    . 'first page request after 03:00. Without overnight traffic it might not run until 08:30.</li>'
    . '<li>⚠️ Long-running backups (especially with <code>files/</code>) can hit PHP-FPM limits on shared hosting.</li>'
    . '</ul>'
    . '<p style="background:var(--updater-bg-stat);padding:.6rem .8rem;border-radius:3px;font-size:.8rem;">'
    . '🧪 <strong>Testing tip:</strong> the "Every 5 minutes / 15 minutes / Hourly" frequencies are '
    . 'interval-based — they fire once that much time has passed since the last run, regardless of the '
    . 'time of day. With web cron this still depends on someone visiting the site and thereby triggering '
    . 'Contao\'s cron framework. Without traffic, reloading any backend page is enough.'
    . '</p>';
$lang['cron']['option2_summary'] = 'Option 2 — Real cron';
$lang['cron']['option2_summary_note'] = '(recommended for production)';
$lang['cron']['option2_badge'] = 'additional setup';
$lang['cron']['option2_intro'] = '<p>Set up your hosting so <code>contao:cron</code> is called on a schedule. '
    . 'Backups then run on time, even without visitors, and don\'t share resources with web requests.</p>'
    . '<p><strong>The command:</strong></p>';
$lang['cron']['option2_command_hint'] = '<p style="font-size:.75rem;color:var(--updater-text-muted);">'
    . 'Adjust the PHP path if needed. Many hosts detect it automatically — often <code>contao-console contao:cron</code> is enough.'
    . '</p>';
$lang['cron']['plesk_title']    = '🔵 Plesk';
$lang['cron']['plesk_body'] = '<li>Open <em>Domains → your domain → Scheduled Tasks</em></li>'
    . '<li>Click <strong>Add Task</strong></li>'
    . '<li>Task type: <strong>Run a command</strong></li>'
    . '<li>Command:<pre class="updater-cron-cmd">vendor/bin/contao-console contao:cron</pre></li>'
    . '<li>Run: <strong>Cron style</strong> → <code class="updater-cron-inline">*/5 * * * *</code> (every 5 minutes)</li>'
    . '<li>Save. Plesk runs the command from the domain root by default.</li>';
$lang['cron']['cpanel_title'] = '🟠 cPanel';
$lang['cron']['cpanel_body'] = '<li>Open <em>Advanced → Cron Jobs</em></li>'
    . '<li>Common Settings: <strong>Every 5 minutes</strong> (<code>*/5 * * * *</code>)</li>'
    . '<li>Command:<pre class="updater-cron-cmd">cd ~/yoursite.com && /usr/local/bin/php vendor/bin/contao-console contao:cron &gt;/dev/null 2&gt;&amp;1</pre></li>'
    . '<li>Replace <code>~/yoursite.com</code> with your actual document root.</li>'
    . '<li>Save.</li>';
$lang['cron']['directadmin_title'] = '🟢 DirectAdmin';
$lang['cron']['directadmin_body'] = '<li>Open <em>Account Manager → Cron Jobs</em></li>'
    . '<li>Set the schedule fields manually: '
    . 'Minute=<code class="updater-cron-inline">*/5</code>, '
    . 'Hour=<code class="updater-cron-inline">*</code>, Day=<code class="updater-cron-inline">*</code>, '
    . 'Month=<code class="updater-cron-inline">*</code>, Weekday=<code class="updater-cron-inline">*</code>'
    . '</li>'
    . '<li>Command:<pre class="updater-cron-cmd">cd /home/USER/domains/yoursite.com/public_html && php vendor/bin/contao-console contao:cron</pre></li>';
$lang['cron']['ssh_title'] = '⚫ SSH / direct crontab';
$lang['cron']['ssh_body'] = '<li>Log in via SSH</li>'
    . '<li>Run <code>crontab -e</code></li>'
    . '<li>Insert:<pre class="updater-cron-cmd">*/5 * * * * /usr/bin/php /full/path/to/site/vendor/bin/contao-console contao:cron &gt;/dev/null 2&gt;&amp;1</pre></li>'
    . '<li>Save and exit (<code>:wq</code> in vim, <code>Ctrl+X Y</code> in nano).</li>';
$lang['cron']['option2_frequency_hint'] = '<p style="font-size:.8rem;margin-top:.8rem;"><strong>Frequency recommendation:</strong> '
    . 'every 5 minutes is perfectly fine. The scheduler internally rate-limits our backup job to "hourly" — '
    . 'more frequent cron runs only reduce drift.</p>';
$lang['cron']['both_summary'] = 'Both at once?';
$lang['cron']['both_summary_note'] = 'Yes — they coexist without any issues.';
$lang['cron']['both_body'] = '<p>If you set up a real cron <em>and</em> have web traffic, the bundle handles both fine:</p>'
    . '<ul>'
    . '<li>Contao remembers the last run time of every cron job. Whichever fires first wins — '
    . 'the second sees "already ran this hour, skipping".</li>'
    . '<li>An additional file lock (<code>flock()</code> on <code>var/updater/backup.lock</code>) also prevents '
    . 'two backups from running in parallel.</li>'
    . '<li>You can remove the real cron again later — the web fallback takes over automatically. '
    . 'Or the other way round: set up cron afterwards, it just works.</li>'
    . '</ul>'
    . '<p><strong>Recommendation:</strong> set up a real cron if your hosting allows it. The '
    . 'web fallback then becomes a safety net — backups still run if the cron service ever fails.</p>';
$lang['cron']['reference'] = 'Reference: <a href="https://docs.contao.org/5.x/manual/en/performance/cronjobs/" target="_blank" rel="noopener">Contao cron jobs documentation</a>';

// ── Update tab ───────────────────────────────────────────────────────
$lang['update']['run_title'] = '🔄 Run update';
$lang['update']['run_desc'] = 'Updates run as background jobs in a separate PHP process — the browser doesn\'t need to stay open. '
    . 'Progress and the live log update automatically. Start with a <strong>dry run</strong> to see '
    . 'what <em>would happen</em> without changing anything.';
$lang['update']['syslog_hint'] = '💡 Important events from updates and scheduled backups are also written to Contao\'s '
    . '<strong>system log</strong> (System → System log, action <code>VTINNOVATIONS_GUARDIAN</code>). '
    . 'That lets you review what happened afterwards, even once the live log here is gone.';
$lang['update']['dry_run']     = '🧪 Dry run (safe simulation)';
$lang['update']['real_update'] = '▶ Real update…';
$lang['update']['pre_snapshot_hint'] = 'A pre-update snapshot is created before every update. If the update fails, a one-click rollback is available.';
$lang['update']['modal_title'] = '▶ Start a real update';
$lang['update']['modal_intro'] = 'This changes your live site. A pre-update snapshot is created first so you can roll back.';
$lang['update']['mode_title'] = 'Update mode';
$lang['update']['mode_full_label']  = 'Full — update everything within the composer.json constraints';
$lang['update']['mode_full_desc']   = 'Updates all packages to the highest versions allowed by composer.json. The most common choice.';
$lang['update']['mode_patch_label'] = 'Conservative — prefer stable releases';
$lang['update']['mode_patch_desc']  = 'Like full, but without pre-release versions. For strictly patch-only, pin to <code>~X.Y.Z</code> in composer.json.';
$lang['update']['mode_selective_label'] = 'Selective — choose individual packages';
$lang['update']['mode_selective_desc']  = 'Choose exactly which packages get updated. Dependencies are pulled in automatically.';
$lang['update']['packages_loading'] = 'Loading packages…';
$lang['update']['snapshot_title'] = 'Pre-update snapshot';
$lang['update']['snapshot_vendor_label'] = 'Include <code>vendor/</code> in the snapshot (recommended)';
$lang['update']['snapshot_vendor_desc']  = 'Allows a full rollback. Without this, the rollback only covers Composer files + DB, '
    . 'and you would need to run <code>composer install</code> manually afterwards.';
$lang['update']['recovery_email_title'] = 'Recovery e-mail';
$lang['update']['recovery_email_label'] = '📧 Send recovery URLs + access token by e-mail <strong>before</strong> the update';
$lang['update']['recovery_email_desc'] = 'If something goes wrong, this e-mail is your lifeline. Configure the recipient under '
    . '<a href="#tab=settings" onclick="updaterSwitchTab(\'settings\', true)" style="color:var(--updater-text-link);">Settings → Recovery e-mail</a>. '
    . '<strong>Delete the e-mail after a successful update</strong> — it contains your full access token.';
$lang['update']['cancel']     = 'Cancel';
$lang['update']['start_now']  = '▶ Start update now';
$lang['update']['job_history_summary'] = '▸ Recent job history';
$lang['update']['job_history_loading'] = 'Loading...';
$lang['update']['no_previous_jobs']    = 'No previous jobs yet.';
$lang['update']['dry_run_confirm'] = 'Start a dry run?\n\nThis simulates an update — composer runs with --dry-run, as does contao:migrate. '
    . 'A REAL backup is still created (safety net). Nothing else is changed.';
$lang['update']['real_confirm'] = 'Start a REAL update?\n\nMode: %mode%\n%snapshot_note%\n\nThis changes your live site. It will be in maintenance mode during the update.';
$lang['update']['mode_label_full']      = 'FULL (all packages within the composer.json constraints)';
$lang['update']['mode_label_patch']     = 'CONSERVATIVE (prefer stable releases)';
$lang['update']['mode_label_selective'] = 'SELECTIVE (%count% package(s))';
$lang['update']['snapshot_note_with_vendor']    = 'The pre-snapshot includes vendor/ — a full rollback is possible.';
$lang['update']['snapshot_note_without_vendor'] = 'The pre-snapshot only includes Composer + DB — a rollback requires running `composer install` manually.';
$lang['update']['select_at_least_one'] = 'Please select at least one package to update.';
$lang['update']['packages_all_current'] = 'All packages are up to date — nothing to update.';
$lang['update']['packages_updates_available'] = 'Updates are available for %count% package(s). Choose which ones to update:';
$lang['update']['select_all']   = 'Select all';
$lang['update']['deselect_all'] = 'Deselect all';
$lang['update']['load_error']   = 'Error: %error%';
$lang['update']['worker_failed_title'] = '❌ Worker could not be started';
$lang['update']['worker_failed_hint'] = 'Open the "PHP CLI settings" section below and enter the correct path to the PHP CLI binary '
    . '(e.g. /opt/plesk/php/8.4/bin/php on Plesk), then try again.';
$lang['update']['email_failed_title'] = '❌ Recovery e-mail could not be sent';
$lang['update']['email_failed_body'] = 'The update was NOT started. Options:\n'
    . '  • Fix the mail configuration (Settings → Recovery e-mail) and try again, or\n'
    . '  • Uncheck "Send recovery URLs by e-mail" in the update dialog and proceed without e-mail';
$lang['update']['email_failed_hint'] = '\n\nHint: %hint%';
$lang['update']['blocked_title'] = 'Another job is in the way:';
$lang['update']['blocked_hint'] = 'Use the "⛔ Force abort" or "🗑 Clear stale job" button on the active job to free up the queue.';
$lang['update']['job_start_failed'] = 'Job could not be started: %error%';

// ── Job runner (client-rendered) ────────────────────────────────────
$lang['job']['live_log'] = 'Live log';
$lang['job']['finished_hint'] = 'Job finished. Reload the page to reset, or start another one directly.';
$lang['job']['update_failed_rollback_title'] = '⚠️ Update failed — rollback available';
$lang['job']['update_failed_rollback_body'] = 'A pre-update snapshot was created before the changes (%snapshot%). '
    . 'Click below to restore it and revert the site to its state before the failed update.';
$lang['job']['auto_rollback_button'] = '↩️ Automatic rollback to pre-snapshot';
$lang['job']['auto_rollback_fallback_hint'] = 'If this button doesn\'t work (e.g. because the failed update broke Contao itself), '
    . 'use the <strong>standalone recovery panel</strong> from the Recovery tab — it works without Contao or Symfony.';
$lang['job']['stale_warning'] = '⚠️ <strong>This job looks stale.</strong> %reason%';
$lang['job']['clear_stale_button'] = '🗑 Clear stale job';
$lang['job']['force_abort_button'] = '⛔ Force abort';
$lang['job']['force_abort_hint'] = '(use this when you know for sure the job is stuck and you cannot wait)';
$lang['job']['rollback_confirm'] = 'Roll back to the pre-update snapshot?\n\n'
    . 'Composer files, the database and (if included) the vendor/ directory will be reset to the state '
    . 'before the failed update.\n\n'
    . 'The site will be in maintenance mode during the rollback.';
$lang['job']['rollback_started'] = 'Rollback started. Follow the live log below.';
$lang['job']['rollback_failed']  = 'Rollback failed: %error%';
$lang['job']['rollback_request_failed'] = 'Rollback request failed: %error%';
$lang['job']['clear_stale_confirm_force'] = 'Force-abort this job?\n\n'
    . 'The job has not yet been detected as stale, but you are aborting it anyway. '
    . 'Only use this when you know for sure the worker is really stuck. Partially completed work is NOT undone.';
$lang['job']['clear_stale_confirm'] = 'Clear the stale job?\n\n'
    . 'This aborts the previous job (which apparently crashed or never started) so you can start a new one. '
    . 'It does NOT undo any work already done — it only frees up the queue slot.';
$lang['job']['clear_stale_failed'] = 'Clearing failed: %error%';
$lang['job']['clear_stale_request_failed'] = 'Failed: %error%';
$lang['job']['job_label'] = 'Job';

// ── Settings tab ─────────────────────────────────────────────────────
$lang['settings']['license_title'] = '🔑 Licence';
$lang['settings']['license_desc'] = 'Licence management is centralised under '
    . '<strong>Contao → Settings → Guardian Licence management</strong>. '
    . 'That\'s where you activate your key, refresh it and remove it again. '
    . 'Without a valid licence, only the <strong>manual backup</strong> module is available '
    . '(Free), or nothing at all.';
$lang['settings']['license_link'] = '🔗 Get a licence at';
$lang['settings']['license_free_pro'] = '(Free &amp; Pro)';
$lang['settings']['license_goto'] = '→ Go to licence management';
$lang['settings']['recovery_email_title'] = '📧 Recovery e-mail notifications';
$lang['settings']['recovery_email_desc'] = 'When a real update starts, the panel can e-mail recovery URLs and an access token '
    . '<strong>before</strong> the update begins, to a configured address. This is essential — '
    . 'if the update breaks the site, you can rescue yourself using the URLs in the e-mail, even without access to this backend.';
$lang['settings']['recipient']             = 'Recipient';
$lang['settings']['recipient_placeholder'] = 'admin@example.com';
$lang['settings']['sender_optional']       = 'Sender (optional)';
$lang['settings']['sender_placeholder']    = 'leave empty to use the recipient address';
$lang['settings']['sender_hint'] = 'Leave the sender field empty unless your mail server requires a specific From address. On Plesk/cPanel, '
    . 'most shared hosts require From to be a real mailbox on the server — if the test e-mail fails, '
    . 'set the sender to a real mailbox address set up in Plesk.';
$lang['settings']['save']           = 'Save';
$lang['settings']['send_test_mail'] = 'Send test e-mail';
$lang['settings']['recipient_invalid'] = '⚠️ Invalid recipient e-mail format';
$lang['settings']['sender_invalid']    = '⚠️ Invalid sender e-mail format';
$lang['settings']['saved_short']    = '✓ Saved';
$lang['settings']['save_failed']    = '❌ %error%';
$lang['settings']['request_failed'] = '❌ %error%';
$lang['settings']['no_recipient']   = '⚠️ Enter and save a recipient first';
$lang['settings']['sending_test']   = '⏳ Sending…';
$lang['settings']['test_sent']      = '✓ Test e-mail sent to %recipient%';
$lang['settings']['test_failed_short'] = '❌ Failed';
$lang['settings']['test_failed_alert'] = 'Test e-mail failed:\n\n%error%%hint%';
$lang['settings']['test_failed_hint']  = '\n\nHint: %hint%';
$lang['settings']['php_title'] = '⚙️ PHP CLI settings';
$lang['settings']['php_desc'] = 'Updates and restores run in a background PHP process. We need the <strong>absolute path to the PHP CLI binary</strong> '
    . '(not the FPM/web version). On Plesk it typically looks like this: '
    . '<code>/opt/plesk/php/8.4/bin/php</code> — the same path the Contao Manager uses. '
    . 'Leave empty for auto-detection.';
$lang['settings']['php_binary_label']       = 'PHP binary';
$lang['settings']['php_binary_placeholder'] = '(auto-detected, leave empty if unsure)';
$lang['settings']['php_test'] = '🔍 Test';
$lang['settings']['php_save'] = '💾 Save';
$lang['settings']['php_no_candidates'] = 'No common PHP paths were found on this server.';
$lang['settings']['php_suggestions']   = 'Suggestions: ';
$lang['settings']['php_testing']       = 'Testing...';
$lang['settings']['php_test_failed']   = '❌ Test failed';
$lang['settings']['php_saving']        = 'Saving...';
$lang['settings']['php_saved']         = '✅ Saved';
$lang['settings']['php_save_failed']   = '❌ %error%';

// ── Recovery tab ─────────────────────────────────────────────────────
$lang['recovery']['filename_title'] = '🛟 Recovery panel filename';
$lang['recovery']['filename_desc'] = 'The standalone recovery panel is copied to <code>public/&lt;filename&gt;</code> on kernel boot. '
    . 'A custom filename makes it harder for scanners to find (security through obscurity, in addition '
    . 'to token authentication). Must end in <code>.php</code>, letters/digits/<code>._-</code> only, '
    . 'max. 60 characters.';
$lang['recovery']['filename_label']       = 'Filename';
$lang['recovery']['filename_placeholder'] = '_updater-recovery.php';
$lang['recovery']['filename_hint'] = 'Example: <code>secret-panel-xyz.php</code> → reachable at <code>https://your-site/secret-panel-xyz.php</code>. '
    . 'When renaming, the previous file is removed automatically on the next boot.';
$lang['recovery']['filename_save'] = '💾 Save';
$lang['recovery']['filename_invalid'] = '❌ Invalid filename (A-Z, 0-9, ._- only; must end in .php)';
$lang['recovery']['filename_saving'] = 'Saving...';
$lang['recovery']['filename_saved'] = '✅ Saved — panel available at %filename% (clear the cache so the next boot deploys it)';
$lang['recovery']['filename_save_failed']    = '❌ %error%';
$lang['recovery']['filename_request_failed'] = '❌ %error%';
$lang['recovery']['why_title'] = '↩️ How restore works in this bundle';
$lang['recovery']['why_intro'] = 'Restore is an <strong>out-of-band operation</strong> — deliberately <em>not</em> triggered from '
    . 'this Contao backend. Two reasons:';
$lang['recovery']['why_reason1'] = 'When Contao is running, you usually don\'t need a restore. When you do need one, Contao is probably '
    . 'broken — and you can no longer reach this tab anyway.';
$lang['recovery']['why_reason2'] = 'Restoring <code>vendor/</code> while Symfony is reading from the same directory is dangerous — '
    . 'the backend would crash in the middle of the restore.';
$lang['recovery']['why_outro'] = 'Use one of the two recovery panels below. Both have their own token-based authentication and '
    . 'run independently of the Contao backend.';
$lang['recovery']['standalone_title'] = '🆘 Standalone recovery panel (works even when Contao is broken)';
$lang['recovery']['standalone_desc'] = 'This is a separate <strong>single-file PHP script</strong> that gets installed automatically at '
    . '<code>public/_updater-recovery.php</code>. It runs <strong>without Symfony, Composer or Contao</strong> — '
    . 'even if a botched update breaks the whole framework, you can still reach it via the web server '
    . 'and restore from a backup.';
$lang['recovery']['standalone_howto_intro'] = '<strong>How to use it in an emergency:</strong>';
$lang['recovery']['standalone_howto_step1'] = 'Open %link% in your browser';
$lang['recovery']['standalone_howto_step2'] = 'The browser shows a basic auth dialog → enter ANY username and your access token (see the token section below) as the password';
$lang['recovery']['standalone_howto_step3'] = 'Select a backup and restore it directly';
$lang['recovery']['standalone_save_warning'] = '<strong>⚠️ Save the URL + token NOW, outside of Contao</strong> (a password manager, a note, wherever). '
    . 'If Contao goes down, you won\'t be able to copy them from this page anymore.';
$lang['recovery']['standalone_regenerated_hint'] = 'The file is regenerated on every bundle update — you don\'t need to maintain it manually. '
    . 'It uses the access token shown below.';
$lang['recovery']['token_title'] = '🔑 Access token';
$lang['recovery']['token_desc'] = 'The standalone recovery panel uses HTTP basic auth. The username can be anything; '
    . 'the password is the access token managed here.';
$lang['recovery']['token_loading'] = 'Loading…';
$lang['recovery']['token_env_fix_summary'] = '▸ Pin the token via <code>.env.local</code>';
$lang['recovery']['token_env_fix_intro'] = '<p>For a stable, version-controllable token, add this line to <code>.env.local</code>:</p>';
$lang['recovery']['token_env_fix_command_hint'] = '<p>The bundle prefers env over the auto-generated file. After editing <code>.env.local</code>, clear the Symfony cache.</p>';
$lang['recovery']['token_env_fix_warning'] = '⚠️ <strong>Test the panel BEFORE an update.</strong> If you can\'t reach it now, '
    . 'you won\'t be able to in an emergency either.';
$lang['recovery']['token_source_env']       = 'from .env';
$lang['recovery']['token_source_generated'] = 'auto-generated';
$lang['recovery']['token_source_label'] = 'Source: %badge%';
$lang['recovery']['token_rotate_button'] = '🔄 Rotate token to get a fresh copy';
$lang['recovery']['token_env_note'] = 'The token is set in <code>.env.local</code>. To rotate it, change the value of <code>VTINNOVATIONS_GUARDIAN_TOKEN</code> '
    . 'there and clear the Symfony cache.';
$lang['recovery']['token_file_note'] = 'For security reasons, only a preview of the token is shown here. To see the full token '
    . '(e.g. to bookmark it or save it in a password manager), click <strong>Rotate token</strong> — a '
    . 'fresh token will be generated and shown once. The previous token becomes invalid immediately.';
$lang['recovery']['token_masked_alert'] = 'The token shown here is a masked preview. Click "Rotate token" '
    . 'to generate a fresh token and show it once.';
$lang['recovery']['token_copied'] = '✅ Copied';
$lang['recovery']['token_rotate_confirm'] = 'Rotate the access token?\n\nThe old token becomes invalid immediately. '
    . 'The new token is shown once — copy and save it now. '
    . 'After closing the dialog or reloading, only a masked preview is visible again.';
$lang['recovery']['token_rotate_failed'] = 'Rotation failed';
$lang['recovery']['token_new_warning_title'] = '⚠️ New token generated — save it now';
$lang['recovery']['token_new_warning_body'] = 'It is shown only this one time. After clicking "I\'ve saved it" '
    . 'or leaving the page, only a masked preview will be available.';
$lang['recovery']['token_copy_button']    = '📋 Copy token';
$lang['recovery']['token_dismiss_button'] = 'I\'ve saved it — hide';

// ── Upgrade modal ────────────────────────────────────────────────────
$lang['upgrade']['title_pro']  = '🔒 This feature needs the Pro package';
$lang['upgrade']['title_none'] = '🔒 This feature needs at least a Free licence';
$lang['upgrade']['body_none'] = 'Without a licence, every feature except the dashboard and settings is locked. '
    . 'A <strong>free licence</strong> unlocks manual backup; '
    . 'the <strong>Pro licence</strong> additionally unlocks updates, restore/recovery, scheduled '
    . 'backups and the standalone recovery panel.';
$lang['upgrade']['body_pro_intro'] = 'You are currently on the <strong>Free package</strong>, which gives you access to '
    . '<strong>manual backup</strong>.';
$lang['upgrade']['body_pro_from_free'] = 'You are currently on the <strong>Free package</strong>, which gives you access to '
    . '<strong>manual backup</strong>. Updates, restore/recovery, scheduled backups and the standalone '
    . 'recovery panel need the Pro licence.';
$lang['upgrade']['body_pro_unlocks'] = 'With the <strong>Pro licence</strong> you unlock:';
$lang['upgrade']['feature_updates']  = 'Update jobs (Composer full / patch / selective)';
$lang['upgrade']['feature_restore']  = 'Restore / recovery from snapshots';
$lang['upgrade']['feature_schedule'] = 'Scheduled backups (mini + full, with e-mail notifications)';
$lang['upgrade']['feature_panel']    = 'Standalone recovery panel for emergencies';
$lang['upgrade']['get_license']  = 'Get a licence at';
$lang['upgrade']['activate_at']  = 'Activate it under <strong>Contao → Settings → Guardian Licence management</strong>.';
$lang['upgrade']['close']        = 'Close';
$lang['upgrade']['goto_license'] = '→ Go to licence management';

// ── Shared/misc ──────────────────────────────────────────────────────
$lang['msc']['error_generic'] = 'Unknown error';
$lang['msc']['generic_error'] = 'Error: %error%';

// ── Backend API responses (JSON error/message strings) ──────────────
$lang['api']['backup_name_missing']        = 'Missing backup name';
$lang['api']['backup_not_found']           = 'Backup not found or could not be deleted';
$lang['api']['invalid_payload']            = 'Invalid payload';
$lang['api']['storage_path_invalid']       = 'Storage path is invalid: %errors%';
$lang['api']['invalid_backup_type']        = 'Invalid backup type';
$lang['api']['job_id_missing']             = 'Job ID missing';
$lang['api']['job_not_found_in_archive']   = 'Job not found in archive: %id%';
$lang['api']['no_pre_snapshot']            = 'No pre-snapshot associated with this job. Manual restore required.';
$lang['api']['rollback_started_from']      = 'Rollback started from snapshot %snapshot%';
$lang['api']['unknown_job_type']           = 'Unknown job type: %type%';
$lang['api']['recovery_email_send_failed'] = 'Recovery e-mail could not be sent: %error%. '
    . 'Disable the e-mail checkbox or fix the mail configuration before starting the update.';
$lang['api']['no_active_job']              = 'No active job';
$lang['api']['job_not_stale_yet']          = 'Job is not detected as stale yet. Pass force=true to abort it anyway.';
$lang['api']['job_force_cleared']          = 'Force-cleared from backend (user-initiated abort)';
$lang['api']['job_cleared_as_stale']       = 'Cleared as stale from backend';
$lang['api']['php_binary_check_failed']    = 'PHP binary check failed: %error%';
$lang['api']['composer_phar_absolute']     = 'Composer phar must be an absolute path (start with /)';
$lang['api']['composer_phar_extension']    = 'Composer phar should be a .phar file';
$lang['api']['token_from_env']             = 'Token comes from .env. Edit VTINNOVATIONS_GUARDIAN_TOKEN in .env.local to rotate it.';
$lang['api']['unknown_error']              = 'Unknown error';

// ── Licence management panel (Contao → Settings) ─────────────────────
$lang['license']['activated']            = 'The Guardian licence was activated successfully.';
$lang['license']['refreshed']            = 'The Guardian licence was refreshed successfully.';
$lang['license']['removed']              = 'The Guardian licence was removed. Licensed features are disabled until a licence is activated again.';
$lang['license']['no_crypto']            = 'Guardian cannot verify licences on this server: the PHP sodium extension is missing. '
    . 'Contact your hosting provider or V&T Innovations.';
$lang['license']['legacy_key_found']     = 'Guardian found a licence key from an earlier version that predates signed licence records, '
    . 'so it could not be authenticated. Click "Update licence" to re-authenticate it. '
    . 'Licensed features stay disabled until that succeeds.';
$lang['license']['no_domain_configured'] = 'Guardian has no configured domain for this installation. Set the domain on a website root page '
    . '(or via the VTINNOVATIONS_GUARDIAN_DOMAINS environment variable) before activating a licence.';
$lang['license']['detail_key']            = 'Key: %value%';
$lang['license']['detail_package']        = 'Package: %value%';
$lang['license']['detail_valid_from']     = 'Valid from: %value%';
$lang['license']['detail_valid_until']    = 'Valid until: %value%';
$lang['license']['detail_unlimited']      = 'unlimited';
$lang['license']['detail_last_verified']  = 'Last verified: %value%';
$lang['license']['detail_configured_domains'] = 'Configured domains: %value%';
$lang['license']['server_unreachable']    = 'The licence server could not be reached. Nothing was changed — '
    . 'any existing licence remains active. Please try again later.';
$lang['license']['state_pro_active']      = 'Pro licence active. All features unlocked.';
$lang['license']['state_trial_active']    = 'Trial licence active. All features unlocked until the trial ends.';
$lang['license']['state_free_active']     = 'Free licence active. Manual backup only.';
$lang['license']['state_paid_fallback']   = 'Pro licence expired. Running on the free feature set (manual backup only).';
$lang['license']['state_expired']              = 'Licence expired. All licensed features are disabled.';
$lang['license']['state_not_yet_valid']        = 'Licence is not valid yet. All licensed features are disabled.';
$lang['license']['state_host_not_authorised']  = 'Licence is not valid for any domain configured on this installation.';
$lang['license']['state_issuer_withheld']      = 'Licence is no longer valid. Click "Update licence", or contact V&T Innovations.';
$lang['license']['state_tier_not_accepted']    = 'Licence package is not supported by this product.';
$lang['license']['state_absent']               = 'No licence activated. Only the dashboard and settings are available.';
$lang['license']['state_default']              = 'No valid licence. All licensed features are disabled.';
$lang['license']['explain_key_missing']            = 'Please enter a licence key.';
$lang['license']['explain_no_configured_domain']   = 'This installation has no configured domain. '
    . 'Set the domain on a website root page before activating a licence.';
$lang['license']['explain_host_not_authorised']    = 'This licence is not valid for this installation\'s domain.';
$lang['license']['explain_registry_denied']        = 'The licence key was not accepted. Please check the key or contact V&T Innovations.';
$lang['license']['explain_no_crypto']              = 'Guardian cannot verify licences on this server: '
    . 'the PHP sodium extension is required.';
$lang['license']['explain_default']                = 'The licence could not be verified. Please contact V&T Innovations if this persists.';

// ── Licence panel (Contao → Settings, button-based section) ──────────
$lang['license']['panel_key_label']       = 'Licence key';
$lang['license']['panel_key_placeholder'] = 'XXXXX-XXXXX-XXXXX-XXXXX';
$lang['license']['panel_activate']        = 'Verify & activate licence';
$lang['license']['panel_refresh']         = 'Update licence';
$lang['license']['panel_remove']          = 'Remove licence';
$lang['license']['panel_remove_confirm']  = 'Remove the licence?\n\nLicensed features are disabled immediately; backups, jobs and settings are kept.';
$lang['license']['panel_hint']            = 'Enter the licence key issued by V&T Innovations, then click "Verify & activate licence". '
    . 'The key is stored as a signed record, not in the Contao configuration.';
$lang['license']['panel_unavailable']     = 'Licence panel currently unavailable.';

// ── Pre-update analysis checks ────────────────────────────────────────
$lang['checker']['label_php_version']    = 'PHP version';
$lang['checker']['php_version_ok']       = 'PHP %current% is compatible with Contao 5';
$lang['checker']['php_version_too_old']  = 'PHP %current% is too old — Contao 5 requires at least PHP %required%';
$lang['checker']['label_composer']       = 'Composer';
$lang['checker']['composer_found']       = 'Composer found: %path%';
$lang['checker']['composer_not_found']   = 'Composer not found in usual paths — updates may need to be run differently';
$lang['checker']['label_write_permissions'] = 'Write permissions';
$lang['checker']['permissions_ok']       = 'All important directories are writable';
$lang['checker']['permissions_issues']   = 'No write access: %paths%';
$lang['checker']['label_disk_space']     = 'Disk space';
$lang['checker']['disk_space_unknown']   = 'Could not determine free disk space';
$lang['checker']['disk_space_ok']        = '%free% MB free — sufficient for backup and update';
$lang['checker']['disk_space_low']       = 'Only %free% MB free — at least %required% MB recommended';
$lang['checker']['label_composer_packages'] = 'Composer packages';
$lang['checker']['installed_json_missing']  = 'vendor/composer/installed.json not found';
$lang['checker']['installed_json_unexpected'] = 'installed.json has unexpected format';
$lang['checker']['packages_abandoned']   = '%count% abandoned package(s): %names%';
$lang['checker']['packages_ok']          = '%total% packages installed (%contao% Contao packages) — none abandoned';
$lang['checker']['label_database']       = 'Database configuration';
$lang['checker']['database_url_set']     = 'DATABASE_URL is set in %filename%';
$lang['checker']['database_url_missing'] = 'DATABASE_URL not found in .env / .env.local — backup will have to be skipped';
$lang['checker']['label_legacy_modules'] = 'Legacy modules (system/modules/)';
$lang['checker']['legacy_none_found']    = 'No legacy module directory found — installation is fully bundle-based';
$lang['checker']['legacy_dir_empty']     = 'system/modules/ exists but is empty';
$lang['checker']['legacy_modules_found'] = '%count% legacy module(s) detected: %names%. These use the old Contao 3 extension format '
    . 'and should be migrated to Composer/Symfony bundles before upgrading to a higher version.';
$lang['checker']['summary_warnings']     = 'Update generally possible — please review warnings';
$lang['checker']['summary_ready']        = 'Everything ready — update can be started';
$lang['checker']['summary_critical']     = 'Critical issues found — please fix first';

// ── Recovery e-mail notifier ──────────────────────────────────────────
$lang['notifier']['not_entitled']       = 'Recovery e-mails require a valid Guardian licence.';
$lang['notifier']['mailer_unavailable_config'] = 'Symfony Mailer is not available in this installation. '
    . 'Configure MAILER_DSN in .env.local to enable email notifications.';
$lang['notifier']['mailer_unavailable']  = 'Symfony Mailer is not available. Configure MAILER_DSN in .env.local first.';
$lang['notifier']['recipient_not_configured'] = 'Recovery email address is not configured. Open Settings → Recovery email '
    . 'and set an address before enabling email notification on updates.';
$lang['notifier']['recipient_invalid']   = 'Configured recovery email is not a valid address: %recipient%';
$lang['notifier']['no_valid_recipient']  = 'No valid recovery email configured. Set one in Settings first.';

unset($lang);
