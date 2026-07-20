<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Notifier;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Vtinnovations\Guardian\Schedule\ScheduleConfig;

/**
 * Sends e-mail notifications about scheduled backup outcomes.
 *
 * Resolves the From address from a fallback chain so the email always has
 * a valid sender (Symfony Mailer requires this):
 *   1. Explicit sender_email from schedule config
 *   2. Contao adminEmail (tl_settings)
 *   3. Recipient address itself (last-resort fallback)
 */
class BackupNotifier
{
    public function __construct(
        private readonly ScheduleConfig $config,
        private readonly string $projectDir,
        private readonly ?MailerInterface $mailer = null,
    ) {
    }

    public function notifySuccess(string $type, array $manifest, array $log): void
    {
        $cfg = $this->config->load();
        if (empty($cfg['notifications']['on_success']) || empty($cfg['notifications']['email'])) {
            return;
        }

        $subject = sprintf('[Updater] %s backup completed: %s',
            ucfirst($type),
            $manifest['created_at'] ?? 'unknown'
        );

        $body = $this->buildSuccessBody($type, $manifest, $log);

        $this->send($cfg['notifications']['email'], $subject, $body);
    }

    public function notifyFailure(string $type, string $error, array $log = []): void
    {
        $cfg = $this->config->load();
        if (empty($cfg['notifications']['on_failure']) || empty($cfg['notifications']['email'])) {
            return;
        }

        // Rate-limit failure emails. On a misconfigured host (mailer rejected by
        // smarthost, etc.) the scheduled backup will fail every cron tick (often
        // every 15 minutes) and try to send a failure notification each time —
        // which then ALSO fails. That spams the Symfony Messenger queue, the
        // Contao tl_log table, and ultimately fills up disk space with retry
        // attempts. We persist a tiny marker file with the last send time and
        // skip sending if we sent (or tried) within the last hour for the same
        // backup type.
        if ($this->isRateLimited($type)) {
            return;
        }
        $this->markSent($type);

        $subject = sprintf('[Updater] ⚠️ %s backup FAILED', ucfirst($type));

        $body = "The scheduled {$type} backup failed.\n\n"
              . "Error:\n{$error}\n\n"
              . "Time: " . date('c') . "\n";

        if (!empty($log)) {
            $body .= "\nExecution log:\n" . str_repeat('─', 60) . "\n"
                  . implode("\n", $log) . "\n";
        }

        $this->send($cfg['notifications']['email'], $subject, $body);
    }

    /**
     * Returns true if we've already sent a failure notice for $type within
     * the last hour. Prevents email-storms when the backup fails on every
     * cron tick.
     */
    private function isRateLimited(string $type): bool
    {
        $marker = $this->getRateLimitMarker($type);
        if (!file_exists($marker)) {
            return false;
        }
        $lastSent = (int) @file_get_contents($marker);
        // 1-hour cooldown
        return ($lastSent + 3600) > time();
    }

    private function markSent(string $type): void
    {
        $marker = $this->getRateLimitMarker($type);
        $dir    = \dirname($marker);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($marker, (string) time());
    }

    private function getRateLimitMarker(string $type): string
    {
        // Sanitise type to be safe as filename
        $clean = preg_replace('#[^a-z0-9_-]#i', '', $type) ?: 'unknown';
        return $this->projectDir . '/var/updater/notifications/last-failure-' . $clean . '.txt';
    }

    private function buildSuccessBody(string $type, array $manifest, array $log): string
    {
        $lines = [];
        $lines[] = "Scheduled {$type} backup completed successfully.";
        $lines[] = '';
        $lines[] = 'Backup: '         . ($manifest['created_at']     ?? 'unknown');
        $lines[] = 'Contao version: ' . ($manifest['contao_version'] ?? 'unknown');
        $lines[] = 'PHP version: '    . ($manifest['php_version']    ?? 'unknown');
        $lines[] = 'Total size: '     . ($manifest['total_size']     ?? '?');
        $lines[] = '';
        $lines[] = 'Components:';

        foreach (['database', 'vendor', 'templates', 'contao_tpl', 'files', 'assets'] as $comp) {
            $info = $manifest[$comp] ?? null;
            if ($info === null) {
                continue;
            }
            $icon = ($info['success'] ?? false) ? '✓' : '✗';
            $lines[] = sprintf('  %s %s: %s (%s)',
                $icon,
                $comp,
                $info['method'] ?? '?',
                $info['size']   ?? '?'
            );
        }

        if (!empty($log)) {
            $lines[] = '';
            $lines[] = 'Execution log:';
            $lines[] = str_repeat('─', 60);
            foreach ($log as $entry) {
                $lines[] = $entry;
            }
        }

        return implode("\n", $lines);
    }

    private function send(string $to, string $subject, string $body): void
    {
        if ($this->mailer === null) {
            // Mailer not configured — nothing to do.
            // We don't throw because backup itself succeeded; missing mail
            // shouldn't mask the actual outcome.
            return;
        }

        try {
            $cfg    = $this->config->load();
            $sender = $this->resolveSenderAddress($cfg, $to);

            $email = (new Email())
                ->to($to)
                ->subject($subject)
                ->text($body);

            if ($sender !== null) {
                $email->from($sender);
            }

            $this->mailer->send($email);
        } catch (\Throwable) {
            // Swallow mailer errors — we don't want a misconfigured mailer
            // to mask actual backup status.
        }
    }

    /**
     * Resolves the From address using a fallback chain.
     * Symfony Mailer requires a From or Sender header — so we always provide one.
     *
     * Priority order:
     *   1. Explicit sender_email from the Updater's schedule config (override)
     *   2. Contao's adminEmail from tl_settings — the system-wide configured
     *      sender. We trust this even if the domain doesn't match the current
     *      HTTP_HOST, because admins set it deliberately and there's no reliable
     *      "current site root" in CLI/cron context anyway.
     *   3. Recipient address itself (last resort so the mail still goes out)
     */
    private function resolveSenderAddress(array $cfg, string $recipient): ?Address
    {
        $name = (string) ($cfg['notifications']['sender_name'] ?? 'Guardian');

        // 1. Explicit override in our own schedule config
        $explicit = trim((string) ($cfg['notifications']['sender_email'] ?? ''));
        if ($explicit !== '' && filter_var($explicit, \FILTER_VALIDATE_EMAIL)) {
            return new Address($explicit, $name);
        }

        // 2. Contao's adminEmail — taken at face value, no domain checks.
        //    The admin configured this in System → Settings; we trust them.
        $adminEmail = $this->getContaoAdminEmail();
        if ($adminEmail !== null) {
            return new Address($adminEmail, $name);
        }

        // 3. Last resort: recipient as sender
        if (filter_var($recipient, \FILTER_VALIDATE_EMAIL)) {
            return new Address($recipient, $name);
        }

        return null;
    }

    /**
     * PUBLIC: sends a test email so the user can verify settings without
     * waiting for an actual backup to fire. Returns success/error info.
     */
    public function sendTestEmail(): array
    {
        $cfg = $this->config->load();
        $recipient = trim((string) ($cfg['notifications']['email'] ?? ''));

        if ($recipient === '') {
            return ['success' => false, 'error' => 'No recipient configured'];
        }
        if ($this->mailer === null) {
            return ['success' => false, 'error' => 'Mailer service not available'];
        }

        $sender = $this->resolveSenderAddress($cfg, $recipient);
        if ($sender === null) {
            return ['success' => false, 'error' => 'Could not determine sender address'];
        }

        try {
            $email = (new Email())
                ->from($sender)
                ->to($recipient)
                ->subject('[Updater] Test e-mail')
                ->text(
                    "This is a test e-mail from the Guardian.

"
                    . 'If you received this, your notification settings are working.' . "\n\n"
                    . 'Sender used: ' . $sender->toString() . "\n"
                    . 'Time: ' . date('c') . "\n"
                );

            $this->mailer->send($email);

            return [
                'success' => true,
                'sender_used' => $sender->toString(),
                'recipient' => $recipient,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'sender_attempted' => $sender->toString(),
                'hint'    => $this->buildErrorHint($e->getMessage(), $sender->getAddress()),
            ];
        }
    }

    /**
     * Generates a human-friendly hint for common mailer errors.
     */
    private function buildErrorHint(string $error, string $senderEmail): string
    {
        $lower = strtolower($error);

        if (str_contains($lower, 'sender address is not allowed') ||
            str_contains($lower, '550') ||
            str_contains($lower, 'mailbox unavailable')) {
            return 'Your mail server is rejecting the sender address ' . $senderEmail . '. '
                . 'Most shared hosters only accept From addresses that match a real mailbox on the server. '
                . 'Either: (a) set up that address as a mailbox in your hosting control panel (Plesk/cPanel), or '
                . '(b) override the sender in the "Sender e-mail" field above with one that exists on this server. '
                . 'The default uses Contao\'s System Settings → Administrator e-mail address.';
        }

        if (str_contains($lower, 'authentication') || str_contains($lower, '535')) {
            return 'Authentication failed. Check your MAILER_DSN in .env.local and the SMTP credentials.';
        }

        if (str_contains($lower, 'connect') || str_contains($lower, 'timeout')) {
            return 'Could not connect to mail server. Check the host and port in MAILER_DSN.';
        }

        return 'Check the MAILER_DSN setting in .env.local and the sender e-mail field.';
    }

    /**
     * Reads Contao's adminEmail directly from tl_settings via PDO.
     * We avoid the full Contao framework because the notifier may run in CLI
     * worker context where the Contao request scope isn't initialised.
     */
    private function getContaoAdminEmail(): ?string
    {
        try {
            $dbConfig = $this->loadDbConfig();
            if (empty($dbConfig['dbname'])) {
                return null;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $dbConfig['host'] ?? 'localhost',
                (int) ($dbConfig['port'] ?? 3306),
                $dbConfig['dbname']
            );

            $pdo = new \PDO($dsn, $dbConfig['user'] ?? '', $dbConfig['password'] ?? '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 3,
            ]);

            $stmt = $pdo->prepare("SELECT value FROM tl_settings WHERE name = 'adminEmail' LIMIT 1");
            $stmt->execute();
            $value = $stmt->fetchColumn();

            if (\is_string($value) && filter_var($value, \FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        } catch (\Throwable) {
            // Silent fallback to next resolution step
        }

        return null;
    }

    /**
     * Reads DATABASE_URL from $_ENV / $_SERVER, falling back to .env.local file.
     */
    private function loadDbConfig(): array
    {
        $url = (string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '');

        if ($url === '') {
            foreach (['.env.local', '.env'] as $filename) {
                $file = $this->projectDir . '/' . $filename;
                if (!file_exists($file)) {
                    continue;
                }

                $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    if (trim($k) === 'DATABASE_URL') {
                        $url = trim($v, " \t\"'");
                        break 2;
                    }
                }
            }
        }

        if ($url === '') {
            return [];
        }

        $p = parse_url($url);
        if ($p === false) {
            return [];
        }

        return [
            'host'     => $p['host']                      ?? 'localhost',
            'port'     => (int) ($p['port']               ?? 3306),
            'user'     => urldecode((string) ($p['user'] ?? '')),
            'password' => urldecode((string) ($p['pass'] ?? '')),
            'dbname'   => ltrim((string) ($p['path']     ?? ''), '/'),
        ];
    }
}
