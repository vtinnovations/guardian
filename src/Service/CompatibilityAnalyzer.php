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
 * Layer-1 pre-flight check for major upgrades.
 *
 * Given a planned constraint change like:
 *     contao/core-bundle: 5.3.* → 5.7.*
 *
 * this service asks, for every direct dependency in composer.json:
 *
 *     "Does a published version exist that supports the new Contao version?"
 *     "Does the user's current constraint allow upgrading to it?"
 *
 * It uses the public Packagist HTTP API (https://repo.packagist.org/p2/...)
 * to fetch package metadata. One HTTP request per package, parsed locally,
 * no composer subprocess required. Total runtime for a typical project:
 * a few seconds, well under FPM timeouts.
 *
 * If Packagist is unreachable (no outbound HTTPS, firewall, custom repo),
 * the package gets a "warning" finding instead of a hard error.
 *
 * The check is intentionally heuristic — composer's own resolution may
 * still find creative conflicts deeper in the graph. This is a fast,
 * focused pre-flight that catches the common case where a third-party
 * Contao bundle simply doesn't have a release for the new Contao yet.
 */
class CompatibilityAnalyzer
{
    /**
     * Packagist API endpoint template. The %s is replaced with a lowercased
     * vendor/package string (e.g. madeyourday/contao-rocksolid-frontend-helper).
     *
     * We use the modern p2/ endpoint which returns all stable versions of
     * a package as a single JSON document.
     */
    private const PACKAGIST_URL_TEMPLATE = 'https://repo.packagist.org/p2/%s.json';

    public function __construct(
        private readonly string $projectDir,
        private readonly RuntimeConfig $runtimeConfig,
    ) {
    }

    /**
     * @param array<string, string> $constraintChanges
     * @return array{
     *     ok: bool,
     *     target_versions: array<string, string>,
     *     findings: list<array{
     *         name: string,
     *         current_constraint: string,
     *         blocks_target: bool,
     *         message: string,
     *         severity: string,
     *     }>,
     * }
     */
    public function analyse(array $constraintChanges): array
    {
        $composerJson = $this->loadComposerJson();
        if ($composerJson === null) {
            return [
                'ok'              => false,
                'target_versions' => [],
                'findings'        => [[
                    'name'               => '(composer.json)',
                    'current_constraint' => '',
                    'blocks_target'      => false,
                    'message'            => 'Could not read composer.json — cannot perform compatibility check.',
                    'severity'           => 'warning',
                ]],
            ];
        }

        $contaoTarget = $this->extractContaoTarget($constraintChanges);
        if ($contaoTarget === null) {
            return [
                'ok'              => true,
                'target_versions' => [],
                'findings'        => [],
            ];
        }

        $requires = $composerJson['require'] ?? [];
        if (!\is_array($requires)) {
            return ['ok' => true, 'target_versions' => ['contao/core-bundle' => $contaoTarget], 'findings' => []];
        }

        $findings   = [];
        $hasBlocker = false;

        foreach ($requires as $pkgName => $currentConstraint) {
            if (!\is_string($pkgName) || !\is_string($currentConstraint)) {
                continue;
            }

            // Skip the Contao packages themselves — they're what's being bumped.
            if (str_starts_with($pkgName, 'contao/')) {
                continue;
            }

            // dev-* constraints follow a moving branch — we can't statically
            // reason about what they require. Warn the user.
            if (str_starts_with($currentConstraint, 'dev-')
                || $currentConstraint === '@dev'
                || $currentConstraint === '*@dev'
            ) {
                $findings[] = [
                    'name'               => $pkgName,
                    'current_constraint' => $currentConstraint,
                    'blocks_target'      => false,
                    'message'            => 'Uses a dev branch constraint (' . $currentConstraint . '). '
                                          . 'Compatibility with Contao ' . $contaoTarget . ' depends on what is '
                                          . 'currently on that branch — cannot be verified without actually fetching.',
                    'severity'           => 'warning',
                ];
                continue;
            }

            // Fetch package metadata from Packagist
            $meta = $this->fetchPackagistMetadata($pkgName);
            if ($meta === null) {
                $findings[] = [
                    'name'               => $pkgName,
                    'current_constraint' => $currentConstraint,
                    'blocks_target'      => false,
                    'message'            => 'Could not query Packagist metadata for this package '
                                          . '(no outbound HTTPS? private package?). Compatibility unverified.',
                    'severity'           => 'warning',
                ];
                continue;
            }

            $compat = $this->checkPackageCompatibility($pkgName, $meta, $contaoTarget, $currentConstraint);
            $findings[] = $compat;
            if ($compat['blocks_target']) {
                $hasBlocker = true;
            }
        }

        return [
            'ok'              => !$hasBlocker,
            'target_versions' => ['contao/core-bundle' => $contaoTarget],
            'findings'        => $findings,
        ];
    }

    private function extractContaoTarget(array $constraintChanges): ?string
    {
        $priority = ['contao/manager-bundle', 'contao/core-bundle'];
        foreach ($priority as $name) {
            if (isset($constraintChanges[$name])) {
                return (string) $constraintChanges[$name];
            }
        }
        foreach ($constraintChanges as $name => $constraint) {
            if (\is_string($name) && str_starts_with($name, 'contao/')) {
                return (string) $constraint;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPackagistMetadata(string $pkgName): ?array
    {
        $url = sprintf(self::PACKAGIST_URL_TEMPLATE, strtolower($pkgName));

        // cURL works even with open_basedir set; preferred path.
        if (\function_exists('curl_init')) {
            return $this->fetchViaCurl($url);
        }
        return $this->fetchViaStreams($url);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchViaCurl(string $url): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Guardian-Compat-Check',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            return null;
        }
        $data = json_decode((string) $body, true);
        return \is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchViaStreams(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 10,
                'header'        => "Accept: application/json\r\nUser-Agent: Guardian-Compat-Check\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
        $data = json_decode((string) $body, true);
        return \is_array($data) ? $data : null;
    }

    /**
     * Packagist p2/ JSON shape:
     * {
     *   "packages": {
     *     "vendor/pkg": [
     *       { "version": "1.2.3", "require": { "contao/core-bundle": "^5.3" }, ... },
     *       ...
     *     ]
     *   }
     * }
     *
     * @param array<string, mixed> $meta
     * @return array{name:string, current_constraint:string, blocks_target:bool, message:string, severity:string}
     */
    private function checkPackageCompatibility(
        string $pkgName,
        array $meta,
        string $targetContao,
        string $currentConstraint,
    ): array {
        $packages = $meta['packages'] ?? [];
        $versions = $packages[strtolower($pkgName)] ?? null;

        if (!\is_array($versions) || empty($versions)) {
            return [
                'name'               => $pkgName,
                'current_constraint' => $currentConstraint,
                'blocks_target'      => false,
                'message'            => 'No version data returned by Packagist — compatibility unverified.',
                'severity'           => 'warning',
            ];
        }

        $contaoBindingVersions = [];
        $compatibleVersions    = [];

        foreach ($versions as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $ver = (string) ($entry['version'] ?? '');
            if ($ver === '') {
                continue;
            }
            if (str_starts_with($ver, 'dev-') || str_contains($ver, '-dev')) {
                continue;
            }

            $reqs = $entry['require'] ?? [];
            if (!\is_array($reqs) || !isset($reqs['contao/core-bundle'])) {
                continue;
            }

            $reqContao = (string) $reqs['contao/core-bundle'];
            $contaoBindingVersions[] = $ver;
            if ($this->constraintsIntersect($reqContao, $targetContao)) {
                $compatibleVersions[] = $ver;
            }
        }

        if (empty($contaoBindingVersions)) {
            return [
                'name'               => $pkgName,
                'current_constraint' => $currentConstraint,
                'blocks_target'      => false,
                'message'            => 'Does not require contao/core-bundle — not affected by Contao upgrade.',
                'severity'           => 'info',
            ];
        }

        if (empty($compatibleVersions)) {
            $latestExamples = \array_slice($contaoBindingVersions, -3);
            return [
                'name'               => $pkgName,
                'current_constraint' => $currentConstraint,
                'blocks_target'      => true,
                'message'            => sprintf(
                    'No published version of %s supports Contao %s. '
                  . 'Most recent versions only bind to: %s. '
                  . 'Wait for an upstream release, or revert the Contao constraint bump.',
                    $pkgName,
                    $targetContao,
                    implode(', ', $latestExamples)
                ),
                'severity'           => 'error',
            ];
        }

        $allowed = array_filter(
            $compatibleVersions,
            fn(string $v) => $this->versionSatisfies($v, $currentConstraint)
        );

        if (empty($allowed)) {
            $latestCompatible = end($compatibleVersions);
            $suggested = $this->suggestConstraint($latestCompatible);
            return [
                'name'               => $pkgName,
                'current_constraint' => $currentConstraint,
                'blocks_target'      => true,
                'message'            => sprintf(
                    'A compatible version exists (%s) but your current constraint %s does not allow it. '
                  . 'Bump this package too — suggested constraint: %s',
                    $latestCompatible,
                    $currentConstraint,
                    $suggested
                ),
                'severity'           => 'error',
            ];
        }

        return [
            'name'               => $pkgName,
            'current_constraint' => $currentConstraint,
            'blocks_target'      => false,
            'message'            => 'Compatible with target Contao version.',
            'severity'           => 'ok',
        ];
    }

    /**
     * Heuristic constraint-vs-version matcher. We deliberately avoid pulling
     * in composer/semver so this service works even when vendor/ is in an
     * unstable state.
     */
    private function versionSatisfies(string $version, string $constraint): bool
    {
        $v = ltrim($version, 'v');
        $v = preg_replace('/[-+].*$/', '', $v) ?? $v;
        $parts = explode('.', $v);
        if (\count($parts) < 2) {
            return false;
        }
        $vMaj = (int) $parts[0];
        $vMin = (int) $parts[1];
        $vPat = isset($parts[2]) ? (int) $parts[2] : 0;

        $c = trim($constraint);

        if (preg_match('/^(\d+)\.(\d+)\.\*$/', $c, $m)) {
            return $vMaj === (int) $m[1] && $vMin === (int) $m[2];
        }
        if (preg_match('/^(\d+)\.\*$/', $c, $m)) {
            return $vMaj === (int) $m[1];
        }
        if (preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/', $c, $m)) {
            $cMaj = (int) $m[1];
            $cMin = (int) $m[2];
            $cPat = isset($m[3]) ? (int) $m[3] : 0;
            if ($vMaj !== $cMaj) {
                return false;
            }
            return [$vMin, $vPat] >= [$cMin, $cPat];
        }
        if (preg_match('/^\^(\d+)$/', $c, $m)) {
            return $vMaj === (int) $m[1];
        }
        if (preg_match('/^~(\d+)\.(\d+)(?:\.(\d+))?$/', $c, $m)) {
            $cMaj = (int) $m[1];
            $cMin = (int) $m[2];
            if ($vMaj !== $cMaj || $vMin !== $cMin) {
                return false;
            }
            $cPat = isset($m[3]) ? (int) $m[3] : 0;
            return $vPat >= $cPat;
        }
        if (preg_match('/^>=\s*(\d+)\.(\d+)(?:\.(\d+))?$/', $c, $m)) {
            $cMaj = (int) $m[1];
            $cMin = (int) $m[2];
            $cPat = isset($m[3]) ? (int) $m[3] : 0;
            return [$vMaj, $vMin, $vPat] >= [$cMaj, $cMin, $cPat];
        }
        if (preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?$/', $c, $m)) {
            $cMaj = (int) $m[1];
            $cMin = (int) $m[2];
            $cPat = isset($m[3]) ? (int) $m[3] : 0;
            return $vMaj === $cMaj && $vMin === $cMin && $vPat === $cPat;
        }
        return true;
    }

    private function constraintsIntersect(string $packageRequirement, string $targetContao): bool
    {
        $probes = $this->enumerateTargetVersions($targetContao);
        foreach ($probes as $v) {
            if ($this->versionSatisfies($v, $packageRequirement)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function enumerateTargetVersions(string $constraint): array
    {
        $c = trim($constraint);
        if (preg_match('/^(\d+)\.(\d+)\.\*$/', $c, $m)) {
            $maj = $m[1];
            $min = $m[2];
            return ["$maj.$min.0", "$maj.$min.10", "$maj.$min.99"];
        }
        if (preg_match('/^\^(\d+)\.(\d+)/', $c, $m)) {
            $maj = $m[1];
            $min = $m[2];
            return ["$maj.$min.0", "$maj.$min.10", "$maj.99.0"];
        }
        if (preg_match('/^~(\d+)\.(\d+)/', $c, $m)) {
            $maj = $m[1];
            $min = $m[2];
            return ["$maj.$min.0", "$maj.$min.99"];
        }
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $c)) {
            return [$c];
        }
        return [$c];
    }

    private function suggestConstraint(string $version): string
    {
        $v = ltrim($version, 'v');
        $v = preg_replace('/[-+].*$/', '', $v) ?? $v;
        $parts = explode('.', $v);
        if (\count($parts) >= 2) {
            return '^' . $parts[0] . '.' . $parts[1];
        }
        return '^' . $v;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadComposerJson(): ?array
    {
        $path = $this->projectDir . '/composer.json';
        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return \is_array($data) ? $data : null;
    }
}
