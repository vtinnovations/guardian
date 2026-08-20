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
use Vtinnovations\Guardian\Backup\BackupManager;
use Vtinnovations\Guardian\Security\BackendAuthChecker;
use Vtinnovations\Guardian\Service\RegistrationPolicy;
use Vtinnovations\Guardian\Service\RegistrationState;

class BackupController
{
    use EntitlementResponses;
    use GuardianTranslations;

    public function __construct(
        private readonly BackupManager $backupManager,
        private readonly BackendAuthChecker $backendAuth,
        private readonly RegistrationPolicy $policy,
    ) {
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/backup/create',
        name: 'vtinnovations_guardian_backup_create',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function create(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->policy->allows(RegistrationState::CAP_BACKUP)) {
            return $this->entitlementDenied(RegistrationState::CAP_BACKUP);
        }

        @set_time_limit(900);

        $data       = json_decode((string) $request->getContent(), true) ?? [];
        $components = \is_array($data['components'] ?? null) ? $data['components'] : [];

        // Sanitize: only known component keys, cast to bool
        $clean = [];
        foreach ([
            BackupManager::COMPONENT_VENDOR,
            BackupManager::COMPONENT_TEMPLATES,
            BackupManager::COMPONENT_FILES,
            BackupManager::COMPONENT_ASSETS,
        ] as $key) {
            if (\array_key_exists($key, $components)) {
                $clean[$key] = (bool) $components[$key];
            }
        }

        try {
            $result = $this->backupManager->createFullBackup($clean);

            return new JsonResponse([
                'success'  => true,
                'name'     => $result['name'],
                'path'     => $result['path'],
                'manifest' => $result['manifest'],
                'log'      => $result['log'],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/backup/list',
        name: 'vtinnovations_guardian_backup_list',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function list(): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->policy->allows(RegistrationState::CAP_BACKUP)) {
            return $this->entitlementDenied(RegistrationState::CAP_BACKUP);
        }

        return new JsonResponse([
            'success' => true,
            'backups' => $this->backupManager->listBackups(),
        ]);
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/backup/delete',
        name: 'vtinnovations_guardian_backup_delete',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function delete(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->policy->allows(RegistrationState::CAP_BACKUP)) {
            return $this->entitlementDenied(RegistrationState::CAP_BACKUP);
        }

        $data = json_decode((string) $request->getContent(), true) ?? [];
        $name = (string) ($data['name'] ?? '');

        if ($name === '') {
            return new JsonResponse(['success' => false, 'error' => $this->msg('backup_name_missing')], 400);
        }

        $deleted = $this->backupManager->deleteBackup($name);

        return new JsonResponse([
            'success' => $deleted,
            'error'   => $deleted ? null : $this->msg('backup_not_found'),
        ]);
    }
}
