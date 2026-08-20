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

use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\Guardian\External\ServiceEndpoints;
use Vtinnovations\Guardian\External\UsageSignal;
use Vtinnovations\Guardian\Security\BackendAuthChecker;

/**
 * Emits the once-per-session notification that an administrator opened
 * Guardian's licence surface.
 *
 * The contract is narrow and the edges are the whole point:
 *
 *   once   per authenticated backend session, per product. Reloads, tab
 *          switches, parallel browser tabs and repeated saves all produce
 *          nothing further. A new login may produce one again.
 *   where  claimed from the licence surface itself, after Contao has
 *          authenticated the user — not from a kernel listener, not from
 *          entitlement evaluation, not from the frontend, cron or CLI.
 *   what   exactly {domain, key}, server-to-server.
 *
 * The claim is written to the session *before* delivery is attempted, so a
 * timeout cannot turn into a retry loop that emits repeatedly. PHP serialises
 * requests that share a session, which is what makes the read-modify-write
 * atomic against parallel tabs.
 *
 * The marker holds the product slug and nothing else — no key, no host, no
 * session identifier, no payload. The key itself is read from the
 * authenticated record, held only in memory for the length of the request, and
 * handed straight to the transport after the response has been sent.
 */
final class SessionEntrySignal
{
    /** Session key holding the list of products claimed in this session. */
    private const CLAIM_BAG = 'vtin_guardian_entry_claims';

    /** @var array{domain: string, key: string}|null */
    private ?array $pending = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RegistrationPolicy $policy,
        private readonly RegistrationStore $store,
        private readonly BackendAuthChecker $backendAuth,
        private readonly UsageSignal $signal,
    ) {
    }

    /**
     * Claims the event for this session if it has not been claimed yet.
     *
     * Called from the licence surface's load lifecycle. Returns silently in
     * every situation where the event does not apply.
     */
    public function claim(): void
    {
        if (null !== $this->pending || null === $this->backendAuth->getBackendUser()) {
            return;
        }

        // Only a cryptographically authenticated record may supply the key.
        // Entitlement may legitimately be withheld — expired, or bound
        // elsewhere — and the event still applies; a missing or unverifiable
        // record means there is nothing authentic to report.
        $record = $this->policy->record();

        if (null === $record) {
            return;
        }

        $key = $record->key();

        if ('' === $key) {
            return;
        }

        // The deterministic authenticated host, never an arbitrary request host.
        $host = $this->policy->state()->matchedHost;

        if ('' === $host) {
            $bound = $this->store->boundHost();
            $host  = '' !== $bound && $record->authorises($bound) ? $bound : '';
        }

        if ('' === $host) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        if (null === $request || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $claims  = $session->get(self::CLAIM_BAG, []);
        $claims  = \is_array($claims) ? $claims : [];

        if (\in_array(ServiceEndpoints::SLUG, $claims, true)) {
            return;
        }

        // Claim first, deliver later. A failed delivery is consumed.
        $claims[] = ServiceEndpoints::SLUG;
        $session->set(self::CLAIM_BAG, $claims);

        $this->pending = ['domain' => $host, 'key' => $key];
    }

    /**
     * Delivers a claimed event. Invoked after the response has been sent, so
     * neither latency nor failure can affect what the administrator sees.
     */
    public function deliver(): void
    {
        if (null === $this->pending) {
            return;
        }

        $payload       = $this->pending;
        $this->pending = null;

        $this->signal->moduleEntry($payload['domain'], $payload['key']);
    }

    public function hasPending(): bool
    {
        return null !== $this->pending;
    }
}
