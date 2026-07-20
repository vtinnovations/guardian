<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Security;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Pro-license gate.
 *
 * Delegates the actual verification decision to LicenseManager, which caches
 * a v-t.one server response with a grace window. Controllers call isPro()
 * and short-circuit with deniedResponse() before doing any work.
 *
 * This is a server-side gate — the UI also hides locked features, but the
 * server is the authority.
 */
class LicenseGuard
{
    public function __construct(private readonly LicenseManager $licenseManager)
    {
    }

    public function isPro(): bool
    {
        return $this->licenseManager->isPro();
    }

    /**
     * Any valid license (Free or Pro). Free unlocks the Manual Backup module;
     * Pro additionally unlocks everything gated by isPro(). With no license
     * at all only the Dashboard + Settings tabs are usable.
     */
    public function isLicensed(): bool
    {
        return $this->licenseManager->isLicensed();
    }

    /**
     * Denied response when a Free-tier feature is used without ANY license.
     * Distinct from deniedResponse() (Pro-only) so the frontend can show the
     * right upsell message.
     */
    public function deniedNoLicenseResponse(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'reason'  => 'license',
            'error'   => 'Diese Funktion benötigt mindestens eine Free-Lizenz von v-t.one. '
                       . 'Trage deinen Lizenzschlüssel unter Einstellungen → Pro-Lizenz ein. '
                       . 'Ohne Lizenz sind nur Dashboard und Einstellungen erreichbar.',
        ], 403);
    }

    /**
     * Standard JSON 403 returned by gated endpoints when no valid license is
     * present. `reason: license` lets the frontend distinguish this from
     * auth/CSRF failures and show the upgrade notice instead of an error.
     */
    public function deniedResponse(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'reason'  => 'license',
            'error'   => 'Diese Funktion erfordert eine gültige Pro-Lizenz von v-t.one. '
                       . 'Trage deinen Lizenzschlüssel unter Einstellungen → Pro-Lizenz ein, '
                       . 'um Updates, Wiederherstellung und geplante Backups freizuschalten. '
                       . 'Das manuelle Backup-Modul bleibt ohne Lizenz verfügbar.',
        ], 403);
    }
}
