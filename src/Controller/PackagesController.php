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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vtinnovations\Guardian\Service\PackageInspector;
use Vtinnovations\Guardian\Security\BackendAuthChecker;

#[Route(
    '%contao.backend.route_prefix%/updater/packages',
    name: 'vtinnovations_guardian_packages',
    defaults: ['_scope' => 'backend'],
    methods: ['POST']
)]
class PackagesController
{
    public function __construct(private readonly PackageInspector $inspector, private readonly BackendAuthChecker $backendAuth)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        $forceRefresh = (bool) $request->query->get('refresh', false);

        try {
            $installed = $this->inspector->listInstalled();
            $outdated  = $this->inspector->getOutdated($forceRefresh);

            $outdatedByName = [];
            foreach ($outdated['packages'] as $pkg) {
                $outdatedByName[$pkg['name']] = $pkg;
            }

            $merged = [];
            foreach ($installed as $pkg) {
                $upd = $outdatedByName[$pkg['name']] ?? null;

                $merged[] = [
                    'name'        => $pkg['name'],
                    'current'     => $pkg['version'],
                    'latest'      => $upd['latest']        ?? null,
                    'has_update'  => $upd['has_update']    ?? false,
                    'is_blocked'  => $upd['is_blocked']    ?? false,
                    'status'      => $upd['latest_status'] ?? 'up-to-date',
                    'type'        => $pkg['type'],
                    'description' => $pkg['description'],
                    'abandoned'   => $pkg['abandoned'],
                ];
            }

            $stats = [
                'total'      => \count($merged),
                'updates'    => \count(array_filter($merged, static fn ($p) => $p['has_update'])),
                'blocked'    => 0, // we don't compute this anymore (would require dep resolution)
                'abandoned'  => \count(array_filter($merged, static fn ($p) => $p['abandoned'])),
                'checked_at' => $outdated['checked_at'],
                'cached'     => $outdated['cached'],
                'error'      => $outdated['error'],
                'source'     => $outdated['source'] ?? 'unknown',
            ];

            return new JsonResponse([
                'success'  => true,
                'packages' => $merged,
                'stats'    => $stats,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
