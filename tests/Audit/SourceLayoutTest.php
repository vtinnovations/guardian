<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Audit;

use PHPUnit\Framework\TestCase;

/**
 * Structural hardening, enforced rather than described.
 *
 * None of this makes distributed PHP unreadable — nothing can. What it does is
 * remove the shortcuts: there is no directory to open, no class name to grep
 * for, and no single registration to delete that turns every gate off at once.
 * Someone determined can still work it out; they just cannot do it in thirty
 * seconds with `ls`.
 *
 * These assertions exist because this property decays silently. A later change
 * that adds `src/Licensing/` breaks a test instead of quietly undoing the
 * design.
 */
final class SourceLayoutTest extends TestCase
{
    /** Directory names that announce the subsystem. */
    private const FORBIDDEN_PATHS = [
        'Licensing', 'License', 'Licence', 'Protection', 'Integrity',
        'AntiTamper', 'DRM', 'VtOne', 'VTone',
    ];

    /** Class names that announce the subsystem. */
    private const FORBIDDEN_CLASSES = [
        'LicenseManager', 'LicenceManager', 'LicenseValidator', 'LicenceValidator',
        'LicenseService', 'LicenceService', 'LicenseRepository', 'LicenseStateStore',
        'LicenseIntegrityService', 'LicenseUpdaterController', 'LicenseGuard',
        'LicenseVerifier', 'TamperDetector', 'AntiTamper', 'ExpectedMd5',
        'ChecksumGuard', 'VtoneLogger', 'VtOneClient',
    ];

    public function testNoDirectoryAnnouncesTheSubsystem(): void
    {
        $offenders = [];

        foreach ($this->directories() as $dir) {
            $name = basename($dir);

            if (\in_array($name, self::FORBIDDEN_PATHS, true)) {
                $offenders[] = $dir;
            }
        }

        self::assertSame([], $offenders, 'Found a directory that advertises the licensing subsystem.');
    }

    public function testNoClassNameAnnouncesTheSubsystem(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $class = basename($file, '.php');

            if (\in_array($class, self::FORBIDDEN_CLASSES, true)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'Found a class name that advertises the licensing subsystem.');
    }

    public function testResponsibilitiesAreSpreadAcrossSeveralArchitecturalSeams(): void
    {
        // Verification, endpoints/transport, storage/policy, request
        // authentication, the public endpoint and the administrator surface all
        // live in different existing parts of the bundle.
        $seams = [
            'src/Checker/TrustAnchors.php',
            'src/Checker/PackageSeal.php',
            'src/Checker/RecordInvariants.php',
            'src/External/ServiceEndpoints.php',
            'src/External/RegistryClient.php',
            'src/External/ExchangeJournal.php',
            'src/Service/RegistrationStore.php',
            'src/Service/RegistrationPolicy.php',
            'src/Service/HostInventory.php',
            'src/Security/RequestAuthorizer.php',
            'src/Controller/RegistryHookController.php',
            'src/EventListener/DataContainer/RegistrationPanel.php',
            'src/Service/RegistrationCoordinator.php',
        ];

        $root = \dirname(__DIR__, 2);

        foreach ($seams as $seam) {
            self::assertFileExists($root . '/' . $seam);
        }

        $directories = array_unique(array_map(static fn (string $s): string => \dirname($s), $seams));

        self::assertGreaterThanOrEqual(6, \count($directories));
    }

    public function testNoSingleFileHoldsTheWholeFlow(): void
    {
        // Endpoints, key material, digest checking, signature verification,
        // host policy, request authentication, persistence and entitlement must
        // not collect in one place.
        $markers = [
            'endpoints'      => 'log-envoke',
            'key material'   => 'fingerprint',
            'digest'         => 'md5(',
            'signature'      => 'sodium_crypto_sign_verify_detached',
            'host policy'    => 'idn_to_ascii',
            'request auth'   => 'X-VT-Signature',
            'persistence'    => 'registration.seal',
            'entitlement'    => 'CAP_PANEL',
        ];

        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);
            $present  = [];

            foreach ($markers as $label => $needle) {
                if (str_contains($contents, $needle)) {
                    $present[] = $label;
                }
            }

            self::assertLessThanOrEqual(
                2,
                \count($present),
                sprintf('%s concentrates too much of the flow: %s', basename($file), implode(', ', $present))
            );
        }
    }

    public function testEveryProtectedBoundaryNamesItsOwnCapability(): void
    {
        // No shared "is unlocked" call: each gate asks for the one capability it
        // needs, so no single edit opens everything.
        $root  = \dirname(__DIR__, 2);
        $gates = [
            'src/Controller/JobController.php'           => ['CAP_RESTORE', 'CAP_UPDATES'],
            'src/Controller/AnalysisController.php'      => ['CAP_UPDATES'],
            'src/Controller/BackupController.php'        => ['CAP_BACKUP'],
            'src/Controller/ScheduleController.php'      => ['CAP_SCHEDULE'],
            'src/Controller/PanelSettingsController.php' => ['CAP_PANEL'],
            'src/Command/RunJobCommand.php'              => ['CAP_RESTORE', 'CAP_UPDATES'],
            'src/Schedule/ScheduledBackupRunner.php'     => ['CAP_SCHEDULE'],
            'src/Notifier/RecoveryEmailNotifier.php'     => ['CAP_NOTIFY'],
            'src/Guardian.php'                           => ['CAP_PANEL'],
        ];

        foreach ($gates as $file => $capabilities) {
            $contents = (string) file_get_contents($root . '/' . $file);

            foreach ($capabilities as $capability) {
                self::assertStringContainsString(
                    $capability,
                    $contents,
                    sprintf('%s must enforce %s server-side.', $file, $capability)
                );
            }
        }
    }

    public function testTheBundleBootGateDoesNotDependOnTheContainer(): void
    {
        // Boot runs before there is a usable container or database, and it is
        // deliberately a second, independent enforcement route.
        $contents = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Guardian.php');

        self::assertStringContainsString('new RegistrationStore(', $contents);
        self::assertStringContainsString('RegistrationPolicy::decide(', $contents);
    }

    public function testNoPrivateSigningMaterialIsShipped(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            self::assertStringNotContainsString('BEGIN PRIVATE KEY', $contents);
            self::assertStringNotContainsString('BEGIN RSA PRIVATE KEY', $contents);
            self::assertStringNotContainsString('sodium_crypto_sign_detached', $contents);
            self::assertStringNotContainsString('sodium_crypto_sign_keypair', $contents);
        }
    }

    public function testNoUnsafeExecutionPrimitivesAreUsed(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            self::assertSame(0, preg_match('/\beval\s*\(/', $contents), basename($file));
            self::assertSame(0, preg_match('/\bcreate_function\s*\(/', $contents), basename($file));
            self::assertSame(0, preg_match('/\bunserialize\s*\(/', $contents), basename($file));
        }
    }

    public function testOutboundDestinationsAreNotConfigurable(): void
    {
        $contents = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/src/External/ServiceEndpoints.php'
        );

        // Constants only: nothing reads an endpoint from configuration, request
        // data or a remote response.
        self::assertStringNotContainsString('getenv', $contents);
        self::assertStringNotContainsString('$_ENV', $contents);
        self::assertStringNotContainsString('%kernel', $contents);
        self::assertStringContainsString('private const AUTHORITY', $contents);
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];

        foreach ($this->iterator() as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function directories(): array
    {
        $dirs = [];

        foreach ($this->iterator() as $file) {
            if ($file instanceof \SplFileInfo && $file->isDir() && !\in_array($file->getFilename(), ['.', '..'], true)) {
                $dirs[] = $file->getPathname();
            }
        }

        return $dirs;
    }

    private function iterator(): \RecursiveIteratorIterator
    {
        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS)
        );
    }
}
