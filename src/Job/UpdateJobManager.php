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

namespace Vtinnovations\Guardian\Job;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Vtinnovations\Guardian\Service\RuntimeConfig;
use Vtinnovations\Guardian\Service\SystemLogger;

/**
 * Creates, persists, starts and reads back update jobs.
 *
 * Storage: var/updater/job.json — only ONE active job at a time.
 * If a previous job exists with status "succeeded" / "failed" / "cancelled",
 * it gets archived to var/updater/jobs/<id>.json so the user has history.
 *
 * Worker startup uses Symfony Process with start() (non-blocking) and
 * disableOutput() so the parent web request can return immediately while
 * the child PHP process keeps running detached.
 */
class UpdateJobManager
{
    private string $jobFile;
    private string $archiveDir;
    private string $consolePath;

    public function __construct(
        private readonly string $projectDir,
        private readonly JobLog $log,
        private readonly SystemLogger $sysLog,
        private readonly RuntimeConfig $runtimeConfig,
    ) {
        $this->jobFile     = $projectDir . '/var/updater/job.json';
        $this->archiveDir  = $projectDir . '/var/updater/jobs';
        $this->consolePath = $projectDir . '/vendor/bin/contao-console';
    }

    /**
     * Loads the current job from disk, or null if none.
     */
    /**
     * Heuristic stale-job detection. A job is considered stale when:
     *   - It's still "queued" after more than 2 minutes (worker never started)
     *   - It's "running" but the recorded worker PID no longer exists
     *   - It's "running" with no PID and hasn't updated its file in 30+ minutes
     *
     * This is conservative — we don't want to flag a genuinely-running slow job
     * as stale. But it's important so users can recover from worker spawn failures
     * (e.g. proc_open errors, host killing the PHP-FPM process before exec()).
     */
    public function isJobStale(UpdateJob $job): bool
    {
        if ($job->isFinished()) {
            return false;
        }

        $now = time();

        // Stuck in "queued" — the worker never started
        if ($job->status === UpdateJob::STATUS_QUEUED) {
            $createdTs = $job->createdAt ? strtotime($job->createdAt) : 0;
            return $createdTs > 0 && ($now - $createdTs) > 120; // 2 min
        }

        // Stuck in "running"
        if ($job->status === UpdateJob::STATUS_RUNNING) {
            // If we have a PID, check whether the process is still alive
            if ($job->workerPid !== null && $job->workerPid > 0) {
                if (\function_exists('posix_kill')) {
                    // posix_kill with signal 0 = check if process exists, no real signal
                    $alive = @\posix_kill($job->workerPid, 0);
                    if (!$alive) {
                        return true;
                    }
                }
            }

            // Fallback: stale if the job.json file hasn't been touched in 30 min
            $mtime = (int) @\filemtime($this->jobFile);
            return $mtime > 0 && ($now - $mtime) > 1800;
        }

        return false;
    }

    /**
     * Returns a human-readable explanation of why a job is stale (or '' if not stale).
     */
    public function getStaleReason(UpdateJob $job): string
    {
        if ($job->isFinished()) {
            return '';
        }

        $now = time();

        if ($job->status === UpdateJob::STATUS_QUEUED) {
            $createdTs = $job->createdAt ? strtotime($job->createdAt) : 0;
            $age = $createdTs > 0 ? ($now - $createdTs) : 0;
            if ($age > 120) {
                return sprintf(
                    'Job has been queued for %d minutes without the worker starting. '
                    . 'The CLI worker likely failed to spawn (check exec() / proc_open() permissions).',
                    (int) round($age / 60)
                );
            }
            return '';
        }

        if ($job->status === UpdateJob::STATUS_RUNNING) {
            if ($job->workerPid !== null && \function_exists('posix_kill')) {
                $alive = @\posix_kill($job->workerPid, 0);
                if (!$alive) {
                    return sprintf(
                        'Worker process (PID %d) is no longer running. '
                        . 'It probably crashed or was killed by the OS.',
                        $job->workerPid
                    );
                }
            }
            $mtime = (int) @\filemtime($this->jobFile);
            $idle  = $now - $mtime;
            if ($idle > 1800) {
                return sprintf(
                    'Job has been idle for %d minutes without status updates. '
                    . 'The worker is probably stuck or has died silently.',
                    (int) round($idle / 60)
                );
            }
        }

        return '';
    }

    public function getCurrentJob(): ?UpdateJob
    {
        if (!file_exists($this->jobFile)) {
            return null;
        }

        $content = @file_get_contents($this->jobFile);
        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return null;
        }

        return UpdateJob::fromArray($data);
    }

    public function saveJob(UpdateJob $job): void
    {
        $dir = \dirname($this->jobFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(
            $this->jobFile,
            json_encode($job->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE),
            \LOCK_EX
        );
    }

    /**
     * Archives a finished job and clears the active slot.
     */
    public function archiveJob(UpdateJob $job): void
    {
        if (!$job->isFinished()) {
            return;
        }

        if (!is_dir($this->archiveDir)) {
            @mkdir($this->archiveDir, 0750, true);
        }

        $archivePath = $this->archiveDir . '/' . $job->id . '.json';
        @file_put_contents(
            $archivePath,
            json_encode($job->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE)
        );

        // Clear active slot
        if (file_exists($this->jobFile)) {
            @unlink($this->jobFile);
        }
    }

    /**
     * Lists archived jobs, newest first.
     *
     * @return array<int, array>
     */
    public function listArchive(int $limit = 20): array
    {
        if (!is_dir($this->archiveDir)) {
            return [];
        }

        $files = glob($this->archiveDir . '/*.json') ?: [];
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $files = \array_slice($files, 0, $limit);

        $result = [];
        foreach ($files as $f) {
            $data = json_decode((string) @file_get_contents($f), true);
            if (\is_array($data)) {
                $result[] = $data;
            }
        }
        return $result;
    }

    /**
     * Loads a single archived job by ID. Returns null if not found or malformed.
     * Used by the rollback flow to find a failed job's pre-snapshot name.
     */
    public function getArchivedJob(string $jobId): ?UpdateJob
    {
        // Strict format check on the job ID — IDs are generated by
        // UpdateJob::generateId() in the format "YYYYMMDD-HHMMSS-xxxxxxx",
        // so anything else is a path-traversal attempt or a bad request.
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{7,}$/', $jobId)) {
            return null;
        }

        $path = $this->archiveDir . '/' . $jobId . '.json';
        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        if (!\is_array($data)) {
            return null;
        }

        return UpdateJob::fromArray($data);
    }

    /**
     * Creates a new job and saves it. Returns the job (status: queued).
     * Refuses if another job is currently running.
     */
    public function createJob(UpdateJob $job): UpdateJob
    {
        $current = $this->getCurrentJob();
        if ($current !== null && !$current->isFinished()) {
            $hint = '';
            if ($this->isJobStale($current)) {
                $hint = ' (This job appears STALE — ' . $this->getStaleReason($current)
                      . ' You can clear it via the recovery panel.)';
            }
            throw new \Vtinnovations\Guardian\Job\Exception\JobBlockedException(
                $current,
                sprintf('Another job is currently %s (id: %s).%s', $current->status, $current->id, $hint)
            );
        }

        // Archive any old finished job from the active slot
        if ($current !== null) {
            $this->archiveJob($current);
        }

        $this->log->reset();
        $this->log->info('manager', "Job {$job->id} queued (type: {$job->type})");
        $this->sysLog->info(sprintf('Update job queued: %s (type: %s)', $job->id, $job->type));
        $this->saveJob($job);

        return $job;
    }

    /**
     * Starts the CLI worker for an existing queued job.
     * The parent process returns immediately; the child runs detached.
     */
    public function startWorker(UpdateJob $job): void
    {
        if ($job->status !== UpdateJob::STATUS_QUEUED) {
            throw new \RuntimeException("Job {$job->id} is not in queued state");
        }

        // Verify console binary exists
        if (!file_exists($this->consolePath)) {
            throw new \RuntimeException("contao-console not found at {$this->consolePath}");
        }

        $php = $this->findPhpCliBinary();
        if ($php === null) {
            throw new \RuntimeException(
                'Could not locate a PHP CLI binary. Please open the Updater backend, '
                . 'go to "PHP CLI settings" and set the absolute path to your PHP binary '
                . '(e.g. /opt/plesk/php/8.4/bin/php on Plesk). '
                . 'You can also set VTINNOVATIONS_GUARDIAN_PHP_BINARY=/path/to/php in .env.local.'
            );
        }

        // ── Pre-flight diagnostics ──────────────────────────────────────────
        // Log everything so we can debug worker-spawn failures from the job log.
        $this->log->info('manager', sprintf('PHP CLI binary: %s', $php));
        $this->log->info('manager', sprintf('Console binary: %s', $this->consolePath));
        $this->log->info('manager', sprintf('PHP_OS_FAMILY: %s', \PHP_OS_FAMILY));

        $disabledFns = array_map('trim', explode(',', (string) \ini_get('disable_functions')));
        $execAvail   = \function_exists('exec')        && !\in_array('exec', $disabledFns, true);
        $popenAvail  = \function_exists('popen')       && !\in_array('popen', $disabledFns, true);
        $procOpen    = \function_exists('proc_open')   && !\in_array('proc_open', $disabledFns, true);
        $shellExec   = \function_exists('shell_exec')  && !\in_array('shell_exec', $disabledFns, true);

        $this->log->info('manager', sprintf(
            'exec=%s, shell_exec=%s, proc_open=%s, popen=%s',
            $execAvail ? 'yes' : 'NO',
            $shellExec ? 'yes' : 'NO',
            $procOpen  ? 'yes' : 'NO',
            $popenAvail ? 'yes' : 'NO'
        ));

        // Verify the PHP CLI can actually run the console (catch most show-stoppers
        // before the actual spawn): does `php contao-console list` work synchronously?
        if ($execAvail) {
            $testCmd = sprintf('%s %s --version 2>&1', escapeshellarg($php), escapeshellarg($this->consolePath));
            @exec($testCmd, $testOut, $testExit);
            $this->log->info('manager', sprintf('Pre-flight test: %s', $testCmd));
            $this->log->info('manager', sprintf('Pre-flight exit code: %d', $testExit));
            $this->log->info('manager', 'Pre-flight output: ' . implode(' | ', \array_slice($testOut, 0, 5)));

            if ($testExit !== 0) {
                throw new \RuntimeException(sprintf(
                    'PHP CLI cannot run contao-console (exit %d). Output: %s. '
                    . 'This typically means PHP CLI is using a different php.ini or is missing extensions. '
                    . 'Try running the command manually on the server: %s',
                    $testExit,
                    implode(' | ', $testOut),
                    $testCmd
                ));
            }
        } else {
            $this->log->warning('manager', 'exec() not available — cannot run pre-flight check. Worker spawn likely to fail.');
        }

        // ── Build the spawn command ─────────────────────────────────────────
        $isWindows = stripos(\PHP_OS_FAMILY, 'win') === 0;

        // POSIX: redirect everything to /dev/null and background with `&`.
        // Optional nohup so the worker survives parent process death.
        if ($isWindows) {
            $cmd = sprintf(
                'start /B "" %s %s guardian:run-job %s > NUL 2>&1',
                escapeshellarg($php),
                escapeshellarg($this->consolePath),
                escapeshellarg($job->id)
            );
        } else {
            $useNohup = $this->isCommandAvailable('nohup');
            $cmd = sprintf(
                '%s%s %s guardian:run-job %s < /dev/null > /dev/null 2>&1 &',
                $useNohup ? 'nohup ' : '',
                escapeshellarg($php),
                escapeshellarg($this->consolePath),
                escapeshellarg($job->id)
            );
            if (!$useNohup) {
                $this->log->info('manager', 'nohup unavailable; using plain & detach');
            }
        }

        $this->log->info('manager', "Spawn command: {$cmd}");

        // ── Spawn ──────────────────────────────────────────────────────────
        // Try Symfony Process first. Fall back to raw exec() if that fails.
        $spawned = false;
        $errors  = [];

        try {
            $process = Process::fromShellCommandline($cmd, $this->projectDir);
            $process->setTimeout(null);
            $process->start();
            $spawned = true;
            $this->log->info('manager', 'Spawned via Symfony Process');
        } catch (\Throwable $e) {
            $errors[] = 'Symfony Process: ' . $e->getMessage();
            $this->log->warning('manager', 'Symfony Process failed: ' . $e->getMessage() . ' — trying exec()');
        }

        if (!$spawned && $execAvail) {
            @exec($cmd, $execOut, $execExit);
            // Exec backgrounded with & returns 0 immediately even if the bg process fails later
            $this->log->info('manager', sprintf('exec() returned exit=%d', $execExit));
            if ($execExit === 0) {
                $spawned = true;
                $this->log->info('manager', 'Spawned via exec()');
            } else {
                $errors[] = 'exec exit ' . $execExit . ': ' . implode(' | ', $execOut);
            }
        }

        if (!$spawned && $shellExec) {
            $result = @shell_exec($cmd);
            $this->log->info('manager', 'shell_exec result: ' . substr((string) $result, 0, 200));
            $spawned = true; // shell_exec doesn't expose exit code; assume success
            $this->log->info('manager', 'Spawned via shell_exec()');
        }

        if (!$spawned) {
            throw new \RuntimeException(
                'Could not spawn worker process. All methods failed:'
                . "\n - " . implode("\n - ", $errors)
                . "\nYour hosting likely disables exec/proc_open/shell_exec. "
                . 'In that case, set up a real cron entry to run `vendor/bin/contao-console contao:cron` '
                . 'every minute — the cron will pick up jobs from var/updater/job.json and run them.'
            );
        }

        // Give the OS a moment to actually fork the process before we report success.
        $this->log->info('manager', 'Spawn submitted. Waiting up to 8 seconds for worker to update job status...');

        // Health check: poll the job file for up to ~8 seconds to confirm the worker
        // actually started. If it never transitions out of "queued", the spawn silently
        // failed (very common on hosts with restricted exec/proc_open behaviour).
        $startedSuccessfully = false;
        for ($i = 0; $i < 16; ++$i) {
            usleep(500_000); // 500ms
            $reloaded = $this->getCurrentJob();
            if ($reloaded === null) {
                // Job file disappeared — worker probably finished already (or was archived)
                $startedSuccessfully = true;
                break;
            }
            if ($reloaded->status !== UpdateJob::STATUS_QUEUED) {
                // Worker has taken over and changed status — good
                $startedSuccessfully = true;
                $this->log->info('manager', sprintf('Worker confirmed running (status: %s) after %dms', $reloaded->status, ($i + 1) * 500));
                break;
            }
        }

        if (!$startedSuccessfully) {
            // Worker didn't pick up the job. Mark it as failed immediately so the
            // user doesn't have to wait 2 minutes for the stale-detection to kick in.
            $job->status     = UpdateJob::STATUS_FAILED;
            $job->finishedAt = date('c');
            $job->error      = 'Worker process was spawned but never updated the job status. '
                             . 'The CLI worker may have crashed silently, or your hosting restricts exec/proc_open/shell_exec. '
                             . 'Check the PHP CLI binary in "PHP CLI settings" and try again.';
            $this->saveJob($job);
            $this->log->error('manager', $job->error);
            $this->sysLog->error(sprintf('Worker spawn for job %s failed silently — marked as failed', $job->id));

            // Archive the failed job so the user can immediately try again
            $this->archiveJob($job);

            throw new \Vtinnovations\Guardian\Job\Exception\WorkerSpawnException($job, $job->error);
        }

        $this->log->info('manager', "Worker spawned for job {$job->id}");
        $this->sysLog->info(sprintf('Update worker started for job %s', $job->id));
    }

    /**
     * Checks if a shell command is on PATH. Used by startWorker() to decide
     * whether nohup is available.
     */
    private function isCommandAvailable(string $cmd): bool
    {
        if (!\function_exists('shell_exec')) {
            return false;
        }
        $output = @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
        return $output !== null && trim((string) $output) !== '';
    }

    /**
     * Locates a PHP CLI binary using multiple fallback strategies. Returns
     * an absolute path or null if no usable binary was found.
     *
     * Why this is complicated:
     *   - PhpExecutableFinder works in most cases but sometimes returns false
     *     in PHP-FPM contexts where there's no clear CLI sibling
     *   - PHP_BINARY in FPM context points to php-fpm, NOT the CLI binary —
     *     we must verify it's actually CLI before using it
     *   - Plesk installs PHP under /opt/plesk/php/<version>/bin/php which is
     *     a non-standard location
     */
    private function findPhpCliBinary(): ?string
    {
        // 1. Explicitly configured in runtime.json (set via backend UI — Contao Manager style)
        $configured = $this->runtimeConfig->getPhpBinary();
        if ($configured !== null && $this->isUsableCliBinary($configured)) {
            return $configured;
        }

        // 2. Environment variable override (.env.local)
        $envOverride = (string) ($_SERVER['PHP_CLI_BINARY'] ?? $_ENV['PHP_CLI_BINARY'] ?? '');
        if ($envOverride !== '' && $this->isUsableCliBinary($envOverride)) {
            return $envOverride;
        }

        // 3. Symfony's PhpExecutableFinder
        $found = (new PhpExecutableFinder())->find();
        if ($found !== false && $found !== '' && $this->isUsableCliBinary($found)) {
            return $found;
        }

        // 4. PHP_BINARY constant — but only if it's CLI, not FPM
        if (\defined('PHP_BINARY') && \PHP_BINARY !== '' && $this->isUsableCliBinary(\PHP_BINARY)) {
            return \PHP_BINARY;
        }

        // 5. Try to derive CLI from FPM binary path
        //    /opt/plesk/php/8.4/sbin/php-fpm  →  /opt/plesk/php/8.4/bin/php
        //    /usr/sbin/php-fpm                →  /usr/bin/php
        if (\defined('PHP_BINARY') && \PHP_BINARY !== '') {
            $sibling = str_replace(['/sbin/php-fpm', '-fpm'], ['/bin/php', ''], \PHP_BINARY);
            if ($sibling !== \PHP_BINARY && $this->isUsableCliBinary($sibling)) {
                return $sibling;
            }
        }

        // 6. `which php` over the shell
        if (\function_exists('shell_exec')) {
            $output = @shell_exec('command -v php 2>/dev/null');
            if ($output !== null) {
                $path = trim((string) $output);
                if ($path !== '' && $this->isUsableCliBinary($path)) {
                    return $path;
                }
            }
        }

        // 7. Hardcoded common locations (Plesk, cPanel, system)
        $phpVersion = \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION;
        $candidates = [
            '/opt/plesk/php/' . $phpVersion . '/bin/php',
            '/opt/plesk/php/8.4/bin/php',
            '/opt/plesk/php/8.3/bin/php',
            '/opt/plesk/php/8.2/bin/php',
            '/opt/plesk/php/8.1/bin/php',
            '/opt/cpanel/ea-php84/root/usr/bin/php',
            '/opt/cpanel/ea-php83/root/usr/bin/php',
            '/opt/cpanel/ea-php82/root/usr/bin/php',
            '/usr/bin/php' . $phpVersion,
            '/usr/bin/php' . \PHP_MAJOR_VERSION,
            '/usr/local/bin/php',
            '/usr/bin/php',
        ];
        foreach ($candidates as $path) {
            if ($this->isUsableCliBinary($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Returns true iff the given path is a usable PHP CLI binary.
     *
     * Critical: PHP-FPM on Plesk runs under open_basedir restriction which makes
     * file_exists()/is_executable() return false for paths outside the allowed
     * scope — even when the binary actually exists and works (e.g. /opt/plesk/php/...).
     *
     * So we use a two-step approach, exactly like Contao Manager:
     *   1. Cheap reject obvious wrong names (-fpm, -cgi suffix)
     *   2. If file_exists() says yes → great, also check is_executable()
     *   3. If file_exists() says no → DON'T trust it. Try to exec the binary
     *      with `-v`. If exec works, the path is valid even though stat-checks
     *      were blocked by open_basedir.
     */
    private function isUsableCliBinary(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Reject obvious non-CLI variants by name to avoid spawning php-fpm
        // (which would happily start as a daemon and never finish our job)
        $name = basename($path);
        if (str_contains($name, '-fpm') || str_contains($name, '-cgi')) {
            return false;
        }

        // First try the cheap filesystem check (works when no open_basedir)
        if (file_exists($path) && !is_dir($path) && is_executable($path)) {
            return true;
        }

        // Filesystem check failed. This may be a genuine "doesn't exist" OR
        // open_basedir blocking us from seeing it. Fall back to running it.
        return $this->canExecuteBinary($path);
    }

    /**
     * Tries to actually execute the given path with `-v` to see if it's a
     * working PHP binary. Used as a fallback when filesystem checks are
     * blocked by open_basedir.
     */
    private function canExecuteBinary(string $path): bool
    {
        if (!\function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) \ini_get('disable_functions')));
        if (\in_array('exec', $disabled, true)) {
            return false;
        }

        $cmd = escapeshellarg($path) . ' -v 2>&1';
        $output = [];
        $exit   = 1;
        @exec($cmd, $output, $exit);

        if ($exit !== 0) {
            return false;
        }

        // Check the output actually looks like PHP's -v output
        $firstLine = $output[0] ?? '';
        return str_contains($firstLine, 'PHP');
    }

    /**
     * Convenience: create + start in one go.
     */
    public function startJob(UpdateJob $job): UpdateJob
    {
        $this->createJob($job);
        $this->startWorker($job);
        return $job;
    }

    /**
     * Marks the job as cancelled (force-kill).
     * Doesn't actually kill the worker process — that's the responsibility of
     * a separate Force-Kill controller (step 5c). This just flips the status.
     */
    public function markCancelled(UpdateJob $job, string $reason = 'cancelled by user'): void
    {
        $job->status     = UpdateJob::STATUS_CANCELLED;
        $job->finishedAt = date('c');
        $job->error      = $reason;
        $this->saveJob($job);
        $this->log->warning('manager', "Job {$job->id} marked cancelled: {$reason}");
        $this->sysLog->warning(sprintf('Update job %s cancelled: %s', $job->id, $reason));
    }

    public function getConsolePath(): string
    {
        return $this->consolePath;
    }
}
