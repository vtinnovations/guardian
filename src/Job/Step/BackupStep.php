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
 * Step 1: Create a safety backup before any update operation.
 *
 * Always creates a Mini-style backup (composer + database only). Even in dry-run
 * mode we create a real backup — that's our safety net regardless of whether
 * the rest is simulated. Skipping the backup would be a bad default.
 */
class BackupStep implements StepInterface
{
    public function __construct(private readonly BackupManager $backupManager)
    {
    }

    public function name(): string
    {
        return 'backup';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        // For real updates we want a richer pre-snapshot than for dry-runs:
        // a dry-run doesn't change anything so a mini backup (composer+db) is
        // fine. A real update will overwrite composer + vendor + DB, so we
        // include vendor in the snapshot if the user didn't explicitly opt out.
        // This lets the rollback step put things back exactly as they were.
        $isRealUpdate = $job->type === UpdateJob::TYPE_UPDATE;
        $includeVendor = $isRealUpdate && (
            ($job->options['snapshot_vendor'] ?? true) === true
        );

        if ($isRealUpdate) {
            $log->step('backup', sprintf(
                'Creating pre-update snapshot (composer + database%s)...',
                $includeVendor ? ' + vendor' : ''
            ));
        } else {
            $log->step('backup', 'Creating safety backup (composer + database)...');
        }

        $components = [
            BackupManager::COMPONENT_VENDOR    => $includeVendor,
            BackupManager::COMPONENT_TEMPLATES => false,
            BackupManager::COMPONENT_FILES     => false,
            BackupManager::COMPONENT_ASSETS    => false,
        ];

        $result = $this->backupManager->createFullBackup($components);

        // Tag manifest so the recovery panel knows this was an auto-update-backup
        $manifestFile = $result['path'] . '/manifest.json';
        if (file_exists($manifestFile)) {
            $manifest = json_decode((string) @file_get_contents($manifestFile), true);
            if (\is_array($manifest)) {
                $manifest['schedule_type']    = 'pre-update';
                $manifest['triggered_by_job'] = $job->id;
                @file_put_contents($manifestFile, json_encode($manifest, \JSON_PRETTY_PRINT));
            }
        }

        // Remember the snapshot name on the job so the rollback flow knows
        // which backup to restore if anything later fails.
        if ($isRealUpdate) {
            $job->setPreSnapshotName($result['name']);
        }

        $log->info('backup', "Backup created: {$result['name']} ({$result['manifest']['total_size']})");

        foreach ($result['log'] as $line) {
            $log->info('backup', $line);
        }
    }
}
