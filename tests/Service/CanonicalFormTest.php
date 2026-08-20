<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Guardian\Service\CanonicalForm;

/**
 * Fixed vectors for the two signing message formats.
 *
 * These assertions are the contract with the vendor's signer. If any expected
 * string here has to be "adjusted" to make a test pass, the correct response is
 * to find out which side changed — not to update the expectation.
 */
final class CanonicalFormTest extends TestCase
{
    private CanonicalForm $canonical;

    protected function setUp(): void
    {
        $this->canonical = new CanonicalForm();
    }

    public function testObjectKeysAreSortedBytewise(): void
    {
        $value          = new \stdClass();
        $value->zebra   = 1;
        $value->Alpha   = 2;
        $value->alpha   = 3;
        $value->_under  = 4;

        // Uppercase sorts before underscore, which sorts before lowercase.
        self::assertSame(
            '{"Alpha":2,"_under":4,"alpha":3,"zebra":1}',
            $this->canonical->encode($value)
        );
    }

    public function testNestedObjectsAreSortedRecursively(): void
    {
        $inner      = new \stdClass();
        $inner->b   = 1;
        $inner->a   = 2;

        $outer          = new \stdClass();
        $outer->second  = $inner;
        $outer->first   = 'x';

        self::assertSame('{"first":"x","second":{"a":2,"b":1}}', $this->canonical->encode($outer));
    }

    public function testListOrderIsPreserved(): void
    {
        $value                  = new \stdClass();
        $value->license_domains = ['zzz.example.com', 'aaa.example.com'];

        // Lists are signed in the order they were signed in. Sorting them here
        // would produce different bytes from the ones that were signed.
        self::assertSame(
            '{"license_domains":["zzz.example.com","aaa.example.com"]}',
            $this->canonical->encode($value)
        );
    }

    public function testScalarTypesArePreserved(): void
    {
        $value            = new \stdClass();
        $value->flag      = false;
        $value->nothing   = null;
        $value->zero      = 0;
        $value->text      = '0';

        self::assertSame(
            '{"flag":false,"nothing":null,"text":"0","zero":0}',
            $this->canonical->encode($value)
        );
    }

    public function testSlashesAndUnicodeAreNotEscaped(): void
    {
        $value        = new \stdClass();
        $value->url   = 'https://www.v-t.one/api/v1/verify';
        $value->label = 'Grüße';

        self::assertSame(
            '{"label":"Grüße","url":"https://www.v-t.one/api/v1/verify"}',
            $this->canonical->encode($value)
        );
    }

    public function testDetachedMessageRemovesOnlyTheTopLevelSignature(): void
    {
        $nested             = new \stdClass();
        $nested->signature  = 'inner-stays';

        $document            = new \stdClass();
        $document->signature = 'outer-goes';
        $document->nested    = $nested;
        $document->project   = 'Guardian';

        self::assertSame(
            '{"nested":{"signature":"inner-stays"},"project":"Guardian"}',
            $this->canonical->detachedMessage($document)
        );
    }

    public function testDetachedMessageDoesNotMutateTheInput(): void
    {
        $document            = new \stdClass();
        $document->signature = 'keep-me';

        $this->canonical->detachedMessage($document);

        self::assertSame('keep-me', $document->signature);
    }

    public function testRequestMessageIsSixNewlineJoinedLines(): void
    {
        $message = $this->canonical->requestMessage(
            'post',
            '/rest/api/v1/guardian-license-updater',
            'req-123',
            1784882547,
            'nonce-abc',
            '{"a":1}'
        );

        $expected = implode("\n", [
            'POST',
            '/rest/api/v1/guardian-license-updater',
            'req-123',
            '1784882547',
            'nonce-abc',
            hash('sha256', '{"a":1}'),
        ]);

        self::assertSame($expected, $message);
        self::assertStringEndsNotWith("\n", $message);
        self::assertCount(6, explode("\n", $message));
    }

    public function testRequestMessageDoesNotIncludeTheKeyId(): void
    {
        // The key id selects the anchor; it is deliberately outside the signed
        // message, so swapping it can only select a key that will not verify.
        $message = $this->canonical->requestMessage('POST', '/x', 'r', 1, 'n', '');

        self::assertStringNotContainsString('vtone-2026a', $message);
    }

    public function testBodyDigestIsLowercaseHexSha256(): void
    {
        self::assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $this->canonical->bodyDigest('')
        );
    }

    public function testDecodeKeepsObjectsAndListsApart(): void
    {
        $decoded = $this->canonical->decode('{"map":{},"list":[]}');

        self::assertInstanceOf(\stdClass::class, $decoded->map);
        self::assertSame([], $decoded->list);

        // Round-tripping must not turn the empty object into an empty list.
        self::assertSame('{"list":[],"map":{}}', $this->canonical->encode($decoded));
    }

    public function testDecodeRejectsMalformedJson(): void
    {
        $this->expectException(\JsonException::class);

        $this->canonical->decode('{"broken"');
    }
}
