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
use Vtinnovations\Guardian\Backup\BackupManager;
use Vtinnovations\Guardian\Security\BackendAuthChecker;
use Vtinnovations\Guardian\Security\LicenseGuard;

class BackupController
{
    public function __construct(
        private readonly BackupManager $backupManager,
        private readonly BackendAuthChecker $backendAuth,
        private readonly LicenseGuard $license,
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
        if (!$this->license->isLicensed()) {
            return $this->license->deniedNoLicenseResponse();
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
        if (!$this->license->isLicensed()) {
            return $this->license->deniedNoLicenseResponse();
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
        if (!$this->license->isLicensed()) {
            return $this->license->deniedNoLicenseResponse();
        }

        $data = json_decode((string) $request->getContent(), true) ?? [];
        $name = (string) ($data['name'] ?? '');

        if ($name === '') {
            return new JsonResponse(['success' => false, 'error' => 'Missing backup name'], 400);
        }

        $deleted = $this->backupManager->deleteBackup($name);

        return new JsonResponse([
            'success' => $deleted,
            'error'   => $deleted ? null : 'Backup not found or could not be deleted',
        ]);
    }
}
