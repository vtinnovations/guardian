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
use Vtinnovations\Guardian\External\PanelAuth;
use Vtinnovations\Guardian\Security\BackendAuthChecker;

/**
 * Backend-side controller for managing the standalone recovery panel's token.
 *
 * The standalone panel itself lives at <project>/public/_updater-recovery.php
 * (a single file, no framework) and reads the same token sources we manage
 * here. This controller only exposes:
 *
 *   - get    : masked token preview + token source ('env' or 'file')
 *   - rotate : generate a fresh file-based token, return it once
 *
 * The token is never returned in full from /get to avoid leaking it via
 * browser memory, devtools network log, or fetch-hooking browser extensions.
 * Rotation is the only way to obtain the full token (and only the user who
 * triggered the rotation sees it, once).
 */
class PanelSettingsController
{
    public function __construct(
        private readonly PanelAuth $auth,
        private readonly BackendAuthChecker $backendAuth,
        private readonly \Vtinnovations\Guardian\Security\LicenseGuard $license,
        private readonly \Vtinnovations\Guardian\Service\RuntimeConfig $runtimeConfig,
    ) {
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/panel/get',
        name: 'vtinnovations_guardian_panel_get',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function get(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->license->isPro()) {
            return $this->license->deniedResponse();
        }

        $fullToken = $this->auth->getActiveToken();

        // The panel filename is admin-configurable (Settings → Recovery-Panel).
        $filename      = $this->runtimeConfig->getRecoveryPanelFilename();
        $standaloneUrl = $request->getSchemeAndHttpHost() . '/' . $filename;

        return new JsonResponse([
            'success'       => true,
            'token_preview' => $this->maskToken($fullToken),
            'token_source'  => $this->auth->getTokenSource(),
            'panel_url'     => $standaloneUrl,
        ]);
    }

    /**
     * Returns a masked preview of a token, e.g. "a1b2****8fe9" — enough
     * for the user to recognise which token is active without exposing it
     * fully on every dashboard load.
     */
    private function maskToken(string $token): string
    {
        $len = strlen($token);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($token, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($token, -4);
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/panel/rotate',
        name: 'vtinnovations_guardian_panel_rotate',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function rotate(): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->license->isPro()) {
            return $this->license->deniedResponse();
        }

        // Refuse rotation if token is in .env — that's user-managed.
        // To rotate, the user has to edit .env.local themselves.
        if ($this->auth->getTokenSource() === 'env') {
            return new JsonResponse([
                'success' => false,
                'error'   => 'Token comes from .env. Edit VTINNOVATIONS_GUARDIAN_TOKEN in .env.local to rotate it.',
            ], 400);
        }

        $token = $this->auth->rotateToken();

        return new JsonResponse([
            'success'      => true,
            'token'        => $token,
            'token_source' => $this->auth->getTokenSource(),
        ]);
    }
}
