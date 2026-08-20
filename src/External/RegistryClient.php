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

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Vtinnovations\Guardian\Service\CanonicalForm;

/**
 * Outbound exchange with the vendor registry.
 *
 * Two operations share one packet shape: first activation of a freshly entered
 * key, and administrator-initiated refresh of the stored one. Both always
 * return a complete current package — never a delta — so the caller replaces
 * state wholesale or keeps what it had.
 *
 * This class speaks HTTP and validates the transport envelope only. It does
 * not decide anything about entitlement, and it never authenticates the
 * payload: that is PackageSeal's job, and keeping the two apart means a
 * transport bug cannot become a trust bug.
 *
 * Nothing here is logged. The packet contains the full key and the response
 * contains the signed payload, so a single well-meaning debug line would leak
 * exactly what the specification forbids.
 */
final class RegistryClient
{
    /** Longest response body accepted, before parsing. */
    private const MAX_RESPONSE_BYTES = 262144;

    private const CONNECT_TIMEOUT = 8;
    private const TOTAL_TIMEOUT   = 20;

    /** Accepted difference between vendor clock and local clock. */
    private const MAX_SKEW = 900;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ServiceEndpoints $endpoints,
        private readonly CanonicalForm $canonical,
    ) {
    }

    /**
     * First activation of a newly entered key.
     *
     * @throws ExchangeFailure
     */
    public function activate(string $key, string $host): \stdClass
    {
        return $this->exchange('activate', $key, $host, null);
    }

    /**
     * Administrator refresh. Sends the version currently held so the registry
     * can tell whether newer state exists.
     *
     * @throws ExchangeFailure
     */
    public function refresh(string $key, string $host, int $currentVersion): \stdClass
    {
        return $this->exchange('refresh', $key, $host, $currentVersion);
    }

    /**
     * @return \stdClass validated transport response: status, request_id,
     *                   server_time and, on success, the sealed package
     *
     * @throws ExchangeFailure
     */
    private function exchange(string $action, string $key, string $host, ?int $currentVersion): \stdClass
    {
        $requestId = $this->token();
        $sentAt    = time();

        $packet = [
            'action'       => $action,
            'project'      => ServiceEndpoints::PROJECT,
            'project_slug' => ServiceEndpoints::SLUG,
            'product_id'   => ServiceEndpoints::PRODUCT_ID,
            'license_key'  => $key,
            'domain'       => $host,
            'request_id'   => $requestId,
            'timestamp'    => $sentAt,
            'nonce'        => $this->token(),
        ];

        if (null !== $currentVersion) {
            $packet['current_license_version'] = $currentVersion;
        }

        $body = json_encode($packet, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        try {
            $response = $this->client->request('POST', $this->endpoints->verifyUrl(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'            => $body,
                'max_redirects'   => 0,
                'timeout'         => self::CONNECT_TIMEOUT,
                'max_duration'    => self::TOTAL_TIMEOUT,
                'verify_peer'     => true,
                'verify_host'     => true,
            ]);

            $status  = $response->getStatusCode();
            $type    = (string) ($response->getHeaders(false)['content-type'][0] ?? '');
            $content = $this->readCapped($response);
        } catch (TransportExceptionInterface $e) {
            throw new ExchangeFailure('transport_error', true, $e->getMessage());
        } catch (ExchangeFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ExchangeFailure('exchange_error', true, $e->getMessage());
        }

        if ($status >= 500) {
            throw new ExchangeFailure('registry_unavailable', true, 'Registry returned ' . $status);
        }

        if (200 !== $status) {
            throw new ExchangeFailure('registry_rejected', false, 'Registry returned ' . $status);
        }

        if (!str_contains(strtolower($type), 'application/json')) {
            throw new ExchangeFailure('unexpected_media_type', true, 'Response was not JSON.');
        }

        try {
            $decoded = $this->canonical->decode($content);
        } catch (\JsonException) {
            throw new ExchangeFailure('malformed_response', true, 'Response was not parseable JSON.');
        }

        if (!$decoded instanceof \stdClass) {
            throw new ExchangeFailure('malformed_response', true, 'Response was not a JSON object.');
        }

        // Correlation: a response that does not answer this request is not an
        // answer at all, whatever it claims.
        if (!\is_string($decoded->request_id ?? null) || !hash_equals($requestId, $decoded->request_id)) {
            throw new ExchangeFailure('request_correlation_failed', true, 'Response referenced another request.');
        }

        $serverTime = $decoded->server_time ?? null;
        if (!\is_int($serverTime) || abs($serverTime - $sentAt) > self::MAX_SKEW) {
            throw new ExchangeFailure('server_time_skew', true, 'Vendor clock is outside the accepted window.');
        }

        if (!\is_string($decoded->status ?? null) || '' === $decoded->status) {
            throw new ExchangeFailure('malformed_response', true, 'Response carried no status.');
        }

        return $decoded;
    }

    /**
     * Reads the body in chunks and aborts once the cap is passed, so a hostile
     * or broken peer cannot make this process buffer an unbounded response.
     *
     * @throws ExchangeFailure
     */
    private function readCapped(ResponseInterface $response): string
    {
        $content = '';

        foreach ($this->client->stream($response, self::TOTAL_TIMEOUT) as $chunk) {
            $content .= $chunk->getContent();

            if (\strlen($content) > self::MAX_RESPONSE_BYTES) {
                $response->cancel();

                throw new ExchangeFailure('response_too_large', true, 'Response exceeded the accepted size.');
            }
        }

        return $content;
    }

    private function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
