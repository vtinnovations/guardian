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

class MaintenanceOffStep implements StepInterface
{
    public function __construct(private readonly RestoreManager $restoreManager)
    {
    }

    public function name(): string
    {
        return 'maintenance_off';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        $log->step('maintenance_off', 'Disabling maintenance mode...');

        if (!$this->restoreManager->setMaintenanceMode(false, $log)) {
            // If we couldn't disable, that's worse than couldn't-enable —
            // the site stays unreachable. Log a warning so the admin sees it.
            $log->warning('maintenance_off',
                'Could NOT disable maintenance mode — please remove var/maintenance.html manually.');
        }
    }
}
