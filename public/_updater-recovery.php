<?php
/**
 * Guardian — STANDALONE RECOVERY PANEL
 *
 * Drop-in single-file recovery tool. Works EVEN WHEN CONTAO IS BROKEN.
 *
 * Architecture: this file has zero dependencies on Symfony, Contao, Doctrine,
 * or composer's autoloader. It uses only PHP-builtin functions and shells out
 * to OS commands (tar, gunzip, mysql) for the heavy lifting. Same approach as
 * Contao Manager's standalone phar.
 *
 * Place at: <project>/public/_updater-recovery.php
 *
 * Auth: same token as the in-Contao recovery panel.
 *   1. ENV var VTINNOVATIONS_GUARDIAN_TOKEN (set via .env.local)
 *   2. File var/updater/access.token (auto-generated on first use)
 *   Accepts the token via HTTP Basic Auth password OR Authorization: Bearer.
 *
 * URL: https://yoursite.example/_updater-recovery.php
 *
 * @copyright V&T Innovations 2026 - 2028
 * @license   GNU/LGPL
 */

declare(strict_types=1);

// ============================================================================
//   ZERO-DEPENDENCY BOOTSTRAP
// ============================================================================

// Detect project root: this file lives in <project>/public/, so go one level up.
$projectDir = realpath(__DIR__ . '/..');
if ($projectDir === false || !is_dir($projectDir . '/var')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Recovery panel: could not detect project directory.\n";
    echo "Expected: " . __DIR__ . "/../var/ to exist.\n";
    exit;
}

// ============================================================================
//   CONFIGURATION
// ============================================================================

const TOKEN_FILE        = '/var/updater/access.token';
const BACKUP_DIR        = '/var/updater/backup';
const ENV_TOKEN_KEY     = 'VTINNOVATIONS_GUARDIAN_TOKEN';
const MAX_LOG_SIZE      = 2 * 1024 * 1024;   // 2 MB of log buffered
const RESTORE_TIMEOUT   = 1800;              // 30 minutes per operation
const STORAGE_DIR_BASE  = '/var/updater/recovery';   // where our own work files live

// Brute-force protection. After RECOVERY_LOCKOUT_MAX failed auth attempts
// from one client IP within RECOVERY_LOCKOUT_WINDOW seconds, that IP is
// locked out for RECOVERY_LOCKOUT_SECONDS.
const RECOVERY_LOCKOUT_MAX     = 8;
const RECOVERY_LOCKOUT_WINDOW  = 900;        // 15 min sliding window
const RECOVERY_LOCKOUT_SECONDS = 900;        // 15 min lockout

// ============================================================================
//   ENTRY POINT — ROUTE BY ACTION QUERY PARAM
// ============================================================================

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

// Brute-force lockout: refuse everything (including the HTML render) while
// this client IP is locked out for too many failed auth attempts.
if (recoveryIsLockedOut($projectDir, $clientIp)) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Retry-After: ' . RECOVERY_LOCKOUT_SECONDS);
    echo "Too many failed authentication attempts.\n";
    echo "This client is temporarily locked out. Try again later.\n";
    exit;
}

// EVERYTHING requires authentication — including the HTML panel render.
// Rendering the page unauthenticated would advertise the panel's existence
// and expose its UI; there is no legitimate anonymous view.
if (!authenticateRequest($projectDir)) {
    recoveryRecordAuthFailure($projectDir, $clientIp);
    sendAuthChallenge();
    exit;
}
recoveryClearAuthFailures($projectDir, $clientIp);

try {
    switch ($action) {
        case '':
            renderPanel($projectDir);
            break;

        case 'list':
            assertSameOrigin(false);
            sendJson(['success' => true, 'backups' => listBackups($projectDir)]);
            break;

        case 'manifest':
            assertSameOrigin(false);
            $name = (string) ($_GET['name'] ?? '');
            sendJson(getBackupManifest($projectDir, $name));
            break;

        case 'restore':
            assertSameOrigin(true);
            $payload   = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $name      = (string) ($payload['name'] ?? '');
            $parts     = (array)  ($payload['components'] ?? []);
            $maintMode = (bool)   ($payload['maintenance'] ?? true);
            sendJson(runRestore($projectDir, $name, $parts, $maintMode));
            break;

        case 'log':
            assertSameOrigin(false);
            $offset = (int) ($_GET['offset'] ?? 0);
            sendJson(readActiveLog($projectDir, $offset));
            break;

        case 'status':
            assertSameOrigin(false);
            sendJson(getRecoveryStatus($projectDir));
            break;

        case 'diagnostics':
            assertSameOrigin(false);
            sendJson(getDiagnostics($projectDir));
            break;

        case 'delete':
            assertSameOrigin(true);
            $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $name    = (string) ($payload['name'] ?? '');
            sendJson(deleteBackup($projectDir, $name));
            break;

        default:
            http_response_code(400);
            sendJson(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    sendJson(['success' => false, 'error' => $e->getMessage()]);
}
exit;


// ============================================================================
//   AUTHENTICATION
// ============================================================================

/**
 * Returns true if the request supplied a valid token via Basic Auth or
 * Authorization: Bearer header. Query-string tokens are deliberately NOT
 * supported here (they leak into server logs and Referer headers).
 */
function authenticateRequest(string $projectDir): bool
{
    $expected = readToken($projectDir);
    if ($expected === null || $expected === '') {
        // No token configured — refuse all access. This forces the admin to
        // either set VTINNOVATIONS_GUARDIAN_TOKEN in .env.local or create
        // var/updater/access.token before the panel becomes usable.
        return false;
    }

    // HTTP Basic Auth (PHP_AUTH_PW)
    $basicPw = $_SERVER['PHP_AUTH_PW'] ?? null;
    if ($basicPw !== null && is_string($basicPw) && hash_equals($expected, $basicPw)) {
        return true;
    }

    // Authorization: Bearer <token>
    // Apache strips this header by default — getallheaders() works around it
    // when mod_rewrite is configured correctly.
    $auth = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }
    if (str_starts_with($auth, 'Bearer ')) {
        $token = substr($auth, 7);
        if (hash_equals($expected, $token)) {
            return true;
        }
    }

    return false;
}

function sendAuthChallenge(): void
{
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="Guardian — Standalone Recovery"');
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Authentication required.\n";
    echo "\n";
    echo "This is the standalone recovery panel — it works even when Contao is broken.\n";
    echo "\n";
    echo "Enter ANY username and the access token as password.\n";
    echo "Find the token in:\n";
    echo "  1. .env.local  →  VTINNOVATIONS_GUARDIAN_TOKEN=...\n";
    echo "  2. var/updater/access.token (auto-generated)\n";
}

// ---------------------------------------------------------------------------
//   BRUTE-FORCE LOCKOUT
//
//   Failed auth attempts are tracked per client IP in a small JSON file under
//   var/updater/recovery/. After RECOVERY_LOCKOUT_MAX failures within
//   RECOVERY_LOCKOUT_WINDOW seconds the IP is locked out for
//   RECOVERY_LOCKOUT_SECONDS. Best-effort: if the file can't be written we
//   fail open (don't block recovery), since this is a last-resort tool.
// ---------------------------------------------------------------------------

function recoveryLockoutFile(string $projectDir): string
{
    $dir = $projectDir . STORAGE_DIR_BASE;
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir . '/auth-failures.json';
}

function recoveryLoadFailures(string $projectDir): array
{
    $file = recoveryLockoutFile($projectDir);
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function recoverySaveFailures(string $projectDir, array $data): void
{
    $file = recoveryLockoutFile($projectDir);

    // Prune entries that are well past the window AND not actively locked,
    // so the file can't grow unbounded.
    $now = time();
    foreach ($data as $ip => $rec) {
        $last       = (int) ($rec['last'] ?? 0);
        $lockedTill = (int) ($rec['locked_until'] ?? 0);
        if ($lockedTill < $now && ($now - $last) > (RECOVERY_LOCKOUT_WINDOW * 4)) {
            unset($data[$ip]);
        }
    }

    @file_put_contents($file, json_encode($data), LOCK_EX);
    @chmod($file, 0600);
}

function recoveryIsLockedOut(string $projectDir, string $ip): bool
{
    $data = recoveryLoadFailures($projectDir);
    $rec  = $data[$ip] ?? null;
    if (!is_array($rec)) {
        return false;
    }
    return (int) ($rec['locked_until'] ?? 0) > time();
}

function recoveryRecordAuthFailure(string $projectDir, string $ip): void
{
    $data = recoveryLoadFailures($projectDir);
    $now  = time();
    $rec  = $data[$ip] ?? ['count' => 0, 'first' => $now, 'last' => $now, 'locked_until' => 0];

    // Reset the counter if the sliding window has elapsed since the first fail.
    if (($now - (int) ($rec['first'] ?? $now)) > RECOVERY_LOCKOUT_WINDOW) {
        $rec = ['count' => 0, 'first' => $now, 'last' => $now, 'locked_until' => 0];
    }

    $rec['count'] = (int) ($rec['count'] ?? 0) + 1;
    $rec['last']  = $now;

    if ($rec['count'] >= RECOVERY_LOCKOUT_MAX) {
        $rec['locked_until'] = $now + RECOVERY_LOCKOUT_SECONDS;
    }

    $data[$ip] = $rec;
    recoverySaveFailures($projectDir, $data);
}

function recoveryClearAuthFailures(string $projectDir, string $ip): void
{
    $data = recoveryLoadFailures($projectDir);
    if (isset($data[$ip])) {
        unset($data[$ip]);
        recoverySaveFailures($projectDir, $data);
    }
}

/**
 * Reads the panel access token from the same sources the in-Contao panel uses,
 * so the user has one token to remember.
 */
function readToken(string $projectDir): ?string
{
    // 1. ENV (typically populated by Symfony's .env loader, but we read it
    //    directly too because we run BEFORE any framework bootstraps)
    $envValue = $_SERVER[ENV_TOKEN_KEY] ?? $_ENV[ENV_TOKEN_KEY] ?? getenv(ENV_TOKEN_KEY);
    if ($envValue !== false && is_string($envValue) && trim($envValue) !== '') {
        return trim($envValue);
    }

    // 2. Parse .env.local / .env manually
    foreach (['.env.local', '.env'] as $file) {
        $path = $projectDir . '/' . $file;
        if (!file_exists($path)) {
            continue;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === ENV_TOKEN_KEY) {
                return trim($v, " \t\"'");
            }
        }
    }

    // 3. Auto-generated file
    $tokenFile = $projectDir . TOKEN_FILE;
    if (file_exists($tokenFile)) {
        $content = trim((string) @file_get_contents($tokenFile));
        if ($content !== '') {
            return $content;
        }
    }

    return null;
}

/**
 * Same-origin (CSRF) check. Only applies to state-changing actions. For GETs
 * it returns immediately.
 */
function assertSameOrigin(bool $isUnsafe): void
{
    if (!$isUnsafe) {
        return;
    }

    $expectedHost = $_SERVER['HTTP_HOST'] ?? '';

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (is_string($origin) && $origin !== '' && $origin !== 'null') {
        $h = (string) parse_url($origin, PHP_URL_HOST);
        if (strcasecmp($h, $expectedHost) === 0) {
            return;
        }
        http_response_code(403);
        sendJson(['success' => false, 'error' => 'CSRF: Origin mismatch']);
        exit;
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (is_string($referer) && $referer !== '') {
        $h = (string) parse_url($referer, PHP_URL_HOST);
        if (strcasecmp($h, $expectedHost) === 0) {
            return;
        }
        http_response_code(403);
        sendJson(['success' => false, 'error' => 'CSRF: Referer mismatch']);
        exit;
    }

    http_response_code(403);
    sendJson(['success' => false, 'error' => 'CSRF: missing Origin/Referer']);
    exit;
}


// ============================================================================
//   BACKUP LISTING
// ============================================================================

/**
 * @return array<int, array<string, mixed>>
 */
function listBackups(string $projectDir): array
{
    $dir = $projectDir . BACKUP_DIR;
    if (!is_dir($dir)) {
        return [];
    }

    $entries = @scandir($dir) ?: [];
    $backups = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        // Backup names follow yyyy-mm-dd_hh-mm-ss
        if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $entry)) {
            continue;
        }
        $manifest = readManifest($dir . '/' . $entry);
        $backups[] = [
            'name'           => $entry,
            'created_at'     => $manifest['created_at']     ?? '?',
            'contao_version' => $manifest['contao_version'] ?? '?',
            'php_version'    => $manifest['php_version']    ?? '?',
            'total_size'     => $manifest['total_size']     ?? '?',
            'schedule_type'  => $manifest['schedule_type']  ?? null,
            'components'     => array_keys($manifest['components'] ?? []),
        ];
    }

    // Newest first
    usort($backups, fn ($a, $b) => strcmp($b['name'], $a['name']));
    return $backups;
}

function readManifest(string $backupPath): array
{
    $f = $backupPath . '/manifest.json';
    if (!file_exists($f)) {
        return [];
    }
    $data = json_decode((string) @file_get_contents($f), true);
    return is_array($data) ? $data : [];
}

/**
 * @return array<string, mixed>
 */
function getBackupManifest(string $projectDir, string $name): array
{
    if (!validateBackupName($name)) {
        return ['success' => false, 'error' => 'Invalid backup name format'];
    }

    $dir = $projectDir . BACKUP_DIR . '/' . $name;
    if (!is_dir($dir)) {
        return ['success' => false, 'error' => 'Backup not found'];
    }

    $manifest = readManifest($dir);
    $components = discoverComponents($dir, $manifest);

    return [
        'success'    => true,
        'manifest'   => $manifest,
        'components' => $components,
    ];
}

/**
 * Lists each restorable component with its physical presence on disk.
 * Falls back to file detection if manifest.json is missing.
 */
function discoverComponents(string $backupPath, array $manifest): array
{
    $files = [
        'composer'  => ['file' => 'composer.json',   'label' => 'Composer files (composer.json + lock)'],
        'database'  => ['file' => 'database.sql.gz', 'label' => 'Database dump'],
        'vendor'    => ['file' => 'vendor.tar.gz',   'label' => 'vendor/ directory'],
        'templates' => ['file' => 'templates.tar.gz','label' => 'templates/ directory'],
        'files'     => ['file' => 'files.tar.gz',    'label' => 'files/ (Contao media)'],
        'assets'    => ['file' => 'assets.tar.gz',   'label' => 'assets/ (compiled CSS/JS)'],
    ];

    $result = [];
    foreach ($files as $key => $info) {
        $path = $backupPath . '/' . $info['file'];
        if (file_exists($path)) {
            $result[] = [
                'key'       => $key,
                'label'     => $info['label'],
                'available' => true,
                'size'      => formatBytes(@filesize($path) ?: 0),
            ];
        } else {
            $result[] = [
                'key'       => $key,
                'label'     => $info['label'],
                'available' => false,
                'size'      => null,
            ];
        }
    }

    return $result;
}


// ============================================================================
//   RESTORE EXECUTION
// ============================================================================

/**
 * Performs a restore. Synchronous (the request blocks until done) — works
 * fine for backups under a few hundred MB. For huge sites this might
 * exceed PHP's max_execution_time; we set_time_limit(0) defensively.
 *
 * @param array<string, mixed> $componentsRaw
 */
function runRestore(string $projectDir, string $name, array $componentsRaw, bool $maintenance): array
{
    if (!validateBackupName($name)) {
        return ['success' => false, 'error' => 'Invalid backup name format'];
    }

    $backupDir = $projectDir . BACKUP_DIR . '/' . $name;
    if (!is_dir($backupDir)) {
        return ['success' => false, 'error' => 'Backup directory not found: ' . $name];
    }

    // Sanitise: components must be one of the known keys, value boolean
    $validKeys = ['composer', 'database', 'vendor', 'templates', 'files', 'assets'];
    $components = [];
    foreach ($validKeys as $k) {
        $components[$k] = !empty($componentsRaw[$k]);
    }

    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $logFile = ensureLogFile($projectDir);
    $log = function (string $level, string $step, string $msg) use ($logFile): void {
        $line = sprintf("[%s] [%s] %s: %s\n", date('H:i:s'), $level, $step, $msg);
        @file_put_contents($logFile, $line, FILE_APPEND);
    };

    $log('info', 'recovery', "Starting standalone restore from {$name}");
    $log('info', 'recovery', 'Selected components: ' . implode(', ', array_keys(array_filter($components))));

    // STATUS FILE — small JSON updated as we progress
    $statusFile = $projectDir . STORAGE_DIR_BASE . '/status.json';
    writeStatus($statusFile, ['running' => true, 'started_at' => date('c'), 'step' => 'starting']);

    try {
        // 1. Maintenance mode ON (file-marker fallback only — we can't trust
        //    contao-console here since Contao might be broken)
        if ($maintenance) {
            $log('info', 'maintenance_on', 'Enabling maintenance mode (file marker)');
            writeStatus($statusFile, ['running' => true, 'step' => 'maintenance_on']);
            enableMaintenance($projectDir, $log);
        }

        // 2. Composer files
        if ($components['composer']) {
            $log('info', 'restore', 'Restoring composer files');
            writeStatus($statusFile, ['running' => true, 'step' => 'composer']);
            restoreFile($backupDir . '/composer.json', $projectDir . '/composer.json', $log);
            restoreFile($backupDir . '/composer.lock', $projectDir . '/composer.lock', $log);
        }

        // 3. Vendor
        if ($components['vendor']) {
            $log('info', 'restore', 'Restoring vendor/ (may take a few minutes)');
            writeStatus($statusFile, ['running' => true, 'step' => 'vendor']);
            restoreTar($backupDir . '/vendor.tar.gz', $projectDir, 'vendor', $log);
        }

        // 4. Templates / files / assets
        foreach (['templates', 'files', 'assets'] as $part) {
            if ($components[$part]) {
                $log('info', 'restore', "Restoring {$part}/");
                writeStatus($statusFile, ['running' => true, 'step' => $part]);
                $archive = $backupDir . '/' . $part . '.tar.gz';
                if (file_exists($archive)) {
                    restoreTar($archive, $projectDir, $part, $log);
                } else {
                    $log('warning', 'restore', "Archive missing: {$part}.tar.gz — skipping");
                }
            }
        }

        // 5. Database
        if ($components['database']) {
            $log('info', 'restore', 'Restoring database');
            writeStatus($statusFile, ['running' => true, 'step' => 'database']);
            restoreDatabase($projectDir, $backupDir . '/database.sql.gz', $log);
        }

        // 6. Clear Symfony cache (safe to do via filesystem)
        $log('info', 'cache_clear', 'Clearing Symfony cache');
        writeStatus($statusFile, ['running' => true, 'step' => 'cache_clear']);
        clearSymfonyCache($projectDir, $log);

        // 7. Maintenance OFF
        if ($maintenance) {
            $log('info', 'maintenance_off', 'Disabling maintenance mode');
            writeStatus($statusFile, ['running' => true, 'step' => 'maintenance_off']);
            disableMaintenance($projectDir, $log);
        }

        writeStatus($statusFile, [
            'running'     => false,
            'success'     => true,
            'finished_at' => date('c'),
            'step'        => 'done',
        ]);
        $log('info', 'recovery', '✅ Restore completed');

        return ['success' => true, 'message' => 'Restore completed'];
    } catch (\Throwable $e) {
        $log('error', 'recovery', '❌ Restore failed: ' . $e->getMessage());
        writeStatus($statusFile, [
            'running'     => false,
            'success'     => false,
            'finished_at' => date('c'),
            'error'       => $e->getMessage(),
        ]);

        // Best-effort maintenance off so the site doesn't stay down
        if ($maintenance) {
            try { disableMaintenance($projectDir, $log); } catch (\Throwable $ignored) {}
        }

        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function restoreFile(string $src, string $dest, callable $log): void
{
    if (!file_exists($src)) {
        $log('warning', 'restore', 'Source file missing: ' . basename($src));
        return;
    }
    if (!@copy($src, $dest)) {
        throw new \RuntimeException("Could not copy {$src} → {$dest}");
    }
    $log('info', 'restore', 'Copied ' . basename($src) . ' (' . formatBytes((int) @filesize($dest)) . ')');
}

/**
 * Extracts $archive into $projectDir, replacing $targetDir entirely.
 * Uses tar via exec — same approach as the main bundle.
 */
function restoreTar(string $archive, string $projectDir, string $targetDir, callable $log): void
{
    if (!file_exists($archive)) {
        throw new \RuntimeException("Archive not found: {$archive}");
    }
    if (!commandExists('tar')) {
        throw new \RuntimeException('tar command not available');
    }

    // Zip-slip check: list entries first, reject any with absolute paths or ..
    $listCmd = 'tar -tzf ' . escapeshellarg($archive) . ' 2>&1';
    $out = [];
    $exit = 1;
    @exec($listCmd, $out, $exit);
    if ($exit !== 0) {
        throw new \RuntimeException('Could not list tar: ' . implode("\n", $out));
    }
    // Every entry must stay within the expected top-level folder (e.g.
    // vendor/, templates/, files/). Without this a tampered/foreign archive
    // could carry public/shell.php and have it extracted into the webroot.
    $allowedTop = trim($targetDir, '/');             // e.g. "vendor"
    $allowedPrefix = $allowedTop . '/';              // e.g. "vendor/"

    foreach ($out as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }

        // Null byte → reject outright (path-truncation tricks).
        if (str_contains($entry, "\0")) {
            throw new \RuntimeException('Refusing tar: null byte in entry');
        }

        // Absolute path → reject.
        if (str_starts_with($entry, '/')) {
            throw new \RuntimeException('Refusing tar: absolute path: ' . $entry);
        }

        // Normalise a possible leading "./" that tar sometimes emits.
        $normalised = $entry;
        while (str_starts_with($normalised, './')) {
            $normalised = substr($normalised, 2);
        }
        $normalised = ltrim($normalised, '/');

        // No ".." traversal segments anywhere.
        foreach (preg_split('#/+#', $normalised) ?: [] as $seg) {
            if ($seg === '..') {
                throw new \RuntimeException('Refusing tar: traversal: ' . $entry);
            }
        }

        // Must be the target folder itself or live inside it.
        $isTopItself = ($normalised === $allowedTop || $normalised === $allowedPrefix);
        if (!$isTopItself && !str_starts_with($normalised, $allowedPrefix)) {
            throw new \RuntimeException(sprintf(
                'Refusing tar entry outside expected "%s/" folder: %s',
                $allowedTop,
                $entry
            ));
        }
    }

    // Remove existing target directory (vendor/, templates/, etc.) before extract
    $targetPath = $projectDir . '/' . $targetDir;
    if (is_dir($targetPath)) {
        $log('info', 'restore', 'Removing existing ' . $targetDir . '/');
        if (!removeDirectory($targetPath)) {
            $log('warning', 'restore', 'Could not fully remove existing ' . $targetDir . '/');
        }
    }

    $extractCmd = 'tar -xzf ' . escapeshellarg($archive)
                . ' -C ' . escapeshellarg($projectDir) . ' 2>&1';
    $out = [];
    $exit = 1;
    @exec($extractCmd, $out, $exit);
    if ($exit !== 0) {
        throw new \RuntimeException('tar extraction failed: ' . implode("\n", $out));
    }
    $log('info', 'restore', 'Extracted ' . basename($archive));
}

/**
 * Restores the database from a .sql.gz dump using the mysql CLI.
 * Password supplied via MYSQL_PWD env var (not argv).
 */
function restoreDatabase(string $projectDir, string $dumpFile, callable $log): void
{
    if (!file_exists($dumpFile)) {
        throw new \RuntimeException('Database dump not found: ' . basename($dumpFile));
    }
    if (!commandExists('gunzip')) {
        throw new \RuntimeException('gunzip command not available');
    }
    if (!commandExists('mysql')) {
        throw new \RuntimeException('mysql command not available');
    }

    $config = parseDatabaseConfig($projectDir);
    if ($config === null) {
        throw new \RuntimeException(
            'Could not parse DATABASE_URL from .env.local / .env. '
          . 'Check that DATABASE_URL=mysql://user:pass@host:port/dbname is set.'
        );
    }

    $cmd = sprintf(
        'gunzip < %s | mysql --host=%s --port=%s --user=%s %s 2>&1',
        escapeshellarg($dumpFile),
        escapeshellarg($config['host']),
        escapeshellarg((string) $config['port']),
        escapeshellarg($config['user']),
        escapeshellarg($config['dbname'])
    );

    $log('info', 'database', 'Running mysql import via MYSQL_PWD');

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = [
        'PATH'      => (string) ($_SERVER['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
        'MYSQL_PWD' => $config['password'],
        'HOME'      => (string) ($_SERVER['HOME'] ?? '/tmp'),
        'LC_ALL'    => 'C',
    ];

    $proc = @proc_open('/bin/sh -c ' . escapeshellarg($cmd), $descriptors, $pipes, null, $env);
    if (!is_resource($proc)) {
        throw new \RuntimeException('proc_open failed');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exit = proc_close($proc);

    $output = $stdout . $stderr;

    if ($exit !== 0) {
        throw new \RuntimeException('mysql import exit ' . $exit . ': ' . substr($output, -1000));
    }

    $log('info', 'database', 'Database imported successfully');
}

/**
 * Parses DATABASE_URL from .env.local / .env. Returns the components or null.
 *
 * @return array{host:string,port:int,user:string,password:string,dbname:string}|null
 */
function parseDatabaseConfig(string $projectDir): ?array
{
    foreach (['.env.local', '.env'] as $file) {
        $path = $projectDir . '/' . $file;
        if (!file_exists($path)) {
            continue;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) !== 'DATABASE_URL') {
                continue;
            }
            $v = trim($v, " \t\"'");
            $parsed = @parse_url($v);
            if (!is_array($parsed) || !isset($parsed['host'])) {
                continue;
            }
            return [
                'host'     => (string) $parsed['host'],
                'port'     => (int) ($parsed['port'] ?? 3306),
                'user'     => (string) ($parsed['user'] ?? 'root'),
                'password' => (string) urldecode($parsed['pass'] ?? ''),
                'dbname'   => (string) ltrim((string) ($parsed['path'] ?? ''), '/'),
            ];
        }
    }
    return null;
}

function clearSymfonyCache(string $projectDir, callable $log): void
{
    foreach (['prod', 'dev'] as $env) {
        $dir = $projectDir . '/var/cache/' . $env;
        if (is_dir($dir)) {
            if (removeDirectory($dir)) {
                $log('info', 'cache_clear', "Removed var/cache/{$env}/");
            } else {
                $log('warning', 'cache_clear', "Could not fully remove var/cache/{$env}/");
            }
        }
    }
}

function enableMaintenance(string $projectDir, callable $log): void
{
    $marker = $projectDir . '/var/maintenance.html';
    $dir = dirname($marker);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Maintenance</title></head>'
          . '<body style="font-family:sans-serif;text-align:center;padding:3rem;">'
          . '<h1>Site is being restored</h1>'
          . '<p>Please come back in a few minutes.</p>'
          . '</body></html>';
    @file_put_contents($marker, $html);
    $log('info', 'maintenance_on', 'Maintenance marker created');
}

function disableMaintenance(string $projectDir, callable $log): void
{
    $marker = $projectDir . '/var/maintenance.html';
    if (file_exists($marker)) {
        @unlink($marker);
        $log('info', 'maintenance_off', 'Maintenance marker removed');
    }
}

function deleteBackup(string $projectDir, string $name): array
{
    if (!validateBackupName($name)) {
        return ['success' => false, 'error' => 'Invalid backup name'];
    }
    $dir = $projectDir . BACKUP_DIR . '/' . $name;
    if (!is_dir($dir)) {
        return ['success' => false, 'error' => 'Backup not found'];
    }
    if (removeDirectory($dir)) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Could not remove all files'];
}


// ============================================================================
//   STATUS / LOG / DIAGNOSTICS
// ============================================================================

function getRecoveryStatus(string $projectDir): array
{
    $statusFile = $projectDir . STORAGE_DIR_BASE . '/status.json';
    if (!file_exists($statusFile)) {
        return ['success' => true, 'idle' => true];
    }
    $data = json_decode((string) @file_get_contents($statusFile), true);
    return ['success' => true, 'idle' => false, 'status' => is_array($data) ? $data : null];
}

function readActiveLog(string $projectDir, int $offset): array
{
    $logFile = $projectDir . STORAGE_DIR_BASE . '/recovery.log';
    if (!file_exists($logFile)) {
        return ['success' => true, 'entries' => '', 'offset' => 0];
    }
    $size = @filesize($logFile) ?: 0;
    if ($offset < 0 || $offset > $size) {
        $offset = 0;
    }
    $fh = @fopen($logFile, 'rb');
    if (!$fh) {
        return ['success' => false, 'error' => 'Could not open log'];
    }
    @fseek($fh, $offset);
    $chunk = (string) @fread($fh, MAX_LOG_SIZE);
    fclose($fh);
    return [
        'success' => true,
        'entries' => $chunk,
        'offset'  => $offset + strlen($chunk),
    ];
}

function ensureLogFile(string $projectDir): string
{
    $dir = $projectDir . STORAGE_DIR_BASE;
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir . '/recovery.log';
}

function writeStatus(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getDiagnostics(string $projectDir): array
{
    return [
        'success'       => true,
        'project_dir'   => $projectDir,
        'php_version'   => PHP_VERSION,
        'php_sapi'      => PHP_SAPI,
        'php_binary'    => PHP_BINARY,
        'open_basedir'  => (string) ini_get('open_basedir'),
        'disable_functions' => (string) ini_get('disable_functions'),
        'exec_enabled'  => function_exists('exec') && !inDisableFunctions('exec'),
        'proc_open_enabled' => function_exists('proc_open') && !inDisableFunctions('proc_open'),
        'tar_available' => commandExists('tar'),
        'mysql_available' => commandExists('mysql'),
        'gunzip_available' => commandExists('gunzip'),
        'backup_dir_exists' => is_dir($projectDir . BACKUP_DIR),
        'env_local_exists' => file_exists($projectDir . '/.env.local'),
        'env_exists' => file_exists($projectDir . '/.env'),
    ];
}


// ============================================================================
//   UTILITIES
// ============================================================================

function validateBackupName(string $name): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $name);
}

function commandExists(string $cmd): bool
{
    if (!function_exists('exec') || inDisableFunctions('exec')) {
        return false;
    }
    $out = [];
    $exit = 1;
    @exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null', $out, $exit);
    return $exit === 0 && !empty($out);
}

function inDisableFunctions(string $func): bool
{
    $list = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return in_array($func, $list, true);
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = $bytes / 1024;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return sprintf('%.1f %s', $v, $units[$i]);
}

function removeDirectory(string $path): bool
{
    if (!is_dir($path)) {
        return true;
    }
    // Use rm -rf via exec — much faster than recursive iteration for large vendor/
    if (function_exists('exec') && !inDisableFunctions('exec')) {
        $out = [];
        $exit = 1;
        @exec('rm -rf ' . escapeshellarg($path) . ' 2>&1', $out, $exit);
        if ($exit === 0) {
            return true;
        }
    }
    // Pure-PHP fallback
    $items = @scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $sub = $path . '/' . $item;
        if (is_dir($sub) && !is_link($sub)) {
            removeDirectory($sub);
        } else {
            @unlink($sub);
        }
    }
    return @rmdir($path);
}

function sendJson(array $data): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}


// ============================================================================
//   HTML RENDER
// ============================================================================

function renderPanel(string $projectDir): void
{
    // Token must exist before we render — otherwise nobody can authenticate
    // and we'd dead-end the user.
    $tokenExists = readToken($projectDir) !== null;

    // We DELIBERATELY don't authenticate the HTML render itself — the page
    // is essentially static and all dangerous endpoints are protected
    // server-side. Showing the login banner is friendlier than a 401 page.

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $self = htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '/_updater-recovery.php', ENT_QUOTES);
    $needsToken = $tokenExists ? 'false' : 'true';

    ?>
<!DOCTYPE html>
<html lang="en" data-color-scheme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Standalone Recovery Panel</title>
<style>
:root {
    --bg: #1a1c20;
    --card: #2a2e35;
    --border: #444;
    --text: #e4e6eb;
    --muted: #b0b3b8;
    --brand: #f47c00;
    --danger: #c04050;
    --success: #2d8045;
    --log-bg: #0f1115;
    --log-fg: #a8ff78;
}
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 0; }
.wrap { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }
header { background: var(--card); border: 1px solid var(--border); border-radius: 6px; padding: 1.2rem 1.5rem; margin-bottom: 1rem; }
h1 { margin: 0 0 .3rem; font-size: 1.4rem; }
.sub { color: var(--muted); font-size: .9rem; }
.section { background: var(--card); border: 1px solid var(--border); border-radius: 6px; padding: 1.2rem 1.5rem; margin-bottom: 1rem; }
.section h2 { margin: 0 0 .8rem; font-size: 1.05rem; }
.btn { display: inline-block; padding: .55rem 1.1rem; background: var(--brand); color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: .9rem; margin-right: .4rem; text-decoration: none; }
.btn:hover { opacity: .9; }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn-secondary { background: #4a4f57; }
.btn-danger { background: var(--danger); }
.notice-warn { background: rgba(248, 195, 64, .12); border-left: 3px solid #c89a3a; padding: .8rem 1rem; margin: .6rem 0; font-size: .85rem; line-height: 1.5; border-radius: 3px; color: #ffe6a8; }
.notice-err { background: rgba(192, 64, 80, .15); border-left: 3px solid var(--danger); padding: .8rem 1rem; margin: .6rem 0; font-size: .85rem; line-height: 1.5; border-radius: 3px; color: #f5b6bc; }
.notice-ok { background: rgba(45, 128, 69, .15); border-left: 3px solid var(--success); padding: .8rem 1rem; margin: .6rem 0; font-size: .85rem; line-height: 1.5; border-radius: 3px; color: #b6e5c1; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid var(--border); font-size: .85rem; }
th { color: var(--muted); font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
code { font-family: monospace; background: rgba(255,255,255,.05); padding: .1rem .35rem; border-radius: 3px; font-size: .85em; }
.live-log { background: var(--log-bg); color: var(--log-fg); font-family: monospace; font-size: .75rem; line-height: 1.4; padding: .8rem 1rem; border-radius: 4px; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-word; }
.modal { position: fixed; inset: 0; background: rgba(0,0,0,.8); display: none; align-items: center; justify-content: center; z-index: 10000; padding: 1rem; }
.modal.open { display: flex; }
.modal-box { background: var(--card); border: 1px solid var(--border); border-radius: 6px; padding: 1.5rem; max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; color: var(--text); }
.modal-box h2 { margin: 0 0 .8rem; }
.modal-actions { display: flex; gap: .5rem; justify-content: flex-end; margin-top: 1.2rem; padding-top: .8rem; border-top: 1px solid var(--border); }
.comp-row { display: flex; align-items: flex-start; gap: .8rem; padding: .5rem .8rem; border: 1px solid var(--border); border-radius: 4px; margin-bottom: .3rem; cursor: pointer; }
.comp-row.disabled { opacity: .5; cursor: not-allowed; }
.comp-row input { margin-top: .2rem; }
.comp-row .lbl { font-weight: 600; font-size: .9rem; color: var(--text); }
.comp-row .desc { font-size: .8rem; color: var(--muted); margin-top: .15rem; }
.diag-grid { display: grid; grid-template-columns: 1fr 2fr; gap: .3rem .8rem; font-size: .8rem; }
.diag-grid .k { color: var(--muted); }
.diag-grid .v { font-family: monospace; word-break: break-all; }
.badge-yes { color: #b6e5c1; }
.badge-no { color: #f5b6bc; }
</style>
</head>
<body>
<div class="wrap">

<header>
    <h1>🛠️ Standalone Recovery Panel</h1>
    <div class="sub">
        Guardian &middot; works even when Contao or Symfony are broken.
        Lives at <code><?= $self ?></code> &middot; uses no framework, no autoloader.
    </div>
</header>

<?php if (!$tokenExists): ?>
<div class="section">
    <div class="notice-err">
        <strong>⚠️ No access token configured.</strong><br>
        Set <code>VTINNOVATIONS_GUARDIAN_TOKEN=...</code> in your <code>.env.local</code>,
        or create the file <code>var/updater/access.token</code> with a 48-character hex token.
        Until then, this panel is locked.
    </div>
</div>
<?php endif; ?>

<div class="section" id="diagSection">
    <h2>System diagnostics</h2>
    <div id="diagBody"><em>Loading…</em></div>
</div>

<div class="section">
    <h2>Available backups</h2>
    <div id="statusBox"></div>
    <div id="backupList"><em>Loading…</em></div>
</div>

<div class="section">
    <h2>Live log</h2>
    <div id="liveLog" class="live-log">(no recovery operation in progress)</div>
</div>

<!-- Restore modal -->
<div class="modal" id="restoreModal">
    <div class="modal-box">
        <h2>Restore from backup</h2>
        <p id="restoreSubtitle" style="color: var(--muted); font-size: .85rem; margin: .3rem 0;"></p>

        <div class="notice-warn">
            <strong>⚠️ Important:</strong> Restore overwrites the selected components on disk and in the database.
            There is no automatic snapshot of the CURRENT state — make a manual backup first if you might want
            to come back.
        </div>

        <h3 style="font-size: .85rem; color: var(--muted); margin-top: 1rem;">Components to restore</h3>
        <div id="componentList"><em>Loading…</em></div>

        <label style="display: flex; align-items: center; gap: .5rem; margin-top: .8rem;">
            <input type="checkbox" id="optMaintenance" checked>
            <span style="font-size: .85rem;">Enable maintenance page during restore</span>
        </label>

        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeRestoreModal()">Cancel</button>
            <button class="btn btn-danger" id="startRestoreBtn" onclick="startRestore()">↩️ Restore now</button>
        </div>
    </div>
</div>

</div>

<script>
const SELF = <?= json_encode($self) ?>;
const NEEDS_TOKEN = <?= $needsToken ?>;
let currentRestoreName = '';
let logOffset = 0;
let logPollTimer = null;

function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
}

function api(action, options) {
    options = options || {};
    const url = SELF + '?action=' + encodeURIComponent(action) + (options.query || '');
    const init = {
        method: options.method || 'GET',
        credentials: 'include',
        headers: { 'Accept': 'application/json' },
    };
    if (options.body) {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(options.body);
    }
    return fetch(url, init).then(r => {
        if (r.status === 401) {
            // Trigger basic-auth dialog
            window.location.reload();
            throw new Error('Authentication required');
        }
        return r.json();
    });
}

function loadDiagnostics() {
    api('diagnostics').then(d => {
        if (!d.success) {
            document.getElementById('diagBody').innerHTML =
                '<div class="notice-err">Diagnostics failed: ' + esc(d.error) + '</div>';
            return;
        }
        const tools = [
            ['PHP', d.php_version + ' (' + d.php_sapi + ')'],
            ['exec()', d.exec_enabled ? '<span class="badge-yes">available</span>' : '<span class="badge-no">DISABLED</span>'],
            ['proc_open()', d.proc_open_enabled ? '<span class="badge-yes">available</span>' : '<span class="badge-no">DISABLED</span>'],
            ['tar', d.tar_available ? '<span class="badge-yes">found</span>' : '<span class="badge-no">missing</span>'],
            ['mysql', d.mysql_available ? '<span class="badge-yes">found</span>' : '<span class="badge-no">missing</span>'],
            ['gunzip', d.gunzip_available ? '<span class="badge-yes">found</span>' : '<span class="badge-no">missing</span>'],
            ['Backup dir', d.backup_dir_exists ? '<span class="badge-yes">exists</span>' : '<span class="badge-no">missing!</span>'],
            ['.env.local', d.env_local_exists ? '<span class="badge-yes">found</span>' : '<span class="badge-no">missing</span>'],
            ['open_basedir', d.open_basedir ? esc(d.open_basedir) : '<em>none</em>'],
        ];
        let html = '<div class="diag-grid">';
        for (const [k, v] of tools) {
            html += '<div class="k">' + esc(k) + '</div><div class="v">' + v + '</div>';
        }
        html += '</div>';
        document.getElementById('diagBody').innerHTML = html;

        // If essential tools missing, show prominent warning
        const missing = [];
        if (!d.exec_enabled) missing.push('exec()');
        if (!d.tar_available) missing.push('tar');
        if (!d.mysql_available) missing.push('mysql');
        if (!d.gunzip_available) missing.push('gunzip');
        if (missing.length > 0) {
            document.getElementById('diagBody').insertAdjacentHTML('afterbegin',
                '<div class="notice-err">Cannot perform restore: required tools/functions missing: '
              + missing.map(esc).join(', ') + '</div>');
        }
    });
}

function loadBackups() {
    api('list').then(d => {
        if (!d.success) {
            document.getElementById('backupList').innerHTML =
                '<div class="notice-err">Failed: ' + esc(d.error) + '</div>';
            return;
        }
        if (!d.backups || d.backups.length === 0) {
            document.getElementById('backupList').innerHTML =
                '<div class="notice-warn">No backups found in <code>var/updater/backup/</code>.</div>';
            return;
        }
        let html = '<table><thead><tr><th>Backup</th><th>Created</th><th>Contao</th><th>Size</th><th>Components</th><th></th></tr></thead><tbody>';
        for (const b of d.backups) {
            const components = (b.components || []).join(', ');
            const tag = b.schedule_type === 'pre-update'
                ? ' <span style="color:#c89a3a;font-size:.7rem;">[pre-update]</span>' : '';
            html += '<tr>'
                  + '<td><code>' + esc(b.name) + '</code>' + tag + '</td>'
                  + '<td>' + esc(b.created_at) + '</td>'
                  + '<td>' + esc(b.contao_version) + '</td>'
                  + '<td>' + esc(b.total_size) + '</td>'
                  + '<td style="color:var(--muted);font-size:.75rem;">' + esc(components) + '</td>'
                  + '<td style="text-align:right;"><button class="btn" onclick="openRestoreModal(\'' + esc(b.name) + '\')">↩️ Restore</button></td>'
                  + '</tr>';
        }
        html += '</tbody></table>';
        document.getElementById('backupList').innerHTML = html;
    });
}

function openRestoreModal(name) {
    currentRestoreName = name;
    document.getElementById('restoreSubtitle').textContent = 'From backup: ' + name;
    document.getElementById('componentList').innerHTML = '<em>Loading…</em>';

    // Reset the action button — a previous restore in this page session
    // repurposes it to "Done — close". Without this reset, every subsequent
    // open shows the old state and a fresh restore can't be started.
    const btn = document.getElementById('startRestoreBtn');
    btn.textContent = '↩️ Restore now';
    btn.onclick = startRestore;
    btn.disabled = false;

    // Clear any leftover live log from a prior run.
    stopLogPolling();
    logOffset = 0;
    const ll = document.getElementById('liveLog');
    if (ll) ll.textContent = '';

    document.getElementById('restoreModal').classList.add('open');

    api('manifest', { query: '&name=' + encodeURIComponent(name) }).then(d => {
        if (!d.success) {
            document.getElementById('componentList').innerHTML =
                '<div class="notice-err">' + esc(d.error) + '</div>';
            return;
        }
        let html = '';
        for (const c of (d.components || [])) {
            const dis = c.available ? '' : ' disabled';
            const dcls = c.available ? '' : ' disabled';
            const sizeStr = c.size ? ' &middot; ' + esc(c.size) : '';
            html += '<label class="comp-row' + dcls + '">'
                  + '<input type="checkbox" class="comp-cb" data-key="' + esc(c.key) + '"' + dis + '>'
                  + '<div>'
                  + '<div class="lbl">' + esc(c.label) + '</div>'
                  + '<div class="desc">' + (c.available ? 'Present in backup' + sizeStr : 'Not in this backup') + '</div>'
                  + '</div>'
                  + '</label>';
        }
        document.getElementById('componentList').innerHTML = html;
    });
}

function closeRestoreModal() {
    document.getElementById('restoreModal').classList.remove('open');
    currentRestoreName = '';
}

function startRestore() {
    const components = {};
    document.querySelectorAll('.comp-cb:checked').forEach(cb => {
        components[cb.dataset.key] = true;
    });
    if (Object.keys(components).length === 0) {
        alert('Select at least one component to restore.');
        return;
    }
    if (!confirm('Restore ' + Object.keys(components).join(', ') + ' from ' + currentRestoreName + '?\n\nThis OVERWRITES the current state of those components.')) {
        return;
    }

    const body = {
        name: currentRestoreName,
        components: components,
        maintenance: document.getElementById('optMaintenance').checked,
    };

    document.getElementById('startRestoreBtn').disabled = true;
    document.getElementById('startRestoreBtn').textContent = 'Restoring…';
    document.getElementById('liveLog').textContent = '';
    logOffset = 0;
    startLogPolling();

    api('restore', { method: 'POST', body: body }).then(d => {
        stopLogPolling();
        // One final log fetch to capture trailing lines
        api('log', { query: '&offset=' + logOffset }).then(l => {
            if (l.success && l.entries) {
                appendLog(l.entries);
                logOffset = l.offset;
            }
            if (d.success) {
                document.getElementById('startRestoreBtn').textContent = '✅ Done — close';
                document.getElementById('startRestoreBtn').onclick = closeRestoreModal;
                document.getElementById('startRestoreBtn').disabled = false;
            } else {
                alert('Restore failed: ' + (d.error || 'unknown'));
                document.getElementById('startRestoreBtn').textContent = '❌ Failed — close';
                document.getElementById('startRestoreBtn').onclick = closeRestoreModal;
                document.getElementById('startRestoreBtn').disabled = false;
            }
        });
    }).catch(e => {
        stopLogPolling();
        alert('Request failed: ' + (e.message || e));
        document.getElementById('startRestoreBtn').textContent = '↩️ Restore now';
        document.getElementById('startRestoreBtn').disabled = false;
    });
}

function appendLog(text) {
    const el = document.getElementById('liveLog');
    el.textContent += text;
    el.scrollTop = el.scrollHeight;
}

function startLogPolling() {
    if (logPollTimer) clearInterval(logPollTimer);
    logPollTimer = setInterval(() => {
        api('log', { query: '&offset=' + logOffset }).then(l => {
            if (l.success && l.entries) {
                appendLog(l.entries);
                logOffset = l.offset;
            }
        });
    }, 1500);
}

function stopLogPolling() {
    if (logPollTimer) clearInterval(logPollTimer);
    logPollTimer = null;
}

// Boot
if (!NEEDS_TOKEN) {
    loadDiagnostics();
    loadBackups();
}
</script>
</body>
</html>
<?php
}
