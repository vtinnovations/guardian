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

namespace Vtinnovations\Guardian\Backup;

/**
 * Creates and lists backups for the Contao installation.
 *
 * Components:
 *   - composer.json + composer.lock (always)
 *   - Database (always, gzipped)
 *   - vendor/ (default on)
 *   - templates/ + contao/templates/ (default on)
 *   - files/ (optional, can be very large)
 *   - assets/ (optional)
 *
 * Falls back to pure PHP when system tools (mysqldump, tar) are unavailable.
 */
class BackupManager
{
    public const COMPONENT_VENDOR    = 'vendor';
    public const COMPONENT_TEMPLATES = 'templates';
    public const COMPONENT_FILES     = 'files';
    public const COMPONENT_ASSETS    = 'assets';

    private string $backupBaseDir;

    public function __construct(private readonly string $projectDir)
    {
        $this->backupBaseDir = $projectDir . '/var/updater/backup';
    }

    /**
     * Allows overriding the backup storage location at runtime.
     * Used by the schedule runner so admins can pick a custom path.
     */
    public function setStorageDir(string $absolutePath): void
    {
        if ($absolutePath !== '') {
            $this->backupBaseDir = rtrim($absolutePath, '/');
        }
    }

    public function getStorageDir(): string
    {
        return $this->backupBaseDir;
    }

    public static function defaultComponents(): array
    {
        return [
            self::COMPONENT_VENDOR    => true,
            self::COMPONENT_TEMPLATES => true,
            self::COMPONENT_FILES     => false,
            self::COMPONENT_ASSETS    => false,
        ];
    }

    // ── LISTING ─────────────────────────────────────────────────────────

    public function listBackups(): array
    {
        if (!is_dir($this->backupBaseDir)) {
            return [];
        }

        $dirs = glob($this->backupBaseDir . '/*', \GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }

        $backups = [];
        foreach ($dirs as $dir) {
            $manifest     = $dir . '/manifest.json';
            $manifestData = [];

            if (file_exists($manifest)) {
                $content = @file_get_contents($manifest);
                if ($content !== false) {
                    $decoded = json_decode($content, true);
                    if (\is_array($decoded)) {
                        $manifestData = $decoded;
                    }
                }
            }

            $backups[] = [
                'path'           => $dir,
                'name'           => basename($dir),
                'created_at'     => $manifestData['created_at']     ?? basename($dir),
                'contao_version' => $manifestData['contao_version'] ?? 'unknown',
                'total_size'     => $manifestData['total_size']     ?? '?',
                'components'     => $manifestData['components']     ?? [],
            ];
        }

        usort($backups, static fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $backups;
    }

    // ── BACKUP CREATION ──────────────────────────────────────────────────

    /**
     * Creates a full backup with the selected components.
     *
     * @param array<string, bool> $components Map of component => enabled
     * @return array{path: string, name: string, manifest: array, log: array<string>}
     */
    public function createFullBackup(array $components = []): array
    {
        $components = array_merge(self::defaultComponents(), $components);

        $log       = [];
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = $this->backupBaseDir . '/' . $timestamp;

        // Ensure base dir exists. If creation fails, collect detailed info so
        // we can tell the user WHY (permissions? open_basedir? disk full?).
        if (!is_dir($this->backupBaseDir)) {
            // Try to create; capture any error PHP gives us
            $errors = [];
            set_error_handler(static function ($errno, $errstr) use (&$errors): bool {
                $errors[] = $errstr;
                return true;
            });
            $created = mkdir($this->backupBaseDir, 0750, true);
            restore_error_handler();

            if (!$created && !is_dir($this->backupBaseDir)) {
                throw new \RuntimeException(
                    'Cannot create backup base directory: ' . $this->backupBaseDir
                    . $this->buildMkdirDiagnostic($this->backupBaseDir, $errors)
                );
            }
        }

        // Sanity check: is the base directory writable by us?
        // If not, we'll fail mkdir below with a less informative error — so
        // check now and produce a better diagnostic message.
        if (!is_writable($this->backupBaseDir)) {
            throw new \RuntimeException(
                'Backup base directory exists but is NOT writable by the current user: '
                . $this->backupBaseDir
                . $this->buildMkdirDiagnostic($this->backupBaseDir, [
                    'is_writable() returned false — check filesystem permissions',
                ])
            );
        }

        // Now try the timestamped subdirectory
        $errors = [];
        set_error_handler(static function ($errno, $errstr) use (&$errors): bool {
            $errors[] = $errstr;
            return true;
        });
        $created = mkdir($backupDir, 0750, true);
        restore_error_handler();

        if (!$created) {
            throw new \RuntimeException(
                'Cannot create backup directory: ' . $backupDir
                . $this->buildMkdirDiagnostic($backupDir, $errors)
            );
        }

        $log[] = "Created backup directory: {$backupDir}";
        $log[] = 'Components: ' . implode(', ', array_keys(array_filter($components)));

        // Always: composer files
        $this->backupFile($this->projectDir . '/composer.json', $backupDir . '/composer.json', $log);
        $this->backupFile($this->projectDir . '/composer.lock', $backupDir . '/composer.lock', $log);

        // Also: a copy of vendor/composer/installed.json — even if the vendor
        // component itself isn't backed up. This is small (a few KB) and lets
        // the restore flow detect whether the current site has packages that
        // the backup didn't know about, which would otherwise cause a broken
        // state (DB schema for package X exists, but composer.lock says X is
        // gone, while vendor/X is still on disk).
        $installedJson = $this->projectDir . '/vendor/composer/installed.json';
        if (file_exists($installedJson)) {
            $this->backupFile($installedJson, $backupDir . '/installed.json', $log);
        }

        // Always: database
        $dbResult = $this->backupDatabase($backupDir, $log);

        // Optional: vendor
        $vendorResult = null;
        if ($components[self::COMPONENT_VENDOR] ?? false) {
            $vendorResult = $this->backupDirectory(
                $this->projectDir . '/vendor',
                $backupDir . '/vendor.tar.gz',
                'vendor',
                $log
            );
        }

        // Optional: templates (project + contao/templates)
        $templatesResult       = null;
        $contaoTemplatesResult = null;
        if ($components[self::COMPONENT_TEMPLATES] ?? false) {
            if (is_dir($this->projectDir . '/templates')) {
                $templatesResult = $this->backupDirectory(
                    $this->projectDir . '/templates',
                    $backupDir . '/templates.tar.gz',
                    'templates',
                    $log
                );
            }
            if (is_dir($this->projectDir . '/contao/templates')) {
                $contaoTemplatesResult = $this->backupDirectory(
                    $this->projectDir . '/contao/templates',
                    $backupDir . '/contao_templates.tar.gz',
                    'contao/templates',
                    $log
                );
            }
        }

        // Optional: files/  (user uploads — can be huge)
        $filesResult = null;
        if ($components[self::COMPONENT_FILES] ?? false) {
            $filesDir = $this->projectDir . '/files';
            if (is_dir($filesDir)) {
                $filesResult = $this->backupDirectory(
                    $filesDir,
                    $backupDir . '/files.tar.gz',
                    'files',
                    $log
                );
            } else {
                $log[] = 'Skipped files/: directory does not exist';
            }
        }

        // Optional: assets/
        $assetsResult = null;
        if ($components[self::COMPONENT_ASSETS] ?? false) {
            $assetsDir = $this->projectDir . '/assets';
            if (is_dir($assetsDir)) {
                $assetsResult = $this->backupDirectory(
                    $assetsDir,
                    $backupDir . '/assets.tar.gz',
                    'assets',
                    $log
                );
            } else {
                $log[] = 'Skipped assets/: directory does not exist';
            }
        }

        $manifest = [
            'created_at'      => $timestamp,
            'contao_version'  => $this->getContaoVersion(),
            'php_version'     => \PHP_VERSION,
            'components'      => $components,
            'database'        => $dbResult,
            'vendor'          => $vendorResult,
            'templates'       => $templatesResult,
            'contao_tpl'      => $contaoTemplatesResult,
            'files'           => $filesResult,
            'assets'          => $assetsResult,
            'files_in_backup' => $this->listBackupFiles($backupDir),
            'total_size'      => $this->formatBytes($this->getDirSize($backupDir)),
        ];

        @file_put_contents(
            $backupDir . '/manifest.json',
            json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE)
        );

        $log[] = 'Manifest written. Total size: ' . $manifest['total_size'];

        return [
            'path'     => $backupDir,
            'name'     => $timestamp,
            'manifest' => $manifest,
            'log'      => $log,
        ];
    }

    public function deleteBackup(string $name): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $name)) {
            return false;
        }

        $path = $this->backupBaseDir . '/' . $name;
        if (!is_dir($path) || !str_starts_with(realpath($path) ?: '', realpath($this->backupBaseDir) ?: '')) {
            return false;
        }

        $this->removeDir($path);
        return !is_dir($path);
    }

    // ── INDIVIDUAL BACKUP STEPS ──────────────────────────────────────────

    private function backupFile(string $src, string $dst, array &$log): void
    {
        if (!file_exists($src)) {
            $log[] = 'Skipped (not found): ' . basename($src);
            return;
        }

        if (@copy($src, $dst)) {
            $log[] = 'Copied: ' . basename($src) . ' (' . $this->formatBytes((int) filesize($dst)) . ')';
        } else {
            $log[] = 'WARNING: Failed to copy ' . basename($src);
        }
    }

    private function backupDatabase(string $backupDir, array &$log): ?array
    {
        $config = $this->loadDbConfigFromEnv();

        if (empty($config['dbname'])) {
            $log[] = 'WARNING: No DATABASE_URL found, skipping database backup';
            return [
                'method'  => 'skipped',
                'file'    => '',
                'size'    => '0 B',
                'success' => false,
                'error'   => 'No database configured',
            ];
        }

        if ($this->canUseExec() && $this->isCommandAvailable('mysqldump')) {
            try {
                $dumpFile = $backupDir . '/database.sql.gz';
                $this->runMysqldump($config, $dumpFile);

                $size  = $this->formatBytes((int) filesize($dumpFile));
                $log[] = "Database backed up via mysqldump: {$size}";

                return [
                    'method'  => 'mysqldump',
                    'file'    => 'database.sql.gz',
                    'size'    => $size,
                    'success' => true,
                ];
            } catch (\Throwable $e) {
                $log[] = "mysqldump failed: {$e->getMessage()} — falling back to PHP dump";
            }
        }

        try {
            $dumpFile = $backupDir . '/database.sql.gz';
            $this->runPhpMysqlDump($config, $dumpFile);

            $size  = $this->formatBytes((int) filesize($dumpFile));
            $log[] = "Database backed up via PHP/PDO: {$size}";

            return [
                'method'  => 'php-pdo',
                'file'    => 'database.sql.gz',
                'size'    => $size,
                'success' => true,
            ];
        } catch (\Throwable $e) {
            $log[] = "PHP database backup also failed: {$e->getMessage()}";

            return [
                'method'  => 'failed',
                'file'    => '',
                'size'    => '0 B',
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    private function backupDirectory(string $sourceDir, string $archivePath, string $label, array &$log): array
    {
        if ($this->canUseExec() && $this->isCommandAvailable('tar')) {
            try {
                $this->runTar($sourceDir, $archivePath);
                $size  = $this->formatBytes((int) filesize($archivePath));
                $log[] = "Archived {$label} via tar: {$size}";

                return [
                    'method'  => 'tar',
                    'file'    => basename($archivePath),
                    'size'    => $size,
                    'success' => true,
                ];
            } catch (\Throwable $e) {
                $log[] = "tar failed for {$label}: {$e->getMessage()} — falling back to ZipArchive";
            }
        }

        try {
            $zipPath = $archivePath . '.zip';
            $this->runZipArchive($sourceDir, $zipPath);
            $size  = $this->formatBytes((int) filesize($zipPath));
            $log[] = "Archived {$label} via ZipArchive: {$size}";

            return [
                'method'  => 'zip',
                'file'    => basename($zipPath),
                'size'    => $size,
                'success' => true,
            ];
        } catch (\Throwable $e) {
            $log[] = "ZipArchive also failed for {$label}: {$e->getMessage()}";

            return [
                'method'  => 'failed',
                'file'    => '',
                'size'    => '0 B',
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    // ── HELPERS: SHELL-BASED ─────────────────────────────────────────────

    private function runMysqldump(array $config, string $dumpFile): void
    {
        // Password is supplied via MYSQL_PWD environment variable so it does
        // NOT appear in `ps -ef` or other process listings. Same approach used
        // by the Symfony Filesystem helper and most modern PHP DB backup tools.
        //
        // --add-drop-table emits a DROP TABLE IF EXISTS before each CREATE TABLE.
        // Without this, restoring onto a live database fails with "Table already
        // exists" errors. Most mysqldump versions enable this by default but
        // some distributions disable it — be explicit so backups are portable.
        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s'
            . ' --add-drop-table --single-transaction --routines --triggers --events --no-tablespaces %s'
            . ' 2>&1 | gzip > %s',
            escapeshellarg((string) ($config['host'] ?? 'localhost')),
            escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg((string) ($config['user'] ?? 'root')),
            escapeshellarg((string) $config['dbname']),
            escapeshellarg($dumpFile)
        );

        $exitCode = $this->runWithMysqlPassword($cmd, (string) ($config['password'] ?? ''), $output);

        if ($exitCode !== 0 || !file_exists($dumpFile) || filesize($dumpFile) < 50) {
            throw new \RuntimeException('mysqldump exit ' . $exitCode . ': ' . implode("\n", $output));
        }
    }

    /**
     * Runs a mysql/mysqldump pipeline with the password supplied through
     * MYSQL_PWD instead of as a CLI argument. See RestoreManager for rationale.
     *
     * @param array<int,string> $output
     */
    private function runWithMysqlPassword(string $cmd, string $password, ?array &$output): int
    {
        $output = [];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = [
            'PATH'      => (string) ($_SERVER['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
            'MYSQL_PWD' => $password,
            'HOME'      => (string) ($_SERVER['HOME'] ?? '/tmp'),
            'LC_ALL'    => 'C',
        ];

        $proc = @proc_open('/bin/sh -c ' . escapeshellarg($cmd), $descriptors, $pipes, null, $env);
        if (!\is_resource($proc)) {
            $output[] = 'proc_open failed';
            return 1;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exit = proc_close($proc);

        foreach (preg_split('/\r?\n/', $stdout . $stderr) ?: [] as $line) {
            $line = rtrim($line);
            if ($line !== '') {
                $output[] = $line;
            }
        }

        return $exit;
    }

    private function runTar(string $sourceDir, string $archivePath): void
    {
        $parent  = \dirname($sourceDir);
        $dirname = basename($sourceDir);

        $cmd = sprintf(
            'tar -czf %s -C %s %s 2>&1',
            escapeshellarg($archivePath),
            escapeshellarg($parent),
            escapeshellarg($dirname)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('tar exit ' . $exitCode . ': ' . implode("\n", $output));
        }
    }

    // ── HELPERS: PURE-PHP FALLBACKS ──────────────────────────────────────

    private function runPhpMysqlDump(array $config, string $dumpFile): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'] ?? 'localhost',
            (int) ($config['port'] ?? 3306),
            $config['dbname']
        );

        $pdo = new \PDO($dsn, $config['user'] ?? '', $config['password'] ?? '', [
            \PDO::ATTR_ERRMODE                  => \PDO::ERRMODE_EXCEPTION,
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]);

        $gz = @gzopen($dumpFile, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Cannot open gzip output: ' . $dumpFile);
        }

        try {
            gzwrite($gz, "-- Guardian backup\n");
            gzwrite($gz, '-- Generated: ' . date('Y-m-d H:i:s') . "\n");
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

            $tables = [];
            foreach ($pdo->query("SHOW TABLES") as $row) {
                $tables[] = $row[0];
            }

            foreach ($tables as $table) {
                $quoted = '`' . str_replace('`', '``', $table) . '`';

                gzwrite($gz, "DROP TABLE IF EXISTS {$quoted};\n");
                $createRow = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(\PDO::FETCH_NUM);
                gzwrite($gz, $createRow[1] . ";\n\n");

                $stmt = $pdo->query("SELECT * FROM {$quoted}");
                if ($stmt) {
                    $batch = [];
                    while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                        // PDO::quote() is charset-aware (the connection is utf8mb4
                        // per the DSN above) and properly escapes binary data, NUL
                        // bytes, and multi-byte sequences. addslashes() does none
                        // of that and is considered unsafe for SQL escaping.
                        $values = array_map(
                            static function ($v) use ($pdo) {
                                if ($v === null) {
                                    return 'NULL';
                                }
                                // PDO::quote returns the value WITH surrounding quotes
                                $quotedValue = $pdo->quote((string) $v);
                                // Some drivers return false on failure — fall back to
                                // a hex literal which mysql understands and which can
                                // represent any byte sequence safely.
                                if ($quotedValue === false) {
                                    return '0x' . bin2hex((string) $v);
                                }
                                return $quotedValue;
                            },
                            $row
                        );
                        $batch[] = '(' . implode(',', $values) . ')';

                        if (\count($batch) >= 200) {
                            gzwrite($gz, "INSERT INTO {$quoted} VALUES\n" . implode(",\n", $batch) . ";\n");
                            $batch = [];
                        }
                    }
                    if (!empty($batch)) {
                        gzwrite($gz, "INSERT INTO {$quoted} VALUES\n" . implode(",\n", $batch) . ";\n");
                    }
                    gzwrite($gz, "\n");
                }
            }

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            gzclose($gz);
        }
    }

    private function runZipArchive(string $sourceDir, string $zipPath): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Neither tar nor ZipArchive is available — cannot archive');
        }

        $zip    = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new \RuntimeException("Cannot create zip: code {$opened}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $sourceLen = \strlen($sourceDir) + 1;
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), $sourceLen);
            $zip->addFile($file->getPathname(), basename($sourceDir) . '/' . $relative);
        }

        $zip->close();
    }

    // ── HELPERS: ENVIRONMENT ─────────────────────────────────────────────

    private function canUseExec(): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }

        $disabled = explode(',', (string) \ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        return !\in_array('exec', $disabled, true);
    }

    private function isCommandAvailable(string $cmd): bool
    {
        $output = @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
        return $output !== null && trim((string) $output) !== '';
    }

    private function loadDbConfigFromEnv(): array
    {
        foreach (['.env.local', '.env'] as $filename) {
            $file = $this->projectDir . '/' . $filename;
            if (!file_exists($file)) {
                continue;
            }

            $env   = [];
            $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v]       = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\"'");
            }

            if (!empty($env['DATABASE_URL'])) {
                $p = parse_url($env['DATABASE_URL']);
                if ($p === false) {
                    continue;
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

        return [];
    }

    // ── HELPERS: FILESYSTEM ──────────────────────────────────────────────

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        if ($this->canUseExec()) {
            exec('rm -rf ' . escapeshellarg($dir));
            if (!is_dir($dir)) {
                return;
            }
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function getDirSize(string $dir): int
    {
        $total = 0;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                $total += (int) filesize($f);
            }
        }
        return $total;
    }

    private function listBackupFiles(string $dir): array
    {
        $result = [];
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                $result[] = [
                    'name' => basename($file),
                    'size' => $this->formatBytes((int) filesize($file)),
                ];
            }
        }
        return $result;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            ++$i;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    /**
     * Builds a detailed diagnostic suffix for mkdir failures.
     *
     * Tells the admin exactly why the directory couldn't be created:
     *   - the PHP error message (open_basedir, permission denied, etc.)
     *   - which parent directory exists and which doesn't
     *   - the owner / group / mode of the closest existing ancestor
     *   - whether open_basedir is active and what it allows
     *   - free disk space
     *
     * @param array<int, string> $errors PHP error messages captured during the failed mkdir
     */
    private function buildMkdirDiagnostic(string $path, array $errors): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = '--- Diagnostic info ---';

        // PHP-level error message — usually the most informative
        if (!empty($errors)) {
            $lines[] = 'PHP error: ' . implode(' | ', array_unique($errors));
        }

        // Walk up the path to find the closest existing ancestor
        $existing = $path;
        while ($existing !== '/' && $existing !== '' && !is_dir($existing)) {
            $existing = \dirname($existing);
        }
        $lines[] = 'Closest existing ancestor: ' . $existing;

        // Show ownership/permissions of that ancestor
        $stat = @stat($existing);
        if ($stat !== false) {
            $owner = \function_exists('posix_getpwuid') ? @posix_getpwuid($stat['uid']) : null;
            $group = \function_exists('posix_getgrgid') ? @posix_getgrgid($stat['gid']) : null;
            $lines[] = sprintf(
                'Owner: %s (uid %d), Group: %s (gid %d), Mode: %o',
                $owner['name'] ?? '?',
                $stat['uid'],
                $group['name'] ?? '?',
                $stat['gid'],
                $stat['mode'] & 0777
            );
        }

        // Show what user we run as
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $self = @posix_getpwuid(posix_geteuid());
            if ($self) {
                $lines[] = 'Process runs as: ' . $self['name'] . ' (uid ' . posix_geteuid() . ')';
            }
        }

        // open_basedir restriction?
        $openBasedir = (string) \ini_get('open_basedir');
        if ($openBasedir !== '') {
            $lines[] = 'open_basedir is active: ' . $openBasedir;
            // Check if our target path is allowed
            $allowed = false;
            foreach (explode(\PATH_SEPARATOR, $openBasedir) as $dir) {
                $dir = rtrim(trim($dir), '/');
                if ($dir !== '' && str_starts_with($path, $dir . '/')) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $lines[] = '⚠️ Target path is OUTSIDE open_basedir — this is likely the cause.';
            }
        } else {
            $lines[] = 'open_basedir: not set';
        }

        // Disk space
        $freeBytes = @disk_free_space($existing);
        if ($freeBytes !== false) {
            $lines[] = 'Free disk space at ' . $existing . ': ' . $this->formatBytes((int) $freeBytes);
            if ($freeBytes < 100 * 1024 * 1024) {
                $lines[] = '⚠️ Less than 100 MB free — disk may be full.';
            }
        }

        return "\n" . implode("\n", $lines);
    }

    private function getContaoVersion(): string
    {
        $installedJson = $this->projectDir . '/vendor/composer/installed.json';
        if (!file_exists($installedJson)) {
            return 'unknown';
        }

        $data = json_decode((string) @file_get_contents($installedJson), true);
        if (!\is_array($data)) {
            return 'unknown';
        }

        $packages = $data['packages'] ?? $data;
        foreach ($packages as $pkg) {
            if (($pkg['name'] ?? '') === 'contao/core-bundle') {
                return ltrim((string) ($pkg['version'] ?? 'unknown'), 'v');
            }
        }

        return 'unknown';
    }
}
