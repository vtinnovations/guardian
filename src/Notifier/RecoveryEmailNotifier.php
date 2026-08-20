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

namespace Vtinnovations\Guardian\Notifier;

use Contao\System;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Vtinnovations\Guardian\External\PanelAuth;
use Vtinnovations\Guardian\Service\RuntimeConfig;

/**
 * Sends "pre-update recovery information" emails. The intent is that the
 * admin gets the recovery URLs and access token in their inbox BEFORE the
 * risky operation starts — so even if the operation breaks the entire site
 * (no backend, no SSH lifeline) they can still recover.
 *
 * The email contains:
 *   - Standalone recovery panel URL (file-based, framework-independent)
 *   - Routed recovery panel URL (Symfony route, works while Symfony works)
 *   - Full access token (one-time, encourage deletion after success)
 *   - Pre-snapshot name once the backup step has produced one
 *   - SSH-based manual recovery snippet as ultimate fallback
 *
 * Security: the access token appears in PLAINTEXT inside the email. The
 * email is therefore explicitly labelled with "delete after successful
 * update" warnings. The user already had to opt in (checkbox in the modal).
 */
class RecoveryEmailNotifier
{
    public function __construct(
        private readonly RuntimeConfig $runtimeConfig,
        private readonly PanelAuth $panelAuth,
        private readonly string $projectDir,
        private readonly \Vtinnovations\Guardian\Service\RegistrationPolicy $policy,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * These mails carry the recovery panel URL and its access token, so they
     * are as privileged as the panel itself and are gated the same way. The
     * check lives here rather than only on the controllers because the
     * pre-update mail is also triggered from the job pipeline.
     */
    private function entitled(): bool
    {
        return $this->policy->allows(\Vtinnovations\Guardian\Service\RegistrationState::CAP_NOTIFY);
    }

    /**
     * Looks up a per-locale string from the `guardian` language file's
     * `notifier` section. Uses `strtr()` rather than Symfony's translator
     * parameter substitution — Contao's `contao_*` domain decorator feeds
     * parameters through `vsprintf()`, which misparses readable `%name%`
     * tokens as sprintf format specifiers.
     */
    private function msg(string $key, array $params = []): string
    {
        System::loadLanguageFile('guardian');

        $value = $GLOBALS['TL_LANG']['notifier'][$key] ?? $key;

        return [] === $params ? $value : strtr($value, $params);
    }

    /**
     * Returns the configured recovery email recipient, or null if not configured.
     */
    public function getConfiguredRecipient(): ?string
    {
        $config = $this->runtimeConfig->load();
        $email  = $config['recovery_email'] ?? null;
        if (!is_string($email) || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $email;
    }

    /**
     * Sends the pre-update recovery email.
     *
     * @param string $jobType   "update" or "major-update" etc. — used in subject
     * @param string $jobId     Job ID for the user to grep logs later
     * @param string $mode      "full"|"patch"|"selective"|"major"
     * @param string|null $overrideRecipient  If set, use this instead of the configured address
     *
     * @return array{success: bool, error?: string, recipient?: string, sender?: string}
     */
    public function sendPreUpdateEmail(string $jobType, string $jobId, string $mode, ?string $overrideRecipient = null): array
    {
        if (!$this->entitled()) {
            return ['success' => false, 'error' => $this->msg('not_entitled')];
        }

        if ($this->mailer === null) {
            return [
                'success' => false,
                'error'   => $this->msg('mailer_unavailable_config'),
            ];
        }

        $recipient = $overrideRecipient !== null && $overrideRecipient !== ''
            ? trim($overrideRecipient)
            : $this->getConfiguredRecipient();

        if ($recipient === null || $recipient === '') {
            return [
                'success' => false,
                'error'   => $this->msg('recipient_not_configured'),
            ];
        }

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error'   => $this->msg('recipient_invalid', ['%recipient%' => $recipient]),
            ];
        }

        $sender = $this->resolveSender($recipient);

        // Gather everything we want in the email
        $token         = $this->panelAuth->getActiveToken();
        $baseUrl       = $this->getBaseUrl();
        $standaloneUrl = $baseUrl . '/' . $this->runtimeConfig->getRecoveryPanelFilename();

        $subject = sprintf(
            '[Updater] 🚨 Pre-update recovery info — job %s (mode: %s)',
            $jobId,
            $mode
        );

        $textBody = $this->buildTextBody($jobType, $jobId, $mode, $token, $standaloneUrl);
        $htmlBody = $this->buildHtmlBody($jobType, $jobId, $mode, $token, $standaloneUrl);

        try {
            $email = (new Email())
                ->from($sender)
                ->to($recipient)
                ->subject($subject)
                ->text($textBody)
                ->html($htmlBody);

            $this->mailer->send($email);

            return [
                'success'   => true,
                'recipient' => $recipient,
                'sender'    => $sender->toString(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'hint'    => $this->buildErrorHint($e->getMessage(), $sender->getAddress()),
            ];
        }
    }

    /**
     * Sends a test email to verify the configured recipient works.
     *
     * @return array{success: bool, error?: string, recipient?: string, sender?: string}
     */
    public function sendTestEmail(?string $overrideRecipient = null): array
    {
        if (!$this->entitled()) {
            return ['success' => false, 'error' => $this->msg('not_entitled')];
        }

        if ($this->mailer === null) {
            return [
                'success' => false,
                'error'   => $this->msg('mailer_unavailable'),
            ];
        }

        $recipient = $overrideRecipient !== null && $overrideRecipient !== ''
            ? trim($overrideRecipient)
            : $this->getConfiguredRecipient();

        if ($recipient === null || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error'   => $this->msg('no_valid_recipient'),
            ];
        }

        $sender = $this->resolveSender($recipient);

        try {
            $email = (new Email())
                ->from($sender)
                ->to($recipient)
                ->subject('[Updater] Recovery email test')
                ->text(
                    "This is a test of the Guardian recovery email notification.\n\n"
                  . "If you received this, your recovery email is configured correctly.\n\n"
                  . "When you run an update with 'Send recovery info to email' enabled, you'll receive "
                  . "an email like this one BEFORE the update starts — containing the recovery panel URLs "
                  . "and access token so you can recover even if the update breaks your site.\n\n"
                  . "Sender:   " . $sender->toString() . "\n"
                  . "Recipient: " . $recipient . "\n"
                  . "Time:      " . date('c') . "\n"
                );

            $this->mailer->send($email);

            return [
                'success'   => true,
                'recipient' => $recipient,
                'sender'    => $sender->toString(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'hint'    => $this->buildErrorHint($e->getMessage(), $sender->getAddress()),
            ];
        }
    }

    private function buildTextBody(string $jobType, string $jobId, string $mode, string $token, string $standaloneUrl): string
    {
        $delete = '⚠️ DELETE THIS EMAIL AFTER THE UPDATE SUCCEEDS — it contains your full recovery token.';
        $sep    = str_repeat('=', 70);

        return <<<TXT
{$delete}

{$sep}
PRE-UPDATE RECOVERY INFORMATION
{$sep}

An update is about to start on your Contao site.
This email gives you everything you need to recover IF something breaks.

Job type:  {$jobType}
Job ID:    {$jobId}
Mode:      {$mode}
Time:      {$this->nowFormatted()}

{$sep}
RECOVERY OPTION 1 — Standalone recovery panel  (recommended)
{$sep}

URL:    {$standaloneUrl}

This is a single PHP file at <project>/public/_updater-recovery.php that has
ZERO dependencies on Symfony, Composer or Contao. It works as long as PHP-FPM
itself is running — even if the rest of your site is completely broken.

How to use:
  1. Open the URL above in your browser
  2. Browser shows Basic Auth dialog
  3. Username: anything (e.g. 'admin')
  4. Password: <see access token below>
  5. Pick the pre-update backup and click Restore

{$sep}
ACCESS TOKEN
{$sep}

  {$token}

Use this as the Basic Auth password for the URL above.

{$sep}
RECOVERY OPTION 2 — SSH (last resort, if even PHP-FPM is broken)
{$sep}

If even the standalone panel doesn't load, SSH into the server and run:

  cd {$this->projectDir}
  ls -lt var/updater/backup/ | head
  # Note the latest pre-update backup name (e.g. 2026-05-15_12-09-51)
  BACKUP=var/updater/backup/<that-name>

  cp \$BACKUP/composer.json composer.json
  cp \$BACKUP/composer.lock composer.lock
  rm -rf vendor/
  tar -xzf \$BACKUP/vendor.tar.gz
  rm -rf var/cache/prod/*

  # Database (replace USER/HOST/DBNAME from .env.local DATABASE_URL):
  MYSQL_PWD='your-db-password' gunzip < \$BACKUP/database.sql.gz \\
    | mysql -u USER -h HOST DBNAME

{$sep}
{$delete}

TXT;
    }

    private function buildHtmlBody(string $jobType, string $jobId, string $mode, string $token, string $standaloneUrl): string
    {
        $esc = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $tokenHtml       = $esc($token);
        $standaloneHtml  = $esc($standaloneUrl);
        $jobIdHtml       = $esc($jobId);
        $modeHtml        = $esc($mode);
        $typeHtml        = $esc($jobType);
        $projectDirHtml  = $esc($this->projectDir);
        $now             = $esc($this->nowFormatted());

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; max-width: 720px; margin: 0 auto; padding: 20px; color: #222;">

<div style="background:#fff3cd;border-left:4px solid #c89a3a;padding:12px 16px;margin-bottom:16px;font-weight:600;color:#856404;border-radius:3px;">
⚠️ Delete this email after your update succeeds — it contains your full recovery token.
</div>

<h1 style="font-size:1.4rem;margin:0 0 8px;">🚨 Pre-update recovery information</h1>
<p style="color:#666;margin:0 0 16px;">An update is about to start on your site. This email gives you everything you need to recover if something breaks.</p>

<table style="width:100%;font-size:.9rem;border-collapse:collapse;margin-bottom:24px;background:#f5f5f5;border-radius:4px;">
<tr><td style="padding:6px 12px;color:#666;width:100px;">Job type</td><td style="padding:6px 12px;font-family:monospace;">{$typeHtml}</td></tr>
<tr><td style="padding:6px 12px;color:#666;">Job ID</td><td style="padding:6px 12px;font-family:monospace;">{$jobIdHtml}</td></tr>
<tr><td style="padding:6px 12px;color:#666;">Mode</td><td style="padding:6px 12px;font-family:monospace;">{$modeHtml}</td></tr>
<tr><td style="padding:6px 12px;color:#666;">Time</td><td style="padding:6px 12px;font-family:monospace;">{$now}</td></tr>
</table>

<h2 style="font-size:1.05rem;border-bottom:1px solid #ddd;padding-bottom:6px;">Option 1 — Standalone recovery panel (recommended)</h2>
<p style="margin:.5rem 0;">Works even when Contao is broken — single-file PHP, no framework needed.</p>
<p style="margin:.5rem 0;"><a href="{$standaloneHtml}" style="display:inline-block;background:#c04050;color:#fff;padding:10px 18px;border-radius:4px;text-decoration:none;font-weight:600;">Open recovery panel</a></p>
<p style="margin:.5rem 0;color:#666;font-size:.85rem;">URL: <code style="background:#eee;padding:2px 6px;border-radius:3px;">{$standaloneHtml}</code></p>

<h2 style="font-size:1.05rem;border-bottom:1px solid #ddd;padding-bottom:6px;margin-top:24px;">Access token</h2>
<p style="margin:.5rem 0;">Use this as the Basic Auth password. Username can be anything.</p>
<div style="background:#fff3cd;border:1px solid #c89a3a;padding:12px 16px;border-radius:4px;font-family:monospace;word-break:break-all;font-size:.95rem;">{$tokenHtml}</div>

<h2 style="font-size:1.05rem;border-bottom:1px solid #ddd;padding-bottom:6px;margin-top:24px;">Option 2 — SSH (last resort, if even PHP-FPM is broken)</h2>
<p style="margin:.5rem 0;font-size:.9rem;">If even the standalone panel doesn't load, SSH into the server and run:</p>
<pre style="background:#222;color:#a8ff78;padding:14px;border-radius:4px;overflow-x:auto;font-size:.78rem;line-height:1.5;">cd {$projectDirHtml}
ls -lt var/updater/backup/ | head
# Note the latest pre-update backup name
BACKUP=var/updater/backup/&lt;name&gt;

cp \$BACKUP/composer.json composer.json
cp \$BACKUP/composer.lock composer.lock
rm -rf vendor/
tar -xzf \$BACKUP/vendor.tar.gz
rm -rf var/cache/prod/*

# Database (find USER/HOST/DBNAME in .env.local DATABASE_URL):
MYSQL_PWD='your-db-password' gunzip &lt; \$BACKUP/database.sql.gz \\
  | mysql -u USER -h HOST DBNAME</pre>

<p style="margin-top:24px;background:#fff3cd;border-left:4px solid #c89a3a;padding:12px 16px;font-weight:600;color:#856404;border-radius:3px;">
⚠️ Delete this email after your update succeeds — it contains your full recovery token.
</p>

</body></html>
HTML;
    }

    /**
     * Resolves the From: address. Same fallback chain as BackupNotifier so both
     * notifiers behave identically.
     */
    private function resolveSender(string $recipient): Address
    {
        $config = $this->runtimeConfig->load();

        // 1. Explicit sender_email from runtime config
        $explicit = $config['notification_sender_email'] ?? null;
        if (is_string($explicit) && filter_var($explicit, FILTER_VALIDATE_EMAIL)) {
            return new Address($explicit, 'Guardian');
        }

        // 2. Recipient (last-resort — most mailers accept From == To)
        return new Address($recipient, 'Guardian');
    }

    private function getBaseUrl(): string
    {
        // Prefer the request context if we're in a web request
        if ($this->requestStack !== null) {
            $req = $this->requestStack->getCurrentRequest();
            if ($req !== null) {
                $scheme = $req->getScheme();
                $host   = $req->getHttpHost();
                return $scheme . '://' . $host;
            }
        }

        // Fall back to runtime config
        $config = $this->runtimeConfig->load();
        $url    = $config['site_url'] ?? null;
        if (is_string($url) && $url !== '') {
            return rtrim($url, '/');
        }

        // Last fallback: build from environment
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    private function nowFormatted(): string
    {
        return date('Y-m-d H:i:s T');
    }

    private function buildErrorHint(string $error, string $senderEmail): string
    {
        $lower = strtolower($error);

        if (str_contains($lower, 'sender address is not allowed') ||
            str_contains($lower, '550') ||
            str_contains($lower, 'mailbox unavailable')) {
            return 'Your mail server is rejecting the sender address ' . $senderEmail . '. '
                . 'Most shared hosters only accept From addresses that match a real mailbox on the server. '
                . 'Set up that address as a mailbox in Plesk/cPanel, or configure a different sender.';
        }

        if (str_contains($lower, 'authentication') || str_contains($lower, '535')) {
            return 'SMTP authentication failed. Check MAILER_DSN in .env.local.';
        }

        if (str_contains($lower, 'connect') || str_contains($lower, 'timeout')) {
            return 'Could not connect to mail server. Check the host and port in MAILER_DSN.';
        }

        return 'Check MAILER_DSN in .env.local.';
    }
}
