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

/**
 * Deterministic byte-exact serialisation helpers.
 *
 * Two separate message formats are produced here, and both must match the
 * remote side byte for byte or every signature check fails:
 *
 *   canonical form  – recursively key-sorted, list-order-preserving, compact
 *                     UTF-8 JSON with unescaped slashes/Unicode. Used for the
 *                     record document and the integrity envelope.
 *   request form    – six newline-joined lines describing an inbound HTTP
 *                     request (method, path, request id, timestamp, nonce,
 *                     lowercase hex SHA-256 of the raw body).
 *
 * Decoded input must come from json_decode() with $associative = false so that
 * JSON objects arrive as stdClass and JSON arrays as PHP lists. Decoding to
 * associative arrays destroys that distinction: an empty object and an empty
 * array both become [] and would re-encode differently from the signed bytes.
 */
final class CanonicalForm
{
    /** Encoding flags. Pretty printing, escaped slashes and \uXXXX are all forbidden. */
    private const FLAGS = \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE
        | \JSON_PRESERVE_ZERO_FRACTION
        | \JSON_THROW_ON_ERROR;

    /** Nesting cap applied when decoding untrusted documents. */
    private const MAX_DEPTH = 32;

    /**
     * Decodes untrusted JSON bytes preserving the object/list distinction.
     *
     * @throws \JsonException on malformed input
     */
    public function decode(string $bytes): mixed
    {
        return json_decode($bytes, false, self::MAX_DEPTH, \JSON_THROW_ON_ERROR);
    }

    /**
     * Serialises a value into its canonical byte form.
     *
     * @throws \JsonException
     */
    public function encode(mixed $value): string
    {
        return json_encode($this->order($value), self::FLAGS);
    }

    /**
     * Canonical bytes of a signed document, with the detached `signature`
     * property removed. Everything else — including nested structures and the
     * order of `license_domains` — is preserved exactly as received.
     *
     * @throws \JsonException
     */
    public function detachedMessage(\stdClass $document): string
    {
        $copy = clone $document;
        unset($copy->signature);

        return $this->encode($copy);
    }

    /**
     * The `vt-one/request-sig-v1` message: six lines joined with "\n" and no
     * trailing newline. The key id header selects the verification key and is
     * deliberately NOT part of the signed message.
     */
    public function requestMessage(
        string $method,
        string $path,
        string $requestId,
        int $timestamp,
        string $nonce,
        string $rawBody,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $requestId,
            (string) $timestamp,
            $nonce,
            $this->bodyDigest($rawBody),
        ]);
    }

    /** Lowercase hexadecimal SHA-256 of the exact raw bytes. */
    public function bodyDigest(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * Recursively sorts object properties in ascending bytewise order while
     * leaving list order untouched. Scalars pass through unchanged so that
     * false never becomes "false" and null never becomes 0.
     */
    private function order(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            ksort($props, \SORT_STRING);

            $ordered = new \stdClass();
            foreach ($props as $name => $child) {
                $ordered->{$name} = $this->order($child);
            }

            return $ordered;
        }

        if (\is_array($value)) {
            return array_map(fn ($child) => $this->order($child), $value);
        }

        return $value;
    }
}
