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

namespace Vtinnovations\Guardian\Service;

/**
 * The immutable outcome of evaluating this installation's registration.
 *
 * Shared as input by every protected boundary, but deliberately not an
 * authority in itself: there is no setter, no cached boolean anyone can flip,
 * and no single "unlocked" flag. Each boundary asks about the one capability
 * it actually needs, so removing or faking any single call site widens exactly
 * that one operation rather than the whole bundle.
 */
final class RegistrationState
{
    public const CAP_BACKUP   = 'backup';
    public const CAP_UPDATES  = 'updates';
    public const CAP_RESTORE  = 'restore';
    public const CAP_SCHEDULE = 'schedule';
    public const CAP_PANEL    = 'panel';
    public const CAP_NOTIFY   = 'notify';

    public const UNLICENSED       = 'unlicensed';
    public const TRIAL_ACTIVE     = 'trial_active';
    public const FREE_ACTIVE      = 'free_active';
    public const PAID_ACTIVE      = 'paid_active';
    public const PAID_FALLBACK    = 'paid_expired_fallback';

    /**
     * @param list<string> $capabilities
     * @param list<string> $hosts
     */
    public function __construct(
        public readonly string $state,
        public readonly array $capabilities,
        public readonly string $tier = '',
        public readonly string $reason = '',
        public readonly string $matchedHost = '',
        public readonly array $hosts = [],
        public readonly int $allowance = 0,
        public readonly int $version = 0,
        public readonly int $issuedAt = 0,
        public readonly int $startsAt = 0,
        public readonly ?int $expiresAt = null,
        public readonly bool $lifetime = false,
        public readonly bool $fallbackAvailable = false,
        public readonly bool $hasAuthenticRecord = false,
    ) {
    }

    /** Nothing is granted and no authentic record is held. */
    public static function none(string $reason, bool $hasAuthenticRecord = false): self
    {
        return new self(
            state: self::UNLICENSED,
            capabilities: [],
            reason: $reason,
            hasAuthenticRecord: $hasAuthenticRecord,
        );
    }

    public function allows(string $capability): bool
    {
        return \in_array($capability, $this->capabilities, true);
    }

    public function isEntitled(): bool
    {
        return [] !== $this->capabilities;
    }

    /** True while the paid feature set is available. */
    public function isPaid(): bool
    {
        return $this->allows(self::CAP_UPDATES);
    }
}
