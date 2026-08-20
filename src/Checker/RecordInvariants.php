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

namespace Vtinnovations\Guardian\Checker;

/**
 * The shape a registry record must have to be usable.
 *
 * Separate from the cryptography on purpose. A valid signature proves the
 * vendor produced these bytes; it says nothing about whether they describe a
 * record this installation can act on. Both questions get asked, and keeping
 * them apart means each can be reasoned about — and tested — on its own.
 *
 * Everything here is strict rather than forgiving, because these bytes were
 * inside a signature. A list that arrives unsorted, a host with a capital
 * letter, an expiry that predates its own start: each is a record that does
 * not match what was signed, or a record the issuer should never have
 * produced. Repairing any of it locally would mean verifying one document and
 * then acting on a different one.
 */
final class RecordInvariants
{
    /** Longest key string accepted in a record. */
    private const MAX_KEY_LENGTH = 190;

    /** Longest single hostname accepted, per DNS limits. */
    private const MAX_HOST_LENGTH = 253;

    /**
     * @param list<string> $acceptedTiers tier vocabulary permitted by the product model
     *
     * @throws SealFailure on the first violation
     */
    public function assert(\stdClass $doc, string $project, string $slug, array $acceptedTiers): void
    {
        if (2 !== ($doc->schema_version ?? null)) {
            throw new SealFailure('schema_invalid', 'Unsupported record schema version.');
        }

        if ($project !== ($doc->project ?? null) || $slug !== ($doc->project_slug ?? null)) {
            throw new SealFailure('product_mismatch', 'Record was issued for a different product.');
        }

        $key = $doc->license_key ?? null;
        if (!\is_string($key) || '' === trim($key) || \strlen($key) > self::MAX_KEY_LENGTH) {
            throw new SealFailure('schema_invalid', 'Record key is missing or out of range.');
        }

        $this->assertHostSet($doc);

        $tier = $doc->license_package ?? null;
        if (!\is_string($tier) || !\in_array(strtolower(trim($tier)), $acceptedTiers, true)) {
            throw new SealFailure('tier_not_accepted', 'Record tier is outside the product model.');
        }

        $features = $doc->license_features ?? null;
        if (!\is_array($features) || !array_is_list($features)) {
            throw new SealFailure('schema_invalid', 'Record features must be a list.');
        }

        foreach ($features as $feature) {
            if (!\is_string($feature)) {
                throw new SealFailure('schema_invalid', 'Record features must be strings.');
            }
        }

        foreach (['license_version', 'license_issued_at', 'license_starts_at', 'license_verified_at'] as $field) {
            if (!\is_int($doc->{$field} ?? null) || $doc->{$field} < 0) {
                throw new SealFailure('schema_invalid', sprintf('Record field "%s" is not a non-negative integer.', $field));
            }
        }

        if (0 === $doc->license_issued_at || 0 === $doc->license_starts_at) {
            throw new SealFailure('schema_invalid', 'Record history timestamps are missing.');
        }

        if (!\is_bool($doc->license_lifetime ?? null) || !\is_bool($doc->free_available ?? null)) {
            throw new SealFailure('schema_invalid', 'Record flags must be booleans.');
        }

        $expires = $doc->license_expires_at ?? null;

        if (true === $doc->license_lifetime) {
            // A lifetime record must not also carry an expiry.
            if (null !== $expires) {
                throw new SealFailure('schema_invalid', 'A lifetime record must not declare an expiry.');
            }
        } else {
            // A non-lifetime record without an expiry would never end, which is
            // a perpetual licence obtained by omitting a field.
            if (!\is_int($expires) || $expires <= $doc->license_starts_at) {
                throw new SealFailure('schema_invalid', 'A time-limited record needs an expiry after its start.');
            }
        }

        $status = $doc->validation_status ?? null;
        if (!\is_string($status) || '' === trim($status)) {
            throw new SealFailure('schema_invalid', 'Record status is missing.');
        }
    }

    /**
     * The signed host set is the entire authorisation surface, so it is never
     * repaired locally: an unsorted, duplicated or wildcard list is a rejected
     * record, not a list to tidy up.
     *
     * @throws SealFailure
     */
    public function assertHostSet(\stdClass $doc): void
    {
        $hosts = $doc->license_domains ?? null;

        if (!\is_array($hosts) || !array_is_list($hosts) || [] === $hosts) {
            throw new SealFailure('schema_invalid', 'Record host set is missing or empty.');
        }

        foreach ($hosts as $host) {
            if (!\is_string($host) || !$this->isCanonicalHost($host)) {
                throw new SealFailure('host_set_invalid', 'Record host set contains a non-canonical entry.');
            }
        }

        if (\count(array_unique($hosts)) !== \count($hosts)) {
            throw new SealFailure('host_set_invalid', 'Record host set contains duplicates.');
        }

        $sorted = $hosts;
        sort($sorted, \SORT_STRING);

        if ($sorted !== $hosts) {
            throw new SealFailure('host_set_invalid', 'Record host set is not in canonical order.');
        }

        $operationHost = $doc->license_domain ?? null;

        if (!\is_string($operationHost) || !$this->isCanonicalHost($operationHost)) {
            throw new SealFailure('host_set_invalid', 'Record operation host is not canonical.');
        }

        // Membership is exact. No apex/www, parent, child or sibling
        // relationship grants access to a host outside this list.
        if (!\in_array($operationHost, $hosts, true)) {
            throw new SealFailure('host_not_in_set', 'Record operation host is absent from the signed set.');
        }

        $allowance = $doc->license_max_domains ?? null;

        if (!\is_int($allowance) || $allowance < 1) {
            throw new SealFailure('schema_invalid', 'Record host allowance must be a positive integer.');
        }

        // Deliberately no count-versus-allowance guard. The issuer permits
        // existing bindings to survive an allowance reduction, and enforcing
        // the number here would take already-bound installations dark. 9999 is
        // likewise just a large allowance, not a wildcard — only exact members
        // of the list above authorise anything.
    }

    /**
     * Exact canonical hostname syntax.
     *
     * Anything that would need repair — uppercase, a trailing dot, a port, a
     * wildcard — is rejected rather than normalised.
     */
    public function isCanonicalHost(string $host): bool
    {
        if ('' === $host || \strlen($host) > self::MAX_HOST_LENGTH) {
            return false;
        }

        if ($host !== strtolower($host)) {
            return false;
        }

        return 1 === preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host);
    }
}
