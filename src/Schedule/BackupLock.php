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

/**
 * File-based lock to prevent overlapping backup runs.
 *
 * Uses flock() on a sentinel file. If a previous PHP process died without
 * releasing the lock, the OS will release it automatically when the file
 * descriptor is closed. We also check the age of the lock file as a
 * defensive sanity check — if a lock is older than the timeout, we consider
 * it stale.
 */
class BackupLock
{
    /** @var resource|null */
    private $handle = null;

    private string $lockFile;

    public function __construct(private readonly string $projectDir, private readonly int $timeoutSeconds = 1800)
    {
        $this->lockFile = $projectDir . '/var/updater/backup.lock';
    }

    public function acquire(): bool
    {
        $dir = \dirname($this->lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        // Stale lock check: if file is older than timeout, remove it
        if (file_exists($this->lockFile)) {
            $age = time() - (int) @filemtime($this->lockFile);
            if ($age > $this->timeoutSeconds) {
                @unlink($this->lockFile);
            }
        }

        $fp = @fopen($this->lockFile, 'c');
        if ($fp === false) {
            return false;
        }

        if (!flock($fp, \LOCK_EX | \LOCK_NB)) {
            fclose($fp);
            return false;
        }

        // Write a marker so we can see it was locked
        ftruncate($fp, 0);
        fwrite($fp, sprintf("locked_at=%s\npid=%d\n", date('c'), getmypid() ?: 0));
        fflush($fp);

        $this->handle = $fp;
        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, \LOCK_UN);
        fclose($this->handle);
        $this->handle = null;

        @unlink($this->lockFile);
    }

    public function __destruct()
    {
        $this->release();
    }
}
