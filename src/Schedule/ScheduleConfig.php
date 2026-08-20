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

namespace Vtinnovations\Guardian\Schedule;

use Vtinnovations\Guardian\Backup\BackupManager;

/**
 * Persistent configuration for scheduled backups.
 *
 * Stored as JSON in var/updater/schedule.json. We use a file rather than a
 * database table so the bundle stays self-contained and works without DB
 * migrations.
 *
 * Structure:
 *   {
 *     "mini": {
 *       "enabled": true,
 *       "frequency": "daily",       // daily | weekly | monthly
 *       "time": "03:00",            // HH:MM, 24h
 *       "weekday": 1,               // 0-6 (only for weekly), 1 = Monday
 *       "day_of_month": 1,          // 1-28 (only for monthly)
 *       "retention": 7              // keep last N
 *     },
 *     "full": {
 *       ...same shape...
 *       "components": { vendor: true, templates: true, files: false, assets: false }
 *     },
 *     "storage_path": null,         // absolute path or null = default var/updater/backup
 *     "notifications": {
 *       "email": "",
 *       "on_success": false,
 *       "on_failure": true
 *     }
 *   }
 */
class ScheduleConfig
{
    public const FREQ_5MIN    = '5min';
    public const FREQ_15MIN   = '15min';
    public const FREQ_HOURLY  = 'hourly';
    public const FREQ_DAILY   = 'daily';
    public const FREQ_WEEKLY  = 'weekly';
    public const FREQ_MONTHLY = 'monthly';

    private string $configFile;

    public function __construct(private readonly string $projectDir)
    {
        $this->configFile = $projectDir . '/var/updater/schedule.json';
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

        // Merge with defaults so newly added options always have a value
        return $this->mergeWithDefaults($data);
    }

    public function save(array $config): array
    {
        $clean = $this->validate($config);

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

    public function defaults(): array
    {
        return [
            'mini' => [
                'enabled'      => false,
                'frequency'    => self::FREQ_DAILY,
                'time'         => '03:00',
                'weekday'      => 1,
                'day_of_month' => 1,
                'retention'    => 7,
            ],
            'full' => [
                'enabled'      => false,
                'frequency'    => self::FREQ_WEEKLY,
                'time'         => '02:00',
                'weekday'      => 0, // Sunday
                'day_of_month' => 1,
                'retention'    => 4,
                'components'   => [
                    BackupManager::COMPONENT_VENDOR    => true,
                    BackupManager::COMPONENT_TEMPLATES => true,
                    BackupManager::COMPONENT_FILES     => false,
                    BackupManager::COMPONENT_ASSETS    => false,
                ],
            ],
            'storage_path' => null,
            'notifications' => [
                'email'        => '',
                'sender_email' => '',
                'sender_name'  => 'Guardian',
                'on_success'   => false,
                'on_failure'   => true,
            ],
        ];
    }

    private function mergeWithDefaults(array $data): array
    {
        $defaults = $this->defaults();

        $merged = [
            'mini'          => array_merge($defaults['mini'], \is_array($data['mini'] ?? null) ? $data['mini'] : []),
            'full'          => array_merge($defaults['full'], \is_array($data['full'] ?? null) ? $data['full'] : []),
            'storage_path'  => $data['storage_path'] ?? null,
            'notifications' => array_merge($defaults['notifications'], \is_array($data['notifications'] ?? null) ? $data['notifications'] : []),
        ];

        // Components in full need their own merge
        if (isset($data['full']['components']) && \is_array($data['full']['components'])) {
            $merged['full']['components'] = array_merge(
                $defaults['full']['components'],
                $data['full']['components']
            );
        }

        return $merged;
    }

    /**
     * Sanitizes user input. Anything invalid falls back to a default value.
     */
    private function validate(array $config): array
    {
        $defaults = $this->defaults();
        $valid    = $this->mergeWithDefaults($config);

        foreach (['mini', 'full'] as $type) {
            $valid[$type]['enabled'] = (bool) $valid[$type]['enabled'];

            $freq = $valid[$type]['frequency'];
            if (!\in_array($freq, [
                self::FREQ_5MIN,
                self::FREQ_15MIN,
                self::FREQ_HOURLY,
                self::FREQ_DAILY,
                self::FREQ_WEEKLY,
                self::FREQ_MONTHLY,
            ], true)) {
                $valid[$type]['frequency'] = $defaults[$type]['frequency'];
            }

            // time HH:MM
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $valid[$type]['time'])) {
                $valid[$type]['time'] = $defaults[$type]['time'];
            }

            $valid[$type]['weekday']      = max(0, min(6, (int) $valid[$type]['weekday']));
            $valid[$type]['day_of_month'] = max(1, min(28, (int) $valid[$type]['day_of_month']));
            $valid[$type]['retention']    = max(1, min(999, (int) $valid[$type]['retention']));
        }

        // Components on full must be bools
        $cleanComponents = [];
        foreach ($defaults['full']['components'] as $key => $defaultVal) {
            $cleanComponents[$key] = (bool) ($valid['full']['components'][$key] ?? $defaultVal);
        }
        $valid['full']['components'] = $cleanComponents;

        // Storage path
        $path = $valid['storage_path'];
        if ($path !== null) {
            $path = trim((string) $path);
            $valid['storage_path'] = $path === '' ? null : $path;
        }

        // Email
        $email = trim((string) $valid['notifications']['email']);
        if ($email !== '' && !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }
        $valid['notifications']['email'] = $email;

        // Sender email (optional override)
        $senderEmail = trim((string) ($valid['notifications']['sender_email'] ?? ''));
        if ($senderEmail !== '' && !filter_var($senderEmail, \FILTER_VALIDATE_EMAIL)) {
            $senderEmail = '';
        }
        $valid['notifications']['sender_email'] = $senderEmail;

        $senderName = trim((string) ($valid['notifications']['sender_name'] ?? 'Guardian'));
        if ($senderName === '' || mb_strlen($senderName) > 100) {
            $senderName = 'Guardian';
        }
        $valid['notifications']['sender_name'] = $senderName;

        $valid['notifications']['on_success'] = (bool) $valid['notifications']['on_success'];
        $valid['notifications']['on_failure'] = (bool) $valid['notifications']['on_failure'];

        return $valid;
    }

    /**
     * Validates the configured storage path. Returns warnings and errors so the UI
     * can show them to the user without blocking save.
     *
     * @return array{ok: bool, warnings: array<string>, errors: array<string>, resolved_path: string}
     */
    public function validateStoragePath(?string $path): array
    {
        $defaultPath = $this->projectDir . '/var/updater/backup';
        $resolved    = $path === null || trim($path) === '' ? $defaultPath : $path;

        $warnings = [];
        $errors   = [];

        // Must be absolute
        if (!str_starts_with($resolved, '/')) {
            $errors[] = 'Path must be absolute (start with /).';
        }

        // Normalise: trim trailing slash, collapse ../ — but DON'T follow symlinks
        // (which would let an attacker tunnel through a symlink to a forbidden area).
        $normalised = $this->normalisePath($resolved);

        // Hard blocklist: paths that must NEVER be a backup target, even if the
        // PHP user could technically write there. Backups contain the full DB
        // dump and site code; we don't want them landing in dangerous places.
        $forbiddenPrefixes = [
            '/etc',  '/usr', '/var/log', '/var/lib', '/var/cache', '/var/run',
            '/var/spool', '/var/mail', '/boot', '/dev', '/proc', '/sys', '/lib',
            '/lib32', '/lib64', '/sbin', '/bin', '/root', '/srv',
        ];
        foreach ($forbiddenPrefixes as $prefix) {
            if ($normalised === $prefix || str_starts_with($normalised, $prefix . '/')) {
                $errors[] = 'Path is in a protected system location (' . $prefix . '). Refusing.';
                break;
            }
        }

        // Refuse top-level filesystem roots
        if ($normalised === '/' || $normalised === '/home' || $normalised === '/tmp') {
            $errors[] = 'Path is too broad. Choose a dedicated subdirectory.';
        }

        // Refuse paths inside project parts that get wiped or are web-accessible
        $danger = [
            $this->projectDir . '/vendor',
            $this->projectDir . '/public',
            $this->projectDir . '/web', // legacy
            $this->projectDir . '/files',
        ];
        foreach ($danger as $bad) {
            if (str_starts_with($normalised, $bad . '/') || $normalised === $bad) {
                $errors[] = 'Path inside ' . basename($bad) . '/ is not allowed — backups would be web-accessible or wiped on composer install.';
            }
        }

        // Recommend (but don't require) that the path is inside a sensible parent:
        // either inside the project, inside the user's home dir, or under /tmp/<something>.
        if (empty($errors)) {
            $projectDirReal = realpath($this->projectDir) ?: $this->projectDir;
            $home           = (string) ($_SERVER['HOME'] ?? '');

            $insideProject = str_starts_with($normalised, $projectDirReal . '/');
            $insideHome    = $home !== '' && str_starts_with($normalised, rtrim($home, '/') . '/');
            $insideTmp     = str_starts_with($normalised, '/tmp/');

            if (!$insideProject && !$insideHome && !$insideTmp && $normalised !== $defaultPath) {
                $warnings[] = 'Path is outside project, home, and /tmp — make sure it survives deployments and is writable by the PHP user.';
            }
        }

        // Try to create + verify writable
        if (empty($errors)) {
            if (!is_dir($normalised) && !@mkdir($normalised, 0750, true) && !is_dir($normalised)) {
                $errors[] = 'Directory could not be created: ' . $normalised;
            } elseif (!is_writable($normalised)) {
                $errors[] = 'Directory exists but is not writable: ' . $normalised;
            }
        }

        return [
            'ok'            => empty($errors),
            'warnings'      => $warnings,
            'errors'        => $errors,
            'resolved_path' => $normalised,
        ];
    }

    /**
     * Collapses `.`/`..` segments and double-slashes WITHOUT following symlinks.
     * Used so the validation doesn't get fooled by symlink games.
     */
    private function normalisePath(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $parts = preg_split('#/+#', $path) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $p;
        }
        return ($absolute ? '/' : '') . implode('/', $out);
    }
}
