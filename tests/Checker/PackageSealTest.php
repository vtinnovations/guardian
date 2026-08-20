<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Checker;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Guardian\Checker\PackageSeal;
use Vtinnovations\Guardian\Checker\SealFailure;
use Vtinnovations\Guardian\Checker\TrustAnchors;
use Vtinnovations\Guardian\Service\CanonicalForm;
use Vtinnovations\Guardian\Service\RegistrationPolicy;

/**
 * Transport-level checks and, above all, ordering.
 *
 * The ordering is the security property: the envelope's signature is verified
 * before anything inside it is believed. That is what makes the digest
 * meaningful — an attacker who edits the record and recomputes its MD5 has to
 * put the new digest somewhere, and the only place it counts is inside an
 * envelope they cannot sign.
 *
 * Positive cases need a vendor signature and are covered by the integration
 * fixture rather than fabricated here.
 */
final class PackageSealTest extends TestCase
{
    private PackageSeal $seal;

    protected function setUp(): void
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            self::markTestSkipped('ext-sodium is required to exercise the package seal.');
        }

        $this->seal = new PackageSeal(new TrustAnchors(), new CanonicalForm());
    }

    public function testPackageWithoutAnEnvelopeIsRejected(): void
    {
        $package                      = new \stdClass();
        $package->license_payload_b64 = base64_encode('{}');

        $this->assertRejects($package, 'malformed_package');
    }

    public function testPackageWithoutAPayloadIsRejected(): void
    {
        $package            = new \stdClass();
        $package->integrity = $this->envelope();

        $this->assertRejects($package, 'malformed_package');
    }

    public function testEnvelopeMissingAFieldIsRejected(): void
    {
        $envelope = $this->envelope();
        unset($envelope->key_id);

        $this->assertRejects($this->package('{}', $envelope), 'malformed_package');
    }

    public function testEnvelopeWithNonHexDigestIsRejected(): void
    {
        $envelope              = $this->envelope();
        $envelope->license_md5 = 'not-a-digest';

        $this->assertRejects($this->package('{}', $envelope), 'malformed_package');
    }

    public function testEnvelopeWithNonIntegerVersionIsRejected(): void
    {
        $envelope                  = $this->envelope();
        $envelope->license_version = '7';

        $this->assertRejects($this->package('{}', $envelope), 'malformed_package');
    }

    public function testUnsignedEnvelopeIsRejectedBeforeTheDigestIsEvenConsulted(): void
    {
        // The digest below is correct for the payload. It gains the attacker
        // nothing, because the envelope carrying it does not verify.
        $bytes = '{"tampered":true}';

        $envelope              = $this->envelope();
        $envelope->license_md5 = md5($bytes);

        $this->assertRejects($this->package($bytes, $envelope), TrustAnchors::BAD_SIGNATURE);
    }

    public function testUnknownSigningKeyFailsClosed(): void
    {
        $envelope         = $this->envelope();
        $envelope->key_id = 'vtone-not-a-key';

        $this->assertRejects($this->package('{}', $envelope), TrustAnchors::UNKNOWN_KEY);
    }

    public function testUnsupportedAlgorithmFailsClosed(): void
    {
        $envelope                      = $this->envelope();
        $envelope->signature_algorithm = 'hmac-sha256';

        $this->assertRejects($this->package('{}', $envelope), TrustAnchors::UNSUPPORTED_ALGO);
    }

    public function testPackageFromRoundTripsBytesExactly(): void
    {
        $bytes    = '{"license_key":"GUARD-TEST","spacing":  "preserved"}';
        $envelope = $this->envelope();

        $package = $this->seal->packageFrom($bytes, $envelope);

        self::assertSame($bytes, base64_decode($package->license_payload_b64, true));
        self::assertSame($envelope, $package->integrity);
    }

    private function assertRejects(\stdClass $package, string $expectedCategory): void
    {
        try {
            $this->seal->open($package, 'Guardian', 'guardian', RegistrationPolicy::ACCEPTED_TIERS);
        } catch (SealFailure $failure) {
            self::assertSame($expectedCategory, $failure->category);

            return;
        }

        self::fail(sprintf('Expected the package to be rejected as "%s".', $expectedCategory));
    }

    private function package(string $bytes, \stdClass $envelope): \stdClass
    {
        return $this->seal->packageFrom($bytes, $envelope);
    }

    private function envelope(): \stdClass
    {
        return (object) [
            'project'             => 'Guardian',
            'project_slug'        => 'guardian',
            'license_version'     => 7,
            'license_md5'         => md5('{}'),
            'generated_at'        => time(),
            'key_id'              => 'vtone-2026a',
            'signature_algorithm' => 'ed25519',
            'signature'           => base64_encode(str_repeat("\x41", \SODIUM_CRYPTO_SIGN_BYTES)),
        ];
    }
}
