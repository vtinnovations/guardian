<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Schedule;

use Vtinnovations\Guardian\Backup\BackupManager;
use Vtinnovations\Guardian\Notifier\BackupNotifier;
use Vtinnovations\Guardian\Service\SystemLogger;

/**
 * Orchestrates the scheduled backup process.
 *
 * Called by:
 *   - the cron job (BackupCron)
 *   - the manual "Run now" button in the UI (BackupController)
 *
 * Steps for each type (mini/full):
 *   1. Check if schedule is enabled and currently due
 *   2. Acquire global backup lock (so two runs can't overlap)
 *   3. Determine effective components (mini = none, full = configured)
 *   4. Run BackupManager::createFullBackup()
 *   5. Apply retention (delete oldest beyond limit)
 *   6. Record state + send notification
 */
class ScheduledBackupRunner
{
    public const TYPE_MINI = 'mini';
    public const TYPE_FULL = 'full';

    public function __construct(
        private readonly ScheduleConfig $config,
        private readonly ScheduleState $state,
        private readonly ScheduleEvaluator $evaluator,
        private readonly BackupLock $lock,
        private readonly BackupManager $backupManager,
        private readonly BackupNotifier $notifier,
        private readonly SystemLogger $sysLog,
    ) {
    }

    /**
     * Runs all schedules that are currently due. Returns a per-type result.
     *
     * @return array<string, array{ran: bool, status: string, message: string, backup?: string}>
     */
    public function runAllDue(): array
    {
        $cfg   = $this->config->load();
        $state = $this->state->load();

        $results = [];
        foreach ([self::TYPE_MINI, self::TYPE_FULL] as $type) {
            if (!$this->evaluator->isDue($cfg[$type], $state[$type] ?? null)) {
                $results[$type] = [
                    'ran'     => false,
                    'status'  => 'skipped',
                    'message' => 'Not due',
                ];
                continue;
            }

            $results[$type] = $this->runOne($type, $cfg);
        }

        return $results;
    }

    /**
     * Forces a run of one specific type, ignoring the schedule.
     * Used by the "Run now" UI button.
     */
    public function forceRun(string $type): array
    {
        $cfg = $this->config->load();
        return $this->runOne($type, $cfg);
    }

    private function runOne(string $type, array $cfg): array
    {
        if (!$this->lock->acquire()) {
            $msg = 'Could not acquire lock — another backup is already running';
            $this->state->recordRun($type, 'skipped', $msg);
            $this->sysLog->warning(sprintf('Scheduled %s backup skipped: %s', $type, $msg));
            return [
                'ran'     => false,
                'status'  => 'skipped',
                'message' => $msg,
            ];
        }

        // Mark as in-progress so dashboard can show a spinner
        $this->state->markStarted($type);

        try {
            // Apply configured storage path (if any)
            if (!empty($cfg['storage_path'])) {
                $this->backupManager->setStorageDir((string) $cfg['storage_path']);
            }

            $components = $this->effectiveComponents($type, $cfg);
            $result     = $this->backupManager->createFullBackup($components);

            // Tag the backup folder with its type so retention knows what it is
            $this->writeTypeMarker($result['path'], $type);

            // Apply retention
            $deleted = $this->applyRetention($type, $cfg[$type]['retention'] ?? 7);

            $this->state->recordRun(
                $type,
                'success',
                sprintf('Backup created: %s%s', $result['name'],
                    $deleted > 0 ? sprintf(' (retention: %d old backup(s) deleted)', $deleted) : ''
                ),
                $result['name']
            );

            $this->sysLog->info(sprintf(
                'Scheduled %s backup completed: %s (size: %s)%s',
                $type,
                $result['name'],
                $result['manifest']['total_size'] ?? '?',
                $deleted > 0 ? sprintf(' — retention deleted %d old backup(s)', $deleted) : ''
            ));

            $this->notifier->notifySuccess($type, $result['manifest'], $result['log']);

            return [
                'ran'     => true,
                'status'  => 'success',
                'message' => 'Backup created: ' . $result['name'],
                'backup'  => $result['name'],
            ];
        } catch (\Throwable $e) {
            $this->state->recordRun($type, 'error', $e->getMessage());
            $this->sysLog->error(sprintf(
                'Scheduled %s backup FAILED: %s',
                $type,
                $e->getMessage()
            ));
            $this->notifier->notifyFailure($type, $e->getMessage());

            return [
                'ran'     => true,
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Mini backup = composer + DB only (all directory components disabled).
     * Full backup = whatever the user configured.
     */
    private function effectiveComponents(string $type, array $cfg): array
    {
        if ($type === self::TYPE_MINI) {
            return [
                BackupManager::COMPONENT_VENDOR    => false,
                BackupManager::COMPONENT_TEMPLATES => false,
                BackupManager::COMPONENT_FILES     => false,
                BackupManager::COMPONENT_ASSETS    => false,
            ];
        }

        return $cfg['full']['components'] ?? BackupManager::defaultComponents();
    }

    private function writeTypeMarker(string $backupPath, string $type): void
    {
        $manifestFile = $backupPath . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return;
        }

        $data = json_decode((string) @file_get_contents($manifestFile), true);
        if (!\is_array($data)) {
            return;
        }

        $data['schedule_type'] = $type;

        @file_put_contents(
            $manifestFile,
            json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Deletes oldest backups of this type once retention limit is exceeded.
     * Returns count of deleted backups.
     */
    private function applyRetention(string $type, int $keepCount): int
    {
        $allBackups = $this->backupManager->listBackups();

        // Filter to this type only, sorted newest first (listBackups already does this)
        $sameType = array_filter($allBackups, function ($b) use ($type) {
            $manifestFile = $b['path'] . '/manifest.json';
            if (!file_exists($manifestFile)) {
                return false;
            }
            $data = json_decode((string) @file_get_contents($manifestFile), true);
            return \is_array($data) && (($data['schedule_type'] ?? null) === $type);
        });

        $sameType = array_values($sameType);

        if (\count($sameType) <= $keepCount) {
            return 0;
        }

        $toDelete = \array_slice($sameType, $keepCount);
        $deleted  = 0;

        foreach ($toDelete as $b) {
            if ($this->backupManager->deleteBackup($b['name'])) {
                ++$deleted;
            }
        }

        return $deleted;
    }
}
