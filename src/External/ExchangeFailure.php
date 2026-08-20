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

namespace Vtinnovations\Guardian\External;

/**
 * Raised when an exchange with the vendor registry cannot be completed.
 *
 * `transient` separates "we could not reach a verdict" (network error,
 * timeout, TLS problem, 5xx, unparseable response) from "we reached one and it
 * was no". Only the second kind may ever change local entitlement state — a
 * flaky network must never be able to disable a paying customer's
 * installation.
 */
final class ExchangeFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly bool $transient,
        string $detail = '',
    ) {
        parent::__construct('' !== $detail ? $detail : $category);
    }
}
