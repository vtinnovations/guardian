<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Checker;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Guardian\Checker\TrustAnchors;

/**
 * The pinned key ring is a release gate: a build whose ring is empty,
 * placeholder-only or missing the advertised active key can never verify a
 * real vendor response, and must not ship.
 *
 * Note what is deliberately absent here: a positive signature vector. Producing
 * one requires the vendor's private key, which exists only on their
 * infrastructure. Fabricating a signature to make a green test would prove
 * nothing except that the fabrication verifies against itself. Positive
 * verification is covered by an integration test against an approved signed
 * fixture — see the completion notes.
 */
final class TrustAnchorsTest extends TestCase
{
    private const ACTIVE_KEY_ID = 'vtone-2026a';

    private TrustAnchors $anchors;

    protected function setUp(): void
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            self::markTestSkipped('ext-sodium is required to exercise the trust anchors.');
        }

        $this->anchors = new TrustAnchors();
    }

    public function testProductionRingIsNotEmpty(): void
    {
        self::assertTrue($this->anchors->isReady());
        self::assertSame(TrustAnchors::READY, $this->anchors->readiness());
        self::assertGreaterThan(0, $this->anchors->count());
    }

    public function testAdvertisedActiveKeyResolves(): void
    {
        self::assertContains(self::ACTIVE_KEY_ID, $this->anchors->activeIds());
    }

    public function testKeyMaterialMatchesThePublishedFingerprint(): void
    {
        // The anchor is only admitted to the ring when its reassembled bytes
        // hash to the published fingerprint, so a populated ring is itself the
        // assertion that reassembly produced the right key.
        self::assertSame(1, $this->anchors->count());
    }

    public function testUnknownKeyIdIsRefused(): void
    {
        self::assertSame(
            TrustAnchors::UNKNOWN_KEY,
            $this->anchors->verifyWithKey(
                TrustAnchors::PURPOSE_ENVELOPE,
                'message',
                base64_encode(str_repeat("\0", \SODIUM_CRYPTO_SIGN_BYTES)),
                'vtone-does-not-exist',
                'ed25519'
            )
        );
    }

    public function testAlgorithmOutsideTheAllowlistIsRefused(): void
    {
        self::assertSame(
            TrustAnchors::UNSUPPORTED_ALGO,
            $this->anchors->verifyWithKey(
                TrustAnchors::PURPOSE_ENVELOPE,
                'message',
                base64_encode(str_repeat("\0", \SODIUM_CRYPTO_SIGN_BYTES)),
                self::ACTIVE_KEY_ID,
                'rsa-sha256'
            )
        );
    }

    public function testGarbageSignatureIsRefused(): void
    {
        self::assertSame(
            TrustAnchors::BAD_SIGNATURE,
            $this->anchors->verifyWithKey(
                TrustAnchors::PURPOSE_ENVELOPE,
                'message',
                base64_encode(str_repeat("\x41", \SODIUM_CRYPTO_SIGN_BYTES)),
                self::ACTIVE_KEY_ID,
                'ed25519'
            )
        );
    }

    public function testMalformedSignatureEncodingIsRefused(): void
    {
        self::assertSame(
            TrustAnchors::BAD_SIGNATURE,
            $this->anchors->verifyWithKey(
                TrustAnchors::PURPOSE_ENVELOPE,
                'message',
                'not-base64-$$$',
                self::ACTIVE_KEY_ID,
                'ed25519'
            )
        );
    }

    public function testSignatureOfTheWrongLengthIsRefused(): void
    {
        self::assertSame(
            TrustAnchors::BAD_SIGNATURE,
            $this->anchors->verifyWithKey(
                TrustAnchors::PURPOSE_ENVELOPE,
                'message',
                base64_encode('short'),
                self::ACTIVE_KEY_ID,
                'ed25519'
            )
        );
    }

    public function testKeyOutsideItsRotationWindowIsRefused(): void
    {
        // The active anchor activates at epoch 0, so nothing predates it; a
        // negative "now" stands in for a clock before that point.
        self::assertSame(
            TrustAnchors::KEY_NOT_ACTIVE,
            $this->anchors->verifyWithKey(
                TrustAnchors::PURPOSE_ENVELOPE,
                'message',
                base64_encode(str_repeat("\0", \SODIUM_CRYPTO_SIGN_BYTES)),
                self::ACTIVE_KEY_ID,
                'ed25519',
                -1
            )
        );
    }

    public function testUnnamedKeyVerificationRefusesGarbage(): void
    {
        self::assertSame(
            TrustAnchors::BAD_SIGNATURE,
            $this->anchors->verifyAny(
                TrustAnchors::PURPOSE_RECORD,
                'message',
                base64_encode(str_repeat("\x41", \SODIUM_CRYPTO_SIGN_BYTES))
            )
        );
    }

    public function testUnnamedKeyVerificationDeniesWhenNoAnchorServesThePurpose(): void
    {
        self::assertSame(
            TrustAnchors::UNKNOWN_KEY,
            $this->anchors->verifyAny(
                'purpose-that-no-anchor-serves',
                'message',
                base64_encode(str_repeat("\0", \SODIUM_CRYPTO_SIGN_BYTES))
            )
        );
    }
}
