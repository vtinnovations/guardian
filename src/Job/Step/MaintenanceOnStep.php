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

class MaintenanceOnStep implements StepInterface
{
    public function __construct(private readonly RestoreManager $restoreManager)
    {
    }

    public function name(): string
    {
        return 'maintenance_on';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        $log->step('maintenance_on', 'Enabling maintenance mode...');

        if (!$this->restoreManager->setMaintenanceMode(true, $log)) {
            // Don't fail the entire restore if we can't enable maintenance —
            // it's a courtesy to visitors, not a hard requirement.
            $log->warning('maintenance_on', 'Could not enable maintenance mode — proceeding anyway.');
        }
    }
}
