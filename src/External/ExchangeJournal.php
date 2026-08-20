<?php

declare(strict_types=1);

/*
 * Guardian
 *
 * Package: vtinnovations/guardian
 * Copyright: V&T Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://www.v-t.one
 */

namespace Vtinnovations\Guardian\External;

/**
 * Replay and idempotency ledger for vendor-initiated requests.
 *
 * Three outcomes have to be told apart, and conflating any two of them is a
 * real vulnerability:
 *
 *   NEW       first time this request id is seen — process it
 *   REPLAY    same request id *and* same authenticated body — the vendor is
 *             retrying after a lost response, so answer identically without
 *             applying anything a second time
 *   CONFLICT  same request id, different body — someone is reusing an
 *             identifier to smuggle different content past the check
 *   REUSED    the nonce has been seen before under another request id
 *
 * Entries hold digests only: a request id, a one-way digest of the nonce, and
 * a fingerprint of the authenticated body. That is enough to answer all four
 * questions and keeps the ledger from becoming a copy of the packets.
 *
 * The ledger is a locked file under the project's private state directory,
 * which is correct for the single-node and shared-filesystem deployments this
 * bundle targets. A deployment that runs several nodes without shared storage
 * needs this backed by a transactional shared store instead — see the
 * deployment notes in the README.
 */
final class ExchangeJournal
{
    public const NEW      = 'new';
    public const REPLAY   = 'replay';
    public const CONFLICT = 'conflict';
    public const REUSED   = 'nonce_reused';

    /** Kept well beyond any sane retry window, then pruned. */
    private const RETENTION = 30 * 86400;

    /** Hard cap so a flood cannot grow the ledger without bound. */
    private const MAX_ENTRIES = 2000;

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * Claims a request id for processing.
     *
     * @return array{verdict: string, version: int|null, result: string|null}
     */
    public function reserve(string $requestId, string $nonce, string $bodyFingerprint): array
    {
        return $this->withLock(function (array $entries) use ($requestId, $nonce, $bodyFingerprint): array {
            $nonceDigest = hash('sha256', $nonce);
            $existing    = $entries[$requestId] ?? null;

            if (null !== $existing) {
                // An exact retry is answered from the ledger. Anything else
                // sharing the identifier is refused outright.
                $verdict = hash_equals((string) ($existing['body'] ?? ''), $bodyFingerprint)
                    ? self::REPLAY
                    : self::CONFLICT;

                return [
                    'entries' => $entries,
                    'return'  => [
                        'verdict' => $verdict,
                        'version' => isset($existing['version']) ? (int) $existing['version'] : null,
                        'result'  => isset($existing['result']) ? (string) $existing['result'] : null,
                    ],
                ];
            }

            foreach ($entries as $id => $entry) {
                if ($id !== $requestId && hash_equals((string) ($entry['nonce'] ?? ''), $nonceDigest)) {
                    return [
                        'entries' => $entries,
                        'return'  => ['verdict' => self::REUSED, 'version' => null, 'result' => null],
                    ];
                }
            }

            $entries[$requestId] = [
                'nonce'   => $nonceDigest,
                'body'    => $bodyFingerprint,
                'at'      => time(),
                'result'  => 'pending',
                'version' => null,
            ];

            return [
                'entries' => $entries,
                'return'  => ['verdict' => self::NEW, 'version' => null, 'result' => null],
            ];
        });
    }

    /** Records the outcome of a reserved request. */
    public function settle(string $requestId, string $result, ?int $version): void
    {
        $this->withLock(static function (array $entries) use ($requestId, $result, $version): array {
            if (isset($entries[$requestId])) {
                $entries[$requestId]['result']  = $result;
                $entries[$requestId]['version'] = $version;
            }

            return ['entries' => $entries, 'return' => null];
        });
    }

    /** Drops a reservation that never got applied, so a retry can proceed. */
    public function release(string $requestId): void
    {
        $this->withLock(static function (array $entries) use ($requestId): array {
            if (($entries[$requestId]['result'] ?? null) === 'pending') {
                unset($entries[$requestId]);
            }

            return ['entries' => $entries, 'return' => null];
        });
    }

    /**
     * Runs $mutate against the ledger while holding an exclusive lock, then
     * persists whatever it returns.
     *
     * @param \Closure(array): array{entries: array, return: mixed} $mutate
     */
    private function withLock(\Closure $mutate): mixed
    {
        $file = $this->journalFile();
        $dir  = \dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['verdict' => self::CONFLICT, 'version' => null, 'result' => null];
        }

        $handle = @fopen($file, 'c+');

        if (false === $handle) {
            // Without a ledger there is no replay protection, so deny rather
            // than process an unprotected request.
            return ['verdict' => self::CONFLICT, 'version' => null, 'result' => null];
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                return ['verdict' => self::CONFLICT, 'version' => null, 'result' => null];
            }

            $raw     = stream_get_contents($handle) ?: '';
            $entries = json_decode($raw, true);
            $entries = \is_array($entries) ? $entries : [];
            $entries = $this->prune($entries);

            $outcome = $mutate($entries);

            $encoded = json_encode($outcome['entries'], \JSON_UNESCAPED_SLASHES);

            if (false !== $encoded) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, $encoded);
                fflush($handle);
                @chmod($file, 0600);
            }

            return $outcome['return'];
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    private function prune(array $entries): array
    {
        $cutoff = time() - self::RETENTION;

        foreach ($entries as $id => $entry) {
            if ((int) ($entry['at'] ?? 0) < $cutoff) {
                unset($entries[$id]);
            }
        }

        if (\count($entries) > self::MAX_ENTRIES) {
            uasort($entries, static fn ($a, $b) => ((int) ($b['at'] ?? 0)) <=> ((int) ($a['at'] ?? 0)));
            $entries = \array_slice($entries, 0, self::MAX_ENTRIES, true);
        }

        return $entries;
    }

    private function journalFile(): string
    {
        return $this->projectDir . '/var/updater/exchange.journal';
    }
}
