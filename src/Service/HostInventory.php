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

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The trusted host inventory for this installation.
 *
 * Guardian is registered instance-wide, so the inventory is the union of every
 * domain the site owner configured on a Contao root page, optionally extended
 * by an explicit deployment list for installs that leave the root-page domain
 * blank.
 *
 * What is deliberately *not* a source: the Host, X-Forwarded-Host, Referer or
 * any other request header. A request may only *select* among hosts that are
 * already in the configured inventory — it can never introduce one. That is
 * what stops a spoofed header from choosing the identity an activation gets
 * bound to.
 *
 * Normalisation here only changes representation (case, one trailing dot, a
 * port, IDN spelling). It never changes which host is meant: `www.example.com`
 * is not `example.com`, and neither is a parent, child or sibling of the other.
 */
final class HostInventory
{
    /** Deployment override for installs whose root pages carry no domain. */
    private const OVERRIDE_ENV = 'VTINNOVATIONS_GUARDIAN_DOMAINS';

    private const MAX_HOST_LENGTH = 253;

    /** @var list<string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Every trusted configured host, normalised, unique and sorted.
     *
     * @return list<string>
     */
    public function configured(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $hosts = [];

        foreach ($this->rootPageDomains() as $raw) {
            $host = $this->normalise($raw);
            if (null !== $host) {
                $hosts[$host] = true;
            }
        }

        foreach ($this->overrideDomains() as $raw) {
            $host = $this->normalise($raw);
            if (null !== $host) {
                $hosts[$host] = true;
            }
        }

        $hosts = array_keys($hosts);
        sort($hosts, \SORT_STRING);

        return $this->cache = $hosts;
    }

    public function has(string $host): bool
    {
        $normalised = $this->normalise($host);

        return null !== $normalised && \in_array($normalised, $this->configured(), true);
    }

    /**
     * The current request host, but only when it is already part of the
     * configured inventory. Returns null otherwise, so an unrecognised or
     * forged host simply has no standing.
     */
    public function trustedRequestHost(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        // getHost() honours Symfony's trusted-proxy configuration; membership
        // in the configured inventory is the check that actually matters.
        $host = $this->normalise($request->getHost());

        return null !== $host && \in_array($host, $this->configured(), true) ? $host : null;
    }

    /**
     * The host to put on an outbound verification packet.
     *
     * Deterministic by construction: the current trusted host when it belongs
     * to the inventory, otherwise the first configured host in canonical
     * order. Null means the installation has no trusted domain at all and
     * cannot be activated until one is configured.
     */
    public function verificationHost(): ?string
    {
        $current = $this->trustedRequestHost();

        if (null !== $current) {
            return $current;
        }

        return $this->configured()[0] ?? null;
    }

    /**
     * Representation-only normalisation.
     *
     * Lowercases, removes one trailing dot, removes a port, converts IDN to
     * its ASCII form, then requires the result to be syntactically canonical.
     * Never strips `www`, never reduces to a registrable domain, never
     * resolves an alias.
     */
    public function normalise(string $host): ?string
    {
        $host = trim($host);

        if ('' === $host) {
            return null;
        }

        // Tolerate a full URL or an authority with a scheme in configuration.
        if (str_contains($host, '://')) {
            $parsed = parse_url($host, \PHP_URL_HOST);
            if (!\is_string($parsed) || '' === $parsed) {
                return null;
            }
            $host = $parsed;
        }

        // Strip a port, but leave bracketed IPv6 literals to be rejected below.
        if (1 === preg_match('/^(?<host>[^:]+):\d{1,5}$/', $host, $m)) {
            $host = $m['host'];
        }

        $host = strtolower($host);

        // Exactly one trailing dot is removed; more is malformed.
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if ('' === $host || \strlen($host) > self::MAX_HOST_LENGTH) {
            return null;
        }

        // Non-ASCII is only accepted where a correct IDN implementation exists;
        // guessing an encoding could silently change which host is meant.
        if (1 !== preg_match('/^[\x20-\x7e]*$/', $host)) {
            if (!\function_exists('idn_to_ascii')) {
                return null;
            }

            $ascii = @idn_to_ascii($host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
            if (!\is_string($ascii) || '' === $ascii) {
                return null;
            }

            $host = strtolower($ascii);
        }

        // A literal IP address is not a licensable identity here, and a
        // wildcard is never a host.
        if (str_contains($host, '*') || filter_var($host, \FILTER_VALIDATE_IP)) {
            return null;
        }

        if (1 !== preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host)) {
            return null;
        }

        return $host;
    }

    /**
     * Root-page domains as configured in the Contao page tree.
     *
     * @return list<string>
     */
    private function rootPageDomains(): array
    {
        try {
            $rows = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT dns FROM tl_page WHERE type = 'root' AND dns != ''"
            );
        } catch (\Throwable) {
            // No database yet (install, CLI worker, broken boot). An empty
            // inventory denies activation; it never invents a host.
            return [];
        }

        return array_values(array_map(strval(...), $rows));
    }

    /** @return list<string> */
    private function overrideDomains(): array
    {
        $raw = $_SERVER[self::OVERRIDE_ENV] ?? $_ENV[self::OVERRIDE_ENV] ?? getenv(self::OVERRIDE_ENV);

        if (!\is_string($raw) || '' === trim($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn ($v) => '' !== $v));
    }
}
