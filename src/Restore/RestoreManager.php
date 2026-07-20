<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Restore;

use Symfony\Component\Process\Process;
use Vtinnovations\Guardian\Backup\BackupManager;
use Vtinnovations\Guardian\Job\JobLog;

/**
 * Restores files and database state from a previously created backup.
 *
 * Companion to BackupManager. Reads the manifest.json that BackupManager
 * wrote to know which components are present and how they were archived
 * (mysqldump vs PHP/PDO, tar vs ZipArchive).
 *
 * Restore order matters because some operations conflict:
 *   1. composer.json + composer.lock (file copy)
 *   2. database (drop + reimport)
 *   3. vendor/ (delete + extract)        — site is broken during this
 *   4. templates/ + contao/templates/    — small, safe
 *   5. files/                            — large, slow
 *   6. assets/                           — usually rebuildable
 *
 * For safety: caller can request a "pre-restore snapshot" (a fresh backup
 * of the CURRENT state) so the restore itself can be undone if it goes wrong.
 */
class RestoreManager
{
    public function __construct(
        private readonly string $projectDir,
        private readonly BackupManager $backupManager,
        private readonly ?\Vtinnovations\Guardian\Service\RuntimeConfig $runtimeConfig = null,
    ) {
    }

    /**
     * Reads a backup's manifest and returns it. Returns null if backup not found.
     */
    public function getBackupManifest(string $backupName): ?array
    {
        $path = $this->resolveBackupPath($backupName);
        if ($path === null) {
            return null;
        }

        $manifestFile = $path . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($manifestFile), true);
        return \is_array($data) ? $data : null;
    }

    /**
     * Lists which components are restorable from this backup, with size + method info.
     *
     * @return array<int, array{key: string, label: string, available: bool, size: ?string, method: ?string, file: ?string}>
     */
    public function getRestorableComponents(string $backupName): array
    {
        $manifest = $this->getBackupManifest($backupName);
        if ($manifest === null) {
            return [];
        }

        $map = [
            ['key' => 'composer',   'label' => 'composer.json + composer.lock',  'manifestKey' => null],
            ['key' => 'database',   'label' => 'Database',                       'manifestKey' => 'database'],
            ['key' => 'vendor',     'label' => 'vendor/',                        'manifestKey' => 'vendor'],
            ['key' => 'templates',  'label' => 'templates/',                     'manifestKey' => 'templates'],
            ['key' => 'contao_tpl', 'label' => 'contao/templates/',              'manifestKey' => 'contao_tpl'],
            ['key' => 'files',      'label' => 'files/',                         'manifestKey' => 'files'],
            ['key' => 'assets',     'label' => 'assets/',                        'manifestKey' => 'assets'],
        ];

        $result = [];
        foreach ($map as $entry) {
            $info = $entry['manifestKey'] ? $manifest[$entry['manifestKey']] ?? null : null;

            // composer files are always present unless explicitly missing
            $available = $entry['key'] === 'composer'
                ? file_exists($this->resolveBackupPath($backupName) . '/composer.json')
                : ($info !== null && ($info['success'] ?? false));

            $result[] = [
                'key'       => $entry['key'],
                'label'     => $entry['label'],
                'available' => $available,
                'size'      => $info['size'] ?? null,
                'method'    => $info['method'] ?? null,
                'file'      => $info['file'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Compares the package set currently installed on disk against the package
     * set that was installed when the backup was made. Used by the recovery
     * panel to warn the user when a partial restore (composer + db only) would
     * leave the system in an inconsistent state — e.g. a bundle was installed
     * after the backup, so restoring composer.json + DB would remove the bundle
     * from the composer files while its vendor/ directory and Symfony container
     * references still exist.
     *
     * Returns:
     *   - missing_in_backup: packages currently installed but NOT in the backup
     *     (these will become orphaned vendor/ entries after restore)
     *   - missing_now:       packages in the backup but NOT currently installed
     *     (these will be restored as composer references with no vendor code)
     *   - version_mismatches: packages whose installed version differs
     *   - has_installed_json: whether the backup includes installed.json
     *
     * @return array{
     *     has_installed_json: bool,
     *     missing_in_backup: array<int, array{name: string, version: string}>,
     *     missing_now: array<int, array{name: string, version: string}>,
     *     version_mismatches: array<int, array{name: string, backup_version: string, current_version: string}>,
     *     risk_level: 'none'|'low'|'high'
     * }
     */
    public function analyzeBackupConsistency(string $backupName): array
    {
        $result = [
            'has_installed_json' => false,
            'missing_in_backup'  => [],
            'missing_now'        => [],
            'version_mismatches' => [],
            'risk_level'         => 'none',
        ];

        $backupPath = $this->resolveBackupPath($backupName);
        if ($backupPath === null) {
            return $result;
        }

        // Load backup's installed.json (saved alongside composer.json/lock)
        $backupInstalled = $this->loadInstalledPackages($backupPath . '/installed.json');
        if ($backupInstalled === null) {
            // Older backups don't have this file. Fall back to composer.lock
            // which lists the same packages (slightly different shape).
            $backupInstalled = $this->loadLockfilePackages($backupPath . '/composer.lock');
        } else {
            $result['has_installed_json'] = true;
        }

        // Load current state
        $currentInstalled = $this->loadInstalledPackages($this->projectDir . '/vendor/composer/installed.json')
            ?? $this->loadLockfilePackages($this->projectDir . '/composer.lock')
            ?? [];

        if (empty($backupInstalled) || empty($currentInstalled)) {
            // Can't analyse if either side is missing; assume worst case
            return $result;
        }

        // Compare
        foreach ($currentInstalled as $name => $version) {
            if (!isset($backupInstalled[$name])) {
                $result['missing_in_backup'][] = ['name' => $name, 'version' => $version];
            } elseif ($backupInstalled[$name] !== $version) {
                $result['version_mismatches'][] = [
                    'name'            => $name,
                    'backup_version'  => $backupInstalled[$name],
                    'current_version' => $version,
                ];
            }
        }
        foreach ($backupInstalled as $name => $version) {
            if (!isset($currentInstalled[$name])) {
                $result['missing_now'][] = ['name' => $name, 'version' => $version];
            }
        }

        // Risk classification:
        //   - Anything missing in backup (= installed after backup) is HIGH risk:
        //     restoring will leave orphan vendor/ + DB schema for these packages.
        //   - Just version mismatches are LOW risk: composer install will sort it out.
        //   - Missing-now packages (in backup, not on disk) are LOW: vendor/ install
        //     would restore them if vendor/ is also being restored.
        if (\count($result['missing_in_backup']) > 0) {
            $result['risk_level'] = 'high';
        } elseif (\count($result['version_mismatches']) > 0 || \count($result['missing_now']) > 0) {
            $result['risk_level'] = 'low';
        }

        return $result;
    }

    /**
     * Reads vendor/composer/installed.json and returns [package_name => version].
     * Returns null if the file is missing or unparseable.
     *
     * @return array<string,string>|null
     */
    private function loadInstalledPackages(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return null;
        }

        // installed.json has two shapes:
        //   Composer 1: a flat array of package dicts
        //   Composer 2: { "packages": [...], "dev": true/false }
        $packages = $data['packages'] ?? $data;
        if (!\is_array($packages)) {
            return null;
        }

        $out = [];
        foreach ($packages as $pkg) {
            if (!\is_array($pkg) || empty($pkg['name'])) {
                continue;
            }
            $out[(string) $pkg['name']] = (string) ($pkg['version'] ?? $pkg['version_normalized'] ?? 'unknown');
        }
        return $out;
    }

    /**
     * Reads composer.lock as a fallback when installed.json is missing.
     * Same return format as loadInstalledPackages.
     *
     * @return array<string,string>|null
     */
    private function loadLockfilePackages(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        if (!\is_array($data) || !isset($data['packages']) || !\is_array($data['packages'])) {
            return null;
        }

        $out = [];
        foreach ($data['packages'] as $pkg) {
            if (!\is_array($pkg) || empty($pkg['name'])) {
                continue;
            }
            $out[(string) $pkg['name']] = (string) ($pkg['version'] ?? 'unknown');
        }
        return $out;
    }

    /**
     * Performs the restore. Each selected component is restored in order.
     * Logs progress to $log.
     *
     * @param array<string, bool> $components
     */
    public function restore(string $backupName, array $components, JobLog $log): void
    {
        $backupPath = $this->resolveBackupPath($backupName);
        if ($backupPath === null) {
            throw new \RuntimeException("Backup not found: {$backupName}");
        }

        $manifest = $this->getBackupManifest($backupName);
        if ($manifest === null) {
            throw new \RuntimeException("Backup manifest missing or unreadable: {$backupName}");
        }

        $log->info('restore', "Restoring from backup: {$backupName}");
        $log->info('restore', 'Backup path: ' . $backupPath);
        $log->info('restore', 'Backup created: ' . ($manifest['created_at'] ?? 'unknown'));
        $log->info('restore', 'Selected components: ' . implode(', ', array_keys(array_filter($components))));

        // Order matters
        $orderedKeys = ['composer', 'database', 'vendor', 'templates', 'contao_tpl', 'files', 'assets'];

        foreach ($orderedKeys as $key) {
            if (empty($components[$key])) {
                continue;
            }

            $log->step('restore', "▶ Restoring component: {$key}");

            match ($key) {
                'composer'   => $this->restoreComposerFiles($backupPath, $log),
                'database'   => $this->restoreDatabase($backupPath, $manifest, $log),
                'vendor'     => $this->restoreDirectory('vendor',           $backupPath, $manifest['vendor']     ?? null, $log),
                'templates'  => $this->restoreDirectory('templates',        $backupPath, $manifest['templates']  ?? null, $log),
                'contao_tpl' => $this->restoreDirectory('contao/templates', $backupPath, $manifest['contao_tpl'] ?? null, $log),
                'files'      => $this->restoreDirectory('files',            $backupPath, $manifest['files']      ?? null, $log),
                'assets'     => $this->restoreDirectory('assets',           $backupPath, $manifest['assets']     ?? null, $log),
                default      => $log->warning('restore', "Unknown component key: {$key}"),
            };

            $log->info('restore', "✓ Component {$key} restored");
        }

        $log->info('restore', 'All selected components restored successfully');
    }

    /**
     * Toggles Contao's maintenance mode via the console command.
     * Returns true on success. On failure tries the var/maintenance.html fallback.
     */
    public function setMaintenanceMode(bool $enable, JobLog $log): bool
    {
        $consoleCmd = $this->projectDir . '/vendor/bin/contao-console';
        if (file_exists($consoleCmd)) {
            try {
                // Invoke explicitly through the PHP CLI binary rather than
                // executing contao-console directly. The script's shebang
                // (`#!/usr/bin/env php`) can fail on hosts where /usr/bin/env
                // doesn't find a CLI PHP, or where the exec bit isn't set —
                // resulting in "Permission denied" code 126.
                $php = $this->runtimeConfig !== null ? $this->runtimeConfig->getPhpBinary() : null;
                if ($php === null || $php === '') {
                    $php = \PHP_BINARY ?: 'php';
                }

                $proc = new Process(
                    [$php, $consoleCmd, 'contao:maintenance-mode', $enable ? 'enable' : 'disable', '--no-interaction'],
                    $this->projectDir,
                    null,
                    null,
                    60,
                );
                $proc->run();

                if ($proc->isSuccessful()) {
                    $log->info('maintenance_' . ($enable ? 'on' : 'off'), 'Maintenance mode ' . ($enable ? 'enabled' : 'disabled') . ' via contao:maintenance-mode');
                    return true;
                }

                $log->warning('maintenance_' . ($enable ? 'on' : 'off'), 'contao:maintenance-mode failed: ' . trim($proc->getOutput() . $proc->getErrorOutput()));
            } catch (\Throwable $e) {
                $log->warning('maintenance_' . ($enable ? 'on' : 'off'), 'contao:maintenance-mode threw: ' . $e->getMessage());
            }
        }

        // Fallback: manage var/maintenance.html directly
        $marker = $this->projectDir . '/var/maintenance.html';
        if ($enable) {
            if (!is_dir(\dirname($marker)) && !@mkdir(\dirname($marker), 0750, true)) {
                $log->error('maintenance_on', 'Cannot create var/ directory for maintenance marker');
                return false;
            }
            $html = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Maintenance</title></head>"
                  . "<body><h1>Site is under maintenance</h1>"
                  . "<p>The site is being restored from a backup. Please come back in a few minutes.</p>"
                  . "</body></html>";
            if (@file_put_contents($marker, $html) !== false) {
                $log->info('maintenance_on', 'Maintenance mode enabled via fallback (var/maintenance.html created)');
                return true;
            }
            $log->error('maintenance_on', 'Could not write var/maintenance.html');
            return false;
        }

        if (file_exists($marker)) {
            if (@unlink($marker)) {
                $log->info('maintenance_off', 'Maintenance mode disabled via fallback (var/maintenance.html removed)');
                return true;
            }
            $log->error('maintenance_off', 'Could not remove var/maintenance.html');
            return false;
        }

        $log->info('maintenance_off', 'Maintenance mode already off');
        return true;
    }

    // ── COMPONENT RESTORERS ──────────────────────────────────────────────

    private function restoreComposerFiles(string $backupPath, JobLog $log): void
    {
        foreach (['composer.json', 'composer.lock'] as $file) {
            $src = $backupPath . '/' . $file;
            $dst = $this->projectDir . '/' . $file;

            if (!file_exists($src)) {
                $log->warning('restore', "Skipping {$file}: not in backup");
                continue;
            }

            if (!@copy($src, $dst)) {
                throw new \RuntimeException("Failed to restore {$file}");
            }
            $log->info('restore', "Restored {$file} (" . $this->formatBytes((int) filesize($dst)) . ')');
        }
    }

    private function restoreDatabase(string $backupPath, array $manifest, JobLog $log): void
    {
        $dbInfo = $manifest['database'] ?? null;
        if (!\is_array($dbInfo) || empty($dbInfo['file'])) {
            throw new \RuntimeException('No database in backup manifest');
        }

        $dumpFile = $backupPath . '/' . $dbInfo['file'];
        if (!file_exists($dumpFile)) {
            throw new \RuntimeException("Database dump file missing: {$dumpFile}");
        }

        $config = $this->loadDbConfig();
        if (empty($config['dbname'])) {
            throw new \RuntimeException('Cannot restore database: no DATABASE_URL configured');
        }

        $log->info('restore', "Database dump: {$dumpFile} ({$dbInfo['size']}, method: {$dbInfo['method']})");

        // Try mysql CLI first (much faster). Fall back to PHP/PDO replay.
        if ($this->canUseExec() && $this->isCommandAvailable('mysql')) {
            try {
                $this->runMysqlImport($config, $dumpFile, $log);
                return;
            } catch (\Throwable $e) {
                $log->warning('restore', 'mysql CLI import failed: ' . $e->getMessage() . ' — falling back to PHP/PDO');
            }
        }

        $this->runPhpMysqlImport($config, $dumpFile, $log);
    }

    private function restoreDirectory(string $relativeDir, string $backupPath, ?array $info, JobLog $log): void
    {
        if (!\is_array($info) || empty($info['success']) || empty($info['file'])) {
            $log->warning('restore', "Skipping {$relativeDir}: not in backup or marked failed");
            return;
        }

        $archiveFile = $backupPath . '/' . $info['file'];
        if (!file_exists($archiveFile)) {
            throw new \RuntimeException("Archive missing: {$archiveFile}");
        }

        $targetDir = $this->projectDir . '/' . $relativeDir;
        $log->info('restore', "Restoring {$relativeDir} from " . $info['file'] . " ({$info['size']}, method: {$info['method']})");

        // Wipe current target directory first (otherwise old files leak through)
        if (is_dir($targetDir)) {
            $log->info('restore', "Removing current {$relativeDir}/...");
            $this->removeDir($targetDir);
        }

        $parent = \dirname($targetDir);
        if (!is_dir($parent)) {
            @mkdir($parent, 0750, true);
        }

        // Use the same method that was used to create it
        $method = $info['method'] ?? 'tar';
        match ($method) {
            'tar' => $this->extractTar($archiveFile, $parent, $log),
            'zip' => $this->extractZip($archiveFile, $parent, $log),
            default => throw new \RuntimeException("Unknown archive method: {$method}"),
        };

        $log->info('restore', "Extraction of {$relativeDir} complete");
    }

    private function extractTar(string $archive, string $targetParent, JobLog $log): void
    {
        if (!$this->canUseExec() || !$this->isCommandAvailable('tar')) {
            throw new \RuntimeException('tar archive present but tar binary unavailable');
        }

        // ── Zip-slip protection for tar archives ──
        // `tar -xzf` strips leading "/" by default but DOES follow ".." segments.
        // A backup tarball with "../../etc/cron.d/exploit" would land outside our
        // restore directory. List the contents first and reject anything that
        // tries to escape.
        $listProc = new Process(['tar', '-tzf', $archive], $this->projectDir, null, null, 120);
        $listProc->run();

        if (!$listProc->isSuccessful()) {
            throw new \RuntimeException('Could not list tar contents: ' . trim($listProc->getErrorOutput()));
        }

        foreach (preg_split('/\r?\n/', $listProc->getOutput()) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            // Absolute paths
            if (str_starts_with($entry, '/')) {
                throw new \RuntimeException('Refusing to extract: tar contains absolute path: ' . $entry);
            }
            // Path traversal
            $segments = preg_split('#/+#', $entry) ?: [];
            foreach ($segments as $segment) {
                if ($segment === '..') {
                    throw new \RuntimeException('Refusing to extract: tar contains path traversal: ' . $entry);
                }
            }
        }

        $proc = new Process(
            ['tar', '-xzf', $archive, '-C', $targetParent],
            $this->projectDir,
            null,
            null,
            1800,
        );
        $proc->run();

        if (!$proc->isSuccessful()) {
            throw new \RuntimeException('tar extraction failed: ' . trim($proc->getErrorOutput()));
        }
    }

    private function extractZip(string $archive, string $targetParent, JobLog $log): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('zip archive present but ZipArchive unavailable');
        }

        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('Could not open zip: ' . $archive);
        }

        // ── Zip-slip protection ──
        // Refuse to extract any entry whose path escapes the target directory.
        // ZipArchive::extractTo() does NOT check this on its own. A malicious
        // archive could contain entries like "../../../etc/cron.d/exploit"
        // which would be written outside our restore directory.
        $targetReal = realpath($targetParent);
        if ($targetReal === false) {
            $zip->close();
            throw new \RuntimeException('Target parent does not exist: ' . $targetParent);
        }
        $targetReal = rtrim($targetReal, '/') . '/';

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entry = (string) $zip->getNameIndex($i);

            // Absolute paths are never legitimate inside a backup
            if (str_starts_with($entry, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $entry) === 1) {
                $zip->close();
                throw new \RuntimeException('Refusing to extract: zip contains absolute path: ' . $entry);
            }

            // Reject any segment of `..`
            $segments = preg_split('#[/\\\\]+#', $entry) ?: [];
            foreach ($segments as $segment) {
                if ($segment === '..') {
                    $zip->close();
                    throw new \RuntimeException('Refusing to extract: zip contains path traversal: ' . $entry);
                }
            }

            // Belt-and-suspenders: resolve where the entry WOULD land and verify
            // it's still inside the target directory.
            $wouldLand = $targetReal . ltrim($entry, '/');
            $resolved  = $this->resolveWithoutSymlinks($wouldLand);
            if (!str_starts_with($resolved, $targetReal)) {
                $zip->close();
                throw new \RuntimeException('Refusing to extract: zip entry resolves outside target: ' . $entry);
            }
        }

        if (!$zip->extractTo($targetParent)) {
            $zip->close();
            throw new \RuntimeException('ZipArchive::extractTo() failed');
        }

        $zip->close();
    }

    /**
     * Resolves `.` and `..` segments in a path without touching the filesystem
     * (so non-existing destinations are handled gracefully). Returns an
     * absolute-ish path with a leading "/" if the input was absolute.
     */
    private function resolveWithoutSymlinks(string $path): string
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

    private function runMysqlImport(array $config, string $dumpFile, JobLog $log): void
    {
        // Build the command WITHOUT the password in argv. Passing it via the
        // MYSQL_PWD environment variable keeps it out of `ps -ef` output and
        // out of bash history. The mysql client reads it transparently.
        $cmd = sprintf(
            'gunzip < %s | mysql --host=%s --port=%s --user=%s %s',
            escapeshellarg($dumpFile),
            escapeshellarg((string) ($config['host'] ?? 'localhost')),
            escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg((string) ($config['user'] ?? 'root')),
            escapeshellarg((string) $config['dbname'])
        );

        // Run the pipeline in a subshell with MYSQL_PWD scoped to it.
        // We use proc_open to set the environment explicitly because exec() does
        // not give us a clean way to pass env vars without going through `env`.
        $exitCode = $this->runWithMysqlPassword($cmd, (string) ($config['password'] ?? ''), $output);

        if ($exitCode !== 0) {
            throw new \RuntimeException('mysql import exit ' . $exitCode . ': ' . implode("\n", \array_slice($output, -10)));
        }

        $log->info('restore', 'Database imported via mysql CLI');
    }

    /**
     * Runs a mysql/mysqldump command with the password supplied via MYSQL_PWD,
     * never as a command-line argument. Returns the exit code; output is
     * collected into the by-ref array.
     *
     * @param array<int,string> $output
     */
    private function runWithMysqlPassword(string $cmd, string $password, array &$output): int
    {
        $output = [];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Build a minimal env that inherits PATH (so `mysql` resolves) plus
        // the secret. We do NOT pass the full $_ENV/$_SERVER because that
        // would leak unrelated secrets to the child.
        $env = [
            'PATH'       => (string) ($_SERVER['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
            'MYSQL_PWD'  => $password,
            'HOME'       => (string) ($_SERVER['HOME'] ?? '/tmp'),
            'LC_ALL'     => 'C',
        ];

        $proc = @proc_open('/bin/sh -c ' . escapeshellarg($cmd . ' 2>&1'), $descriptors, $pipes, null, $env);
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

    private function runPhpMysqlImport(array $config, string $dumpFile, JobLog $log): void
    {
        $log->info('restore', 'Importing database via PHP/PDO (slower than mysql CLI)...');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'] ?? 'localhost',
            (int) ($config['port'] ?? 3306),
            $config['dbname']
        );

        $pdo = new \PDO($dsn, $config['user'] ?? '', $config['password'] ?? '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        // Disable foreign key checks for the import duration. Lets us drop+
        // recreate tables in any order. Re-enabled in the finally block.
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        } catch (\PDOException $e) {
            $log->warning('restore', 'Could not disable foreign key checks: ' . $e->getMessage());
        }

        $gz = @gzopen($dumpFile, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('Cannot open gzipped dump: ' . $dumpFile);
        }

        $buffer        = '';
        $stmtCount     = 0;
        $errors        = [];   // serious errors only
        $harmlessCount = 0;    // "table exists" etc.

        // Error classification:
        //
        //   HARMLESS: errors that happen when restoring onto an existing schema.
        //     Older mysqldump backups may lack DROP statements, leading to
        //     "table already exists" floods. We auto-DROP before each CREATE
        //     TABLE to prevent this, AND we tolerate these errors as a fallback.
        //
        //   FATAL: connection / permission / resource problems → stop immediately.
        //
        //   SERIOUS: anything else. Counted toward abort thresholds.
        //
        // MySQL SQLSTATE reference:
        //   42S01  base table or view already exists (errno 1050)
        //   42S02  base table or view not found (1051, 1146)
        //   42S21  column already exists (1060)
        //   23000  integrity constraint violation — duplicate key (1062)
        $harmlessSqlStateCodes = ['42S01', '42S02', '42S21', '23000'];

        $errorThreshold      = 50;
        $errorRateThreshold  = 0.10;
        $rateCheckMinStmts   = 100;

        $fatalPatterns = [
            'server has gone away',
            'lost connection',
            'access denied',
            'out of memory',
            'disk full',
            'no space left',
            'table is full',
            'too many connections',
        ];

        $classifyAndAct = function (string $stmt, \PDOException $e) use (
            &$errors, &$harmlessCount, $log, $fatalPatterns, $harmlessSqlStateCodes,
            $errorThreshold, $errorRateThreshold, $rateCheckMinStmts, &$stmtCount
        ): void {
            $errMsg   = $e->getMessage();
            $errLower = strtolower($errMsg);
            $sqlState = (string) $e->getCode();

            // Fatal: bail out immediately
            foreach ($fatalPatterns as $pat) {
                if (str_contains($errLower, $pat)) {
                    throw new \RuntimeException(
                        'Aborting restore: fatal SQL error (' . $errMsg . '). '
                      . 'The database is likely in an inconsistent state — investigate before retrying.'
                    );
                }
            }

            // Harmless: log first few, then suppress
            if (\in_array($sqlState, $harmlessSqlStateCodes, true)
                || str_contains($errLower, 'already exists')
                || str_contains($errLower, 'duplicate')
                || str_contains($errLower, "doesn't exist")
                || str_contains($errLower, 'unknown table')) {
                ++$harmlessCount;
                if ($harmlessCount <= 5) {
                    $log->info('restore', 'Skipping harmless SQL warning: ' . $errMsg);
                } elseif ($harmlessCount === 6) {
                    $log->info('restore', '(further harmless warnings suppressed)');
                }
                return;
            }

            // Serious: log + count toward thresholds
            $errors[] = $errMsg;
            $log->warning('restore', 'SQL error: ' . $errMsg . ' — statement: ' . substr($stmt, 0, 80));

            if (\count($errors) >= $errorThreshold) {
                throw new \RuntimeException(sprintf(
                    'Aborting restore: %d SERIOUS SQL errors. Dump likely corrupt or incompatible. Last: %s',
                    \count($errors),
                    $errMsg
                ));
            }

            if ($stmtCount >= $rateCheckMinStmts) {
                $rate = \count($errors) / ($stmtCount + \count($errors));
                if ($rate > $errorRateThreshold) {
                    throw new \RuntimeException(sprintf(
                        'Aborting restore: %d serious errors in %d statements (%.0f%% failure rate).',
                        \count($errors),
                        $stmtCount + \count($errors),
                        $rate * 100
                    ));
                }
            }
        };

        // When the dump doesn't include DROP TABLE statements (older mysqldump
        // without --add-drop-table), we proactively drop existing tables right
        // before recreating them. Without this, every CREATE TABLE hits
        // "table already exists" on a non-empty target schema.
        $ensureDropBeforeCreate = function (string $stmt) use ($pdo, $log): void {
            if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`[^`]+`|\S+)/i', $stmt, $m)) {
                $tableName = trim($m[1]);
                try {
                    $pdo->exec('DROP TABLE IF EXISTS ' . $tableName);
                } catch (\PDOException $e) {
                    $log->info('restore', 'Pre-drop of ' . $tableName . ' failed (ignored): ' . $e->getMessage());
                }
            }
        };

        try {
            while (!gzeof($gz)) {
                $buffer .= gzread($gz, 65536);

                while (($pos = strpos($buffer, ";\n")) !== false) {
                    $stmt = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 2);

                    if ($stmt === '' || str_starts_with($stmt, '--') || str_starts_with($stmt, '/*')) {
                        continue;
                    }

                    $ensureDropBeforeCreate($stmt);

                    try {
                        $pdo->exec($stmt);
                        ++$stmtCount;
                        if ($stmtCount % 100 === 0) {
                            $log->info('restore', "  Imported {$stmtCount} statements...");
                        }
                    } catch (\PDOException $e) {
                        $classifyAndAct($stmt, $e);
                    }
                }
            }

            $tail = trim($buffer);
            if ($tail !== '' && !str_starts_with($tail, '--')) {
                $ensureDropBeforeCreate($tail);
                try {
                    $pdo->exec($tail);
                    ++$stmtCount;
                } catch (\PDOException $e) {
                    $classifyAndAct($tail, $e);
                }
            }
        } finally {
            gzclose($gz);
            // Re-enable foreign key checks no matter how we exit
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (\PDOException $e) {
                $log->warning('restore', 'Could not re-enable foreign key checks: ' . $e->getMessage());
            }
        }

        $errorCount = \count($errors);
        if ($errorCount > 0) {
            $log->warning('restore', sprintf(
                'Database imported with %d serious SQL errors and %d harmless warnings in %d statements. '
              . 'Review warnings above — the database may need manual inspection.',
                $errorCount,
                $harmlessCount,
                $stmtCount
            ));
        } else {
            $log->info('restore', sprintf(
                'Database imported cleanly: %d statements executed%s',
                $stmtCount,
                $harmlessCount > 0 ? ", {$harmlessCount} harmless warnings suppressed" : ''
            ));
        }
    }

    // ── UTILITIES ────────────────────────────────────────────────────────

    private function resolveBackupPath(string $name): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $name)) {
            return null;
        }

        $candidates = [
            $this->backupManager->getStorageDir() . '/' . $name,
            $this->projectDir . '/var/updater/backup/' . $name,
        ];

        foreach ($candidates as $path) {
            if (is_dir($path)) {
                return rtrim($path, '/');
            }
        }
        return null;
    }

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

    private function loadDbConfig(): array
    {
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
                    $p = parse_url($url);
                    if ($p === false) continue;
                    return [
                        'host'     => $p['host']                      ?? 'localhost',
                        'port'     => (int) ($p['port']               ?? 3306),
                        'user'     => urldecode((string) ($p['user'] ?? '')),
                        'password' => urldecode((string) ($p['pass'] ?? '')),
                        'dbname'   => ltrim((string) ($p['path']     ?? ''), '/'),
                    ];
                }
            }
        }
        return [];
    }

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

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            ++$i;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
