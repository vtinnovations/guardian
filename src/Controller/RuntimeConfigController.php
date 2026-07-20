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
use Vtinnovations\Guardian\Service\RuntimeConfig;
use Vtinnovations\Guardian\Security\BackendAuthChecker;

/**
 * Backend endpoints for runtime configuration (PHP binary path etc).
 *
 * This mirrors what Contao Manager does on its "Server Settings" page: lets
 * the user manually set the PHP CLI binary path if auto-detection fails.
 */
class RuntimeConfigController
{
    public function __construct(private readonly RuntimeConfig $config, private readonly BackendAuthChecker $backendAuth)
    {
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/runtime/get',
        name: 'vtinnovations_guardian_runtime_get',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function get(): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        return new JsonResponse([
            'success'    => true,
            'config'     => $this->config->load(),
            'candidates' => $this->config->suggestCandidates(),
        ]);
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/runtime/save',
        name: 'vtinnovations_guardian_runtime_save',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function save(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        $data = json_decode((string) $request->getContent(), true) ?? [];
        if (!\is_array($data)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        // Test before saving — refuse to save a broken binary
        if (!empty($data['php_binary'])) {
            $test = $this->config->testBinary((string) $data['php_binary']);
            if (!$test['ok']) {
                return new JsonResponse([
                    'success' => false,
                    'error'   => 'PHP binary check failed: ' . ($test['error'] ?? 'unknown'),
                ], 422);
            }
        }

        // Lightweight check on composer_phar: it should be an absolute path
        // ending in .phar. We don't strictly require the file to exist (it
        // may be unreachable from FPM's open_basedir but reachable from the
        // worker — that's the whole point of this override).
        if (!empty($data['composer_phar'])) {
            $cp = (string) $data['composer_phar'];
            if (!str_starts_with($cp, '/')) {
                return new JsonResponse([
                    'success' => false,
                    'error'   => 'Composer phar must be an absolute path (start with /)',
                ], 422);
            }
            if (!str_ends_with($cp, '.phar') && !str_ends_with($cp, '/composer')) {
                return new JsonResponse([
                    'success' => false,
                    'error'   => 'Composer phar should be a .phar file',
                ], 422);
            }
        }

        $saved = $this->config->save($data);

        return new JsonResponse([
            'success' => true,
            'config'  => $saved,
        ]);
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/runtime/test',
        name: 'vtinnovations_guardian_runtime_test',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function test(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        $data = json_decode((string) $request->getContent(), true) ?? [];
        $path = (string) ($data['php_binary'] ?? '');

        return new JsonResponse([
            'success' => true,
            'result'  => $this->config->testBinary($path),
        ]);
    }

    /**
     * Sends a test recovery email to verify the configured recipient + mailer
     * are working before the admin relies on the feature for a real update.
     */
    #[Route(
        '%contao.backend.route_prefix%/updater/runtime/test-recovery-email',
        name: 'vtinnovations_guardian_runtime_test_recovery_email',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function testRecoveryEmail(Request $request, \Vtinnovations\Guardian\Notifier\RecoveryEmailNotifier $notifier): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        $data     = json_decode((string) $request->getContent(), true) ?? [];
        $override = isset($data['recipient']) && is_string($data['recipient']) ? trim($data['recipient']) : null;

        $result = $notifier->sendTestEmail($override);

        return new JsonResponse([
            'success' => $result['success'],
            'result'  => $result,
        ], $result['success'] ? 200 : 422);
    }
}
