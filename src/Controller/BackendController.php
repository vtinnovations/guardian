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

use Contao\CoreBundle\Controller\AbstractBackendController;
use Contao\System;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Vtinnovations\Guardian\Backup\BackupManager;
use Vtinnovations\Guardian\Security\BackendAuthChecker;
use Vtinnovations\Guardian\Service\RegistrationPolicy;
use Vtinnovations\Guardian\Service\RegistrationState;
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
        private readonly RegistrationPolicy $policy,
        private readonly UrlGeneratorInterface $router,
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
            'is_pro'          => $this->policy->allows(RegistrationState::CAP_UPDATES),
            'is_licensed'     => $this->policy->allows(RegistrationState::CAP_BACKUP),
            // Licence management lives in Contao → Settings; this module only
            // links to it.
            'settings_url'    => $this->router->generate('contao_backend', ['do' => 'settings']),
            'i18n'            => $this->translations(),
        ]);
    }

    /**
     * The same per-locale strings both the server-rendered HTML (via the
     * `trans` filter) and the client-side JavaScript (via a JSON-encoded
     * object) draw from, so the two can never drift out of sync with each
     * other.
     */
    private function translations(): array
    {
        System::loadLanguageFile('guardian');

        $sections = ['tabs', 'dashboard', 'backup', 'sched', 'cron', 'update', 'job', 'settings', 'recovery', 'upgrade', 'msc'];

        return array_intersect_key($GLOBALS['TL_LANG'], array_flip($sections));
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
