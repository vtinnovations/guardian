<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class Guardian extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * Standalone recovery panel deployment.
     *
     * The panel is a powerful, framework-free restore/delete tool. Exposing it
     * in the public webroot is a real attack surface, so deployment is now
     * OPT-IN rather than automatic on every boot.
     *
     * Enable by setting, in .env.local:
     *
     *     VTINNOVATIONS_GUARDIAN_DEPLOY_RECOVERY_PANEL=1
     *
     * When the flag is enabled we keep <public>/_updater-recovery.php in sync
     * with the shipped copy (md5 compare). When it is NOT enabled we actively
     * REMOVE any previously deployed copy, so turning the flag off genuinely
     * eliminates the exposure rather than leaving a stale file behind.
     *
     * Recommendation: enable it only for the duration of a risky update, then
     * disable it again. Also protect the file at the webserver level (IP
     * allowlist / HTTP auth) — the in-app token is the last line, not the only.
     */
    public function boot(): void
    {
        parent::boot();
        $this->syncRecoveryPanel();
    }

    private function recoveryPanelEnabled(): bool
    {
        $val = $_SERVER['VTINNOVATIONS_GUARDIAN_DEPLOY_RECOVERY_PANEL']
            ?? $_ENV['VTINNOVATIONS_GUARDIAN_DEPLOY_RECOVERY_PANEL']
            ?? getenv('VTINNOVATIONS_GUARDIAN_DEPLOY_RECOVERY_PANEL');

        if ($val === false || $val === null) {
            return false;
        }

        return \in_array(strtolower(trim((string) $val)), ['1', 'true', 'yes', 'on'], true);
    }

    private function syncRecoveryPanel(): void
    {
        try {
            $source = $this->getPath() . '/public/_updater-recovery.php';

            $kernel     = $this->container?->get('kernel');
            $projectDir = $kernel ? $kernel->getProjectDir() : \dirname($this->getPath(), 4);

            // Honour the project's configured public dir if available
            $publicDir = $projectDir . '/public';
            if (!is_dir($publicDir)) {
                // Some legacy installs use web/ — try that as fallback
                $alt = $projectDir . '/web';
                $publicDir = is_dir($alt) ? $alt : $publicDir;
            }
            if (!is_dir($publicDir)) {
                return;
            }

            // Resolve the admin-configured filename (defaults to
            // _updater-recovery.php). We also compute the DEFAULT path so we
            // can clean up a previously deployed file if the admin renamed it.
            $configuredName = $this->resolvePanelFilename($projectDir);
            $target         = $publicDir . '/' . $configuredName;
            $defaultTarget  = $publicDir . '/_updater-recovery.php';

            // Opt-in gate. If disabled, remove ANY previously deployed panel
            // (both the configured name and the default) so the attack surface
            // actually goes away when you turn it off.
            if (!$this->recoveryPanelEnabled()) {
                foreach (array_unique([$target, $defaultTarget]) as $stale) {
                    if (is_file($stale)) {
                        @unlink($stale);
                    }
                }
                return;
            }

            if (!is_file($source)) {
                return;
            }

            // If admin renamed the panel, delete the old default before writing
            // the new one — otherwise both would remain reachable.
            if ($configuredName !== '_updater-recovery.php' && is_file($defaultTarget)) {
                @unlink($defaultTarget);
            }

            // Also clean up any stale copy from an earlier custom name that no
            // longer matches. We can't enumerate old names reliably; the admin
            // can rename explicitly and the previous file gets orphaned. That's
            // acceptable — panel access still requires the token.

            // Cheap idempotency check: if hashes match, nothing to do.
            if (is_file($target) && @md5_file($source) === @md5_file($target)) {
                return;
            }

            // Copy. Failure is non-fatal — bundle should still function even
            // if the standalone panel can't be deployed (e.g. read-only public/).
            @copy($source, $target);
        } catch (\Throwable $ignored) {
            // Never let panel install break bundle boot
        }
    }

    /**
     * Reads the configured panel filename from var/updater/runtime.json
     * directly (no service container — this runs during kernel boot). Falls
     * back to the safe default on any parse/validate failure.
     */
    private function resolvePanelFilename(string $projectDir): string
    {
        $default    = '_updater-recovery.php';
        $configFile = $projectDir . '/var/updater/runtime.json';
        if (!is_file($configFile)) {
            return $default;
        }
        $raw = @file_get_contents($configFile);
        if ($raw === false) {
            return $default;
        }
        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            return $default;
        }
        $name = trim((string) ($data['recovery_panel_filename'] ?? ''));
        if ($name === '' || !preg_match('#^[A-Za-z0-9._-]{1,60}\.php$#', $name) || str_contains($name, '..')) {
            return $default;
        }
        return $name;
    }
}
