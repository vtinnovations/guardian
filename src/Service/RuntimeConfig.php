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

namespace Vtinnovations\Guardian\Service;

/**
 * Runtime configuration for the Guardian bundle.
 *
 * Stored as JSON in var/updater/runtime.json. The big one here is the
 * PHP CLI binary path — the same way Contao Manager has a "Server Settings"
 * page where the user enters this manually if auto-detection fails.
 *
 * This is critical on Plesk: web PHP runs as /opt/plesk/php/8.4/sbin/php-fpm
 * but CLI PHP is /opt/plesk/php/8.4/bin/php. The path varies by PHP version.
 */
class RuntimeConfig
{
    private string $configFile;

    public function __construct(private readonly string $projectDir)
    {
        $this->configFile = $projectDir . '/var/updater/runtime.json';
    }

    public function load(): array
    {
        if (!file_exists($this->configFile)) {
            return $this->defaults();
        }

        $content = @file_get_contents($this->configFile);
        if ($content === false) {
            return $this->defaults();
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return $this->defaults();
        }

        return array_merge($this->defaults(), $data);
    }

    public function save(array $config): array
    {
        // Merge over the currently stored config so partial saves (e.g. only
        // the license field, or only the PHP binary) don't wipe unrelated
        // fields. Callers may send just the keys they changed.
        $merged = array_merge($this->load(), $config);
        $clean  = $this->validate($merged);

        $dir = \dirname($this->configFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(
            $this->configFile,
            json_encode($clean, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE)
        );

        return $clean;
    }

    public function getPhpBinary(): ?string
    {
        $path = trim((string) ($this->load()['php_binary'] ?? ''));
        return $path === '' ? null : $path;
    }

    /**
     * Filename of the standalone recovery panel dropped into public/. Custom
     * names add security through obscurity on top of Basic-Auth. Sane default
     * is `_updater-recovery.php`.
     */
    public function getRecoveryPanelFilename(): string
    {
        $name = trim((string) ($this->load()['recovery_panel_filename'] ?? ''));
        return $name !== '' ? $name : '_updater-recovery.php';
    }

    public function defaults(): array
    {
        return [
            'php_binary'                 => '',
            'composer_phar'              => '',
            'recovery_email'             => '',
            'notification_sender_email'  => '',
            'recovery_panel_filename'    => '_updater-recovery.php',
        ];
    }

    /**
     * Tests if a given path looks like a usable PHP CLI binary.
     * Used by the UI's "Detect" / "Test" feature.
     *
     * @return array{ok: bool, version?: string, error?: string}
     */
    public function testBinary(string $path): array
    {
        if ($path === '') {
            return ['ok' => false, 'error' => 'No path given'];
        }

        if (str_contains(basename($path), '-fpm') || str_contains(basename($path), '-cgi')) {
            return ['ok' => false, 'error' => 'This appears to be an FPM/CGI binary, not CLI. Look for the same path under /bin/ instead of /sbin/.'];
        }

        if (!\function_exists('exec')) {
            return ['ok' => false, 'error' => 'Cannot test: exec() is disabled.'];
        }
        $disabled = array_map('trim', explode(',', (string) \ini_get('disable_functions')));
        if (\in_array('exec', $disabled, true)) {
            return ['ok' => false, 'error' => 'Cannot test: exec() is in disable_functions.'];
        }

        // We intentionally do NOT call file_exists() / is_executable() here.
        // On Plesk PHP-FPM, open_basedir blocks stat() on /opt/plesk/php/...,
        // even though exec() can run the same binary just fine. Trying to exec
        // is a more reliable signal than the filesystem checks.
        $cmd = escapeshellarg($path) . ' -v 2>&1';
        $output = [];
        $exit   = 1;
        @exec($cmd, $output, $exit);

        if ($exit !== 0) {
            // Hard to know if it's "doesn't exist" or "not executable" since
            // we can't trust file_exists(). Provide a friendly message based on output.
            $err = trim(implode("\n", $output));

            // Common shell error: "command not found" / "No such file or directory"
            if (str_contains(strtolower($err), 'not found') || str_contains(strtolower($err), 'no such file')) {
                return [
                    'ok'    => false,
                    'error' => 'Binary not found or not executable: ' . $path,
                ];
            }

            return [
                'ok'    => false,
                'error' => 'PHP binary returned exit ' . $exit . ': ' . ($err !== '' ? $err : '(no output)'),
            ];
        }

        $firstLine = $output[0] ?? '';
        if (!str_contains($firstLine, 'PHP')) {
            return [
                'ok'    => false,
                'error' => 'Output does not look like PHP version: ' . $firstLine,
            ];
        }

        return ['ok' => true, 'version' => $firstLine];
    }

    /**
     * Generates candidate paths to suggest in the UI.
     * Returns an array of path => label entries.
     */
    public function suggestCandidates(): array
    {
        $candidates = [];

        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        // Plesk standard paths (most common on the user's actual host)
        foreach (['8.4', '8.3', '8.2', '8.1', '8.0', $phpVersion] as $v) {
            $candidates['/opt/plesk/php/' . $v . '/bin/php'] = 'Plesk PHP ' . $v;
        }

        // cPanel
        foreach (['ea-php84', 'ea-php83', 'ea-php82'] as $v) {
            $candidates['/opt/cpanel/' . $v . '/root/usr/bin/php'] = 'cPanel ' . $v;
        }

        // Standard system paths
        $candidates['/usr/bin/php']                     = 'Standard /usr/bin/php';
        $candidates['/usr/local/bin/php']               = 'Standard /usr/local/bin/php';
        $candidates['/usr/bin/php' . $phpVersion]       = 'System PHP ' . $phpVersion;
        $candidates['/usr/bin/php' . PHP_MAJOR_VERSION] = 'System PHP ' . PHP_MAJOR_VERSION;

        // On hosts WITHOUT open_basedir, filter to files that actually exist.
        // On hosts WITH open_basedir (e.g. Plesk), file_exists() returns false
        // for /opt/plesk/php/... even when those binaries DO exist and work —
        // so in that case, show all common candidates and let the user pick.
        $openBaseDir = trim((string) \ini_get('open_basedir'));

        if ($openBaseDir === '') {
            // No restriction → filter normally
            $existing = [];
            foreach ($candidates as $path => $label) {
                if (file_exists($path)) {
                    $existing[$path] = $label;
                }
            }
            return $existing;
        }

        // open_basedir is set → we can't trust file_exists for /opt/plesk/*.
        // Return all candidates; the user clicks Test to verify.
        return $candidates;
    }

    private function validate(array $config): array
    {
        $path = trim((string) ($config['php_binary'] ?? ''));

        // composer_phar — manual override for the composer.phar path.
        // We don't strictly validate it here (the controller does that
        // before calling save) — we just preserve whatever was sent.
        $composerPhar = trim((string) ($config['composer_phar'] ?? ''));

        // Recovery email — must be a valid email or empty
        $recoveryEmail = trim((string) ($config['recovery_email'] ?? ''));
        if ($recoveryEmail !== '' && !filter_var($recoveryEmail, \FILTER_VALIDATE_EMAIL)) {
            // Silently drop invalid addresses rather than refuse the save —
            // the UI shows a validation error on screen first anyway.
            $recoveryEmail = '';
        }

        $senderEmail = trim((string) ($config['notification_sender_email'] ?? ''));
        if ($senderEmail !== '' && !filter_var($senderEmail, \FILTER_VALIDATE_EMAIL)) {
            $senderEmail = '';
        }

        // Recovery panel filename: alnum + dash + underscore + dot, must end in
        // .php, max 64 chars. No path separators (single-file drop only).
        // Empty / invalid → default.
        $panel = trim((string) ($config['recovery_panel_filename'] ?? ''));
        if ($panel === '' || !preg_match('#^[A-Za-z0-9._-]{1,60}\.php$#', $panel) || str_contains($panel, '..')) {
            $panel = '_updater-recovery.php';
        }

        return [
            'php_binary'                => $path,
            'composer_phar'             => $composerPhar,
            'recovery_email'            => $recoveryEmail,
            'notification_sender_email' => $senderEmail,
            'recovery_panel_filename'   => $panel,
        ];
    }
}
