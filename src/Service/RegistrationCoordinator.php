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

namespace Vtinnovations\Guardian\Service;

use Vtinnovations\Guardian\Checker\PackageSeal;
use Vtinnovations\Guardian\Checker\SealFailure;
use Vtinnovations\Guardian\External\ExchangeFailure;
use Vtinnovations\Guardian\External\ExchangeJournal;
use Vtinnovations\Guardian\External\RegistryClient;
use Vtinnovations\Guardian\External\ServiceEndpoints;

/**
 * Drives the three administrator-initiated registration transactions.
 *
 * Each one is all-or-nothing. A package is authenticated end to end and
 * matched against this installation's configured hosts *before* anything is
 * written, so a partially applied or half-trusted state cannot exist.
 *
 * The asymmetry between failure kinds is the important part: a network error,
 * a timeout, a TLS problem or a vendor 5xx leaves whatever was already stored
 * completely untouched. Only a successful, fully verified exchange replaces
 * state, and only an explicit administrator removal clears it. An outage must
 * never be able to switch a customer's installation off.
 */
// Not final, so the endpoint tests can assert it is never reached by an
// unauthenticated request.
class RegistrationCoordinator
{
    /** Longest key string accepted from the administrator form. */
    private const MAX_KEY_LENGTH = 190;

    public function __construct(
        private readonly RegistryClient $client,
        private readonly PackageSeal $seal,
        private readonly RegistrationStore $store,
        private readonly RegistrationPolicy $policy,
        private readonly HostInventory $inventory,
        private readonly ExchangeJournal $journal,
        private readonly CanonicalForm $canonical,
        private readonly SystemLogger $log,
    ) {
    }

    /**
     * First activation with a freshly entered key.
     *
     * @return array{ok: bool, category: string, transient: bool}
     */
    public function activate(string $key): array
    {
        $key = trim($key);

        if ('' === $key || \strlen($key) > self::MAX_KEY_LENGTH) {
            return $this->fail('key_missing', false);
        }

        $host = $this->inventory->verificationHost();

        if (null === $host) {
            return $this->fail('no_configured_domain', false);
        }

        try {
            $response = $this->client->activate($key, $host);
        } catch (ExchangeFailure $failure) {
            return $this->fail($failure->category, $failure->transient);
        }

        return $this->apply($response, $host, 'activate');
    }

    /**
     * Administrator refresh.
     *
     * Uses the key from the stored authenticated record unless a replacement
     * is explicitly entered, and reports the version currently held so the
     * vendor can decide whether newer state exists.
     *
     * @return array{ok: bool, category: string, transient: bool}
     */
    public function refresh(string $replacementKey = ''): array
    {
        $replacementKey = trim($replacementKey);
        $record         = $this->policy->record();

        $key = '' !== $replacementKey
            ? $replacementKey
            : ($record?->key() ?? $this->store->carriedKey());

        if ('' === $key || \strlen($key) > self::MAX_KEY_LENGTH) {
            return $this->fail('key_missing', false);
        }

        $host = $this->inventory->verificationHost();

        if (null === $host) {
            return $this->fail('no_configured_domain', false);
        }

        try {
            $response = $this->client->refresh($key, $host, $record?->version() ?? 0);
        } catch (ExchangeFailure $failure) {
            // Whatever was valid before stays valid. This is the path a flaky
            // network takes, and it must not cost the customer anything.
            return $this->fail($failure->category, $failure->transient);
        }

        return $this->apply($response, $host, 'refresh');
    }

    /**
     * Removes the registration.
     *
     * Every protected capability returns to Guardian's unlicensed default
     * immediately; stored backups, jobs and configuration are left alone.
     */
    public function remove(): void
    {
        $this->store->purge();
        $this->policy->invalidate();
        $this->log->info('Guardian registration removed by an administrator.');
    }

    /**
     * Applies a vendor-initiated update whose HTTP request has already been
     * cryptographically authenticated.
     *
     * The signature proved who sent the request; it says nothing about whether
     * the enclosed package is genuine, current or meant for this
     * installation. Those are separate questions and all of them are asked
     * again here.
     *
     * @param array{requestId: string, nonce: string, timestamp: int} $auth
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function applyPush(array $auth, string $rawBody): array
    {
        $requestId   = $auth['requestId'];
        $fingerprint = $this->canonical->bodyDigest($rawBody);
        $reservation = $this->journal->reserve($requestId, $auth['nonce'], $fingerprint);

        // A retry of an identical request is answered from the ledger without
        // applying anything twice.
        if (ExchangeJournal::REPLAY === $reservation['verdict']) {
            return $this->pushResult(200, [
                'status'          => 'already_processed',
                'request_id'      => $requestId,
                'license_version' => $reservation['version'],
            ]);
        }

        // Same identifier, different content, or a nonce seen before. Both are
        // abuse of the protocol rather than an honest retry.
        if (ExchangeJournal::CONFLICT === $reservation['verdict']) {
            $this->log->warning('Guardian registration push refused: request identifier reused with different content.');

            return $this->pushResult(409, ['status' => 'conflict', 'request_id' => $requestId]);
        }

        if (ExchangeJournal::REUSED === $reservation['verdict']) {
            $this->log->warning('Guardian registration push refused: replayed nonce.');

            return $this->pushResult(403, ['status' => 'rejected']);
        }

        try {
            $body = $this->canonical->decode($rawBody);
        } catch (\JsonException) {
            return $this->abandonPush($requestId, 400, 'malformed_body');
        }

        if (!$body instanceof \stdClass) {
            return $this->abandonPush($requestId, 400, 'malformed_body');
        }

        // The signed headers and the body must tell the same story; a mismatch
        // means part of the request was not covered by what we verified.
        $agrees = 'license_update' === ($body->action ?? null)
            && ServiceEndpoints::PROJECT === ($body->project ?? null)
            && ServiceEndpoints::SLUG === ($body->project_slug ?? null)
            && ServiceEndpoints::PRODUCT_ID === ($body->product_id ?? null)
            && $requestId === ($body->request_id ?? null)
            && $auth['timestamp'] === ($body->timestamp ?? null)
            && $auth['nonce'] === ($body->nonce ?? null);

        if (!$agrees) {
            return $this->abandonPush($requestId, 403, 'header_body_mismatch');
        }

        try {
            $record = $this->seal->open(
                $body,
                ServiceEndpoints::PROJECT,
                ServiceEndpoints::SLUG,
                RegistrationPolicy::ACCEPTED_TIERS,
            );
        } catch (SealFailure $failure) {
            return $this->abandonPush($requestId, 403, $failure->category);
        }

        $domain = $body->domain ?? null;

        if (!\is_string($domain) || $record->operationHost() !== $domain) {
            return $this->abandonPush($requestId, 403, 'operation_host_mismatch');
        }

        $matched = $record->matchHost($this->inventory->configured(), $domain);

        if (null === $matched) {
            return $this->abandonPush($requestId, 403, 'host_not_authorised');
        }

        // A push must move the installation forward. An older or equal version
        // is refused outright and leaves current state alone.
        $outcome = $this->store->commit($record, $matched, requireNewer: true);

        if (RegistrationStore::OK !== $outcome) {
            return $this->abandonPush($requestId, 409, $outcome);
        }

        $this->journal->settle($requestId, 'updated', $record->version());
        $this->store->dropCarriedKey();
        $this->policy->invalidate();

        $this->log->info(sprintf(
            'Guardian registration updated by the vendor (version %d, host %s).',
            $record->version(),
            $matched,
        ));

        return $this->pushResult(200, [
            'status'          => 'updated',
            'request_id'      => $requestId,
            'license_version' => $record->version(),
        ]);
    }

    /**
     * Releases the reservation so a corrected retry is still possible, and
     * answers generically.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function abandonPush(string $requestId, int $status, string $category): array
    {
        $this->journal->release($requestId);
        $this->log->warning(sprintf('Guardian registration push not applied (%s).', $category));

        return $this->pushResult($status, ['status' => 'rejected']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function pushResult(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }

    /**
     * Authenticates a transport response and commits it.
     *
     * @return array{ok: bool, category: string, transient: bool}
     */
    private function apply(\stdClass $response, string $requestHost, string $operation): array
    {
        if ('valid' !== strtolower((string) ($response->status ?? ''))) {
            // An authenticated denial, not a transport problem. Previous state
            // is still preserved: the vendor's documented revocation path is an
            // explicitly signed replacement package, never a bare "no".
            return $this->fail('registry_denied', false, $operation);
        }

        try {
            $record = $this->seal->open(
                $response,
                ServiceEndpoints::PROJECT,
                ServiceEndpoints::SLUG,
                RegistrationPolicy::ACCEPTED_TIERS,
            );
        } catch (SealFailure $failure) {
            return $this->fail($failure->category, false, $operation);
        }

        // The record must describe the exact host we asked about. A package
        // issued for some other host is not an answer to this request.
        if ($record->operationHost() !== $requestHost) {
            return $this->fail('operation_host_mismatch', false, $operation);
        }

        $matched = $record->matchHost($this->inventory->configured(), $requestHost);

        if (null === $matched) {
            return $this->fail('host_not_authorised', false, $operation);
        }

        $outcome = $this->store->commit($record, $matched);

        if (RegistrationStore::OK !== $outcome) {
            return $this->fail($outcome, RegistrationStore::LOCK_FAILED === $outcome, $operation);
        }

        $this->store->dropCarriedKey();
        $this->policy->invalidate();

        // Safe operational metadata only: operation, result, applied version,
        // matched host. No key, no payload, no digest, no signature, no packet.
        $this->log->info(sprintf(
            'Guardian registration %s succeeded (version %d, host %s).',
            $operation,
            $record->version(),
            $matched,
        ));

        return ['ok' => true, 'category' => RegistrationStore::OK, 'transient' => false];
    }

    /** @return array{ok: bool, category: string, transient: bool} */
    private function fail(string $category, bool $transient, string $operation = ''): array
    {
        if ('' !== $operation) {
            $this->log->warning(sprintf(
                'Guardian registration %s did not complete (%s).',
                $operation,
                $category,
            ));
        }

        return ['ok' => false, 'category' => $category, 'transient' => $transient];
    }
}
