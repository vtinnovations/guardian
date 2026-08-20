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
 * Product identity and the fixed vendor destinations.
 *
 * The destinations are compile-time constants on purpose. Nothing —
 * configuration, request data, a DNS alias, a redirect, a remote response —
 * may steer an outbound call somewhere else, because the alternative is a
 * product whose licensing traffic can be pointed at an attacker's host by
 * editing a settings row.
 *
 * The literals are assembled from fragments so a hardened release build can
 * transform them independently and so the shipped artefact does not contain a
 * single greppable URL string. This is release hygiene, not a security
 * boundary: the boundary is that these values are constants and that TLS peer
 * and hostname verification stay on.
 */
final class ServiceEndpoints
{
    /** Registered product identity. Must match the vendor catalogue entry. */
    public const PROJECT    = 'Guardian';
    public const SLUG       = 'guardian';
    public const PRODUCT_ID = 'vt-guardian';

    /**
     * The inbound path this installation exposes for vendor-initiated
     * updates. Public and fixed by the protocol.
     */
    public const UPDATER_PATH = '/rest/api/v1/' . self::SLUG . '-license-updater';

    /** @var list<string> */
    private const AUTHORITY = ['//www', '.v-t', '.one'];

    /** @var list<string> */
    private const VERIFY_PATH = ['/api', '/v1', '/verify'];

    /** @var list<string> */
    private const SIGNAL_PATH = ['/rest', '/api', '/v1', '/log-envoke'];

    /** Activation and refresh destination. */
    public function verifyUrl(): string
    {
        return $this->origin() . implode('', self::VERIFY_PATH);
    }

    /** Destination for both invocation signal shapes. */
    public function signalUrl(): string
    {
        return $this->origin() . implode('', self::SIGNAL_PATH);
    }

    /** Exact host both destinations must resolve to. */
    public function authority(): string
    {
        return ltrim(implode('', self::AUTHORITY), '/');
    }

    private function origin(): string
    {
        return 'https:' . implode('', self::AUTHORITY);
    }
}
