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
use Vtinnovations\Guardian\Job\JobLog;
use Vtinnovations\Guardian\Job\UpdateJob;
use Vtinnovations\Guardian\Job\UpdateJobManager;
use Vtinnovations\Guardian\Notifier\RecoveryEmailNotifier;
use Vtinnovations\Guardian\Security\BackendAuthChecker;
use Vtinnovations\Guardian\Security\LicenseGuard;

class JobController
{
    public function __construct(
        private readonly UpdateJobManager $manager,
        private readonly JobLog $log,
        private readonly BackendAuthChecker $backendAuth,
        private readonly RecoveryEmailNotifier $recoveryEmail,
        private readonly LicenseGuard $license,
    ) {
    }

    /**
     * Rolls back a failed update job by restoring its pre-snapshot.
     *
     * Body: { job_id: "20260513-..." }
     *
     * Looks up the failed job in the archive, reads its pre_snapshot_name,
     * and queues a new restore job that restores all components of that
     * snapshot. The user is shown progress through the same job UI as the
     * original update.
     */
    #[Route(
        '%contao.backend.route_prefix%/updater/job/rollback',
        name: 'vtinnovations_guardian_job_rollback',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function rollback(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->license->isPro()) {
            return $this->license->deniedResponse();
        }

        $data  = json_decode((string) $request->getContent(), true) ?? [];
        $jobId = (string) ($data['job_id'] ?? '');

        if ($jobId === '') {
            return new JsonResponse(['success' => false, 'error' => 'job_id missing'], 400);
        }

        // Look up the failed job to find its pre-snapshot name
        $archived = $this->manager->getArchivedJob($jobId);
        if ($archived === null) {
            return new JsonResponse([
                'success' => false,
                'error'   => 'Job not found in archive: ' . $jobId,
            ], 404);
        }

        $snapshotName = $archived->getPreSnapshotName();
        if ($snapshotName === null) {
            return new JsonResponse([
                'success' => false,
                'error'   => 'No pre-snapshot associated with this job. Manual restore required.',
            ], 400);
        }

        // Restore ALL components of the snapshot — the user explicitly asked
        // to roll back, so we revert as completely as possible.
        $components = [
            'composer' => true,
            'database' => true,
            'vendor'   => true,
            'templates' => true,
            'files'    => true,
            'assets'   => true,
        ];

        try {
            $job = UpdateJob::newRestore(
                $snapshotName,
                $components,
                [
                    'maintenance'   => true,
                    'pre_snapshot'  => false, // we ARE the snapshot, no nested snapshot
                    'rollback_of'   => $jobId, // metadata: which job triggered this
                ]
            );
            $this->manager->startJob($job);

            return new JsonResponse([
                'success' => true,
                'job'     => $job->toArray(),
                'message' => 'Rollback started from snapshot ' . $snapshotName,
            ]);
        } catch (\Vtinnovations\Guardian\Job\Exception\JobBlockedException $blocked) {
            return new JsonResponse([
                'success'      => false,
                'error'        => $blocked->getMessage(),
                'reason'       => 'blocked',
                'blocking_job' => $blocked->blockingJob->toArray(),
            ], 409);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Starts a new job. Body: {type: "dry_run"|"update"}.
     * Returns the queued job. The actual worker runs detached.
     */
    #[Route(
        '%contao.backend.route_prefix%/updater/job/start',
        name: 'vtinnovations_guardian_job_start',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function start(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();
        if (!$this->license->isPro()) {
            return $this->license->deniedResponse();
        }

        $data = json_decode((string) $request->getContent(), true) ?? [];
        $type = (string) ($data['type'] ?? '');
        $options = $data['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }

        // Extract email-sending flag so it doesn't get persisted in job options
        $sendEmail = !empty($options['send_recovery_email']);
        unset($options['send_recovery_email']);

        try {
            $job = match ($type) {
                UpdateJob::TYPE_DRY_RUN => UpdateJob::newDryRun(),
                UpdateJob::TYPE_UPDATE  => UpdateJob::newUpdate($options),
                default => throw new \InvalidArgumentException("Unknown job type: {$type}"),
            };

            // If the user requested a pre-update recovery email, send it BEFORE
            // spawning the worker. We refuse to start the job if sending fails —
            // the whole point is to have the recovery info in the inbox before
            // anything risky happens.
            if ($sendEmail && $type === UpdateJob::TYPE_UPDATE) {
                $mode = $job->getUpdateMode();
                $emailResult = $this->recoveryEmail->sendPreUpdateEmail(
                    'real-update',
                    $job->id,
                    $mode
                );
                if (!$emailResult['success']) {
                    return new JsonResponse([
                        'success' => false,
                        'reason'  => 'email_failed',
                        'error'   => 'Recovery email could not be sent: ' . ($emailResult['error'] ?? 'unknown error') . '. '
                                   . 'Disable the email checkbox or fix the mail configuration before starting the update.',
                        'hint'    => $emailResult['hint'] ?? null,
                    ], 422);
                }
            }

            $this->manager->startJob($job);

            return new JsonResponse([
                'success'         => true,
                'job'             => $job->toArray(),
                'email_sent_to'   => $sendEmail ? ($this->recoveryEmail->getConfiguredRecipient() ?? null) : null,
            ]);
        } catch (\Vtinnovations\Guardian\Job\Exception\JobBlockedException $blocked) {
            return new JsonResponse([
                'success'      => false,
                'error'        => $blocked->getMessage(),
                'reason'       => 'blocked',
                'blocking_job' => $blocked->blockingJob->toArray(),
                'is_blocked'   => true,
                'can_clear'    => $this->manager->isJobStale($blocked->blockingJob),
                'stale_reason' => $this->manager->getStaleReason($blocked->blockingJob),
            ], 409);
        } catch (\Vtinnovations\Guardian\Job\Exception\WorkerSpawnException $spawnFailed) {
            return new JsonResponse([
                'success'    => false,
                'error'      => $spawnFailed->getMessage(),
                'reason'     => 'worker_spawn_failed',
                'failed_job' => $spawnFailed->failedJob->toArray(),
            ], 500);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
                'reason'  => 'unknown',
            ], 500);
        }
    }

    /**
     * Returns the current job's status (or null if none).
     */
    #[Route(
        '%contao.backend.route_prefix%/updater/job/status',
        name: 'vtinnovations_guardian_job_status',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function status(): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        $current = $this->manager->getCurrentJob();

        $response = [
            'success' => true,
            'job'     => $current?->toArray(),
        ];

        if ($current !== null && !$current->isFinished()) {
            $response['is_stale']     = $this->manager->isJobStale($current);
            $response['stale_reason'] = $this->manager->getStaleReason($current);
        }

        return new JsonResponse($response);
    }

    /**
     * Returns recent log entries since a given byte offset.
     * The frontend persists the offset between polls, so each poll only fetches
     * the new lines (no need to re-render the whole log).
     */
    #[Route(
        '%contao.backend.route_prefix%/updater/job/log',
        name: 'vtinnovations_guardian_job_log',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function logSince(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        $data   = json_decode((string) $request->getContent(), true) ?? [];
        $offset = max(0, (int) ($data['offset'] ?? 0));

        $result = $this->log->readSince($offset);

        return new JsonResponse([
            'success' => true,
            'entries' => $result['entries'],
            'offset'  => $result['offset'],
        ]);
    }

    /**
     * Lists the archive of completed jobs.
     */
    #[Route(
        '%contao.backend.route_prefix%/updater/job/archive',
        name: 'vtinnovations_guardian_job_archive',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function archive(): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        return new JsonResponse([
            'success' => true,
            'jobs'    => $this->manager->listArchive(20),
        ]);
    }

    /**
     * Clears a stale active job so a new one can be started.
     * Only allowed if the job is detected as stale (worker crashed / never spawned).
     */
    #[Route(
        '%contao.backend.route_prefix%/updater/job/clear-stale',
        name: 'vtinnovations_guardian_job_clear_stale',
        defaults: ['_scope' => 'backend'],
        methods: ['POST']
    )]
    public function clearStale(Request $request): JsonResponse
    {
        $this->backendAuth->assertAdmin();

        $data  = json_decode((string) $request->getContent(), true) ?? [];
        $force = (bool) ($data['force'] ?? false);

        $current = $this->manager->getCurrentJob();
        if ($current === null) {
            return new JsonResponse(['success' => true, 'message' => 'No active job']);
        }

        $isStale = $this->manager->isJobStale($current);

        if (!$isStale && !$force) {
            return new JsonResponse([
                'success'  => false,
                'error'    => 'Job is not detected as stale yet. Pass force=true to abort it anyway.',
                'is_stale' => false,
                'job'      => $current->toArray(),
            ], 400);
        }

        $reason = $force && !$isStale
            ? 'Force-cleared from backend (user-initiated abort)'
            : 'Cleared as stale from backend';

        $this->manager->markCancelled($current, $reason);
        $this->manager->archiveJob($current);

        return new JsonResponse([
            'success'     => true,
            'cleared_job' => $current->id,
            'was_forced'  => $force && !$isStale,
        ]);
    }
}
