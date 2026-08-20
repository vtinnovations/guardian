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

namespace Vtinnovations\Guardian\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * CSRF protection for all state-changing requests to Guardian routes.
 *
 * Backend XHR clients (Contao backend JS) always run from the same origin as
 * the server, so a strict Origin/Referer check is a clean and reliable CSRF
 * defence. No token-juggling between Twig templates and JS needed — the
 * browser always sends Origin (modern fetch) or Referer (form posts), and a
 * cross-origin attacker cannot forge either when targeting our domain.
 *
 * Triggered on POST/PUT/PATCH/DELETE for any route whose name starts with
 * "vtinnovations_guardian_" — both backend and external panel routes.
 *
 * Read-only methods (GET/HEAD/OPTIONS) are exempt; they should never have
 * side-effects and exempting them keeps `curl -H "Authorization: Bearer ..."`
 * style usage working for diagnostics.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 100)]
class CsrfRequestListener
{
    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $method  = $request->getMethod();

        // Only state-changing methods need CSRF protection
        if (\in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        // Restrict to our own routes
        $routeName = (string) $request->attributes->get('_route', '');
        if (!str_starts_with($routeName, 'vtinnovations_guardian_')) {
            return;
        }

        // Server-to-server routes carry no browser context, so there is no
        // Origin or Referer to compare and no session to protect. They opt out
        // here and authenticate the request cryptographically instead — see
        // RequestAuthorizer. This is not a weaker check: a same-origin test
        // proves the request came from a browser on our own site, while a
        // detached signature proves it came from the vendor at all.
        if (true === $request->attributes->get('_vt_signed_request', false)) {
            return;
        }

        if (!$this->isSameOrigin($request)) {
            throw new AccessDeniedHttpException(
                'CSRF check failed: Origin/Referer does not match request host. '
                . 'State-changing requests must originate from the same site.'
            );
        }
    }

    /**
     * Checks Origin header first (modern fetch/XHR always sends it on POST),
     * falls back to Referer (form posts may not have Origin in older browsers).
     * Refuses the request if neither matches the request's own host.
     */
    private function isSameOrigin(Request $request): bool
    {
        $expectedHost = $request->getHost();

        $origin = $request->headers->get('Origin');
        if (\is_string($origin) && $origin !== '' && $origin !== 'null') {
            $originHost = parse_url($origin, \PHP_URL_HOST);
            return \is_string($originHost) && strcasecmp($originHost, $expectedHost) === 0;
        }

        $referer = $request->headers->get('Referer');
        if (\is_string($referer) && $referer !== '') {
            $refererHost = parse_url($referer, \PHP_URL_HOST);
            return \is_string($refererHost) && strcasecmp($refererHost, $expectedHost) === 0;
        }

        // No Origin and no Referer on a POST → reject. Real browsers always
        // include at least one. CLI tools doing destructive operations should
        // be explicit about their identity (the rare legitimate case can pass
        // -H "Origin: https://..." to curl).
        return false;
    }
}
