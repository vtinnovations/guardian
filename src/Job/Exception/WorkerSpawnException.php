<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
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
