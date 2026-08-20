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

namespace Vtinnovations\Guardian\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Vtinnovations\Guardian\Checker\PreUpdateChecker;
use Vtinnovations\Guardian\Security\BackendAuthChecker;
use Vtinnovations\Guardian\Service\RegistrationPolicy;
use Vtinnovations\Guardian\Service\RegistrationState;

#[Route(
    '%contao.backend.route_prefix%/updater/analyse',
    name: 'vtinnovations_guardian_analyse',
    defaults: ['_scope' => 'backend'],
    methods: ['POST']
)]
class AnalysisController
{
    use EntitlementResponses;

    public function __construct(private readonly PreUpdateChecker $checker, private readonly BackendAuthChecker $backendAuth, private readonly RegistrationPolicy $policy)
    {
    }

    public function __invoke(): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->policy->allows(RegistrationState::CAP_UPDATES)) {
            return $this->entitlementDenied(RegistrationState::CAP_UPDATES);
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
