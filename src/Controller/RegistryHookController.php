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

namespace Vtinnovations\Guardian\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Vtinnovations\Guardian\External\ServiceEndpoints;
use Vtinnovations\Guardian\Security\RequestAuthorizer;
use Vtinnovations\Guardian\Service\RegistrationCoordinator;

/**
 * The public endpoint the vendor calls to push a new registration package.
 *
 * Intentionally the thinnest thing in this bundle. It enforces the shape of
 * the request — method, media type, size — and then hands off: authentication
 * to the request authorizer, everything about the package to the coordinator.
 * No key material, no signature logic, no storage and no entitlement decision
 * lives here, so the file that is easiest to find is also the one that is
 * least worth finding.
 *
 * It is deliberately outside backend authentication, because the caller is a
 * server rather than a logged-in administrator, and equally outside browser
 * CSRF protection, because there is no browser and no session to protect. What
 * replaces both is the signed request: nothing gets past this controller
 * without a valid detached signature over the exact method, path, metadata and
 * body bytes.
 *
 * Failures are uniform and uninformative on purpose. An unauthenticated caller
 * learns that it failed, never why.
 */
final class RegistryHookController
{
    /** Largest request body accepted, checked before anything is parsed. */
    private const MAX_BODY_BYTES = 65536;

    public function __construct(
        private readonly RequestAuthorizer $authorizer,
        private readonly RegistrationCoordinator $coordinator,
    ) {
    }

    #[Route(
        ServiceEndpoints::UPDATER_PATH,
        name: 'vtinnovations_guardian_registry_hook',
        defaults: ['_vt_signed_request' => true],
        methods: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        // The path exists for POST only. Answering 405 rather than 404 tells
        // the vendor's own diagnostics that the endpoint is deployed.
        if (!$request->isMethod('POST')) {
            return new JsonResponse(
                ['status' => 'method_not_allowed'],
                405,
                ['Allow' => 'POST'],
            );
        }

        $contentType = strtolower((string) $request->headers->get('Content-Type', ''));

        if (!str_contains($contentType, 'application/json')) {
            return new JsonResponse(['status' => 'unsupported_media_type'], 415);
        }

        // Cap on the declared length first, then on what actually arrived, so
        // a lying Content-Length gains nothing.
        $declared = (int) $request->headers->get('Content-Length', '0');

        if ($declared > self::MAX_BODY_BYTES) {
            return new JsonResponse(['status' => 'payload_too_large'], 413);
        }

        $raw = (string) $request->getContent();

        if (\strlen($raw) > self::MAX_BODY_BYTES) {
            return new JsonResponse(['status' => 'payload_too_large'], 413);
        }

        if ('' === $raw) {
            return new JsonResponse(['status' => 'rejected'], 400);
        }

        $auth = $this->authorizer->authorize($request, $raw);

        if (!$auth['ok']) {
            // One shape for every authentication failure.
            return new JsonResponse(['status' => 'rejected'], 401);
        }

        $result = $this->coordinator->applyPush($auth, $raw);

        return new JsonResponse($result['body'], $result['status']);
    }
}
