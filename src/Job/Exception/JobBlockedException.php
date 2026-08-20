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
 * Thrown by UpdateJobManager::createJob() when a different active job is
 * blocking the new one. Carries the blocking job so the caller can show
 * it to the user (e.g. "Another job in the way" screen).
 *
 * This is distinct from a worker-spawn failure: in this case the new job
 * was never created because something else was already in the queue.
 */
class JobBlockedException extends \RuntimeException
{
    public function __construct(
        public readonly UpdateJob $blockingJob,
        string $message,
    ) {
        parent::__construct($message);
    }
}
