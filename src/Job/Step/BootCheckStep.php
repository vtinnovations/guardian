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
use Vtinnovations\Guardian\Service\RuntimeConfig;

/**
 * Step (post-composer, pre-cache-clear): verifies the updated vendor/ actually
 * boots before we let cache:clear try to use it.
 *
 * WHY THIS EXISTS
 * ---------------
 * A successful `composer update` only guarantees that constraint resolution
 * worked — every package's composer.json is happy. It does NOT guarantee
 * that the resulting PHP code is internally consistent. The classic failure
 * is a "split-brain" dependency: two packages need a shared library, but
 * Composer picks two different incompatible major versions (or one that's
 * compatible only by composer.json constraint but not by actual PHP type
 * signatures). PHP only notices when it tries to load and verify the
 * inheritance hierarchy.
 *
 * Real example that motivated this step: A Contao 5.3 → 5.7 major upgrade
 * pulled symfony/monolog-bridge 7.x (which has
 *   ConsoleFormatter::format(\Monolog\LogRecord $record): mixed
 * ) while a transitive dep held monolog/monolog at 2.x (which has
 *   FormatterInterface::format(array $record)
 * ). Composer was fine. PHP exploded with Fatal Compile Error on the next
 * cache:clear because the inheritance was incompatible.
 *
 * HOW IT WORKS
 * ------------
 * We invoke `php vendor/autoload.php`-style smoke test: a tiny PHP one-liner
 * that requires the autoloader and triggers a few likely-affected class
 * loads (the monolog bridge in particular). If PHP fails to compile any of
 * these classes, we catch the fatal-error output and fail the step BEFORE
 * the user's cache_clear has a chance to run and leave the system in a
 * half-broken state.
 *
 * On failure the JobRunner sees an exception, the pipeline aborts, and
 * (because constraint_bump_applied is set) composer.json is automatically
 * rolled back. The vendor/ directory is still on the broken state — that's
 * what the auto-rollback button / standalone recovery panel is for.
 */
class BootCheckStep implements StepInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly string $projectDir,
        private readonly RuntimeConfig $runtimeConfig,
    ) {
    }

    public function name(): string
    {
        return 'boot_check';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        $php = $this->getPhpBinary();
        if ($php === null) {
            // We can't run a check without a PHP CLI. Don't fail the job just
            // because of this — log a warning and move on. The user will find
            // out from cache_clear instead.
            $log->warning('boot_check', 'No PHP CLI binary available, skipping boot check.');
            return;
        }

        $autoload = $this->projectDir . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            // No vendor/autoload.php means composer didn't actually finish.
            // Composer step should have caught that, but be defensive.
            throw new \RuntimeException(
                'vendor/autoload.php is missing after composer update — '
              . 'composer did not complete successfully.'
            );
        }

        $log->info('boot_check', 'Verifying vendor/ actually boots…');

        // Strategy: run a tiny PHP script that loads the autoloader and
        // forces resolution of classes most likely to expose split-brain
        // dependency bugs. We pass it via -r so we don't need to create
        // a temp file. The script exits with code 0 on success, non-zero
        // on compile/fatal error.
        //
        // The classes we touch:
        //   - The autoloader itself (catches missing/broken autoload.php)
        //   - Symfony\Bridge\Monolog\Formatter\ConsoleFormatter
        //       (the actual class that exploded for user kassra)
        //   - Symfony\Component\HttpKernel\Kernel (the framework's heart)
        //   - Contao\CoreBundle\ContaoCoreBundle (the actual Contao bundle)
        //
        // class_exists() with autoload=true is the cleanest trigger: it
        // forces PHP to load and verify the class hierarchy. If anything
        // is incompatible, this is where you get the Fatal Compile Error.
        $script = sprintf(
            'require %s;'
          . ' $errors = [];'
          . ' foreach (["Symfony\\\\Bridge\\\\Monolog\\\\Formatter\\\\ConsoleFormatter",'
          . '          "Symfony\\\\Component\\\\HttpKernel\\\\Kernel",'
          . '          "Contao\\\\CoreBundle\\\\ContaoCoreBundle"] as $c) {'
          . '   try {'
          . '     if (!class_exists($c) && !interface_exists($c) && !trait_exists($c)) {'
          . '       $errors[] = $c . ": not found";'
          . '     }'
          . '   } catch (\\Throwable $e) {'
          . '     $errors[] = $c . ": " . $e->getMessage();'
          . '   }'
          . ' }'
          . ' if (!empty($errors)) {'
          . '   fwrite(STDERR, "BOOT_CHECK_FAILED:" . implode("|", $errors) . PHP_EOL);'
          . '   exit(2);'
          . ' }'
          . ' echo "BOOT_CHECK_OK" . PHP_EOL;',
            var_export($autoload, true)
        );

        // We pass -d display_errors=1 so any fatal compile errors get
        // printed to stderr where we can capture them. Without that they'd
        // go to PHP's default error log only and we'd never see them.
        $result = $this->runner->runCapturing(
            [
                $php,
                '-d', 'display_errors=stderr',
                '-d', 'error_reporting=' . (string) E_ALL,
                '-r', $script,
            ],
            $log,
            'boot_check',
            60
        );

        $combined = $result['stdout'] . "\n" . $result['stderr'];

        // Detect the canonical "split-brain" failure mode and give the user
        // a focused, actionable error message rather than the raw PHP fatal.
        if (preg_match('/Declaration of (\S+) must be compatible with (\S+)/', $combined, $m)) {
            throw new \RuntimeException(sprintf(
                "Post-update boot check FAILED: PHP type inheritance is broken.\n"
              . "  %s\n"
              . "  is not compatible with\n"
              . "  %s\n\n"
              . "This is a 'split-brain' dependency: composer picked incompatible "
              . "versions of two related packages. The composer update succeeded but "
              . "the resulting vendor/ won't run. Your composer.json will be rolled back "
              . "automatically. Use the Standalone Recovery Panel to restore vendor/ "
              . "from the pre-snapshot (your site is currently broken).",
                $m[1],
                $m[2]
            ));
        }

        if (preg_match('/(Fatal error|Compile Error|Parse error)[^:\n]*:\s*(.+)/', $combined, $m)) {
            throw new \RuntimeException(sprintf(
                "Post-update boot check FAILED: %s. "
              . "vendor/ does not boot. composer.json will be rolled back. "
              . "Use the Standalone Recovery Panel to restore vendor/.",
                trim($m[2])
            ));
        }

        if ($result['exit'] !== 0 || !str_contains($combined, 'BOOT_CHECK_OK')) {
            throw new \RuntimeException(sprintf(
                'Post-update boot check FAILED (exit %d). Output: %s. '
              . 'composer.json will be rolled back. Use the Standalone Recovery Panel '
              . 'to restore vendor/.',
                $result['exit'],
                trim($combined) !== '' ? trim($combined) : '(no output)'
            ));
        }

        $log->info('boot_check', '✓ vendor/ boots cleanly. Core framework classes load without errors.');
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
}
