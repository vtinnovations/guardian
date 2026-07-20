<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Job;

use Vtinnovations\Guardian\Job\Step\StepInterface;
use Vtinnovations\Guardian\Service\SystemLogger;

/**
 * Executes the configured steps of an UpdateJob in order.
 *
 * Called by the CLI command (RunJobCommand). Updates job status as it goes,
 * persisting after each step boundary so the backend UI can poll for progress.
 *
 * Stops at the first failed step. The job is marked FAILED with the
 * exception message attached.
 */
class JobRunner
{
    /**
     * @param array<string, StepInterface> $steps  Map of step-name => StepInterface
     */
    public function __construct(
        private readonly UpdateJobManager $manager,
        private readonly JobLog $log,
        private readonly SystemLogger $sysLog,
        private readonly array $steps,
    ) {
    }

    public function run(UpdateJob $job): void
    {
        $job->status    = UpdateJob::STATUS_RUNNING;
        $job->startedAt = date('c');
        $job->workerPid = getmypid() ?: null;
        $this->manager->saveJob($job);

        $this->log->info('runner', "Job {$job->id} starting (type: {$job->type})");
        $this->sysLog->info(sprintf('Update job started: %s (type: %s)', $job->id, $job->type));

        // Steps marked as "cleanup" run even after a failure, so that things
        // like maintenance mode get turned back off no matter what happened.
        // Only maintenance_off qualifies right now — it must always run if
        // an earlier step turned maintenance ON.
        $cleanupStepNames = ['maintenance_off'];

        $failure    = null;
        $failedStep = null;  // Name of the step that actually triggered the failure
        $maintenanceWasEnabled = false;

        try {
            foreach ($job->steps as $stepName) {
                if (!isset($this->steps[$stepName])) {
                    throw new \RuntimeException("Unknown step: {$stepName}");
                }

                // If we already failed earlier, skip non-cleanup steps
                if ($failure !== null && !\in_array($stepName, $cleanupStepNames, true)) {
                    $this->log->info('runner', "Skipping step '{$stepName}' due to earlier failure");
                    continue;
                }

                $job->currentStep = $stepName;
                $this->manager->saveJob($job);

                $this->log->step($stepName, "▶ Starting step: {$stepName}");
                $this->sysLog->info(sprintf('Job %s: starting step %s', $job->id, $stepName));

                try {
                    $start = microtime(true);
                    $this->steps[$stepName]->execute($job, $this->log);
                    $duration = round(microtime(true) - $start, 1);

                    $this->log->step($stepName, "✓ Step {$stepName} finished in {$duration}s");
                    $this->sysLog->info(sprintf('Job %s: step %s completed in %ss', $job->id, $stepName, $duration));

                    if ($stepName === 'maintenance_on') {
                        $maintenanceWasEnabled = true;
                    }
                } catch (\Throwable $stepError) {
                    if ($failure === null) {
                        // First failure — record it, but continue iterating
                        // so cleanup steps still get a chance.
                        $failure     = $stepError;
                        $failedStep  = $stepName;  // remember which step actually broke
                        $this->log->error('runner', "❌ Step {$stepName} failed: {$stepError->getMessage()}");
                    } else {
                        // Cleanup step also failed. Log but don't replace
                        // the original failure (that's what the user needs to see).
                        $this->log->warning(
                            'runner',
                            "Cleanup step '{$stepName}' failed too: {$stepError->getMessage()}"
                        );
                    }
                }
            }

            if ($failure !== null) {
                throw $failure;
            }

            $job->status      = UpdateJob::STATUS_SUCCEEDED;
            $job->currentStep = null;
            $job->finishedAt  = date('c');
            $this->manager->saveJob($job);

            // Clean up the constraint-bump backup file — kept around only for
            // failure rollback. Successful update means the new constraints are
            // committed and we don't need the .bak.updater file anymore.
            if (!empty($job->options['constraint_bump_backup'])) {
                $backup = (string) $job->options['constraint_bump_backup'];
                if (file_exists($backup)) {
                    @unlink($backup);
                }
            }

            $this->log->info('runner', "✅ Job {$job->id} completed successfully");
            $this->sysLog->info(sprintf('Update job %s completed successfully (type: %s)', $job->id, $job->type));
            $this->manager->archiveJob($job);
        } catch (\Throwable $e) {
            $job->status     = UpdateJob::STATUS_FAILED;
            $job->finishedAt = date('c');
            $job->error      = $e->getMessage();

            // Pin the step name where the actual failure happened. $job->currentStep
            // may have advanced to a cleanup step (e.g. maintenance_off) after the
            // real failure, which would mis-attribute the error to the cleanup step.
            // We use $failedStep which was captured at the moment the first
            // exception was thrown, before cleanup steps ran.
            $attributedStep = $failedStep ?? $job->currentStep ?? '(unknown)';
            $job->failedStep = $attributedStep;

            $this->manager->saveJob($job);

            $this->log->error('runner', "❌ Job {$job->id} failed at step {$attributedStep}: {$e->getMessage()}");
            $this->sysLog->error(sprintf(
                'Update job %s FAILED at step %s: %s',
                $job->id,
                $attributedStep,
                $e->getMessage()
            ));

            if ($e->getPrevious() !== null) {
                $this->log->error('runner', '  Caused by: ' . $e->getPrevious()->getMessage());
            }

            // Major-update specific cleanup: if we bumped composer.json constraints
            // but didn't reach a successful composer step, restore the original
            // composer.json from our backup. Otherwise the admin is left with
            // an edited composer.json AND a failed install — they'd see
            // mismatches everywhere on the next manual run.
            if (!empty($job->options['constraint_bump_applied'])) {
                $this->rollBackComposerJson($job);
            }

            // For real-update failures, log a clear hint that the user can
            // roll back to the pre-snapshot from the backend UI.
            if ($job->type === UpdateJob::TYPE_UPDATE) {
                $snapshot = $job->getPreSnapshotName();
                if ($snapshot !== null) {
                    $this->log->info('runner',
                        "💡 A pre-update snapshot is available: {$snapshot}. "
                      . 'Open the backend → Updater → Recovery section to roll back.'
                    );
                }
            }

            if ($maintenanceWasEnabled) {
                $this->log->info('runner', '(maintenance mode was active — cleanup attempted above)');
            }

            $this->manager->archiveJob($job);

            throw $e;
        }
    }

    /**
     * Restores the composer.json backup created by ConstraintBumpStep.
     * Called on job failure when constraint changes have been applied but
     * the rest of the update didn't succeed.
     */
    private function rollBackComposerJson(UpdateJob $job): void
    {
        $backup = (string) ($job->options['constraint_bump_backup'] ?? '');
        if ($backup === '' || !file_exists($backup)) {
            $this->log->warning('runner', 'Constraint bump backup not found — composer.json may be in an edited state.');
            return;
        }

        // composer.json is two levels up from a typical backup location
        // (we stored the path explicitly so we can use it directly)
        $composerJson = str_replace('.bak.updater', '', $backup);
        if ($composerJson === $backup || !str_ends_with($composerJson, 'composer.json')) {
            $this->log->warning('runner', 'Could not determine composer.json path from backup file name.');
            return;
        }

        $content = @file_get_contents($backup);
        if ($content === false) {
            $this->log->error('runner', 'Could not read composer.json backup at: ' . $backup);
            return;
        }

        if (@file_put_contents($composerJson, $content) === false) {
            $this->log->error('runner', 'Could not restore composer.json from backup at: ' . $backup);
            return;
        }

        $this->log->info('runner', '↩️  composer.json restored from backup — constraint changes reverted');
        @unlink($backup);
    }
}
