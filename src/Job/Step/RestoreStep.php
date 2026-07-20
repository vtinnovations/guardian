<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Job\Step;

use Vtinnovations\Guardian\Job\JobLog;
use Vtinnovations\Guardian\Job\UpdateJob;
use Vtinnovations\Guardian\Restore\RestoreManager;

/**
 * The actual restore step. Reads backup_name + components from job options
 * and delegates to RestoreManager.
 */
class RestoreStep implements StepInterface
{
    public function __construct(private readonly RestoreManager $restoreManager)
    {
    }

    public function name(): string
    {
        return 'restore';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        $backupName = (string) ($job->options['backup_name'] ?? '');
        $components = $job->options['components'] ?? [];

        if ($backupName === '') {
            throw new \RuntimeException('No backup_name in job options');
        }
        if (!\is_array($components)) {
            throw new \RuntimeException('components must be an array');
        }

        $log->step('restore', "Restoring from {$backupName}");
        $this->restoreManager->restore($backupName, $components, $log);
    }
}
