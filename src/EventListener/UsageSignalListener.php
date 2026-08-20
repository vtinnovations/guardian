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

namespace Vtinnovations\Guardian\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vtinnovations\Guardian\External\UsageSignal;
use Vtinnovations\Guardian\Service\HostInventory;
use Vtinnovations\Guardian\Service\RegistrationStore;
use Vtinnovations\Guardian\Service\SessionEntrySignal;

/**
 * Flushes both vendor signals once the response is already on its way to the
 * browser.
 *
 * Running on terminate is what keeps these calls off the critical path: the
 * administrator has their page before either request is attempted, so a slow
 * or unreachable endpoint costs nothing visible and cannot change what was
 * rendered.
 *
 * Two different events, deliberately kept apart:
 *
 *   invocation    at most one {project, domain} per request, for backend
 *                 requests only — Guardian is a backend tool, so a frontend
 *                 page view is not a Guardian invocation.
 *   module entry  the {domain, key} event, but only if the licence surface
 *                 already claimed it earlier in this same request. This
 *                 listener never decides to send that one; it only delivers a
 *                 decision the surface made.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: -128)]
final class UsageSignalListener
{
    /** At most one invocation signal per PHP process. */
    private bool $invocationSent = false;

    public function __construct(
        private readonly ScopeMatcher $scopeMatcher,
        private readonly UsageSignal $signal,
        private readonly HostInventory $inventory,
        private readonly RegistrationStore $store,
        private readonly SessionEntrySignal $entrySignal,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        // The entry event was authorised by the licence surface; deliver it
        // regardless of scope classification below.
        if ($this->entrySignal->hasPending()) {
            $this->entrySignal->deliver();
        }

        if ($this->invocationSent || !$this->scopeMatcher->isBackendRequest($request)) {
            return;
        }

        $this->invocationSent = true;

        // Trusted configured host, or the host this installation was bound to.
        // Never a raw request header.
        $host = $this->inventory->trustedRequestHost() ?? $this->store->boundHost();

        if ('' !== $host) {
            $this->signal->invocation($host);
        }
    }
}
