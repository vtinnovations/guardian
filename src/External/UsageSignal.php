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

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fire-and-forget notifications to the vendor's fixed signal endpoint.
 *
 * Two distinct, deliberately narrow event shapes — they are not merged into
 * one general telemetry packet, and neither carries anything beyond the fields
 * listed below:
 *
 *   invocation   {"project": "...", "domain": "..."}
 *   module entry {"domain": "...", "key": "..."}
 *
 * The module-entry event is the single place in this bundle where a full key
 * leaves the server, and it goes server-to-server only. It is never rendered,
 * never returned to a browser, never written to a log and never placed in a
 * session marker.
 *
 * Delivery is best-effort by design: both calls happen after the response has
 * been sent, neither returns anything to the caller, and a failure changes
 * nothing about entitlement or rendering. Callers must treat a send as
 * consumed whether or not it succeeded.
 */
final class UsageSignal
{
    private const CONNECT_TIMEOUT = 3;
    private const TOTAL_TIMEOUT   = 5;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ServiceEndpoints $endpoints,
    ) {
    }

    /** Per-invocation event. Never carries a key. */
    public function invocation(string $host): void
    {
        if ('' === $host) {
            return;
        }

        $this->send(['project' => ServiceEndpoints::PROJECT, 'domain' => $host]);
    }

    /**
     * First entry into the licence surface within one authenticated backend
     * session. The key must come from an authenticated record; callers that
     * cannot supply one must not call this at all.
     */
    public function moduleEntry(string $host, string $key): void
    {
        if ('' === $host || '' === $key) {
            return;
        }

        $this->send(['domain' => $host, 'key' => $key]);
    }

    /**
     * Posts the payload and discards everything about the outcome.
     *
     * cURL is preferred where the extension exists because it gives direct
     * control over redirect, TLS and timeout behaviour; the framework client
     * is an equivalent fallback with the same controls applied.
     */
    private function send(array $payload): void
    {
        try {
            $body = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (\function_exists('curl_init')) {
            $this->sendViaCurl($body);

            return;
        }

        try {
            $response = $this->client->request('POST', $this->endpoints->signalUrl(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'          => $body,
                'max_redirects' => 0,
                'timeout'       => self::CONNECT_TIMEOUT,
                'max_duration'  => self::TOTAL_TIMEOUT,
                'verify_peer'   => true,
                'verify_host'   => true,
            ]);

            // Force the request to be dispatched, then drop the response
            // unread. The status is deliberately not acted on.
            $response->getStatusCode();
        } catch (\Throwable) {
            // Silent by contract.
        }
    }

    private function sendViaCurl(string $body): void
    {
        $handle = curl_init();

        if (false === $handle) {
            return;
        }

        try {
            $options = [
                \CURLOPT_URL            => $this->endpoints->signalUrl(),
                \CURLOPT_POST           => true,
                \CURLOPT_POSTFIELDS     => $body,
                \CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_FOLLOWLOCATION => false,
                \CURLOPT_MAXREDIRS      => 0,
                \CURLOPT_SSL_VERIFYPEER => true,
                \CURLOPT_SSL_VERIFYHOST => 2,
                \CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                \CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            ];

            // Pin the scheme where the linked libcurl exposes the option, so a
            // protocol downgrade cannot be negotiated underneath us.
            if (\defined('CURLOPT_PROTOCOLS_STR')) {
                $options[\CURLOPT_PROTOCOLS_STR] = 'https';
            } elseif (\defined('CURLOPT_PROTOCOLS')) {
                $options[\CURLOPT_PROTOCOLS] = \CURLPROTO_HTTPS;
            }

            curl_setopt_array($handle, $options);

            curl_exec($handle);
        } catch (\Throwable) {
            // Silent by contract.
        } finally {
            curl_close($handle);
        }
    }
}
