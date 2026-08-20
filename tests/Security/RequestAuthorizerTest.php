<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\Guardian\Checker\TrustAnchors;
use Vtinnovations\Guardian\External\ServiceEndpoints;
use Vtinnovations\Guardian\Security\RequestAuthorizer;
use Vtinnovations\Guardian\Service\CanonicalForm;

/**
 * Inbound request authentication.
 *
 * As with the trust anchors, there is no positive case here: signing a request
 * requires the vendor's private key. What can be proven locally is that every
 * way of *not* having a valid signature is refused — including the ones that
 * look like identity but are not, such as a plausible Origin header.
 */
final class RequestAuthorizerTest extends TestCase
{
    private RequestAuthorizer $authorizer;

    protected function setUp(): void
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            self::markTestSkipped('ext-sodium is required to exercise request authentication.');
        }

        $this->authorizer = new RequestAuthorizer(new TrustAnchors(), new CanonicalForm());
    }

    public function testRequestWithoutHeadersIsRefused(): void
    {
        $bare = Request::create(
            ServiceEndpoints::UPDATER_PATH,
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}'
        );

        $result = $this->authorizer->authorize($bare, '{}');

        self::assertFalse($result['ok']);
        self::assertSame('missing_authentication_headers', $result['reason']);
    }

    public function testMalformedTimestampIsRefused(): void
    {
        $result = $this->authorizer->authorize(
            $this->request(['X-VT-Timestamp' => '+1784882547']),
            '{}'
        );

        self::assertFalse($result['ok']);
        self::assertSame('malformed_timestamp', $result['reason']);
    }

    public function testStaleTimestampIsRefused(): void
    {
        $result = $this->authorizer->authorize(
            $this->request(['X-VT-Timestamp' => (string) (time() - 3600)]),
            '{}'
        );

        self::assertFalse($result['ok']);
        self::assertSame('stale_timestamp', $result['reason']);
    }

    public function testFutureTimestampIsRefused(): void
    {
        $result = $this->authorizer->authorize(
            $this->request(['X-VT-Timestamp' => (string) (time() + 3600)]),
            '{}'
        );

        self::assertFalse($result['ok']);
        self::assertSame('stale_timestamp', $result['reason']);
    }

    public function testMalformedIdentifiersAreRefused(): void
    {
        $result = $this->authorizer->authorize(
            $this->request(['X-VT-Request-ID' => 'has spaces and <brackets>']),
            '{}'
        );

        self::assertFalse($result['ok']);
        self::assertSame('malformed_authentication_headers', $result['reason']);
    }

    public function testInvalidSignatureIsRefused(): void
    {
        $result = $this->authorizer->authorize($this->request(), '{}');

        self::assertFalse($result['ok']);
    }

    public function testAPlausibleOriginHeaderDoesNotAuthenticate(): void
    {
        // Origin, Referer, User-Agent and source IP are all attacker-chosen.
        // None of them is allowed to stand in for the signature.
        $result = $this->authorizer->authorize(
            $this->request([
                'Origin'     => 'https://www.v-t.one',
                'Referer'    => 'https://www.v-t.one/',
                'User-Agent' => 'v-t.one/1.0',
            ]),
            '{}'
        );

        self::assertFalse($result['ok']);
        self::assertSame('request_signature_rejected', $result['reason']);
    }

    public function testChangingTheKeyIdCannotProduceAVerification(): void
    {
        foreach (['vtone-2026a', 'vtone-9999z', 'attacker-key'] as $keyId) {
            $result = $this->authorizer->authorize($this->request(['X-VT-Key-ID' => $keyId]), '{}');

            self::assertFalse($result['ok'], $keyId . ' must not authenticate');
        }
    }

    /** @param array<string, string> $headers */
    private function request(array $headers = []): Request
    {
        $headers = array_merge([
            'X-VT-Request-ID' => 'req-0123456789',
            'X-VT-Timestamp'  => (string) time(),
            'X-VT-Nonce'      => 'nonce-0123456789',
            'X-VT-Key-ID'     => 'vtone-2026a',
            'X-VT-Signature'  => base64_encode(str_repeat("\x41", \SODIUM_CRYPTO_SIGN_BYTES)),
        ], $headers);

        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create(ServiceEndpoints::UPDATER_PATH, 'POST', server: $server, content: '{}');
    }
}
