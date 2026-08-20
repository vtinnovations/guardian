<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Checker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vtinnovations\Guardian\Checker\RecordInvariants;
use Vtinnovations\Guardian\Checker\SealFailure;

/**
 * The rules a record has to satisfy once its signature has been established.
 *
 * The host-set cases are the important ones: this is where "the licence is for
 * example.com" is stopped from quietly meaning "and also www.example.com, and
 * anything under it".
 */
final class RecordInvariantsTest extends TestCase
{
    private const PROJECT = 'Guardian';
    private const SLUG    = 'guardian';
    private const TIERS   = ['trial', 'free', 'pro'];

    private RecordInvariants $invariants;

    protected function setUp(): void
    {
        $this->invariants = new RecordInvariants();
    }

    public function testAValidRecordPasses(): void
    {
        $this->invariants->assert($this->record(), self::PROJECT, self::SLUG, self::TIERS);

        $this->expectNotToPerformAssertions();
    }

    public function testSchemaVersionMustBeTwo(): void
    {
        $this->assertRejects($this->record(['schema_version' => 3]), 'schema_invalid');
        $this->assertRejects($this->record(['schema_version' => '2']), 'schema_invalid');
    }

    public function testRecordForAnotherProductIsRejected(): void
    {
        $this->assertRejects($this->record(['project' => 'Brickie']), 'product_mismatch');
        $this->assertRejects($this->record(['project_slug' => 'brickie']), 'product_mismatch');
    }

    public function testTierOutsideTheProductModelIsRejected(): void
    {
        $this->assertRejects($this->record(['license_package' => 'enterprise']), 'tier_not_accepted');
    }

    #[DataProvider('acceptedTiers')]
    public function testEveryModelTierIsAccepted(string $tier): void
    {
        $this->invariants->assert($this->record(['license_package' => $tier]), self::PROJECT, self::SLUG, self::TIERS);

        $this->expectNotToPerformAssertions();
    }

    public static function acceptedTiers(): array
    {
        return [['trial'], ['free'], ['pro']];
    }

    public function testLifetimeRecordMustNotCarryAnExpiry(): void
    {
        $this->assertRejects(
            $this->record(['license_lifetime' => true, 'license_expires_at' => 1815536000]),
            'schema_invalid'
        );
    }

    public function testLifetimeRecordWithNullExpiryIsAccepted(): void
    {
        $this->invariants->assert(
            $this->record(['license_lifetime' => true, 'license_expires_at' => null]),
            self::PROJECT,
            self::SLUG,
            self::TIERS
        );

        $this->expectNotToPerformAssertions();
    }

    public function testTimeLimitedRecordWithoutExpiryIsRejected(): void
    {
        // Otherwise a perpetual licence could be obtained by omitting a field.
        $this->assertRejects(
            $this->record(['license_lifetime' => false, 'license_expires_at' => null]),
            'schema_invalid'
        );
    }

    public function testExpiryMustFollowTheStart(): void
    {
        $this->assertRejects(
            $this->record(['license_starts_at' => 1784000000, 'license_expires_at' => 1783000000]),
            'schema_invalid'
        );
    }

    public function testMissingHistoryTimestampsAreRejected(): void
    {
        $this->assertRejects($this->record(['license_issued_at' => 0]), 'schema_invalid');
        $this->assertRejects($this->record(['license_starts_at' => 0]), 'schema_invalid');
    }

    public function testFlagsMustBeRealBooleans(): void
    {
        $this->assertRejects($this->record(['free_available' => 'true']), 'schema_invalid');
        $this->assertRejects($this->record(['license_lifetime' => 1]), 'schema_invalid');
    }

    public function testEmptyHostSetIsRejected(): void
    {
        $this->assertRejects($this->record(['license_domains' => []]), 'schema_invalid');
    }

    public function testUnsortedHostSetIsRejected(): void
    {
        // The vendor signs a canonical list. Sorting it locally would mean
        // verifying one byte sequence and then acting on another.
        $this->assertRejects(
            $this->record(['license_domains' => ['www.example.com', 'example.com']]),
            'host_set_invalid'
        );
    }

    public function testDuplicateHostsAreRejected(): void
    {
        $this->assertRejects(
            $this->record(['license_domains' => ['example.com', 'example.com']]),
            'host_set_invalid'
        );
    }

    public function testWildcardHostIsRejected(): void
    {
        $this->assertRejects(
            $this->record([
                'license_domain'  => '*.example.com',
                'license_domains' => ['*.example.com'],
            ]),
            'host_set_invalid'
        );
    }

    public function testUppercaseHostIsRejected(): void
    {
        $this->assertRejects(
            $this->record([
                'license_domain'  => 'Example.com',
                'license_domains' => ['Example.com'],
            ]),
            'host_set_invalid'
        );
    }

    public function testTrailingDotHostIsRejected(): void
    {
        $this->assertRejects(
            $this->record([
                'license_domain'  => 'example.com.',
                'license_domains' => ['example.com.'],
            ]),
            'host_set_invalid'
        );
    }

    public function testHostWithPortIsRejected(): void
    {
        $this->assertRejects(
            $this->record([
                'license_domain'  => 'example.com:8080',
                'license_domains' => ['example.com:8080'],
            ]),
            'host_set_invalid'
        );
    }

    public function testOperationHostMustBelongToTheSignedSet(): void
    {
        $this->assertRejects(
            $this->record([
                'license_domain'  => 'other.example.com',
                'license_domains' => ['example.com', 'www.example.com'],
            ]),
            'host_not_in_set'
        );
    }

    public function testAllowanceMustBePositive(): void
    {
        $this->assertRejects($this->record(['license_max_domains' => 0]), 'schema_invalid');
        $this->assertRejects($this->record(['license_max_domains' => -1]), 'schema_invalid');
        $this->assertRejects($this->record(['license_max_domains' => '3']), 'schema_invalid');
    }

    public function testInstanceBoundAllowanceIsAcceptedAndIsNotAWildcard(): void
    {
        $record = $this->record(['license_max_domains' => 9999]);

        $this->invariants->assert($record, self::PROJECT, self::SLUG, self::TIERS);

        // 9999 is a large allowance, not permission for absent hosts.
        self::assertSame(['example.com', 'www.example.com'], $record->license_domains);
    }

    public function testBoundCountAboveTheAllowanceIsStillAccepted(): void
    {
        // The issuer lets existing bindings survive an allowance reduction;
        // rejecting them here would take live installations dark.
        $this->invariants->assert(
            $this->record([
                'license_domains'     => ['a.example.com', 'b.example.com', 'example.com'],
                'license_domain'      => 'example.com',
                'license_max_domains' => 1,
            ]),
            self::PROJECT,
            self::SLUG,
            self::TIERS
        );

        $this->expectNotToPerformAssertions();
    }

    public function testFeaturesMustBeAListOfStrings(): void
    {
        $this->assertRejects($this->record(['license_features' => [1, 2]]), 'schema_invalid');
    }

    public function testOversizedKeyIsRejected(): void
    {
        $this->assertRejects($this->record(['license_key' => str_repeat('K', 191)]), 'schema_invalid');
    }

    #[DataProvider('hostSyntax')]
    public function testCanonicalHostSyntax(string $host, bool $expected): void
    {
        self::assertSame($expected, $this->invariants->isCanonicalHost($host));
    }

    public static function hostSyntax(): array
    {
        return [
            'plain'            => ['example.com', true],
            'www is distinct'  => ['www.example.com', true],
            'deep subdomain'   => ['admin.shop.example.com', true],
            'punycode'         => ['xn--bcher-kva.example', true],
            'single label'     => ['localhost', true],
            'uppercase'        => ['Example.com', false],
            'trailing dot'     => ['example.com.', false],
            'leading dot'      => ['.example.com', false],
            'wildcard'         => ['*.example.com', false],
            'with port'        => ['example.com:443', false],
            'with scheme'      => ['https://example.com', false],
            'leading hyphen'   => ['-example.com', false],
            'trailing hyphen'  => ['example-.com', false],
            'empty'            => ['', false],
            'underscore'       => ['ex_ample.com', false],
        ];
    }

    private function assertRejects(\stdClass $record, string $expectedCategory): void
    {
        try {
            $this->invariants->assert($record, self::PROJECT, self::SLUG, self::TIERS);
        } catch (SealFailure $failure) {
            self::assertSame($expectedCategory, $failure->category);

            return;
        }

        self::fail(sprintf('Expected the record to be rejected as "%s".', $expectedCategory));
    }

    /** @param array<string, mixed> $overrides */
    private function record(array $overrides = []): \stdClass
    {
        $record = [
            'schema_version'      => 2,
            'project'             => self::PROJECT,
            'project_slug'        => self::SLUG,
            'license_key'         => 'GUARD-TEST-0001',
            'license_domain'      => 'example.com',
            'license_domains'     => ['example.com', 'www.example.com'],
            'license_max_domains' => 3,
            'license_package'     => 'pro',
            'license_features'    => [],
            'license_version'     => 7,
            'license_issued_at'   => 1784000000,
            'license_starts_at'   => 1784000000,
            'license_expires_at'  => 1815536000,
            'license_lifetime'    => false,
            'license_verified_at' => 1784880547,
            'free_available'      => true,
            'signature'           => 'signature-placeholder',
            'validation_status'   => 'valid',
        ];

        return (object) array_merge($record, $overrides);
    }
}
