<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Service;

use Symfony\Component\Process\Process;
use Vtinnovations\Guardian\Job\JobLog;

/**
 * Detects packages that have newer MAJOR versions available beyond the user's
 * current composer.json constraints.
 *
 * `composer outdated` by default only reports updates within the current
 * constraints. We use `composer outdated --all` (or check Packagist directly)
 * to see what's REALLY out there, then surface that in the UI so the admin
 * can decide which constraints to bump.
 *
 * Output for each package:
 *   - name              (e.g. "contao/core-bundle")
 *   - current_version   (5.3.45)
 *   - current_constraint ("5.3.*")
 *   - latest_compatible (5.3.46 — what minor mode would install)
 *   - latest_overall    (5.7.5 — what's available if we bump the constraint)
 *   - suggested_constraint ("5.7.*" — what the new constraint would be)
 *   - has_major_update  (true if latest_overall has a different major/minor)
 *   - is_contao         (special-cased so UI can group them prominently)
 */
class MajorVersionInspector
{
    public function __construct(
        private readonly string $projectDir,
        private readonly RuntimeConfig $runtimeConfig,
    ) {
    }

    /**
     * Returns the full list of packages with version/constraint analysis.
     * `composer outdated --all --format=json` is the data source.
     *
     * @param string|null $php       Optional PHP binary path. Defaults to PHP_BINARY.
     * @param string      $composer  Path to composer.phar (REQUIRED — we don't fall back to shell wrappers here for the same reason as elsewhere).
     * @return array<int, array<string, mixed>>
     */
    public function detect(string $composer, ?string $php = null, ?JobLog $log = null): array
    {
        $php = $php ?? $this->resolvePhp();
        if ($php === null) {
            throw new \RuntimeException('No PHP CLI binary configured');
        }

        $composerJson = $this->loadComposerJson();
        if ($composerJson === null) {
            throw new \RuntimeException('composer.json not found or not readable');
        }

        // Outdated --all: shows every direct dependency, including those
        // already at the highest version allowed by the constraint. We need
        // this because we want to know "could 5.7.5 be installed if we
        // changed the constraint?" — which `--direct` alone doesn't tell us.
        $cmd = [$php, $composer, 'outdated', '--all', '--direct', '--no-interaction', '--format=json'];

        if ($log !== null) {
            $log->info('inspector', 'Running: ' . implode(' ', $cmd));
        }

        $proc = new Process($cmd, $this->projectDir, null, null, 180);
        $proc->run();

        if (!$proc->isSuccessful()) {
            $err = trim($proc->getErrorOutput() ?: $proc->getOutput());
            throw new \RuntimeException('composer outdated failed: ' . $err);
        }

        $data = json_decode($proc->getOutput(), true);
        if (!\is_array($data) || !isset($data['installed'])) {
            return [];
        }

        $requires = $composerJson['require'] ?? [];

        $result = [];
        foreach ($data['installed'] as $pkg) {
            $name = (string) ($pkg['name'] ?? '');
            if ($name === '') {
                continue;
            }

            // Skip platform "packages" like php, ext-intl — they're not updatable via composer
            if (str_starts_with($name, 'ext-') || $name === 'php' || str_starts_with($name, 'lib-')) {
                continue;
            }

            // Only consider direct dependencies — packages mentioned in `require`.
            // We skip transitive deps because the admin shouldn't (and usually
            // can't) bump constraints for those — they're locked by their parents.
            if (!isset($requires[$name])) {
                continue;
            }

            $currentVersion  = (string) ($pkg['version'] ?? '?');
            $latestOverall   = (string) ($pkg['latest'] ?? '?');
            $currentConstraint = (string) $requires[$name];

            $analysis = $this->analyseVersionJump($name, $currentVersion, $latestOverall, $currentConstraint);

            $result[] = [
                'name'                  => $name,
                'current_version'       => $currentVersion,
                'current_constraint'    => $currentConstraint,
                'latest_overall'        => $latestOverall,
                'suggested_constraint'  => $analysis['suggested_constraint'],
                'has_major_update'      => $analysis['has_major_update'],
                'has_minor_update'      => $analysis['has_minor_update'],
                'jump_type'             => $analysis['jump_type'],  // 'patch'|'minor'|'major'|'none'
                'jump_label'            => $analysis['jump_label'],
                'uses_contao_versioning'=> $this->isContaoStyleVersioning($name),
                'is_contao_core'        => $this->isContaoCore($name),
                'is_contao_bundle'      => $this->isContaoBundle($name),
            ];
        }

        // Sort: Contao core first, then other Contao bundles, then by name
        usort($result, static function ($a, $b) {
            if ($a['is_contao_core'] !== $b['is_contao_core']) {
                return $a['is_contao_core'] ? -1 : 1;
            }
            if ($a['is_contao_bundle'] !== $b['is_contao_bundle']) {
                return $a['is_contao_bundle'] ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $result;
    }

    /**
     * Compares two version strings and suggests a new constraint that allows
     * the latest version. Tries to preserve the user's constraint style:
     *   - "5.3.*" + latest 5.7.5  →  "5.7.*"
     *   - "^5.3"  + latest 5.7.5  →  "^5.7"
     *   - "^5.3"  + latest 6.0.0  →  "^6.0"
     *   - "~5.3.0" + latest 5.3.46 → "~5.3.0" (already compatible — no change needed)
     *
     * For Contao-style packages (contao/*), a second-digit bump is treated as
     * MAJOR rather than minor, because Contao ships breaking changes inside
     * the same first-digit. For example, 5.3 → 5.7 is a major feature release
     * that may break templates, deprecate APIs, or require migrations.
     *
     * @return array{
     *     suggested_constraint: string,
     *     has_major_update: bool,
     *     has_minor_update: bool,
     *     jump_type: string,
     *     jump_label: string,
     * }
     */
    private function analyseVersionJump(string $packageName, string $current, string $latest, string $constraint): array
    {
        $curParts = $this->parseVersion($current);
        $latParts = $this->parseVersion($latest);

        if ($curParts === null || $latParts === null) {
            return [
                'suggested_constraint' => $constraint,
                'has_major_update'     => false,
                'has_minor_update'     => false,
                'jump_type'            => 'none',
                'jump_label'           => 'unknown',
            ];
        }

        $contaoStyle = $this->isContaoStyleVersioning($packageName);

        // Determine raw semver jump
        $rawJump = 'none';
        if ($latParts[0] > $curParts[0]) {
            $rawJump = 'major';
        } elseif ($latParts[0] === $curParts[0] && $latParts[1] > $curParts[1]) {
            $rawJump = 'minor';
        } elseif ($latParts[0] === $curParts[0] && $latParts[1] === $curParts[1] && $latParts[2] > $curParts[2]) {
            $rawJump = 'patch';
        }

        // For Contao-style packages, promote semver-minor to "major" because
        // Contao 5.3 → 5.7 is conceptually a major feature release with
        // potential breaking changes.
        $jumpType = $rawJump;
        $jumpLabel = $rawJump;

        if ($contaoStyle && $rawJump === 'minor') {
            $jumpType  = 'major';
            $jumpLabel = 'major (Contao feature release)';
        } elseif ($contaoStyle && $rawJump === 'major') {
            $jumpLabel = 'major (Contao LTS jump)';
        }

        $suggested = $this->buildSuggestedConstraint($constraint, $latParts, $rawJump);

        return [
            'suggested_constraint' => $suggested,
            'has_major_update'     => $jumpType === 'major',
            'has_minor_update'     => $jumpType === 'minor' || $jumpType === 'major',
            'jump_type'            => $jumpType,
            'jump_label'           => $jumpLabel,
        ];
    }

    /**
     * Build a new constraint string that would allow $targetVersion while
     * preserving the style of $currentConstraint where possible.
     *
     * @param array{0:int,1:int,2:int} $targetParts
     */
    private function buildSuggestedConstraint(string $currentConstraint, array $targetParts, string $jumpType): string
    {
        if ($jumpType === 'none' || $jumpType === 'patch') {
            // No constraint change needed
            return $currentConstraint;
        }

        $trimmed = trim($currentConstraint);

        // Style 1: "X.Y.*" — bump to new X.Y
        if (preg_match('/^(\d+)\.(\d+)\.\*$/', $trimmed, $m)) {
            return $targetParts[0] . '.' . $targetParts[1] . '.*';
        }

        // Style 2: "^X.Y" or "^X.Y.Z" — bump to ^TargetMajor.TargetMinor
        if (preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/', $trimmed, $m)) {
            return '^' . $targetParts[0] . '.' . $targetParts[1];
        }

        // Style 3: "~X.Y" or "~X.Y.Z" — tilde, bump similarly
        if (preg_match('/^~(\d+)\.(\d+)(?:\.(\d+))?$/', $trimmed, $m)) {
            return '~' . $targetParts[0] . '.' . $targetParts[1] . '.0';
        }

        // Style 4: ">= X.Y" — leave as is, since constraint is already open
        if (preg_match('/^>=?\s*\d/', $trimmed)) {
            return $currentConstraint;
        }

        // Fallback: ^TargetMajor.TargetMinor (most common composer style)
        return '^' . $targetParts[0] . '.' . $targetParts[1];
    }

    /**
     * Parses a version string into [major, minor, patch]. Returns null on failure.
     * Handles common forms: "5.3.45", "v5.3.45", "5.3.45-beta1".
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private function parseVersion(string $version): ?array
    {
        $version = ltrim($version, 'v');
        if (preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $m)) {
            return [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
        }
        return null;
    }

    private function isContaoCore(string $name): bool
    {
        return $name === 'contao/manager-bundle'
            || $name === 'contao/core-bundle';
    }

    private function isContaoBundle(string $name): bool
    {
        return str_starts_with($name, 'contao/');
    }

    /**
     * Returns true if the package follows Contao's release convention, where a
     * second-digit bump (5.3 → 5.7) is a "major feature release" with breaking
     * changes — not a minor as semver would call it.
     *
     * Contao itself never reaches semver-major within an LTS cycle: they ship
     * breaking changes inside the same first-digit (5.x) and only bump the
     * first digit for the next LTS (6.0). So treating 5.3 → 5.7 as a minor
     * (no warning, simple constraint widening) gives users a false sense of
     * safety.
     *
     * For now we apply this to all packages under the contao/ namespace.
     * Third-party Contao bundles can be added here later if needed.
     */
    private function isContaoStyleVersioning(string $name): bool
    {
        return str_starts_with($name, 'contao/');
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

    private function resolvePhp(): ?string
    {
        $configured = $this->runtimeConfig->getPhpBinary();
        if ($configured !== null && $configured !== '') {
            return $configured;
        }
        return \defined('PHP_BINARY') && \PHP_BINARY !== '' ? \PHP_BINARY : null;
    }
}
