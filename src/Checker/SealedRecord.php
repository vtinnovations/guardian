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
 * An authenticated registry record together with the exact bytes it was
 * verified from.
 *
 * Instances are only ever produced by PackageSeal after the envelope
 * signature, the exact-byte digest and the document signature have all
 * passed, so holding one is proof of authenticity. The raw bytes are kept
 * because they — not a re-serialisation of the parsed document — are what
 * gets persisted and what the digest was computed over.
 */
final class SealedRecord
{
    /**
     * @param string   $bytes    exact decoded record bytes
     * @param array    $envelope authenticated integrity envelope
     * @param \stdClass $document parsed view of $bytes
     */
    public function __construct(
        public readonly string $bytes,
        public readonly array $envelope,
        public readonly \stdClass $document,
    ) {
    }

    public function project(): string
    {
        return (string) ($this->document->project ?? '');
    }

    public function slug(): string
    {
        return (string) ($this->document->project_slug ?? '');
    }

    /**
     * The full key. Only ever leaves this object for an outbound registry
     * request or the once-per-session entry signal — never for a response
     * body, a template, a log line or a session marker.
     */
    public function key(): string
    {
        return (string) ($this->document->license_key ?? '');
    }

    /** The exact host this record was issued for. */
    public function operationHost(): string
    {
        return (string) ($this->document->license_domain ?? '');
    }

    /** @return list<string> the signed authorisation set, in signed order */
    public function hosts(): array
    {
        $hosts = $this->document->license_domains ?? [];

        return \is_array($hosts) ? array_values(array_map(strval(...), $hosts)) : [];
    }

    public function hostAllowance(): int
    {
        return (int) ($this->document->license_max_domains ?? 0);
    }

    public function tier(): string
    {
        return strtolower(trim((string) ($this->document->license_package ?? '')));
    }

    /** @return list<string> */
    public function features(): array
    {
        $features = $this->document->license_features ?? [];

        return \is_array($features) ? array_values(array_map(strval(...), $features)) : [];
    }

    public function version(): int
    {
        return (int) ($this->document->license_version ?? 0);
    }

    public function issuedAt(): int
    {
        return (int) ($this->document->license_issued_at ?? 0);
    }

    public function startsAt(): int
    {
        return (int) ($this->document->license_starts_at ?? 0);
    }

    public function expiresAt(): ?int
    {
        $expires = $this->document->license_expires_at ?? null;

        return null === $expires ? null : (int) $expires;
    }

    public function isLifetime(): bool
    {
        return true === ($this->document->license_lifetime ?? false);
    }

    public function verifiedAt(): int
    {
        return (int) ($this->document->license_verified_at ?? 0);
    }

    /** Whether the issuer authorises a reduced entitlement after paid expiry. */
    public function fallbackAvailable(): bool
    {
        return true === ($this->document->free_available ?? false);
    }

    public function status(): string
    {
        return strtolower(trim((string) ($this->document->validation_status ?? '')));
    }

    /** Exact-host membership test. No suffix, wildcard or parent matching. */
    public function authorises(string $normalisedHost): bool
    {
        return \in_array($normalisedHost, $this->hosts(), true);
    }

    /**
     * First exact intersection between the signed set and a trusted inventory,
     * preferring $preferred when it is a member of both.
     *
     * @param list<string> $inventory
     */
    public function matchHost(array $inventory, string $preferred = ''): ?string
    {
        if ('' !== $preferred && \in_array($preferred, $inventory, true) && $this->authorises($preferred)) {
            return $preferred;
        }

        foreach ($this->hosts() as $host) {
            if (\in_array($host, $inventory, true)) {
                return $host;
            }
        }

        return null;
    }
}
