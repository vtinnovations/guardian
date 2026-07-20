<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Security;

/**
 * Persists the cached verification result and decides whether the bundle is
 * unlocked. Paid-only Pro tier: a single verify call gates update/restore/
 * schedule/panel; manual backup remains free regardless.
 *
 * State stored in var/updater/license.json:
 *   license_key           the customer's key
 *   license_verified_at   unix ts of the last successful verify (0 = never)
 *   license_expires_at    hard expiry from the server (null = lifetime)
 *   license_domain        domain bound on first verify — used on re-checks
 *   license_package       package code from the server — informational
 */
final class LicenseManager
{
    /** Trust the cache this long after the last successful verify. */
    private const GRACE = 7 * 86400;

    private string $lastMessage = '';

    public function __construct(
        private readonly string $projectDir,
        private readonly LicenseVerifier $verifier,
    ) {
    }

    public function getLicenseKey(): string
    {
        return trim((string) ($this->load()['license_key'] ?? ''));
    }

    public function getLicenseDomain(): string
    {
        return trim((string) ($this->load()['license_domain'] ?? ''));
    }

    public function getExpiresAt(): ?int
    {
        $v = $this->load()['license_expires_at'] ?? null;
        return null === $v ? null : (int) $v;
    }

    public function getVerifiedAt(): int
    {
        return (int) ($this->load()['license_verified_at'] ?? 0);
    }

    public function getPackage(): string
    {
        return (string) ($this->load()['license_package'] ?? '');
    }

    public function lastMessage(): string
    {
        return $this->lastMessage;
    }

    /**
     * True when the stored key was successfully verified within the grace
     * window and hasn't expired — regardless of the package tier. Both Free
     * and Pro keys count as "licensed"; use isPro() to gate paid features.
     */
    public function isLicensed(): bool
    {
        $c   = $this->load();
        $key = trim((string) ($c['license_key'] ?? ''));
        if ('' === $key) {
            return false;
        }

        $expiresAt = $c['license_expires_at'] ?? null;
        if (null !== $expiresAt && (int) $expiresAt < time()) {
            return false;
        }

        $verifiedAt = (int) ($c['license_verified_at'] ?? 0);
        if (0 === $verifiedAt) {
            return false;
        }

        return time() - $verifiedAt <= self::GRACE;
    }

    /**
     * True only when the stored key is licensed AND the server assigned it
     * the "pro" package. Free keys report package "free" (or empty) — those
     * unlock Manual Backup but nothing else.
     */
    public function isPro(): bool
    {
        return $this->isLicensed()
            && strtolower(trim($this->getPackage())) === 'pro';
    }

    public function isCacheStale(int $maxAge = 86400): bool
    {
        $verifiedAt = $this->getVerifiedAt();
        return $verifiedAt > 0 && time() - $verifiedAt > $maxAge;
    }

    /**
     * Verify a freshly entered key and persist the result. On any failure the
     * key is kept (so the UI shows which key was rejected) but the
     * verification timestamp stays zeroed — a first activation can never rely
     * on the grace window.
     */
    public function activate(string $key, string $domain): bool
    {
        $key = trim($key);
        if ('' === $key || \strlen($key) > 190) {
            $this->revokeCache();
            $this->persist(['license_key' => '']);
            $this->lastMessage = 'No license key entered.';
            return false;
        }

        $result            = $this->verifier->verify($key, $domain);
        $this->lastMessage = $result['message'];

        if ($result['valid']) {
            $this->persist([
                'license_key'         => $key,
                'license_verified_at' => time(),
                'license_expires_at'  => $result['expires_at'],
                'license_domain'      => $domain,
                'license_package'     => (string) ($result['package'] ?? ''),
            ]);
            return true;
        }

        $this->persist([
            'license_key'         => $key,
            'license_verified_at' => 0,
            'license_expires_at'  => null,
            'license_domain'      => '',
            'license_package'     => '',
        ]);
        return false;
    }

    /**
     * Background 24-hour re-check. A transient error keeps the cache so the
     * grace window holds; an explicit denial wipes it so the customer is
     * locked out at once.
     */
    public function refresh(string $domain): void
    {
        $c   = $this->load();
        $key = trim((string) ($c['license_key'] ?? ''));
        if ('' === $key) {
            return;
        }

        $useDomain = trim((string) ($c['license_domain'] ?? '')) ?: $domain;
        $result    = $this->verifier->verify($key, $useDomain);

        if ($result['valid']) {
            $this->persist([
                'license_verified_at' => time(),
                'license_expires_at'  => $result['expires_at'],
                'license_package'     => (string) ($result['package'] ?? ($c['license_package'] ?? '')),
            ]);
        } elseif (!$result['server_error']) {
            $this->revokeCache();
        }
    }

    /**
     * Clear the cached verification / binding fields on an explicit server
     * denial. Keeps license_key so the UI still shows which key was rejected.
     */
    public function revokeCache(): void
    {
        $this->persist([
            'license_verified_at' => 0,
            'license_expires_at'  => null,
            'license_domain'      => '',
            'license_package'     => '',
        ]);
    }

    public function clear(): void
    {
        $this->persist([
            'license_key'         => '',
            'license_verified_at' => 0,
            'license_expires_at'  => null,
            'license_domain'      => '',
            'license_package'     => '',
        ]);
    }

    /**
     * @return array{license_key: string, license_verified_at: int, license_expires_at: int|null, license_domain: string, license_package: string}
     */
    private function load(): array
    {
        $file = $this->licenseFile();
        if (!is_file($file)) {
            return $this->defaults();
        }

        $raw  = @file_get_contents($file);
        $data = false !== $raw ? json_decode((string) $raw, true) : null;
        return \is_array($data) ? array_merge($this->defaults(), $data) : $this->defaults();
    }

    /**
     * @return array{license_key: string, license_verified_at: int, license_expires_at: int|null, license_domain: string, license_package: string}
     */
    private function defaults(): array
    {
        return [
            'license_key'         => '',
            'license_verified_at' => 0,
            'license_expires_at'  => null,
            'license_domain'      => '',
            'license_package'     => '',
        ];
    }

    private function persist(array $patch): void
    {
        $merged = array_merge($this->load(), $patch);
        $file   = $this->licenseFile();
        $dir    = \dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $tmp  = $file . '.tmp';
        $json = json_encode($merged, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if (false !== $json && false !== @file_put_contents($tmp, $json, LOCK_EX) && @rename($tmp, $file)) {
            @chmod($file, 0640);
        } else {
            @unlink($tmp);
        }
    }

    private function licenseFile(): string
    {
        return $this->projectDir . '/var/updater/license.json';
    }
}
