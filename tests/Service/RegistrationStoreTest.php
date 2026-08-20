<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Guardian\Checker\PackageSeal;
use Vtinnovations\Guardian\Checker\TrustAnchors;
use Vtinnovations\Guardian\Service\CanonicalForm;
use Vtinnovations\Guardian\Service\RegistrationPolicy;
use Vtinnovations\Guardian\Service\RegistrationStore;

/**
 * The authoritative state store.
 *
 * The theme running through these cases: there is no way to end up
 * "registered" by writing files. Absent state, hand-written state and
 * corrupted state all read back as nothing, because every read goes through
 * the same signature and digest checks that a forged vendor response would
 * have to pass.
 */
final class RegistrationStoreTest extends TestCase
{
    private string $projectDir;

    private RegistrationStore $store;

    protected function setUp(): void
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            self::markTestSkipped('ext-sodium is required to exercise the store.');
        }

        $this->projectDir = sys_get_temp_dir() . '/guardian-store-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/updater', 0750, true);

        $this->store = new RegistrationStore(
            $this->projectDir,
            new PackageSeal(new TrustAnchors(), new CanonicalForm()),
            RegistrationPolicy::ACCEPTED_TIERS,
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->projectDir);
    }

    public function testAbsentStateReadsAsNothing(): void
    {
        self::assertNull($this->store->current());
        self::assertSame(RegistrationStore::ABSENT, $this->store->reason());
    }

    public function testHandWrittenRecordIsNotAcceptedAsState(): void
    {
        // The exact scenario this design exists to defeat: an attacker with
        // write access to var/ authoring themselves a licence.
        file_put_contents($this->projectDir . '/var/updater/registration.json', json_encode([
            'schema_version'  => 2,
            'project'         => 'Guardian',
            'project_slug'    => 'guardian',
            'license_package' => 'pro',
            'license_key'     => 'FORGED',
        ]));
        file_put_contents($this->projectDir . '/var/updater/registration.seal', json_encode([
            'project'             => 'Guardian',
            'project_slug'        => 'guardian',
            'license_version'     => 1,
            'license_md5'         => md5('anything'),
            'generated_at'        => time(),
            'key_id'              => 'vtone-2026a',
            'signature_algorithm' => 'ed25519',
            'signature'           => base64_encode(str_repeat("\0", \SODIUM_CRYPTO_SIGN_BYTES)),
        ]));

        $this->store->invalidate();

        self::assertNull($this->store->current());
        self::assertNotSame(RegistrationStore::OK, $this->store->reason());
    }

    public function testMalformedSealReadsAsNothing(): void
    {
        file_put_contents($this->projectDir . '/var/updater/registration.json', '{}');
        file_put_contents($this->projectDir . '/var/updater/registration.seal', 'not json');

        $this->store->invalidate();

        self::assertNull($this->store->current());
        self::assertSame('seal_malformed', $this->store->reason());
    }

    public function testBoundHostIsEmptyWithoutState(): void
    {
        self::assertSame('', $this->store->boundHost());
    }

    public function testCarriedKeyRoundTrips(): void
    {
        $this->store->carryKey('GUARD-CARRIED-0001');

        self::assertSame('GUARD-CARRIED-0001', $this->store->carriedKey());

        $this->store->dropCarriedKey();

        self::assertSame('', $this->store->carriedKey());
    }

    public function testCarriedKeyIsNotWorldReadable(): void
    {
        $this->store->carryKey('GUARD-CARRIED-0001');

        $mode = fileperms($this->projectDir . '/var/updater/registration.carry') & 0777;

        self::assertSame(0600, $mode);
    }

    public function testLegacyCacheIsRetiredAndItsKeyCarriedOver(): void
    {
        $legacy = $this->projectDir . '/var/updater/license.json';

        file_put_contents($legacy, json_encode([
            'license_key'         => 'GUARD-LEGACY-0001',
            'license_verified_at' => time(),
            'license_package'     => 'pro',
        ]));

        $this->store->adoptLegacy();

        // The key survives as a convenience; the entitlement does not.
        self::assertSame('GUARD-LEGACY-0001', $this->store->carriedKey());
        self::assertFileDoesNotExist($legacy);
        self::assertNull($this->store->current());
    }

    public function testPurgeRemovesEverything(): void
    {
        $this->store->carryKey('GUARD-CARRIED-0001');
        file_put_contents($this->projectDir . '/var/updater/registration.json', 'x');
        file_put_contents($this->projectDir . '/var/updater/registration.seal', 'x');
        file_put_contents($this->projectDir . '/var/updater/registration.scope', 'x');

        $this->store->purge();

        self::assertFileDoesNotExist($this->projectDir . '/var/updater/registration.json');
        self::assertFileDoesNotExist($this->projectDir . '/var/updater/registration.seal');
        self::assertFileDoesNotExist($this->projectDir . '/var/updater/registration.scope');
        self::assertSame('', $this->store->carriedKey());
        self::assertNull($this->store->current());
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
