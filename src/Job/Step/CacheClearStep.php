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

class CacheClearStep implements StepInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly string $projectDir,
    ) {
    }

    public function name(): string
    {
        return 'cache_clear';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        $console = $this->projectDir . '/vendor/bin/contao-console';

        if ($job->isDryRun()) {
            $this->runner->dryRun(
                [$console, 'cache:clear', '--env=prod', '--no-warmup'],
                $log,
                'cache_clear',
                'A real run would clear and warm up the Symfony cache.'
            );
            return;
        }

        $this->runner->run(
            [$console, 'cache:clear', '--env=prod', '--no-warmup'],
            $log,
            'cache_clear',
            180
        );
    }
}
