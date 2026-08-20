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

/**
 * Append-only log file for job execution output.
 *
 * Each line is JSON: {"ts": "ISO8601", "level": "info|warning|error", "step": "...", "msg": "..."}
 *
 * The CLI worker writes to this file. The backend reads from it for the live
 * progress UI. Both can do so concurrently — appends are atomic at the OS level
 * for chunks under PIPE_BUF (4KB on Linux), and our log lines are well under that.
 */
class JobLog
{
    private string $logFile;

    public function __construct(private readonly string $projectDir)
    {
        $this->logFile = $projectDir . '/var/updater/job.log';
    }

    public function path(): string
    {
        return $this->logFile;
    }

    public function reset(): void
    {
        $dir = \dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents($this->logFile, '');
    }

    public function info(string $step, string $message): void
    {
        $this->write('info', $step, $message);
    }

    public function warning(string $step, string $message): void
    {
        $this->write('warning', $step, $message);
    }

    public function error(string $step, string $message): void
    {
        $this->write('error', $step, $message);
    }

    public function step(string $step, string $message): void
    {
        $this->write('step', $step, $message);
    }

    /**
     * Reads recent log entries, optionally from a given byte offset.
     * Returns the new entries plus the new offset for the next poll.
     *
     * @return array{entries: array<int, array>, offset: int}
     */
    public function readSince(int $offset = 0): array
    {
        if (!file_exists($this->logFile)) {
            return ['entries' => [], 'offset' => 0];
        }

        $size = (int) filesize($this->logFile);
        if ($size === 0 || $offset >= $size) {
            return ['entries' => [], 'offset' => $size];
        }

        $fp = @fopen($this->logFile, 'rb');
        if ($fp === false) {
            return ['entries' => [], 'offset' => $offset];
        }

        @fseek($fp, $offset);
        $content = (string) stream_get_contents($fp);
        $newOffset = ftell($fp);
        fclose($fp);

        $entries = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return [
            'entries' => $entries,
            'offset'  => $newOffset !== false ? $newOffset : $size,
        ];
    }

    private function write(string $level, string $step, string $message): void
    {
        $dir = \dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $entry = json_encode([
            'ts'    => date('c'),
            'level' => $level,
            'step'  => $step,
            'msg'   => self::redactSecrets($message),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        @file_put_contents($this->logFile, $entry . "\n", \FILE_APPEND | \LOCK_EX);
    }

    /**
     * Strips obvious secrets out of log messages before persisting them.
     *
     * Job logs sit in var/updater/ and can leak via:
     *   - someone running `tar var/` for a site backup
     *   - other shared-hosting users with read access
     *   - the recovery panel which shows the log to anyone with the token
     *
     * Patterns covered:
     *   --password=foo / --password foo / -pfoo (MySQL CLI shortcut)
     *   password=foo (env-style)
     *   MYSQL_PWD=foo
     *   token=foo / Bearer foo / Authorization: foo
     *   DSN-embedded creds: mysql://user:secret@host/db
     */
    public static function redactSecrets(string $message): string
    {
        // --password=<x>, --pwd=<x>, --pass=<x>
        $message = preg_replace('/(--(?:password|pwd|pass))=\S+/i', '$1=***', $message) ?? $message;
        // --password <x>, --pwd <x>  (note the space)
        $message = preg_replace('/(--(?:password|pwd|pass))\s+\S+/i', '$1 ***', $message) ?? $message;
        // -p<value> (no space — mysql CLI shorthand). -p alone with no value is OK.
        $message = preg_replace('/(?<!\w)-p[A-Za-z0-9!@#$%^&*()_+\-=\[\]{};:\'",.<>\/?\\\\|`~]+/', '-p***', $message) ?? $message;
        // password=<x>, pwd=<x>, pass=<x>, MYSQL_PWD=<x>, secret=<x>, token=<x>, api_key=<x>
        $message = preg_replace('/((?:password|pwd|pass|secret|token|api_key|MYSQL_PWD|mysql_pwd))=\S+/i', '$1=***', $message) ?? $message;
        // Bearer <token>
        $message = preg_replace('/(Bearer\s+)\S+/i', '$1***', $message) ?? $message;
        // DSN inline credentials: scheme://user:password@host
        $message = preg_replace('#([a-z][a-z0-9+.-]*://[^/@\s:]+):[^@\s]+@#i', '$1:***@', $message) ?? $message;

        return $message;
    }
}
