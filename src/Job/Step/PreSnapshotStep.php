<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Job\Step;

use Vtinnovations\Guardian\Backup\BackupManager;
use Vtinnovations\Guardian\Job\JobLog;
use Vtinnovations\Guardian\Job\UpdateJob;

/**
 * Step run before a restore: creates a mini-backup of the CURRENT state so
 * the user has an "undo" path if the restore turns out to be wrong.
 *
 * Marked in the manifest as schedule_type=pre-restore so it's distinguishable
 * from regular and update-pre backups.
 */
class PreSnapshotStep implements StepInterface
{
    public function __construct(private readonly BackupManager $backupManager)
    {
    }

    public function name(): string
    {
        return 'pre_snapshot';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        $log->step('pre_snapshot', 'Creating safety snapshot of current state before restore...');

        // Mini snapshot: composer + db only (cheap, fast, gives full recoverability)
        $components = [
            BackupManager::COMPONENT_VENDOR    => false,
            BackupManager::COMPONENT_TEMPLATES => false,
            BackupManager::COMPONENT_FILES     => false,
            BackupManager::COMPONENT_ASSETS    => false,
        ];

        $result = $this->backupManager->createFullBackup($components);

        // Tag manifest so the user can find this snapshot later
        $manifestFile = $result['path'] . '/manifest.json';
        if (file_exists($manifestFile)) {
            $manifest = json_decode((string) @file_get_contents($manifestFile), true);
            if (\is_array($manifest)) {
                $manifest['schedule_type']      = 'pre-restore';
                $manifest['triggered_by_job']   = $job->id;
                $manifest['restoring_backup']   = $job->options['backup_name'] ?? null;
                @file_put_contents($manifestFile, json_encode($manifest, \JSON_PRETTY_PRINT));
            }
        }

        $log->info('pre_snapshot', "Pre-restore snapshot created: {$result['name']} ({$result['manifest']['total_size']})");
        $log->info('pre_snapshot', "If the restore goes wrong, this snapshot can bring you back to the state right before it.");
    }
}
