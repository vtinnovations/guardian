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

namespace Vtinnovations\Guardian\Job\Exception;

use Vtinnovations\Guardian\Job\UpdateJob;

/**
 * Thrown when the worker process could not be started for a newly created
 * job. The job has already been marked as failed and archived. This is
 * different from JobBlockedException because no OTHER job is in the way —
 * the new job itself failed to launch.
 */
class WorkerSpawnException extends \RuntimeException
{
    public function __construct(
        public readonly UpdateJob $failedJob,
        string $message,
    ) {
        parent::__construct($message);
    }
}
