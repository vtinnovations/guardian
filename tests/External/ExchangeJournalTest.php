<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\External;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Guardian\External\ExchangeJournal;

/**
 * Replay protection for vendor-initiated requests.
 *
 * The distinction that matters: an honest retry after a lost response must be
 * answered identically without applying anything twice, while the same
 * identifier carrying different content must be refused outright.
 */
final class ExchangeJournalTest extends TestCase
{
    private string $projectDir;

    private ExchangeJournal $journal;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/guardian-journal-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/updater', 0750, true);

        $this->journal = new ExchangeJournal($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->projectDir);
    }

    public function testFirstSightingIsNew(): void
    {
        $result = $this->journal->reserve('req-1', 'nonce-1', 'fingerprint-1');

        self::assertSame(ExchangeJournal::NEW, $result['verdict']);
        self::assertNull($result['version']);
    }

    public function testIdenticalRetryIsAReplayAndReportsTheAppliedVersion(): void
    {
        $this->journal->reserve('req-1', 'nonce-1', 'fingerprint-1');
        $this->journal->settle('req-1', 'updated', 9);

        $result = $this->journal->reserve('req-1', 'nonce-1', 'fingerprint-1');

        self::assertSame(ExchangeJournal::REPLAY, $result['verdict']);
        self::assertSame(9, $result['version']);
        self::assertSame('updated', $result['result']);
    }

    public function testSameIdentifierWithDifferentContentIsAConflict(): void
    {
        $this->journal->reserve('req-1', 'nonce-1', 'fingerprint-1');
        $this->journal->settle('req-1', 'updated', 9);

        $result = $this->journal->reserve('req-1', 'nonce-1', 'fingerprint-DIFFERENT');

        self::assertSame(ExchangeJournal::CONFLICT, $result['verdict']);
    }

    public function testReusedNonceUnderANewIdentifierIsRefused(): void
    {
        $this->journal->reserve('req-1', 'nonce-1', 'fingerprint-1');
        $this->journal->settle('req-1', 'updated', 9);

        $result = $this->journal->reserve('req-2', 'nonce-1', 'fingerprint-2');

        self::assertSame(ExchangeJournal::REUSED, $result['verdict']);
    }

    public function testReleasedReservationCanBeRetried(): void
    {
        $this->journal->reserve('req-1', 'nonce-1', 'fingerprint-1');
        $this->journal->release('req-1');

        $result = $this->journal->reserve('req-1', 'nonce-1', 'fingerprint-1');

        self::assertSame(ExchangeJournal::NEW, $result['verdict']);
    }

    public function testSettledReservationIsNotReleased(): void
    {
        $this->journal->reserve('req-1', 'nonce-1', 'fingerprint-1');
        $this->journal->settle('req-1', 'updated', 9);
        $this->journal->release('req-1');

        self::assertSame(ExchangeJournal::REPLAY, $this->journal->reserve('req-1', 'nonce-1', 'fingerprint-1')['verdict']);
    }

    public function testLedgerStoresDigestsRatherThanNonces(): void
    {
        $this->journal->reserve('req-1', 'nonce-plaintext', 'fingerprint-1');

        $contents = file_get_contents($this->projectDir . '/var/updater/exchange.journal');

        self::assertIsString($contents);
        self::assertStringNotContainsString('nonce-plaintext', $contents);
        self::assertStringContainsString(hash('sha256', 'nonce-plaintext'), $contents);
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
