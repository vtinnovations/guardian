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

use Vtinnovations\Guardian\Checker\PackageSeal;
use Vtinnovations\Guardian\Checker\SealedRecord;
use Vtinnovations\Guardian\Checker\SealFailure;
use Vtinnovations\Guardian\External\ServiceEndpoints;

/**
 * The single authoritative home for this installation's registration state.
 *
 * Three pieces are kept together and only ever swapped as one unit:
 *
 *   registration.json   the exact record bytes the digest was taken over
 *   registration.seal   the authenticated integrity envelope for those bytes
 *   registration.scope  which configured host was matched when it was bound
 *
 * Everything is re-authenticated on every read. Hand-editing the record fails
 * the digest, hand-editing the envelope fails its signature, and swapping in
 * an older pair is refused on version. There is no code path that reads this
 * state without going through that verification, which is why the file lives
 * in plain sight under var/ without being a bypass.
 *
 * Writes are a lock-guarded transaction: validate, stage to the same
 * filesystem, re-read the staged copy, back up what is live, swap, re-read the
 * live copy, and roll back to the backup if the final read does not verify.
 * A crash halfway can leave a stale backup but never a record whose bytes and
 * envelope disagree.
 */
final class RegistrationStore
{
    public const OK              = 'committed';
    public const REJECTED_OLDER  = 'version_rollback_refused';
    public const REJECTED_STALE  = 'version_not_newer';
    public const WRITE_FAILED    = 'state_write_failed';
    public const LOCK_FAILED     = 'state_locked';

    /** Reason reported when nothing is stored at all. */
    public const ABSENT = 'no_registration';

    private ?SealedRecord $cached = null;

    private string $reason = self::ABSENT;

    private bool $loaded = false;

    /**
     * @param list<string> $acceptedTiers tier vocabulary permitted by the product model
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly PackageSeal $seal,
        private readonly array $acceptedTiers,
    ) {
    }

    /**
     * The currently stored record, or null when nothing authentic is held.
     *
     * A null here is never "assume valid": callers treat it as unlicensed.
     */
    public function current(): ?SealedRecord
    {
        if ($this->loaded) {
            return $this->cached;
        }

        $this->loaded = true;
        $this->cached = null;

        $bytes    = @file_get_contents($this->recordFile());
        $sealJson = @file_get_contents($this->sealFile());

        if (!\is_string($bytes) || '' === $bytes || !\is_string($sealJson) || '' === $sealJson) {
            $this->reason = self::ABSENT;

            return null;
        }

        $envelope = json_decode($sealJson, false);

        if (!$envelope instanceof \stdClass) {
            $this->reason = 'seal_malformed';

            return null;
        }

        try {
            $this->cached = $this->seal->open(
                $this->seal->packageFrom($bytes, $envelope),
                ServiceEndpoints::PROJECT,
                ServiceEndpoints::SLUG,
                $this->acceptedTiers,
            );
            $this->reason = self::OK;
        } catch (SealFailure $failure) {
            $this->reason = $failure->category;
            $this->cached = null;
        }

        return $this->cached;
    }

    /** Diagnostic category for the last read. Never a bypass. */
    public function reason(): string
    {
        $this->current();

        return $this->reason;
    }

    /**
     * The configured host that was matched when the current state was bound.
     *
     * Recorded so background work — CLI workers, cron, the bundle's own boot —
     * evaluates against the host the vendor actually authorised rather than
     * whatever host happens to be on the current request. Editing it cannot
     * widen anything: the value still has to be a member of the signed set.
     */
    public function boundHost(): string
    {
        $raw = @file_get_contents($this->scopeFile());

        if (!\is_string($raw)) {
            return '';
        }

        $data = json_decode($raw, true);

        return \is_array($data) ? trim((string) ($data['matched_host'] ?? '')) : '';
    }

    /**
     * Atomically replaces the stored state.
     *
     * @param bool $requireNewer push updates must strictly increase the version;
     *                          administrator-driven refresh may re-apply the same one
     *
     * @return string self::OK or a reason code
     */
    public function commit(SealedRecord $record, string $matchedHost, bool $requireNewer = false): string
    {
        $handle = $this->acquireLock();

        if (null === $handle) {
            return self::LOCK_FAILED;
        }

        $stagedRecord = $this->recordFile() . '.stage';
        $stagedSeal   = $this->sealFile() . '.stage';
        $stagedScope  = $this->scopeFile() . '.stage';
        $backupRecord = $this->recordFile() . '.bak';
        $backupSeal   = $this->sealFile() . '.bak';
        $backupScope  = $this->scopeFile() . '.bak';

        try {
            // Read the live version through the same verification path, so a
            // corrupt live pair cannot block a legitimate recovery.
            $liveVersion = $this->current()?->version();

            if (null !== $liveVersion) {
                if ($record->version() < $liveVersion) {
                    return self::REJECTED_OLDER;
                }

                if ($requireNewer && $record->version() <= $liveVersion) {
                    return self::REJECTED_STALE;
                }
            }

            $sealJson  = json_encode($record->envelope, \JSON_UNESCAPED_SLASHES);
            $scopeJson = json_encode(
                ['matched_host' => $matchedHost, 'bound_at' => time()],
                \JSON_UNESCAPED_SLASHES
            );

            if (false === $sealJson || false === $scopeJson) {
                return self::WRITE_FAILED;
            }

            if (!$this->stage($stagedRecord, $record->bytes)
                || !$this->stage($stagedSeal, $sealJson)
                || !$this->stage($stagedScope, $scopeJson)
            ) {
                return self::WRITE_FAILED;
            }

            // Re-read what was actually written, not what we meant to write.
            if (!$this->verifyPair($stagedRecord, $stagedSeal)) {
                return self::WRITE_FAILED;
            }

            $hadLive = is_file($this->recordFile()) && is_file($this->sealFile());

            if ($hadLive) {
                @copy($this->recordFile(), $backupRecord);
                @copy($this->sealFile(), $backupSeal);
                @copy($this->scopeFile(), $backupScope);
            }

            // The swap. Rename is atomic per file; the surrounding lock plus
            // the rollback below is what makes the pair atomic as a unit.
            if (!@rename($stagedRecord, $this->recordFile())
                || !@rename($stagedSeal, $this->sealFile())
                || !@rename($stagedScope, $this->scopeFile())
            ) {
                $this->rollback($hadLive, $backupRecord, $backupSeal, $backupScope);

                return self::WRITE_FAILED;
            }

            $this->invalidate();

            if (!$this->verifyPair($this->recordFile(), $this->sealFile())) {
                $this->rollback($hadLive, $backupRecord, $backupSeal, $backupScope);
                $this->invalidate();

                return self::WRITE_FAILED;
            }

            @unlink($backupRecord);
            @unlink($backupSeal);
            @unlink($backupScope);

            return self::OK;
        } finally {
            foreach ([$stagedRecord, $stagedSeal, $stagedScope] as $stale) {
                if (is_file($stale)) {
                    @unlink($stale);
                }
            }

            $this->releaseLock($handle);
        }
    }

    /**
     * Removes the authoritative state.
     *
     * This is a real revocation, not a UI toggle: every protected capability
     * returns to its unlicensed default the moment this returns.
     */
    public function purge(): void
    {
        $handle = $this->acquireLock();

        try {
            foreach ([$this->recordFile(), $this->sealFile(), $this->scopeFile(), $this->carryFile()] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            $this->invalidate();
        } finally {
            if (null !== $handle) {
                $this->releaseLock($handle);
            }
        }
    }

    /**
     * A key carried over from an earlier, unsigned local record.
     *
     * Purely a convenience so an administrator does not have to retype the key
     * after upgrading. It grants nothing: entitlement still requires a
     * successful signed exchange.
     */
    public function carriedKey(): string
    {
        $raw = @file_get_contents($this->carryFile());

        return \is_string($raw) ? trim($raw) : '';
    }

    public function carryKey(string $key): void
    {
        $key = trim($key);

        if ('' === $key) {
            return;
        }

        $this->ensureDir();

        if (false !== @file_put_contents($this->carryFile(), $key, \LOCK_EX)) {
            @chmod($this->carryFile(), 0600);
        }
    }

    public function dropCarriedKey(): void
    {
        if (is_file($this->carryFile())) {
            @unlink($this->carryFile());
        }
    }

    /**
     * Retires the unsigned licence cache written by Guardian before signed
     * records existed.
     *
     * The old file cannot be adopted as state: it carries no signature, so
     * trusting it would mean granting entitlement to a plain JSON file anyone
     * with write access could author — exactly what this design exists to
     * prevent. Only the key string is kept, purely so the administrator does
     * not have to retype it, and the old file is deleted so the two stores can
     * never disagree about what is licensed.
     */
    public function adoptLegacy(): void
    {
        $legacy = $this->projectDir . '/var/updater/license.json';

        if (!is_file($legacy)) {
            return;
        }

        $raw  = @file_get_contents($legacy);
        $data = \is_string($raw) ? json_decode($raw, true) : null;

        if (\is_array($data) && null === $this->current() && '' === $this->carriedKey()) {
            $this->carryKey(trim((string) ($data['license_key'] ?? '')));
        }

        @unlink($legacy);
    }

    /** Forces the next read to hit disk again. */
    public function invalidate(): void
    {
        $this->loaded = false;
        $this->cached = null;
        $this->reason = self::ABSENT;
    }

    private function verifyPair(string $recordFile, string $sealFile): bool
    {
        $bytes    = @file_get_contents($recordFile);
        $sealJson = @file_get_contents($sealFile);

        if (!\is_string($bytes) || !\is_string($sealJson)) {
            return false;
        }

        $envelope = json_decode($sealJson, false);

        if (!$envelope instanceof \stdClass) {
            return false;
        }

        try {
            $this->seal->open(
                $this->seal->packageFrom($bytes, $envelope),
                ServiceEndpoints::PROJECT,
                ServiceEndpoints::SLUG,
                $this->acceptedTiers,
            );

            return true;
        } catch (SealFailure) {
            return false;
        }
    }

    private function rollback(bool $hadLive, string $backupRecord, string $backupSeal, string $backupScope): void
    {
        if (!$hadLive) {
            foreach ([$this->recordFile(), $this->sealFile(), $this->scopeFile()] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            return;
        }

        @rename($backupRecord, $this->recordFile());
        @rename($backupSeal, $this->sealFile());

        if (is_file($backupScope)) {
            @rename($backupScope, $this->scopeFile());
        }
    }

    private function stage(string $path, string $contents): bool
    {
        $this->ensureDir();

        $handle = @fopen($path, 'wb');

        if (false === $handle) {
            return false;
        }

        try {
            if (false === fwrite($handle, $contents)) {
                return false;
            }

            fflush($handle);

            // Best effort durability; not every filesystem or SAPI supports it.
            if (\function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            fclose($handle);
        }

        @chmod($path, 0600);

        return true;
    }

    /** @return resource|null */
    private function acquireLock()
    {
        $this->ensureDir();

        $handle = @fopen($this->lockFile(), 'c');

        if (false === $handle) {
            return null;
        }

        if (!flock($handle, \LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    private function releaseLock($handle): void
    {
        flock($handle, \LOCK_UN);
        fclose($handle);
    }

    private function ensureDir(): void
    {
        $dir = $this->projectDir . '/var/updater';

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
    }

    private function recordFile(): string
    {
        return $this->projectDir . '/var/updater/registration.json';
    }

    private function sealFile(): string
    {
        return $this->projectDir . '/var/updater/registration.seal';
    }

    private function scopeFile(): string
    {
        return $this->projectDir . '/var/updater/registration.scope';
    }

    private function carryFile(): string
    {
        return $this->projectDir . '/var/updater/registration.carry';
    }

    private function lockFile(): string
    {
        return $this->projectDir . '/var/updater/registration.lock';
    }
}
