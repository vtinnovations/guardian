<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Guardian\Checker\SealedRecord;
use Vtinnovations\Guardian\Service\SystemLogger;

/**
 * Packet material must never reach ordinary logs or browser responses.
 *
 * Redacting only the key does not make a packet dump safe: the payload, the
 * digest, the signatures and the nonce are all sensitive on their own, and a
 * key *fingerprint* is enough to correlate installations. So the rule is that
 * none of it is logged, and this file enforces that by reading the source
 * rather than trusting anyone to remember.
 */
final class PacketSecrecyTest extends TestCase
{
    /** Values and field names that must never appear in a log call. */
    private const FORBIDDEN_IN_LOGS = [
        'request_packet',
        'response_packet',
        'request_body',
        'response_body',
        'license_payload_b64',
        'license_md5',
        'request_sha256',
        'response_sha256',
        'licence_key_sha256',
        'license_key_sha256',
        'licence_key_length',
        'license_key_length',
    ];

    public function testNoLogCallMentionsForbiddenPacketFields(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            foreach ($this->logCalls((string) file_get_contents($file)) as $line => $call) {
                foreach (self::FORBIDDEN_IN_LOGS as $needle) {
                    if (str_contains($call, $needle)) {
                        $offenders[] = sprintf('%s:%d mentions %s', basename($file), $line, $needle);
                    }
                }
            }
        }

        self::assertSame([], $offenders, "Log calls must not carry packet material:\n" . implode("\n", $offenders));
    }

    public function testNoLogCallInterpolatesAKeyPayloadOrSignature(): void
    {
        $suspicious = [
            '->key()',
            '->bytes',
            '$key',
            '$rawBody',
            '$signature',
            '$nonce',
            '$payload',
        ];

        $offenders = [];

        // Scoped to the files that actually handle registration material.
        // Elsewhere in the bundle `$key` means a backup component or an array
        // index, and flagging those would train people to ignore this test.
        foreach ($this->registrationFiles() as $file) {
            foreach ($this->logCalls((string) file_get_contents($file)) as $line => $call) {
                foreach ($suspicious as $needle) {
                    if (str_contains($call, $needle)) {
                        $offenders[] = sprintf('%s:%d interpolates %s', basename($file), $line, $needle);
                    }
                }
            }
        }

        self::assertSame([], $offenders, "Log calls must not interpolate sensitive values:\n" . implode("\n", $offenders));
    }

    public function testTheRecordIsNeverSerialisedWholesale(): void
    {
        // json_encode of a SealedRecord would put the key straight into
        // whatever consumed it.
        $record = new SealedRecord('{}', [], (object) ['license_key' => 'GUARD-SECRET']);

        $encoded = json_encode($record, \JSON_THROW_ON_ERROR);

        // Public readonly properties do serialise, which is exactly why no
        // response or log path may ever be handed the record itself. This test
        // documents that fact so the pairing with the source scans above is
        // deliberate rather than accidental.
        self::assertStringContainsString('GUARD-SECRET', (string) $encoded);
    }

    public function testOnlyThreePlacesReadTheKeyAtAll(): void
    {
        $readers = [];

        foreach ($this->sourceFiles() as $file) {
            if (str_contains((string) file_get_contents($file), '->key()')) {
                $readers[] = basename($file);
            }
        }

        sort($readers);

        // Two send it — the coordinator, on activation and refresh packets, and
        // the session entry signal. The third only masks it for display. Any
        // new name appearing here needs a deliberate decision, which is the
        // point of pinning the list.
        self::assertSame(
            ['RegistrationCoordinator.php', 'RegistrationSummary.php', 'SessionEntrySignal.php'],
            $readers
        );
    }

    public function testTheAdministratorSurfaceOnlyEverShowsAMaskedKey(): void
    {
        $contents = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/src/BackendModule/RegistrationSummary.php'
        );

        // Every read of the key on the surface goes straight into mask().
        preg_match_all('/->key\(\)/', $contents, $reads);
        preg_match_all('/\$this->mask\(\$record\?->key\(\) \?\? \'\'\)/', $contents, $masked);

        self::assertNotSame(0, \count($reads[0]));
        self::assertCount(\count($reads[0]), $masked[0]);
    }

    public function testSystemLoggerOnlyAcceptsAStringMessage(): void
    {
        // There is no context array to accidentally fill with a packet.
        $method = new \ReflectionMethod(SystemLogger::class, 'info');

        self::assertSame('string', (string) $method->getParameters()[0]->getType());
        self::assertCount(2, $method->getParameters());
    }

    /**
     * Extracts log call sites, keyed by line number.
     *
     * @return array<int, string>
     */
    private function logCalls(string $contents): array
    {
        $calls = [];

        foreach (explode("\n", $contents) as $index => $line) {
            if (1 === preg_match('/->(info|warning|error|debug|notice|critical|log)\(/', $line)) {
                $calls[$index + 1] = $line;
            }
        }

        return $calls;
    }

    /**
     * The files that handle registration material.
     *
     * @return list<string>
     */
    private function registrationFiles(): array
    {
        $root = \dirname(__DIR__, 2);

        return array_map(static fn (string $path): string => $root . '/' . $path, [
            'src/Checker/PackageSeal.php',
            'src/Checker/RecordInvariants.php',
            'src/Checker/SealedRecord.php',
            'src/Checker/TrustAnchors.php',
            'src/External/ExchangeJournal.php',
            'src/External/RegistryClient.php',
            'src/External/ServiceEndpoints.php',
            'src/External/UsageSignal.php',
            'src/EventListener/DataContainer/RegistrationPanel.php',
            'src/BackendModule/RegistrationSummary.php',
            'src/EventListener/UsageSignalListener.php',
            'src/Controller/RegistryHookController.php',
            'src/Security/RequestAuthorizer.php',
            'src/Service/CanonicalForm.php',
            'src/Service/HostInventory.php',
            'src/Service/RegistrationCoordinator.php',
            'src/Service/RegistrationPolicy.php',
            'src/Service/RegistrationState.php',
            'src/Service/RegistrationStore.php',
            'src/Service/SessionEntrySignal.php',
        ]);
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $root  = \dirname(__DIR__, 2) . '/src';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
