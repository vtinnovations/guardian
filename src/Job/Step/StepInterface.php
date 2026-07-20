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
