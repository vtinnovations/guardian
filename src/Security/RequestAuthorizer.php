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
use Vtinnovations\Guardian\Checker\TrustAnchors;
use Vtinnovations\Guardian\External\ServiceEndpoints;
use Vtinnovations\Guardian\Service\CanonicalForm;

/**
 * Authenticates vendor-initiated requests to this installation.
 *
 * These requests arrive server-to-server with no browser and no logged-in
 * user, so none of the usual defences apply: there is no session to check and
 * no CSRF token to compare. Equally, none of the *apparent* identity signals
 * mean anything here — an Origin header, a Referer, a User-Agent and a source
 * IP are all trivially chosen by whoever is making the request. The only thing
 * that establishes authenticity is the detached signature over the request's
 * own method, path, metadata and body hash.
 *
 * The signed message is six newline-joined lines. The key id header selects
 * which pinned anchor verifies it and is deliberately *not* one of those
 * lines — so it can be swapped freely by an attacker, and swapping it just
 * selects a key that will not verify.
 *
 * Every failure is the same generic refusal to the caller. Which check failed
 * is useful internally and is exactly what an attacker would like to be told.
 */
// Not final: the endpoint tests substitute this to prove nothing reaches the
// coordinator unauthenticated. `final` would add no security here — PHP class
// modifiers are not a trust boundary, the signature check is.
class RequestAuthorizer
{
    public const OK = 'authorized';

    /** How far a vendor timestamp may sit from local time. */
    private const MAX_AGE = 300;

    private const HEADER_REQUEST_ID = 'X-VT-Request-ID';
    private const HEADER_TIMESTAMP  = 'X-VT-Timestamp';
    private const HEADER_NONCE      = 'X-VT-Nonce';
    private const HEADER_KEY_ID     = 'X-VT-Key-ID';
    private const HEADER_SIGNATURE  = 'X-VT-Signature';

    /** This profile signs requests with one algorithm and accepts no other. */
    private const ALGORITHM = 'ed25519';

    public function __construct(
        private readonly TrustAnchors $anchors,
        private readonly CanonicalForm $canonical,
    ) {
    }

    /**
     * @return array{ok: bool, reason: string, requestId: string, nonce: string, timestamp: int, keyId: string}
     */
    public function authorize(Request $request, string $rawBody): array
    {
        $deny = static fn (string $reason): array => [
            'ok'        => false,
            'reason'    => $reason,
            'requestId' => '',
            'nonce'     => '',
            'timestamp' => 0,
            'keyId'     => '',
        ];

        $requestId = trim((string) $request->headers->get(self::HEADER_REQUEST_ID, ''));
        $timestamp = trim((string) $request->headers->get(self::HEADER_TIMESTAMP, ''));
        $nonce     = trim((string) $request->headers->get(self::HEADER_NONCE, ''));
        $keyId     = trim((string) $request->headers->get(self::HEADER_KEY_ID, ''));
        $signature = trim((string) $request->headers->get(self::HEADER_SIGNATURE, ''));

        if ('' === $requestId || '' === $timestamp || '' === $nonce || '' === $keyId || '' === $signature) {
            return $deny('missing_authentication_headers');
        }

        if (!$this->isToken($requestId) || !$this->isToken($nonce) || !$this->isToken($keyId)) {
            return $deny('malformed_authentication_headers');
        }

        // Unsigned decimal only. A value with a sign, padding or exponent would
        // render differently on the signing side and must not be normalised.
        if (1 !== preg_match('/^[0-9]{1,12}$/', $timestamp)) {
            return $deny('malformed_timestamp');
        }

        $sentAt = (int) $timestamp;

        if (abs(time() - $sentAt) > self::MAX_AGE) {
            return $deny('stale_timestamp');
        }

        $verified = false;

        foreach ($this->pathCandidates($request) as $path) {
            $message = $this->canonical->requestMessage(
                $request->getMethod(),
                $path,
                $requestId,
                $sentAt,
                $nonce,
                $rawBody,
            );

            if (TrustAnchors::READY === $this->anchors->verifyWithKey(
                TrustAnchors::PURPOSE_REQUEST,
                $message,
                $signature,
                $keyId,
                self::ALGORITHM,
            )) {
                $verified = true;
                break;
            }
        }

        if (!$verified) {
            return $deny('request_signature_rejected');
        }

        return [
            'ok'        => true,
            'reason'    => self::OK,
            'requestId' => $requestId,
            'nonce'     => $nonce,
            'timestamp' => $sentAt,
            'keyId'     => $keyId,
        ];
    }

    /**
     * The path the vendor signed.
     *
     * Normally the protocol path exactly. An installation served from a
     * subdirectory sees a longer path locally, so the actual served path is
     * accepted too — but only when it really ends in the protocol path, and
     * both candidates come from this same request, so neither widens what the
     * signature binds.
     *
     * @return list<string>
     */
    private function pathCandidates(Request $request): array
    {
        $candidates = [ServiceEndpoints::UPDATER_PATH];
        $served     = $request->getPathInfo();

        if ($served !== ServiceEndpoints::UPDATER_PATH && str_ends_with($served, ServiceEndpoints::UPDATER_PATH)) {
            $candidates[] = $served;
        }

        $full = parse_url($request->getRequestUri(), \PHP_URL_PATH);

        if (\is_string($full) && !\in_array($full, $candidates, true) && str_ends_with($full, ServiceEndpoints::UPDATER_PATH)) {
            $candidates[] = $full;
        }

        return $candidates;
    }

    private function isToken(string $value): bool
    {
        return \strlen($value) <= 128 && 1 === preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $value);
    }
}
