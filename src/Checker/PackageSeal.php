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

use Vtinnovations\Guardian\Service\CanonicalForm;

/**
 * Opens a sealed registry package and hands back an authenticated record.
 *
 * Order matters and is fixed:
 *
 *   1. envelope shape
 *   2. envelope signature  — nothing inside the envelope is trusted before this
 *   3. strict Base64 decode of the payload
 *   4. exact-byte digest against the now-authenticated envelope value
 *   5. parse the decoded bytes (never re-serialise them for the digest)
 *   6. record signature over the canonical document
 *   7. schema, product and host-set invariants
 *
 * The digest is a tamper tripwire over exact bytes, not proof of origin: it is
 * only meaningful because step 2 already established that the expected digest
 * itself came from the vendor. Recomputing a digest over edited content can
 * therefore never produce an acceptable package.
 *
 * The same entry point verifies freshly received packages and packages
 * re-read from local storage, so locally edited state is rejected by exactly
 * the checks that reject a forged response.
 */
final class PackageSeal
{
    /** Refuse to parse absurdly large records long before json_decode sees them. */
    private const MAX_RECORD_BYTES = 65536;

    private readonly RecordInvariants $invariants;

    public function __construct(
        private readonly TrustAnchors $anchors,
        private readonly CanonicalForm $canonical,
        ?RecordInvariants $invariants = null,
    ) {
        $this->invariants = $invariants ?? new RecordInvariants();
    }

    /**
     * @param \stdClass    $package       object exposing license_payload_b64 and integrity
     * @param list<string> $acceptedTiers tier vocabulary permitted by the product model
     *
     * @throws SealFailure when any check fails
     */
    public function open(
        \stdClass $package,
        string $project,
        string $slug,
        array $acceptedTiers,
        ?int $now = null,
    ): SealedRecord {
        $now      = $now ?? time();
        $envelope = $package->integrity ?? null;
        $payload  = $package->license_payload_b64 ?? null;

        if (!$envelope instanceof \stdClass || !\is_string($payload) || '' === $payload) {
            throw new SealFailure('malformed_package', 'Package is missing the payload or the integrity envelope.');
        }

        $this->assertEnvelopeShape($envelope);

        // (2) Authenticate the envelope before believing anything it claims.
        $verdict = $this->anchors->verifyWithKey(
            TrustAnchors::PURPOSE_ENVELOPE,
            $this->canonical->detachedMessage($envelope),
            (string) $envelope->signature,
            (string) $envelope->key_id,
            (string) $envelope->signature_algorithm,
            $now,
        );

        if (TrustAnchors::READY !== $verdict) {
            throw new SealFailure($verdict, 'Integrity envelope did not verify.');
        }

        // (3) Strict decode: reject any alphabet deviation or trailing noise.
        $bytes = base64_decode($payload, true);

        if (!\is_string($bytes) || '' === $bytes) {
            throw new SealFailure('payload_not_base64', 'Payload is not strict Base64.');
        }

        if (\strlen($bytes) > self::MAX_RECORD_BYTES) {
            throw new SealFailure('record_too_large', 'Decoded record exceeds the accepted size.');
        }

        // (4) Exact-byte tripwire against the authenticated expected digest.
        if (!hash_equals(strtolower((string) $envelope->license_md5), md5($bytes))) {
            throw new SealFailure('digest_mismatch', 'Decoded bytes do not match the sealed digest.');
        }

        // (5) Parse for reading only. $bytes stays authoritative.
        try {
            $document = $this->canonical->decode($bytes);
        } catch (\JsonException) {
            throw new SealFailure('document_malformed', 'Record is not valid JSON.');
        }

        if (!$document instanceof \stdClass) {
            throw new SealFailure('document_malformed', 'Record is not a JSON object.');
        }

        $signature = $document->signature ?? null;
        if (!\is_string($signature) || '' === $signature) {
            throw new SealFailure('document_malformed', 'Record carries no signature.');
        }

        // (6) The record names no key, so every usable record-purpose anchor
        //     is tried. An empty candidate set denies.
        $verdict = $this->anchors->verifyAny(
            TrustAnchors::PURPOSE_RECORD,
            $this->canonical->detachedMessage($document),
            $signature,
            $now,
        );

        if (TrustAnchors::READY !== $verdict) {
            throw new SealFailure($verdict, 'Record signature did not verify.');
        }

        // (7) Invariants. Only reached with both signatures verified.
        $this->invariants->assert($document, $project, $slug, $acceptedTiers);
        $this->assertEnvelopeAgrees($envelope, $document);

        return new SealedRecord($bytes, $this->toArray($envelope), $document);
    }

    /** Rebuilds a transport-shaped package from separately stored parts. */
    public function packageFrom(string $bytes, \stdClass $envelope): \stdClass
    {
        $package                      = new \stdClass();
        $package->license_payload_b64 = base64_encode($bytes);
        $package->integrity           = $envelope;

        return $package;
    }

    private function assertEnvelopeShape(\stdClass $envelope): void
    {
        foreach (['project', 'project_slug', 'license_md5', 'key_id', 'signature_algorithm', 'signature'] as $field) {
            $value = $envelope->{$field} ?? null;
            if (!\is_string($value) || '' === $value) {
                throw new SealFailure('malformed_package', sprintf('Envelope field "%s" is missing.', $field));
            }
        }

        if (!\is_int($envelope->license_version ?? null) || $envelope->license_version < 0) {
            throw new SealFailure('malformed_package', 'Envelope version is not a non-negative integer.');
        }

        if (!\is_int($envelope->generated_at ?? null)) {
            throw new SealFailure('malformed_package', 'Envelope generation time is not an integer.');
        }

        if (1 !== preg_match('/^[0-9a-f]{32}$/', strtolower((string) $envelope->license_md5))) {
            throw new SealFailure('malformed_package', 'Envelope digest is not a 32-character hex value.');
        }
    }

    private function assertEnvelopeAgrees(\stdClass $envelope, \stdClass $document): void
    {
        $agrees = $envelope->project === ($document->project ?? null)
            && $envelope->project_slug === ($document->project_slug ?? null)
            && $envelope->license_version === ($document->license_version ?? null);

        if (!$agrees) {
            throw new SealFailure('envelope_record_mismatch', 'Envelope and record describe different state.');
        }
    }

    private function toArray(\stdClass $envelope): array
    {
        return json_decode(json_encode($envelope, \JSON_THROW_ON_ERROR), true, 32, \JSON_THROW_ON_ERROR);
    }
}
