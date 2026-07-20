<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Controller;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vtinnovations\Guardian\Backup\BackupManager;
use Vtinnovations\Guardian\Security\BackendAuthChecker;
use Vtinnovations\Guardian\Security\LicenseGuard;
use Vtinnovations\Guardian\Service\StatusManager;

#[Route(
    '%contao.backend.route_prefix%/updater',
    name: self::class,
    defaults: ['_scope' => 'backend']
)]
class BackendController extends AbstractBackendController
{
    public function __construct(
        private readonly StatusManager $statusManager,
        private readonly BackupManager $backupManager,
        private readonly BackendAuthChecker $backendAuth,
        private readonly LicenseGuard $license,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(): Response
    {
        $this->backendAuth->assertAdmin();

        $installed = $this->loadInstalledPackages();

        return $this->render('@Guardian/backend/vtinnovations_guardian.html.twig', [
            'title'           => 'Guardian',
            'headline'        => 'Guardian',
            'status'          => $this->statusManager->getStatus(),
            'is_running'      => $this->statusManager->isRunning(),
            'backups'         => $this->backupManager->listBackups(),
            'current_version' => $this->getContaoVersion($installed),
            'package_count'   => \count($installed),
            'project_dir'     => $this->projectDir,
            'is_pro'          => $this->license->isPro(),
            'is_licensed'     => $this->license->isLicensed(),
        ]);
    }

    private function loadInstalledPackages(): array
    {
        $installedJson = $this->projectDir . '/vendor/composer/installed.json';

        if (!file_exists($installedJson)) {
            return [];
        }

        $content = @file_get_contents($installedJson);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return [];
        }

        $packages = $data['packages'] ?? $data;

        return \is_array($packages) ? $packages : [];
    }

    private function getContaoVersion(array $packages): string
    {
        foreach ($packages as $pkg) {
            if (($pkg['name'] ?? '') === 'contao/core-bundle') {
                $version = $pkg['version'] ?? $pkg['version_normalized'] ?? '';
                if ($version !== '') {
                    return ltrim((string) $version, 'v');
                }
            }
        }

        return 'unknown';
    }
}
