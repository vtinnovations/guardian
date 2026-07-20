<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vtinnovations\Guardian\Security\BackendAuthChecker;
use Vtinnovations\Guardian\Security\LicenseManager;

/**
 * Backend endpoints for the v-t.one Pro-license integration.
 *
 *   POST /license/status   → current cached state (pro, key preview, expiry, domain, message)
 *   POST /license/activate → verify a freshly entered key against v-t.one and persist the result
 *   POST /license/clear    → wipe local state (customer removed the key)
 *
 * All three require an admin backend user. The activate route always hits
 * the remote server; status/clear only touch local state.
 */
class LicenseController
{
    public function __construct(
        private readonly BackendAuthChecker $backendAuth,
        private readonly LicenseManager $licenseManager,
    ) {
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/license/status',
        name: 'vtinnovations_guardian_license_status',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function status(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        return new JsonResponse([
            'success'     => true,
            'pro'         => $this->licenseManager->isPro(),
            'licensed'    => $this->licenseManager->isLicensed(),
            'plan'        => strtolower(trim($this->licenseManager->getPackage())) ?: 'free',
            'key_preview' => $this->maskKey($this->licenseManager->getLicenseKey()),
            'has_key'     => $this->licenseManager->getLicenseKey() !== '',
            'domain'      => $this->licenseManager->getLicenseDomain(),
            'expires_at'  => $this->licenseManager->getExpiresAt(),
            'verified_at' => $this->licenseManager->getVerifiedAt() ?: null,
            'package'     => $this->licenseManager->getPackage(),
            'cache_stale' => $this->licenseManager->isCacheStale(),
        ]);
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/license/activate',
        name: 'vtinnovations_guardian_license_activate',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function activate(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid JSON payload.'], 400);
        }

        $key = trim((string) ($payload['key'] ?? ''));
        // Bind the license to the host the user activated it from.
        $domain = strtolower(trim((string) $request->getHost()));

        $ok = $this->licenseManager->activate($key, $domain);

        return new JsonResponse([
            'success'     => true,
            'valid'       => $ok,
            'pro'         => $this->licenseManager->isPro(),
            'licensed'    => $this->licenseManager->isLicensed(),
            'plan'        => strtolower(trim($this->licenseManager->getPackage())) ?: 'free',
            'message'     => $this->licenseManager->lastMessage(),
            'key_preview' => $this->maskKey($this->licenseManager->getLicenseKey()),
            'has_key'     => $this->licenseManager->getLicenseKey() !== '',
            'domain'      => $this->licenseManager->getLicenseDomain(),
            'expires_at'  => $this->licenseManager->getExpiresAt(),
            'verified_at' => $this->licenseManager->getVerifiedAt() ?: null,
            'package'     => $this->licenseManager->getPackage(),
        ]);
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/license/clear',
        name: 'vtinnovations_guardian_license_clear',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function clear(): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        $this->licenseManager->clear();

        return new JsonResponse([
            'success' => true,
            'pro'     => false,
        ]);
    }

    private function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len === 0) {
            return '';
        }
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($key, 0, 4) . str_repeat('•', max(4, $len - 8)) . substr($key, -4);
    }
}
