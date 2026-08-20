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

namespace Vtinnovations\Guardian\Job\Step;

use Symfony\Component\Process\PhpExecutableFinder;
use Vtinnovations\Guardian\Job\JobLog;
use Vtinnovations\Guardian\Job\UpdateJob;
use Vtinnovations\Guardian\Service\PlatformChecker;
use Vtinnovations\Guardian\Service\RuntimeConfig;

/**
 * Step 2: Run composer update.
 *
 * Step 5b-1: dry-run logic.
 * Step 5d:   real update with three modes (full / patch / selective).
 *
 * IMPORTANT: composer is ALWAYS invoked as `<configured-php> <composer.phar>`
 * — never via a shell wrapper. The wrapper might pick its own PHP CLI
 * (often the system /usr/bin/php) which can have a different extension set
 * than the PHP that actually runs the website. That mismatch caused real
 * problems on Plesk: contao/core-bundle requires ext-intl, the site's PHP
 * has it, but the system /usr/bin/php doesn't — so composer refused to
 * install anything. By driving composer through our own PHP binary, the
 * platform-requirement checks composer does match the runtime checks the
 * site does, and ext-intl etc. are seen consistently.
 */
class ComposerUpdateStep implements StepInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly string $projectDir,
        private readonly PlatformChecker $platformChecker,
        private readonly RuntimeConfig $runtimeConfig,
    ) {
    }

    public function name(): string
    {
        return 'composer';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        // Resolve the PHP CLI binary we use everywhere else.
        // composer is ALWAYS invoked through this PHP so its platform checks
        // see the same extension set as our website runtime.
        $php = $this->getPhpBinary();
        if ($php === null) {
            throw new \RuntimeException(
                'PHP CLI binary not configured. Open the backend Updater → PHP CLI settings '
              . 'and set the absolute path (e.g. /opt/plesk/php/8.4/bin/php).'
            );
        }

        $composer = $this->findComposer();
        if ($composer === null) {
            throw new \RuntimeException(
                'composer.phar not found. Tried project root, /opt/psa/var/modules/composer/, '
              . '/usr/local/share/composer/, /usr/share/composer/. '
              . 'You can place a copy of composer.phar in the project root to fix this.'
            );
        }

        $log->info('composer', sprintf('Using PHP CLI: %s', $php));
        $log->info('composer', sprintf('Using composer: %s', $composer));

        // Detect CLI vs web extension mismatches and prepare --ignore-platform-req
        // flags for extensions that the web PHP has but CLI PHP doesn't.
        // Because we now drive composer via OUR PHP binary, the CLI extensions
        // composer sees are the same ones our worker sees — so we can trust
        // the standard (non-aggressive) check.
        $platform = $this->platformChecker->getComposerIgnoreFlags(true);

        foreach ($platform['warnings'] as $warning) {
            $log->warning('composer', $warning);
        }

        if (!empty($platform['real_missing'])) {
            // Some required extensions are missing in BOTH web and CLI PHP.
            // That's a real problem — don't proceed.
            throw new \RuntimeException(sprintf(
                'Required PHP extension(s) genuinely missing on this server: ext-%s. '
                . 'These are needed in both CLI and web PHP. Install them via your hosting control panel before running updates.',
                implode(', ext-', $platform['real_missing'])
            ));
        }

        $extraFlags = $platform['flags'];
        if (!empty($extraFlags)) {
            $log->info('composer', sprintf(
                'Auto-adding %d platform-ignore flag(s) to compensate for CLI/web extension differences.',
                \count($extraFlags)
            ));
        }

        // Helper: build a composer command as [php, composer.phar, ...args].
        // ALWAYS via $php, never let the shell pick an interpreter.
        $buildCmd = static fn (array $args): array => array_merge([$php, $composer], $args);

        if ($job->isDryRun()) {
            $log->step('composer', 'Dry-run: simulating composer update');

            $dryCmd = $buildCmd(array_merge(
                ['update', '--no-interaction', '--dry-run', '--no-progress'],
                $extraFlags
            ));

            $this->runner->dryRun(
                $dryCmd,
                $log,
                'composer',
                'A real run would invoke this same command without --dry-run.'
            );

            // Run actual composer dry-run to show what WOULD happen
            $log->info('composer', 'Running real composer in --dry-run mode for accurate preview...');
            $this->runner->run(
                $dryCmd,
                $log,
                'composer',
                300
            );
            return;
        }

        // ── REAL UPDATE ──────────────────────────────────────────────────
        // Build the command based on the user's selected update mode.
        // All three modes share the same baseline of safety flags:
        //   --no-interaction  — don't prompt for anything from the CLI
        //   --no-progress     — keep log output line-based instead of console redraws
        //   --no-scripts      — DON'T run composer scripts during update.
        //                       Contao's post-install scripts can be heavy
        //                       (asset publishing, cache clear). We run those
        //                       deliberately as separate steps after composer
        //                       so failures are attributable.

        // ── PRE-FLIGHT: clear composer cache + list what's outdated ──────
        // Composer caches package metadata locally for a long time. On shared
        // hosting where updates happen rarely, that cache often has a stale
        // view of "what versions exist". If we don't clear it first, both the
        // `outdated` check and the actual `update` may decide there's nothing
        // to do — even though a newer version was released yesterday.
        //
        // Contao Manager hits the same problem and clears its own cache too.
        $this->clearComposerCache($php, $composer, $log);

        // Run `composer outdated` so the log shows clearly which packages WILL
        // be touched. Helps the user understand whether the upcoming run is
        // "big" or essentially a no-op.
        $this->logOutdatedSummary($php, $composer, $extraFlags, $log);

        $mode = $job->getUpdateMode();
        $log->step('composer', sprintf('Running real composer update (mode: %s)', $mode));

        $baseFlags = ['--no-interaction', '--no-progress', '--no-scripts', '--with-all-dependencies'];

        switch ($mode) {
            case UpdateJob::UPDATE_MODE_FULL:
                // composer update — everything to the highest version that
                // composer.json constraints allow.
                $cmd = $buildCmd(array_merge(['update'], $baseFlags, $extraFlags));
                $log->info('composer', 'Mode: FULL — updating all packages within composer.json constraints');
                break;

            case UpdateJob::UPDATE_MODE_PATCH:
                // Patch-only mode: composer doesn't have a true "patch only"
                // flag (that would require parsing every constraint).
                // --prefer-lowest is the wrong direction.
                // The closest is to use --prefer-stable (avoid pre-releases)
                // combined with constraints that are already pinned via ~/^.
                // Power users who want strict patch can edit composer.json to
                // use `~1.2.3` then run this mode.
                $cmd = $buildCmd(array_merge(
                    ['update'],
                    $baseFlags,
                    ['--prefer-stable'],
                    $extraFlags
                ));
                $log->info('composer', 'Mode: PATCH — preferring stable releases within composer.json constraints');
                $log->info('composer', 'Note: true patch-only requires `~X.Y.Z` constraints in composer.json.');
                break;

            case UpdateJob::UPDATE_MODE_SELECTIVE:
                $packages = $job->getSelectedPackages();
                if (empty($packages)) {
                    throw new \RuntimeException(
                        'Selective update mode requires at least one package to be selected.'
                    );
                }

                // Validate package names — composer is fine with vendor/name format.
                // We reject anything else to prevent injection of flags.
                foreach ($packages as $pkg) {
                    if (!preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $pkg)) {
                        throw new \RuntimeException(sprintf(
                            'Invalid package name in selective update: "%s". Must be vendor/name format.',
                            $pkg
                        ));
                    }
                }

                $cmd = $buildCmd(array_merge(
                    ['update'],
                    $packages,
                    $baseFlags,
                    $extraFlags
                ));
                $log->info('composer', sprintf(
                    'Mode: SELECTIVE — updating %d package(s): %s',
                    count($packages),
                    implode(', ', $packages)
                ));
                break;

            case UpdateJob::UPDATE_MODE_MAJOR:
                // Major mode: composer.json has already been edited by the
                // bump_constraints step. We now run a regular update — composer
                // will resolve against the NEW constraints and pick the higher
                // versions. --with-all-dependencies is essential here because
                // major upgrades typically pull in newer Symfony / Doctrine
                // versions that locked sub-dependencies need too.
                $cmd = $buildCmd(array_merge(
                    ['update'],
                    $baseFlags,
                    $extraFlags
                ));
                $changedPackages = array_keys($job->options['constraint_changes'] ?? []);
                $log->info('composer', sprintf(
                    'Mode: MAJOR — %d constraint(s) bumped, running full resolution',
                    count($changedPackages)
                ));
                if (!empty($changedPackages)) {
                    $log->info('composer', '  Affected packages: ' . implode(', ', $changedPackages));
                }
                break;

            default:
                throw new \RuntimeException('Unknown update mode: ' . $mode);
        }

        // Record lock-file hash BEFORE so we can detect "nothing changed"
        $lockFile = $this->projectDir . '/composer.lock';
        $hashBefore = file_exists($lockFile) ? md5_file($lockFile) : null;

        // 30 minutes — composer updates can be slow on shared hosting,
        // especially when downloading large packages.
        $this->runner->run($cmd, $log, 'composer', 1800);

        // Did anything actually change?
        $hashAfter = file_exists($lockFile) ? md5_file($lockFile) : null;
        if ($hashBefore !== null && $hashBefore === $hashAfter) {
            $log->info('composer', '');
            $log->info('composer', 'ℹ️  composer.lock is UNCHANGED — no packages were actually updated.');
            $log->info('composer', '   This typically means:');
            $log->info('composer', '     • All packages are already at the highest versions your constraints allow.');
            $log->info('composer', '     • To upgrade further you may need to edit composer.json (e.g. "5.3.*" → "5.4.*").');
            $log->info('composer', '');
        } else {
            $log->info('composer', '✓ composer.lock updated — package versions changed.');
        }

        $log->info('composer', '✓ Composer update completed');
    }

    /**
     * Clears the composer package metadata cache so the upcoming update sees
     * the latest available versions from Packagist instead of a cached snapshot.
     *
     * Safe to run repeatedly. Cache rebuilds automatically on the next package
     * fetch (composer outdated / update) — the only cost is a few seconds of
     * network traffic.
     */
    private function clearComposerCache(string $php, string $composer, JobLog $log): void
    {
        $log->info('composer', 'Clearing composer cache to ensure we see the newest package versions...');

        try {
            $proc = new \Symfony\Component\Process\Process(
                [$php, $composer, 'clear-cache', '--no-interaction'],
                $this->projectDir,
                null,
                null,
                60
            );
            $proc->run();

            if ($proc->isSuccessful()) {
                $log->info('composer', '✓ Composer cache cleared');
            } else {
                // Don't fail the update — just log and move on. Stale cache
                // results in "nothing to update" but doesn't break anything.
                $log->warning('composer', 'composer clear-cache failed (continuing): '
                    . trim($proc->getErrorOutput() ?: $proc->getOutput()));
            }
        } catch (\Throwable $e) {
            $log->warning('composer', 'composer clear-cache threw (continuing): ' . $e->getMessage());
        }
    }

    /**
     * Runs `composer outdated --direct` and logs a short summary so the user
     * sees up front whether the upcoming update will actually change anything.
     *
     * Best-effort: failures here are logged but don't abort the update — this
     * is a UX nicety, not a correctness check.
     *
     * @param array<int,string> $extraFlags
     */
    private function logOutdatedSummary(string $php, string $composer, array $extraFlags, JobLog $log): void
    {
        $log->info('composer', 'Pre-flight: checking which packages have updates available...');

        $cmd = array_merge(
            [$php, $composer, 'outdated', '--direct', '--no-interaction', '--format=json'],
            $extraFlags
        );

        // Use a temporary log-collector via Process directly (don't pollute
        // the main log stream with the verbose JSON output)
        try {
            $proc = new \Symfony\Component\Process\Process($cmd, $this->projectDir, null, null, 120);
            $proc->run();

            if (!$proc->isSuccessful()) {
                $log->info('composer', '(pre-flight outdated check failed — proceeding anyway)');
                return;
            }

            $json = $proc->getOutput();
            $data = json_decode($json, true);

            if (!\is_array($data) || empty($data['installed'])) {
                $log->info('composer', '✓ No direct dependencies have updates available.');
                $log->info('composer', '  composer update will run anyway to refresh transitive dependencies.');
                return;
            }

            $count = \count($data['installed']);
            $log->info('composer', sprintf('Found %d direct package(s) with updates available:', $count));

            foreach ($data['installed'] as $pkg) {
                $name        = $pkg['name'] ?? '?';
                $current     = $pkg['version'] ?? '?';
                $latest      = $pkg['latest'] ?? '?';
                $status      = $pkg['latest-status'] ?? '';

                $marker = match ($status) {
                    'semver-safe-update' => '✓',  // safe — composer can take it
                    'update-possible'    => '!',  // outside constraint — needs composer.json edit
                    default              => '·',
                };

                $log->info('composer', sprintf('  %s %s: %s → %s', $marker, $name, $current, $latest));
            }

            $log->info('composer', '  Legend: ✓ = composer can update · ! = requires composer.json change');
            $log->info('composer', '');
        } catch (\Throwable $e) {
            $log->info('composer', '(pre-flight outdated check threw: ' . $e->getMessage() . ' — proceeding anyway)');
        }
    }

    /**
     * Locates a real composer.phar — a shell-wrapper composer (like Plesk's
     * /usr/local/bin/composer) is NOT accepted, because the wrapper picks its
     * own PHP CLI which may have a different extension set than the PHP that
     * actually runs the website. We MUST run composer under the same PHP we
     * use everywhere else so the platform-requirement checks match the
     * runtime checks.
     *
     * If no real composer.phar is found, this returns null and the step
     * raises a clear error pointing the admin to either:
     *   - drop a composer.phar into the project root, or
     *   - configure the path explicitly in the PHP CLI settings panel
     *
     * Common locations checked (in order):
     *   1. <project>/composer.phar           — project-local, highest priority
     *   2. /opt/psa/var/modules/composer/composer.phar  — Plesk's bundled phar
     *   3. /usr/local/share/composer/composer.phar      — common system install
     *   4. /usr/local/bin/composer.phar                 — alt system install
     *   5. /usr/share/composer/composer.phar            — Debian package
     */
    private function findComposer(): ?string
    {
        // Only PHAR files. Shell wrappers (no `.phar` suffix, plain
        // /usr/local/bin/composer etc.) are deliberately rejected because
        // they use their own PHP interpreter which may differ from ours.
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

        // Last resort: try to resolve a shell wrapper, then check whether
        // the wrapper points at a phar we can use directly. Many wrappers
        // (including Plesk's) are simple shells that just exec a phar.
        $pharFromWrapper = $this->resolvePharFromWrapper();
        if ($pharFromWrapper !== null) {
            return $pharFromWrapper;
        }

        return null;
    }

    /**
     * Some hosts only ship a shell-wrapper composer (e.g. /usr/local/bin/composer)
     * which internally execs a phar. Try to read the wrapper and extract the
     * phar path from it, so we can call the phar directly via our own PHP.
     */
    private function resolvePharFromWrapper(): ?string
    {
        $wrappers = ['/usr/local/bin/composer', '/usr/bin/composer'];

        foreach ($wrappers as $wrapper) {
            $content = @file_get_contents($wrapper, false, null, 0, 1024);
            if ($content === false || $content === '') {
                continue;
            }

            // Match lines like `COMPOSER_BIN="/opt/psa/var/modules/composer/composer.phar"`
            // or `exec /path/to/composer.phar "$@"` etc.
            if (preg_match('#["\']?(/[^\s"\']+\.phar)["\']?#', $content, $m)) {
                $pharPath = $m[1];
                if (file_exists($pharPath) && is_readable($pharPath)) {
                    return $pharPath;
                }
            }
        }

        return null;
    }

    /**
     * Resolves the PHP CLI binary used to invoke composer.
     *
     * Priority order:
     *   1. RuntimeConfig (admin-configured path from the backend Settings)
     *   2. PHP_BINARY of the current process (our worker IS a CLI, so this
     *      is by definition a working CLI binary)
     *   3. Symfony PhpExecutableFinder as a last-ditch detection
     *
     * Returns null only if everything fails — which shouldn't happen because
     * the worker itself was spawned with a working PHP, so PHP_BINARY will
     * always be a valid fallback.
     */
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
}
