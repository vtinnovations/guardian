<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Guardian\Checker\SealedRecord;
use Vtinnovations\Guardian\Service\RegistrationPolicy;
use Vtinnovations\Guardian\Service\RegistrationState;

/**
 * The entitlement state machine for the trial/free/paid model.
 *
 * The single most important case in this file is the first one: no record
 * means no capability. Every other case is a refinement of that — there is no
 * path through this table that grants anything without an authenticated
 * record, and none that a local clock, a deleted file or a fresh install can
 * take.
 */
final class RegistrationPolicyTest extends TestCase
{
    private const NOW  = 1_800_000_000;
    private const HOST = 'example.com';

    public function testNoRecordGrantsNothing(): void
    {
        $state = RegistrationPolicy::decide(null, self::HOST, self::NOW);

        self::assertSame(RegistrationState::UNLICENSED, $state->state);
        self::assertSame([], $state->capabilities);
        self::assertFalse($state->isEntitled());
        self::assertFalse($state->hasAuthenticRecord);
    }

    public function testRecordTheIssuerMarkedInvalidGrantsNothing(): void
    {
        $state = RegistrationPolicy::decide(
            $this->record(['validation_status' => 'revoked']),
            self::HOST,
            self::NOW
        );

        self::assertSame(RegistrationState::UNLICENSED, $state->state);
        self::assertSame('issuer_withheld', $state->reason);
        // The record is still genuine — refresh and the session signal need it.
        self::assertTrue($state->hasAuthenticRecord);
    }

    public function testRecordWithNoMatchingConfiguredHostGrantsNothing(): void
    {
        // This is what stops a valid package being copied to another site.
        $state = RegistrationPolicy::decide($this->record(), null, self::NOW);

        self::assertSame(RegistrationState::UNLICENSED, $state->state);
        self::assertSame('host_not_authorised', $state->reason);
        self::assertTrue($state->hasAuthenticRecord);
    }

    public function testRecordThatHasNotStartedYetGrantsNothing(): void
    {
        $state = RegistrationPolicy::decide(
            $this->record(['license_starts_at' => self::NOW + 86400]),
            self::HOST,
            self::NOW
        );

        self::assertSame('not_yet_valid', $state->reason);
        self::assertSame([], $state->capabilities);
    }

    public function testActivePaidRecordUnlocksEverything(): void
    {
        $state = RegistrationPolicy::decide($this->record(['license_package' => 'pro']), self::HOST, self::NOW);

        self::assertSame(RegistrationState::PAID_ACTIVE, $state->state);
        self::assertTrue($state->isPaid());

        foreach ($this->allCapabilities() as $capability) {
            self::assertTrue($state->allows($capability), $capability . ' should be granted');
        }
    }

    public function testActiveTrialUnlocksEverythingForItsWindow(): void
    {
        $state = RegistrationPolicy::decide($this->record(['license_package' => 'trial']), self::HOST, self::NOW);

        self::assertSame(RegistrationState::TRIAL_ACTIVE, $state->state);

        foreach ($this->allCapabilities() as $capability) {
            self::assertTrue($state->allows($capability), $capability . ' should be granted');
        }
    }

    public function testActiveFreeUnlocksManualBackupOnly(): void
    {
        $state = RegistrationPolicy::decide($this->record(['license_package' => 'free']), self::HOST, self::NOW);

        self::assertSame(RegistrationState::FREE_ACTIVE, $state->state);
        self::assertSame([RegistrationState::CAP_BACKUP], $state->capabilities);
        self::assertFalse($state->isPaid());
    }

    public function testExpiredTrialGrantsNothing(): void
    {
        // No fallback for a trial: when it is over, it is over.
        $state = RegistrationPolicy::decide(
            $this->record(['license_package' => 'trial', 'license_expires_at' => self::NOW - 1, 'free_available' => true]),
            self::HOST,
            self::NOW
        );

        self::assertSame(RegistrationState::UNLICENSED, $state->state);
        self::assertSame('expired', $state->reason);
        self::assertSame([], $state->capabilities);
    }

    public function testExpiredFreeGrantsNothing(): void
    {
        $state = RegistrationPolicy::decide(
            $this->record(['license_package' => 'free', 'license_expires_at' => self::NOW - 1, 'free_available' => true]),
            self::HOST,
            self::NOW
        );

        self::assertSame(RegistrationState::UNLICENSED, $state->state);
        self::assertSame([], $state->capabilities);
    }

    public function testExpiredPaidRecordFallsBackOnlyWhenTheIssuerAuthorisesIt(): void
    {
        $state = RegistrationPolicy::decide(
            $this->record(['license_package' => 'pro', 'license_expires_at' => self::NOW - 1, 'free_available' => true]),
            self::HOST,
            self::NOW
        );

        self::assertSame(RegistrationState::PAID_FALLBACK, $state->state);
        self::assertSame([RegistrationState::CAP_BACKUP], $state->capabilities);
        self::assertFalse($state->allows(RegistrationState::CAP_UPDATES));
        // The fallback stays tied to the same signed record and host.
        self::assertSame(self::HOST, $state->matchedHost);
    }

    public function testExpiredPaidRecordWithoutAuthorisedFallbackGrantsNothing(): void
    {
        $state = RegistrationPolicy::decide(
            $this->record(['license_package' => 'pro', 'license_expires_at' => self::NOW - 1, 'free_available' => false]),
            self::HOST,
            self::NOW
        );

        self::assertSame(RegistrationState::UNLICENSED, $state->state);
        self::assertSame('expired', $state->reason);
        self::assertSame([], $state->capabilities);
    }

    public function testLifetimeRecordNeverExpires(): void
    {
        $state = RegistrationPolicy::decide(
            $this->record([
                'license_package'    => 'pro',
                'license_lifetime'   => true,
                'license_expires_at' => null,
            ]),
            self::HOST,
            self::NOW + (100 * 365 * 86400)
        );

        self::assertSame(RegistrationState::PAID_ACTIVE, $state->state);
    }

    public function testUnexpectedTierGrantsNothing(): void
    {
        $state = RegistrationPolicy::decide(
            $this->record(['license_package' => 'enterprise']),
            self::HOST,
            self::NOW
        );

        self::assertSame(RegistrationState::UNLICENSED, $state->state);
        self::assertSame('tier_not_accepted', $state->reason);
    }

    public function testHostMatchingIsExact(): void
    {
        $record = $this->record(['license_domains' => ['example.com', 'www.example.com']]);

        self::assertTrue($record->authorises('example.com'));
        self::assertTrue($record->authorises('www.example.com'));

        // Neither a subdomain nor a parent of a signed host is authorised.
        self::assertFalse($record->authorises('shop.example.com'));
        self::assertFalse($record->authorises('com'));
        self::assertFalse($record->authorises('example.com.evil.test'));
        self::assertFalse($record->authorises('notexample.com'));
    }

    public function testMatchHostPrefersTheCurrentHostWhenItIsAuthorised(): void
    {
        $record = $this->record(['license_domains' => ['example.com', 'www.example.com']]);

        self::assertSame(
            'www.example.com',
            $record->matchHost(['example.com', 'www.example.com'], 'www.example.com')
        );
    }

    public function testMatchHostFallsBackToAnyExactIntersection(): void
    {
        $record = $this->record(['license_domains' => ['example.com', 'www.example.com']]);

        self::assertSame('www.example.com', $record->matchHost(['www.example.com'], 'unrelated.test'));
    }

    public function testMatchHostReturnsNullWithoutAnIntersection(): void
    {
        $record = $this->record(['license_domains' => ['example.com']]);

        self::assertNull($record->matchHost(['other.test'], 'other.test'));
    }

    /** @return list<string> */
    private function allCapabilities(): array
    {
        return [
            RegistrationState::CAP_BACKUP,
            RegistrationState::CAP_UPDATES,
            RegistrationState::CAP_RESTORE,
            RegistrationState::CAP_SCHEDULE,
            RegistrationState::CAP_PANEL,
            RegistrationState::CAP_NOTIFY,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function record(array $overrides = []): SealedRecord
    {
        $document = (object) array_merge([
            'schema_version'      => 2,
            'project'             => 'Guardian',
            'project_slug'        => 'guardian',
            'license_key'         => 'GUARD-TEST-0001',
            'license_domain'      => self::HOST,
            'license_domains'     => [self::HOST, 'www.example.com'],
            'license_max_domains' => 3,
            'license_package'     => 'pro',
            'license_features'    => [],
            'license_version'     => 7,
            'license_issued_at'   => self::NOW - 86400,
            'license_starts_at'   => self::NOW - 86400,
            'license_expires_at'  => self::NOW + 86400,
            'license_lifetime'    => false,
            'license_verified_at' => self::NOW,
            'free_available'      => true,
            'signature'           => 'signature-placeholder',
            'validation_status'   => 'valid',
        ], $overrides);

        $bytes = json_encode($document, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return new SealedRecord($bytes, ['license_version' => $document->license_version], $document);
    }
}
