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
use Symfony\Component\Routing\Attribute\Route;
use Vtinnovations\Guardian\Checker\PreUpdateChecker;
use Vtinnovations\Guardian\Security\BackendAuthChecker;
use Vtinnovations\Guardian\Security\LicenseGuard;

#[Route(
    '%contao.backend.route_prefix%/updater/analyse',
    name: 'vtinnovations_guardian_analyse',
    defaults: ['_scope' => 'backend'],
    methods: ['POST']
)]
class AnalysisController
{
    public function __construct(private readonly PreUpdateChecker $checker, private readonly BackendAuthChecker $backendAuth, private readonly LicenseGuard $license)
    {
    }

    public function __invoke(): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->license->isPro()) {
            return $this->license->deniedResponse();
        }

        try {
            return new JsonResponse([
                'success' => true,
                'result'  => $this->checker->runAll(),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
