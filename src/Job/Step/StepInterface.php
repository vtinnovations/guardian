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

/**
 * Each step in an update job is a self-contained class implementing this interface.
 *
 * Contract:
 *   - name() returns the step identifier (matches values in UpdateJob::$steps)
 *   - execute() performs the step. Throws on failure. Logs progress via $log.
 *   - In dry-run mode, the step should simulate without touching anything.
 */
interface StepInterface
{
    public function name(): string;

    public function execute(UpdateJob $job, JobLog $log): void;
}
