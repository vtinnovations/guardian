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

namespace Vtinnovations\Guardian\Job\Step;

use Vtinnovations\Guardian\Job\JobLog;
use Vtinnovations\Guardian\Job\UpdateJob;

class MigrateStep implements StepInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly string $projectDir,
    ) {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        $console = $this->projectDir . '/vendor/bin/contao-console';

        if ($job->isDryRun()) {
            // contao:migrate has a --dry-run flag that lists pending migrations
            // without applying anything. Used for the preview flow.
            $this->runner->run(
                [$console, 'contao:migrate', '--dry-run', '--no-interaction', '--env=prod'],
                $log,
                'migrate',
                300
            );
            return;
        }

        $log->step('migrate', 'Running contao:migrate (applying DB schema + Contao migrations)...');

        // 30-minute timeout — large databases with many ALTER TABLE statements
        // can take a while on shared hosting. Most installs finish in under a
        // minute, but we'd rather not kill a legitimate long-running migration.
        $this->runner->run(
            [$console, 'contao:migrate', '--no-interaction', '--env=prod'],
            $log,
            'migrate',
            1800
        );

        $log->info('migrate', '✓ Contao migrations applied');
    }
}
