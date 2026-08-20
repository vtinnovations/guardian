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

namespace Vtinnovations\Guardian\Checker;

/**
 * Raised when a transport package cannot be authenticated.
 *
 * The category is a short, stable, non-revealing token suitable for operational
 * logs and for mapping to a generic administrator message. The exception
 * message is developer-facing and must never be echoed to a browser: it can
 * name which check failed, which is more than an unauthenticated caller should
 * learn.
 */
final class SealFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $category,
        string $detail = '',
    ) {
        parent::__construct('' !== $detail ? $detail : $category);
    }
}
