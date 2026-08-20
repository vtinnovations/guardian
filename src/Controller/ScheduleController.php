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
use Vtinnovations\Guardian\Notifier\BackupNotifier;
use Vtinnovations\Guardian\Schedule\ScheduleConfig;
use Vtinnovations\Guardian\Schedule\ScheduledBackupRunner;
use Vtinnovations\Guardian\Schedule\ScheduleEvaluator;
use Vtinnovations\Guardian\Schedule\ScheduleState;
use Vtinnovations\Guardian\Security\BackendAuthChecker;
use Vtinnovations\Guardian\Service\RegistrationPolicy;
use Vtinnovations\Guardian\Service\RegistrationState;

class ScheduleController
{
    use EntitlementResponses;
    use GuardianTranslations;

    public function __construct(
        private readonly ScheduleConfig $config,
        private readonly ScheduleState $state,
        private readonly ScheduleEvaluator $evaluator,
        private readonly ScheduledBackupRunner $runner,
        private readonly BackupNotifier $notifier,
        private readonly BackendAuthChecker $backendAuth,
        private readonly RegistrationPolicy $policy,
    ) {
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/schedule/get',
        name: 'vtinnovations_guardian_schedule_get',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function get(): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->policy->allows(RegistrationState::CAP_SCHEDULE)) {
            return $this->entitlementDenied(RegistrationState::CAP_SCHEDULE);
        }

        return new JsonResponse([
            'success' => true,
            'config'  => $this->config->load(),
            'state'   => $this->buildStateInfo(),
        ]);
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/schedule/save',
        name: 'vtinnovations_guardian_schedule_save',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function save(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->policy->allows(RegistrationState::CAP_SCHEDULE)) {
            return $this->entitlementDenied(RegistrationState::CAP_SCHEDULE);
        }

        $data = json_decode((string) $request->getContent(), true) ?? [];

        if (!\is_array($data)) {
            return new JsonResponse(['success' => false, 'error' => $this->msg('invalid_payload')], 400);
        }

        // Validate the storage path BEFORE saving so the user gets feedback
        $pathCheck = $this->config->validateStoragePath($data['storage_path'] ?? null);

        if (!$pathCheck['ok']) {
            return new JsonResponse([
                'success'      => false,
                'error'        => $this->msg('storage_path_invalid', ['%errors%' => implode(' ', $pathCheck['errors'])]),
                'path_warnings'=> $pathCheck['warnings'],
                'path_errors'  => $pathCheck['errors'],
            ], 422);
        }

        $saved = $this->config->save($data);

        return new JsonResponse([
            'success'       => true,
            'config'        => $saved,
            'state'         => $this->buildStateInfo(),
            'path_warnings' => $pathCheck['warnings'],
            'resolved_path' => $pathCheck['resolved_path'],
        ]);
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/schedule/run',
        name: 'vtinnovations_guardian_schedule_run',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function runNow(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->policy->allows(RegistrationState::CAP_SCHEDULE)) {
            return $this->entitlementDenied(RegistrationState::CAP_SCHEDULE);
        }

        @set_time_limit(900);

        $data = json_decode((string) $request->getContent(), true) ?? [];
        $type = (string) ($data['type'] ?? '');

        if (!\in_array($type, [ScheduledBackupRunner::TYPE_MINI, ScheduledBackupRunner::TYPE_FULL], true)) {
            return new JsonResponse(['success' => false, 'error' => $this->msg('invalid_backup_type')], 400);
        }

        try {
            $result = $this->runner->forceRun($type);

            return new JsonResponse([
                'success' => $result['status'] === 'success',
                'result'  => $result,
                'state'   => $this->buildStateInfo(),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[Route(
        '%contao.backend.route_prefix%/updater/schedule/test-email',
        name: 'vtinnovations_guardian_schedule_test_email',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function testEmail(): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->policy->allows(RegistrationState::CAP_SCHEDULE)) {
            return $this->entitlementDenied(RegistrationState::CAP_SCHEDULE);
        }

        $result = $this->notifier->sendTestEmail();

        return new JsonResponse([
            'success' => $result['success'] ?? false,
            'result'  => $result,
        ]);
    }

    private function buildStateInfo(): array
    {
        $cfg   = $this->config->load();
        $state = $this->state->load();

        $info = [];
        foreach (['mini', 'full'] as $type) {
            $next = $this->evaluator->nextOccurrence($cfg[$type], null, $state[$type] ?? null);

            $info[$type] = [
                'last_run'       => $state[$type]['last_run']     ?? null,
                'last_status'    => $state[$type]['last_status']  ?? null,
                'last_message'   => $state[$type]['last_message'] ?? null,
                'last_backup'    => $state[$type]['last_backup']  ?? null,
                'in_progress'    => (bool) ($state[$type]['in_progress'] ?? false),
                'started_at'     => $state[$type]['started_at']   ?? null,
                'next_run'       => $next?->format('c'),
                'next_run_human' => $next ? $next->format('Y-m-d H:i') : null,
                'enabled'        => (bool) $cfg[$type]['enabled'],
            ];
        }

        return $info;
    }
}
