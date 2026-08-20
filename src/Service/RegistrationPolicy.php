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

use Vtinnovations\Guardian\Checker\SealedRecord;

/**
 * Turns an authenticated record into the capabilities this installation may
 * actually use.
 *
 * Guardian ships under the trial/free/paid model, so three tiers are accepted
 * and nothing else:
 *
 *   trial  full feature set for the window the vendor signed
 *   free   manual backup only
 *   pro    full feature set
 *
 * Every one of those requires an activated, signed record. There is no
 * anonymous free mode, no trial that starts on install, and no local clock or
 * file state that can begin or restart one — a trial exists exactly as long as
 * the signed dates say it does, and deleting local state gets the customer a
 * fresh activation against the vendor's memory, not a fresh trial.
 *
 * When a paid record expires, the reduced free feature set is offered only if
 * that same authenticated record says so. Nothing is synthesised locally: no
 * invented dates, no invented tier, no invented key.
 */
final class RegistrationPolicy
{
    /** The only tier values this product accepts in a signed record. */
    public const TIER_TRIAL = 'trial';
    public const TIER_FREE  = 'free';
    public const TIER_PAID  = 'pro';

    /** @var list<string> */
    public const ACCEPTED_TIERS = [self::TIER_TRIAL, self::TIER_FREE, self::TIER_PAID];

    /** Everything Guardian can do behind the paid tier. */
    private const FULL_SET = [
        RegistrationState::CAP_BACKUP,
        RegistrationState::CAP_UPDATES,
        RegistrationState::CAP_RESTORE,
        RegistrationState::CAP_SCHEDULE,
        RegistrationState::CAP_PANEL,
        RegistrationState::CAP_NOTIFY,
    ];

    /** What the free tier — and an authorised post-expiry fallback — allows. */
    private const FREE_SET = [RegistrationState::CAP_BACKUP];

    private ?RegistrationState $evaluated = null;

    public function __construct(
        private readonly RegistrationStore $store,
        private readonly HostInventory $inventory,
    ) {
    }

    /** The evaluated state for the current request. */
    public function state(): RegistrationState
    {
        return $this->evaluated ??= $this->evaluate();
    }

    public function allows(string $capability): bool
    {
        return $this->state()->allows($capability);
    }

    /**
     * The authentic record, if one is held — regardless of whether it
     * currently grants anything.
     *
     * A record can be genuine but withheld (expired, or bound to a host this
     * installation no longer serves). Refresh and the session entry signal
     * both need the record in that situation; feature gates do not.
     */
    public function record(): ?SealedRecord
    {
        return $this->store->current();
    }

    /** Forces re-evaluation after a state change. */
    public function invalidate(): void
    {
        $this->evaluated = null;
        $this->store->invalidate();
    }

    private function evaluate(): RegistrationState
    {
        $record = $this->store->current();

        if (null === $record) {
            return RegistrationState::none($this->store->reason());
        }

        // Which configured host does this record actually authorise? Prefer
        // the host bound at activation so background work and web requests
        // agree; fall back to any exact intersection with the live inventory.
        $inventory = $this->inventory->configured();
        $bound     = $this->store->boundHost();
        $matched   = $record->matchHost($inventory, $this->inventory->trustedRequestHost() ?? '');

        if (null === $matched && '' !== $bound && $record->authorises($bound) && \in_array($bound, $inventory, true)) {
            $matched = $bound;
        }

        return self::decide($record, $matched, time());
    }

    /**
     * The pure decision, with no container and no database behind it.
     *
     * Kept callable in isolation so early-boot and worker paths can reach the
     * same verdict without rebuilding half the application — and so this table
     * can be tested directly against fixed records.
     */
    public static function decide(?SealedRecord $record, ?string $matchedHost, int $now): RegistrationState
    {
        if (null === $record) {
            return RegistrationState::none(RegistrationStore::ABSENT);
        }

        $common = [
            'tier'               => $record->tier(),
            'hosts'              => $record->hosts(),
            'allowance'          => $record->hostAllowance(),
            'version'            => $record->version(),
            'issuedAt'           => $record->issuedAt(),
            'startsAt'           => $record->startsAt(),
            'expiresAt'          => $record->expiresAt(),
            'lifetime'           => $record->isLifetime(),
            'fallbackAvailable'  => $record->fallbackAvailable(),
            'hasAuthenticRecord' => true,
        ];

        // The issuer's own verdict comes first: a record marked anything other
        // than valid grants nothing, however well it verifies.
        if ('valid' !== $record->status()) {
            return RegistrationState::none('issuer_withheld', true);
        }

        // No exact intersection between the signed host set and this
        // installation's configured domains. Copying state to another site
        // stops here, before any capability is granted.
        if (null === $matchedHost || '' === $matchedHost) {
            return RegistrationState::none('host_not_authorised', true);
        }

        if ($now < $record->startsAt()) {
            return RegistrationState::none('not_yet_valid', true);
        }

        $expires = $record->expiresAt();
        $expired = !$record->isLifetime() && null !== $expires && $now >= $expires;

        if ($expired) {
            // Only a paid record may fall back, only when the same signed
            // record authorises it, and only to the documented free set.
            if (self::TIER_PAID === $record->tier() && $record->fallbackAvailable()) {
                return new RegistrationState(...[
                    'state'        => RegistrationState::PAID_FALLBACK,
                    'capabilities' => self::FREE_SET,
                    'reason'       => 'paid_expired_fallback',
                    'matchedHost'  => $matchedHost,
                ] + $common);
            }

            return RegistrationState::none('expired', true);
        }

        [$state, $capabilities] = match ($record->tier()) {
            self::TIER_PAID  => [RegistrationState::PAID_ACTIVE, self::FULL_SET],
            self::TIER_TRIAL => [RegistrationState::TRIAL_ACTIVE, self::FULL_SET],
            self::TIER_FREE  => [RegistrationState::FREE_ACTIVE, self::FREE_SET],
            default          => [RegistrationState::UNLICENSED, []],
        };

        if ([] === $capabilities) {
            return RegistrationState::none('tier_not_accepted', true);
        }

        return new RegistrationState(...[
            'state'        => $state,
            'capabilities' => $capabilities,
            'reason'       => '',
            'matchedHost'  => $matchedHost,
        ] + $common);
    }
}
