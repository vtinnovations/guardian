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
 * Manages authentication for the external recovery panel.
 *
 * Two sources, in order of precedence:
 *   1. .env / .env.local: VTINNOVATIONS_GUARDIAN_TOKEN=...
 *   2. Auto-generated file: var/updater/access.token
 *
 * The auto-generated file is created on demand. Anyone who can access the
 * Contao backend can read it (and rotate it). Anyone who can SSH into the
 * server can read it too — but that's true of any secret stored on the
 * filesystem, and an SSH attacker doesn't need our token anyway.
 *
 * Authentication is checked via:
 *   - HTTP Basic Auth (username can be anything, password is the token)
 *   - Or query string ?token=... (only useful for first-time bookmarking)
 *
 * Both methods compare with hash_equals() to defend against timing attacks.
 */
class PanelAuth
{
    private const ENV_KEY    = 'VTINNOVATIONS_GUARDIAN_TOKEN';
    private const TOKEN_FILE = '/var/updater/access.token';

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * Returns the active token, generating one if neither env nor file exists.
     */
    public function getActiveToken(): string
    {
        // 1. Environment variable
        $envToken = $this->readEnvToken();
        if ($envToken !== null && $envToken !== '') {
            return $envToken;
        }

        // 2. Auto-generated file
        $filePath = $this->projectDir . self::TOKEN_FILE;
        if (file_exists($filePath)) {
            $content = trim((string) @file_get_contents($filePath));
            if ($content !== '') {
                return $content;
            }
        }

        // 3. Generate new
        return $this->generateAndStore();
    }

    /**
     * Returns where the active token came from, for the UI to display.
     * @return 'env'|'file'|'missing'
     */
    public function getTokenSource(): string
    {
        if ($this->readEnvToken() !== null) {
            return 'env';
        }
        if (file_exists($this->projectDir . self::TOKEN_FILE)) {
            return 'file';
        }
        return 'missing';
    }

    public function rotateToken(): string
    {
        return $this->generateAndStore();
    }

    /**
     * Validates a presented token against the active one.
     * Uses hash_equals() to prevent timing attacks.
     */
    public function isValidToken(?string $presented): bool
    {
        if ($presented === null || $presented === '') {
            return false;
        }

        $active = $this->getActiveToken();
        if ($active === '') {
            return false;
        }

        // hash_equals needs equal-length strings to truly be timing-safe
        // but it short-circuits on length mismatch — which is fine for us
        return hash_equals($active, $presented);
    }

    /**
     * Authenticates an incoming HTTP request.
     *
     * Accepts only header-based credentials:
     *   - HTTP Basic Auth (Authorization: Basic <base64>)
     *   - Bearer token (Authorization: Bearer <token>)
     *
     * Query-string tokens (?token=...) are intentionally NOT supported anymore.
     * They leak into:
     *   - Apache/Nginx access logs
     *   - Browser address bar and history
     *   - HTTP Referer headers sent to external resources
     *   - Reverse proxy / CDN logs
     *   - Browser extensions and DevTools
     *
     * For state-changing requests (POST/PUT/PATCH/DELETE), additionally enforces
     * an Origin/Referer same-origin check (CSRF defence) on top of the token.
     */
    public function authenticateRequest(\Symfony\Component\HttpFoundation\Request $request): bool
    {
        if (!$this->checkSameOriginForUnsafeRequests($request)) {
            return false;
        }

        // 1. HTTP Basic Auth (PHP_AUTH_PW)
        $basicPw = $request->getPassword();
        if ($basicPw !== null && $this->isValidToken($basicPw)) {
            return true;
        }

        // 2. Authorization: Bearer <token>
        $authHeader = $request->headers->get('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $bearerToken = substr($authHeader, 7);
            if ($this->isValidToken($bearerToken)) {
                return true;
            }
        }

        return false;
    }

    /**
     * For state-changing requests (POST/PUT/PATCH/DELETE), require that the
     * Origin header matches the request host. If Origin is absent (older
     * clients, command-line tools), fall back to Referer. If neither is
     * present, reject — we can't tell where the request came from.
     *
     * GET/HEAD/OPTIONS are exempt (they shouldn't have side-effects anyway,
     * and exempting them keeps cURL / `curl -u` style usage working).
     */
    private function checkSameOriginForUnsafeRequests(\Symfony\Component\HttpFoundation\Request $request): bool
    {
        $method = $request->getMethod();
        if (\in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $expectedHost = $request->getHost();

        $origin = $request->headers->get('Origin');
        if (\is_string($origin) && $origin !== '' && $origin !== 'null') {
            $originHost = parse_url($origin, \PHP_URL_HOST);
            return \is_string($originHost) && strcasecmp($originHost, $expectedHost) === 0;
        }

        $referer = $request->headers->get('Referer');
        if (\is_string($referer) && $referer !== '') {
            $refererHost = parse_url($referer, \PHP_URL_HOST);
            return \is_string($refererHost) && strcasecmp($refererHost, $expectedHost) === 0;
        }

        // Neither Origin nor Referer present on an unsafe request → refuse.
        // Browsers always include at least one of these. CLI tools sending
        // POST without either are uncommon; users can still use GET-only
        // endpoints with ?token= for read-only access.
        return false;
    }

    private function readEnvToken(): ?string
    {
        // Symfony has already loaded .env into $_SERVER / $_ENV
        $value = $_SERVER[self::ENV_KEY] ?? $_ENV[self::ENV_KEY] ?? null;
        if ($value !== null && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        // Fallback: parse .env.local manually in case env hasn't been loaded
        // (e.g. in standalone fallback mode if Symfony bootstrap is broken)
        foreach (['.env.local', '.env'] as $filename) {
            $file = $this->projectDir . '/' . $filename;
            if (!file_exists($file)) {
                continue;
            }

            $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                if (trim($k) === self::ENV_KEY) {
                    return trim($v, " \t\"'");
                }
            }
        }

        return null;
    }

    private function generateAndStore(): string
    {
        $token = bin2hex(random_bytes(48)); // 48 random bytes → 96-char hex token

        $filePath = $this->projectDir . self::TOKEN_FILE;
        $dir      = \dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents($filePath, $token);
        @chmod($filePath, 0600);

        return $token;
    }
}
