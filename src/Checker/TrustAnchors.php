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
 * Pinned verification anchors for material published by the vendor registry.
 *
 * Only public verification keys live here — they are safe to distribute, but
 * their authenticity is what the whole trust chain rests on, so they are
 * pinned in code rather than read from configuration. A remote response can
 * never introduce, replace or activate an anchor: rotation ships through a
 * release.
 *
 * Everything fails closed. A missing crypto extension, an empty ring, an
 * unadvertised key id, a purpose mismatch or an algorithm outside the
 * allowlist all deny verification instead of degrading to "unsigned but
 * accepted". The reason codes are diagnostic only and are never a bypass.
 */
final class TrustAnchors
{
    public const PURPOSE_RECORD   = 'record';
    public const PURPOSE_ENVELOPE = 'envelope';
    public const PURPOSE_REQUEST  = 'request';

    public const READY               = 'ready';
    public const NO_CRYPTO           = 'crypto_unavailable';
    public const STORE_EMPTY         = 'signing_key_store_empty';
    public const UNKNOWN_KEY         = 'unknown_signing_key';
    public const UNSUPPORTED_ALGO    = 'unsupported_algorithm';
    public const PURPOSE_MISMATCH    = 'key_purpose_mismatch';
    public const KEY_NOT_ACTIVE      = 'key_outside_rotation_window';
    public const BAD_SIGNATURE       = 'signature_rejected';

    /** The only algorithm this profile accepts. */
    private const ALGORITHM = 'ed25519';

    /** Raw Ed25519 public keys are exactly this many bytes. */
    private const RAW_KEY_LENGTH = 32;

    /**
     * Anchor descriptors. The encoded key material is held in fragments so a
     * hardened release build can transform them independently and so the
     * complete literal is not greppable in the shipped artefact. The
     * fingerprint is an integrity check on reassembly, never a substitute for
     * the key or the signature.
     *
     * @var array<string, array{fragments: list<string>, fingerprint: string, algorithm: string, purposes: list<string>, activates: int, retires: int|null}>
     */
    private const ANCHORS = [
        'vtone-2026a' => [
            'fragments'   => ['66+mgllq', 'O3JFBVUF', 'b8GFCI86', 'Mj9+Rd73', 'Sp/4+1rf', '=Egy'],
            'fingerprint' => 'edcd614e70c59ce0',
            'algorithm'   => self::ALGORITHM,
            'purposes'    => [self::PURPOSE_RECORD, self::PURPOSE_ENVELOPE, self::PURPOSE_REQUEST],
            'activates'   => 0,
            'retires'     => null,
        ],
    ];

    /** @var array<string, array{key: string, algorithm: string, purposes: list<string>, activates: int, retires: int|null}> */
    private array $usable = [];

    private string $state;

    public function __construct()
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            $this->state = self::NO_CRYPTO;

            return;
        }

        foreach (self::ANCHORS as $id => $anchor) {
            $raw = base64_decode(implode('', array_map(strrev(...), $anchor['fragments'])), true);

            // Reject anything that is not a structurally valid raw key for the
            // declared algorithm, and anything whose reassembled bytes do not
            // match the published fingerprint.
            if (!\is_string($raw)
                || \strlen($raw) !== self::RAW_KEY_LENGTH
                || self::ALGORITHM !== $anchor['algorithm']
                || !hash_equals($anchor['fingerprint'], substr(hash('sha256', $raw), 0, \strlen($anchor['fingerprint'])))
            ) {
                continue;
            }

            $this->usable[$id] = [
                'key'       => $raw,
                'algorithm' => $anchor['algorithm'],
                'purposes'  => $anchor['purposes'],
                'activates' => $anchor['activates'],
                'retires'   => $anchor['retires'],
            ];
        }

        $this->state = [] === $this->usable ? self::STORE_EMPTY : self::READY;
    }

    /**
     * Readiness category. Anything other than READY means no signed workflow
     * can succeed; a distributable build must never be produced in that state.
     */
    public function readiness(): string
    {
        return $this->state;
    }

    public function isReady(): bool
    {
        return self::READY === $this->state;
    }

    /** Number of usable anchors. Used by build-time validation and tests. */
    public function count(): int
    {
        return \count($this->usable);
    }

    /** @return list<string> ids of anchors currently inside their rotation window */
    public function activeIds(?int $now = null): array
    {
        $now = $now ?? time();
        $ids = [];

        foreach ($this->usable as $id => $anchor) {
            if ($this->inWindow($anchor, $now)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Verifies a detached signature against one explicitly named anchor.
     *
     * Used where the packet itself names the key — the integrity envelope and
     * inbound registry requests both carry a key id and an algorithm id.
     *
     * @param string $signature base64-encoded detached signature
     *
     * @return string one of the reason constants; READY means verified
     */
    public function verifyWithKey(
        string $purpose,
        string $message,
        string $signature,
        string $keyId,
        string $algorithm,
        ?int $now = null,
    ): string {
        if (!$this->isReady()) {
            return $this->state;
        }

        $anchor = $this->usable[$keyId] ?? null;
        if (null === $anchor) {
            return self::UNKNOWN_KEY;
        }

        if (!hash_equals($anchor['algorithm'], strtolower(trim($algorithm)))) {
            return self::UNSUPPORTED_ALGO;
        }

        if (!\in_array($purpose, $anchor['purposes'], true)) {
            return self::PURPOSE_MISMATCH;
        }

        if (!$this->inWindow($anchor, $now ?? time())) {
            return self::KEY_NOT_ACTIVE;
        }

        return $this->check($message, $signature, $anchor['key']) ? self::READY : self::BAD_SIGNATURE;
    }

    /**
     * Verifies a detached signature that does not name its key.
     *
     * The record document deliberately carries no key id, so its signature is
     * tested against every anchor currently usable for that purpose. An empty
     * candidate set denies rather than passes.
     *
     * @param string $signature base64-encoded detached signature
     *
     * @return string one of the reason constants; READY means verified
     */
    public function verifyAny(string $purpose, string $message, string $signature, ?int $now = null): string
    {
        if (!$this->isReady()) {
            return $this->state;
        }

        $now       = $now ?? time();
        $candidate = false;

        foreach ($this->usable as $anchor) {
            if (!\in_array($purpose, $anchor['purposes'], true) || !$this->inWindow($anchor, $now)) {
                continue;
            }

            $candidate = true;

            if ($this->check($message, $signature, $anchor['key'])) {
                return self::READY;
            }
        }

        return $candidate ? self::BAD_SIGNATURE : self::UNKNOWN_KEY;
    }

    private function check(string $message, string $signature, string $publicKey): bool
    {
        $raw = base64_decode($signature, true);

        if (!\is_string($raw) || \strlen($raw) !== \SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($raw, $message, $publicKey);
        } catch (\Throwable) {
            // A malformed key or signature must deny, never surface internals.
            return false;
        }
    }

    /** @param array{activates: int, retires: int|null} $anchor */
    private function inWindow(array $anchor, int $now): bool
    {
        if ($now < $anchor['activates']) {
            return false;
        }

        return null === $anchor['retires'] || $now <= $anchor['retires'];
    }
}
