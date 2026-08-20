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

namespace Vtinnovations\Guardian\Cron;

use Contao\CoreBundle\Cron\Cron;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Vtinnovations\Guardian\Schedule\ScheduledBackupRunner;

/**
 * Hourly cron that checks if a scheduled backup is due, and if so, runs it.
 *
 * Runs in BOTH scopes:
 *   - CLI scope: when `vendor/bin/contao-console contao:cron` is invoked from a server crontab
 *   - WEB scope: when triggered as part of a frontend or backend page load
 *
 * In the web scope, Contao executes cron jobs in `kernel.terminate` — that is,
 * AFTER the HTTP response has been sent to the user. The user does not wait
 * for the backup to finish, but the PHP-FPM worker is still busy until done.
 *
 * To keep this safe on shared hosting:
 *   - We raise PHP execution and memory limits as far as the host allows
 *   - We acquire a global lock so only one backup ever runs at once
 *   - The schedule evaluator decides if a backup is actually due (most invocations
 *     return immediately because nothing is scheduled for this minute)
 *   - We let the BackupManager handle the heavy lifting (which has its own timeouts)
 *
 * Why 'minutely' instead of 'hourly':
 *   - Allows sub-hourly schedules (every 5/15 minutes) to fire on time
 *   - The "is anything due?" check is essentially free (file_exists + json_decode)
 *   - Concurrent runs are prevented by BackupLock
 */
#[AsCronJob('minutely')]
class BackupCron
{
    public function __construct(private readonly ScheduledBackupRunner $runner)
    {
    }

    public function __invoke(string $scope): void
    {
        // Raise limits — running inside kernel.terminate, after the response was sent.
        // Hosters may still cap these via php.ini (set_time_limit doesn't always work),
        // but we try our best.
        @set_time_limit(0);                        // 0 = no limit (or as long as host allows)
        @ini_set('memory_limit', '512M');          // Override default 128M for archiving
        @ignore_user_abort(true);                  // Don't stop if browser closes connection

        // Detach from output buffers so any incidental output doesn't try to flush back
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $this->runner->runAllDue();

        // Don't propagate scope-specific behavior up — both web and cli pass through here
        unset($scope);
    }
}
