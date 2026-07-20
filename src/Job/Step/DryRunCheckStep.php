<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Job\Step;

use Symfony\Component\Process\PhpExecutableFinder;
use Vtinnovations\Guardian\Job\JobLog;
use Vtinnovations\Guardian\Job\UpdateJob;
use Vtinnovations\Guardian\Service\RuntimeConfig;

/**
 * Step (post-bump, pre-composer): runs `composer update --dry-run` against
 * the already-bumped composer.json to detect resolution failures BEFORE
 * actually mutating vendor/.
 *
 * WHY THIS EXISTS
 * ---------------
 * The constraint editor lets the admin choose new constraints (e.g. all
 * contao/* go from 5.3.* to 5.7.*). If a third-party Contao bundle in the
 * project doesn't support 5.7 yet, composer will fail to resolve. Without
 * this step, the failure happens INSIDE the real composer update step,
 * after vendor/ may already be partially modified, with the user staring
 * at composer's wall-of-text and no obvious next step.
 *
 * With this step, we discover the conflict in a dry-run first, show the
 * focused error message ("rocksolid-frontend-helper ^2.2 doesn't allow
 * contao/core-bundle 5.7.*"), abort the job, and let the JobRunner roll
 * back composer.json. vendor/ is untouched.
 *
 * The dry-run is much faster than a real update because it doesn't
 * download/install anything.
 *
 * IMPORTANT: This catches *resolution* failures (the dependency graph
 * doesn't have a valid solution). It does NOT catch runtime failures
 * (resolution worked but the resulting code is incompatible at the PHP
 * type level) — that's what BootCheckStep is for.
 */
class DryRunCheckStep implements StepInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly string $projectDir,
        private readonly RuntimeConfig $runtimeConfig,
    ) {
    }

    public function name(): string
    {
        return 'dry_run_check';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        // Skip if no constraint bump was applied — there's nothing new to
        // check. The user might also have disabled the check explicitly.
        if (empty($job->options['constraint_bump_applied'])) {
            $log->info('dry_run_check', 'No constraint bump applied for this job, skipping dry-run check.');
            return;
        }

        $php = $this->getPhpBinary();
        if ($php === null) {
            $log->warning('dry_run_check', 'No PHP CLI binary available, skipping dry-run check.');
            return;
        }

        $composer = $this->findComposer();
        if ($composer === null) {
            $log->warning('dry_run_check', 'composer.phar not found, skipping dry-run check.');
            return;
        }

        $log->info('dry_run_check', 'Running composer update --dry-run to verify the bumped constraints can be resolved…');

        // We use --no-interaction --no-scripts to avoid prompts and to keep
        // it fast. --with-all-dependencies is critical: without it, composer
        // refuses to bump transitive deps that need to move along with our
        // direct deps, and we get a false-negative "no solution" error.
        //
        // We do NOT pass --no-install / similar — --dry-run already implies
        // "don't actually write anything", so we can rely on that.
        $result = $this->runner->runCapturing(
            [
                $php,
                $composer,
                'update',
                '--dry-run',
                '--no-interaction',
                '--no-scripts',
                '--with-all-dependencies',
            ],
            $log,
            'dry_run_check',
            300
        );

        $combined = $result['stdout'] . "\n" . $result['stderr'];

        if ($result['exit'] === 0) {
            $log->info('dry_run_check', '✓ Dry-run succeeded — composer can resolve the new constraints.');
            return;
        }

        // Try to extract the most useful chunk of composer's error message.
        // Composer's "Your requirements could not be resolved" block is the
        // canonical resolution-failure indicator; it lists which packages
        // are blocking each other.
        $excerpt = $this->extractConflictExcerpt($combined);

        throw new \RuntimeException(sprintf(
            "Dry-run check FAILED: composer cannot resolve the new constraints.\n\n"
          . "%s\n\n"
          . "Your composer.json will be rolled back automatically. "
          . "Common cause: a third-party bundle in your composer.json doesn't yet "
          . "support the target Contao version. Either wait for the bundle to be "
          . "updated, or bump the bundle's constraint at the same time as Contao.",
            $excerpt
        ));
    }

    /**
     * Pull out the most relevant chunk of composer's resolution-error text.
     * Composer typically prints a long header, then a "Problem N:" section
     * that's the actually useful bit.
     */
    private function extractConflictExcerpt(string $output): string
    {
        // Capture from "Problem 1:" / "Your requirements could not be resolved" onwards
        if (preg_match('/((?:Your requirements could not be resolved.*$|Problem \d+:.*$)(?:\R.*?)*?)(?=\R{2,}|\z)/sm', $output, $m)) {
            return trim($m[1]);
        }

        // Fallback: last 30 lines of output
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        if (\count($lines) > 30) {
            $lines = \array_slice($lines, -30);
        }
        return implode("\n", $lines);
    }

    private function getPhpBinary(): ?string
    {
        $configured = $this->runtimeConfig->getPhpBinary();
        if ($configured !== null && $configured !== '') {
            return $configured;
        }
        if (\defined('PHP_BINARY') && \PHP_BINARY !== '') {
            return \PHP_BINARY;
        }
        $found = (new PhpExecutableFinder())->find();
        return $found !== false && $found !== '' ? $found : null;
    }

    /**
     * Same composer-discovery logic as ComposerUpdateStep. Kept in sync
     * deliberately to avoid coupling two steps via a shared service —
     * the steps are independent units.
     */
    private function findComposer(): ?string
    {
        $pharCandidates = [
            $this->projectDir . '/composer.phar',
            '/opt/psa/var/modules/composer/composer.phar',
            '/usr/local/share/composer/composer.phar',
            '/usr/local/bin/composer.phar',
            '/usr/share/composer/composer.phar',
        ];

        foreach ($pharCandidates as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }

        // Try shell wrappers
        foreach (['/usr/local/bin/composer', '/usr/bin/composer'] as $wrapper) {
            $content = @file_get_contents($wrapper, false, null, 0, 1024);
            if ($content === false || $content === '') {
                continue;
            }
            if (preg_match('#["\']?(/[^\s"\']+\.phar)["\']?#', $content, $m)) {
                if (file_exists($m[1]) && is_readable($m[1])) {
                    return $m[1];
                }
            }
        }

        return null;
    }
}
