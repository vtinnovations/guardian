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
 * Inspects installed Composer packages and queries Packagist for available versions.
 *
 * Why we query Packagist directly instead of `composer outdated`:
 * `composer outdated` resolves the "latest" version against your composer.json
 * constraints. So if your root requires "contao/manager-bundle: 5.3.*", Composer
 * will report core-bundle 5.3.45 as "up-to-date" even when 5.7.x exists, because
 * 5.7.x would conflict with the manager-bundle constraint.
 *
 * For an updater dashboard we want to know what's ACTUALLY available on Packagist,
 * regardless of current constraints — so the user can decide whether to bump
 * the constraint and run a major upgrade.
 *
 * We hit https://repo.packagist.org/p2/<vendor>/<pkg>.json directly. This is the
 * same endpoint Composer itself uses, served via Cloudflare CDN, very fast.
 */
class PackageInspector
{
    private string $cacheFile;

    public function __construct(private readonly string $projectDir)
    {
        $this->cacheFile = $projectDir . '/var/updater/outdated.json';
    }

    /**
     * Returns all installed packages with their current version.
     */
    public function listInstalled(): array
    {
        $installedJson = $this->projectDir . '/vendor/composer/installed.json';
        if (!file_exists($installedJson)) {
            return [];
        }

        $content = @file_get_contents($installedJson);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return [];
        }

        $packages = $data['packages'] ?? $data;
        if (!\is_array($packages)) {
            return [];
        }

        $result = [];
        foreach ($packages as $pkg) {
            $name = $pkg['name'] ?? '';
            if ($name === '') {
                continue;
            }

            $result[] = [
                'name'        => $name,
                'version'     => ltrim((string) ($pkg['version'] ?? ''), 'v'),
                'type'        => (string) ($pkg['type']        ?? 'library'),
                'description' => (string) ($pkg['description'] ?? ''),
                'abandoned'   => isset($pkg['abandoned']),
            ];
        }

        usort($result, static function ($a, $b) {
            $aContao = str_contains($a['name'], 'contao') ? 0 : 1;
            $bContao = str_contains($b['name'], 'contao') ? 0 : 1;
            if ($aContao !== $bContao) {
                return $aContao - $bContao;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $result;
    }

    public function getOutdated(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && $this->isCacheFresh()) {
            return $this->loadCache();
        }

        return $this->refreshFromPackagist();
    }

    public function isCacheFresh(int $maxAgeSeconds = 86400): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        $mtime = @filemtime($this->cacheFile);
        if ($mtime === false) {
            return false;
        }

        return (time() - $mtime) < $maxAgeSeconds;
    }

    private function loadCache(): array
    {
        $content = @file_get_contents($this->cacheFile);
        if ($content === false) {
            return $this->emptyResult('Cache could not be read');
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return $this->emptyResult('Cache is malformed');
        }

        $data['cached'] = true;
        return $data;
    }

    /**
     * Queries Packagist for each installed package in parallel and finds the latest stable version.
     */
    private function refreshFromPackagist(): array
    {
        $installed = $this->listInstalled();
        if (empty($installed)) {
            return $this->emptyResult('No installed packages found');
        }

        // Build URL list
        $urls = [];
        foreach ($installed as $pkg) {
            // Only valid vendor/package names (skip pseudo-packages like ext-*)
            if (!preg_match('#^[a-z0-9._-]+/[a-z0-9._-]+$#i', $pkg['name'])) {
                continue;
            }
            $urls[$pkg['name']] = 'https://repo.packagist.org/p2/' . $pkg['name'] . '.json';
        }

        $responses = $this->parallelFetch($urls);

        // Build results
        $packages = [];
        foreach ($installed as $pkg) {
            $name    = $pkg['name'];
            $current = $pkg['version'];

            $latest = $this->extractLatestStable($responses[$name] ?? null);

            $hasUpdate = false;
            if ($latest !== null && $current !== '' && $current !== $latest) {
                // Only count as "newer" if latest is strictly higher
                if (version_compare($current, $latest, '<')) {
                    $hasUpdate = true;
                }
            }

            $packages[] = [
                'name'          => $name,
                'current'       => $current,
                'latest'        => $latest ?? '',
                'has_update'    => $hasUpdate,
                // We can't easily tell "blocked" without resolving deps, so leave false.
                // The user sees the latest version and can decide.
                'is_blocked'    => false,
                'latest_status' => $hasUpdate ? 'update-available' : 'up-to-date',
                'description'   => $pkg['description'],
            ];
        }

        $result = [
            'packages'   => $packages,
            'checked_at' => date('c'),
            'cached'     => false,
            'error'      => null,
            'source'     => 'packagist-api',
        ];

        $this->writeCache($result);

        return $result;
    }

    /**
     * Extracts the latest stable version from a Packagist /p2/ JSON response.
     * Falls back to highest version if no stable version exists.
     */
    private function extractLatestStable(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!\is_array($data) || empty($data['packages'])) {
            return null;
        }

        // /p2/ format: { "packages": { "vendor/name": [ {version, ...}, ... ] } }
        // The first entry in the array is normally the highest version.
        $versions = reset($data['packages']);
        if (!\is_array($versions)) {
            return null;
        }

        $stableLatest = null;
        $anyLatest    = null;

        foreach ($versions as $entry) {
            $version = (string) ($entry['version'] ?? '');
            if ($version === '') {
                continue;
            }
            $clean = ltrim($version, 'v');

            // Skip dev versions (dev-main, 6.0.x-dev, etc.)
            if (str_starts_with($version, 'dev-') || str_ends_with($version, '-dev')) {
                continue;
            }

            $anyLatest ??= $clean;

            // Stable = no -RC, -beta, -alpha suffix
            if (preg_match('/-(?:rc|beta|alpha|dev)/i', $clean)) {
                continue;
            }

            $stableLatest = $clean;
            break; // packages array is sorted highest-first
        }

        return $stableLatest ?? $anyLatest;
    }

    /**
     * Fetches multiple URLs in parallel via curl_multi.
     *
     * @param array<string, string> $urls  Map of key => URL
     * @return array<string, ?string>      Map of key => response body (null on error)
     */
    private function parallelFetch(array $urls): array
    {
        if (!\function_exists('curl_multi_init')) {
            return $this->serialFetch($urls);
        }

        $multi   = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($urls as $key => $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                \CURLOPT_URL            => $url,
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_FOLLOWLOCATION => true,
                \CURLOPT_TIMEOUT        => 10,
                \CURLOPT_CONNECTTIMEOUT => 5,
                \CURLOPT_USERAGENT      => 'Guardian/1.0',
                \CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        // Run requests concurrently
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === \CURLM_OK);

        foreach ($handles as $key => $ch) {
            $code = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
            $body = curl_multi_getcontent($ch);
            $results[$key] = ($code >= 200 && $code < 300) ? $body : null;
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $results;
    }

    /**
     * Fallback when curl_multi is unavailable: serial requests via file_get_contents.
     */
    private function serialFetch(array $urls): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header'  => "User-Agent: Guardian/1.0\r\nAccept: application/json\r\n",
            ],
        ]);

        $results = [];
        foreach ($urls as $key => $url) {
            $body = @file_get_contents($url, false, $context);
            $results[$key] = ($body === false) ? null : $body;
        }
        return $results;
    }

    private function writeCache(array $result): void
    {
        $dir = \dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(
            $this->cacheFile,
            json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE)
        );
    }

    private function emptyResult(?string $error = null): array
    {
        return [
            'packages'   => [],
            'checked_at' => date('c'),
            'cached'     => false,
            'error'      => $error,
        ];
    }
}
