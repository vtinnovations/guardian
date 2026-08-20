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
use Symfony\Component\Process\Process;
use Vtinnovations\Guardian\Job\JobLog;
use Vtinnovations\Guardian\Service\RuntimeConfig;

/**
 * Shared helper for steps that need to run external commands and stream their
 * output to the JobLog line-by-line in real time.
 *
 * Important nuance: scripts under vendor/bin/ (like contao-console) have a
 * `#!/usr/bin/env php` shebang. On hosts with restricted PATH or missing
 * exec bits this fails with code 126 (Permission denied). We work around
 * this by detecting "shebang-script" commands and prepending the explicit
 * PHP binary path so the command runs as `php /path/to/script ...` rather
 * than relying on the OS finding the interpreter via shebang.
 */
class CommandRunner
{
    public function __construct(
        private readonly string $projectDir,
        private readonly ?RuntimeConfig $runtimeConfig = null,
    ) {
    }

    /**
     * Runs a command, streaming each line of output to the log.
     * Returns the process exit code.
     *
     * @param array<int, string> $command  e.g. ['composer', 'update', '--no-interaction']
     * @param int                $timeout  Per-step timeout in seconds (default 600)
     * @throws \RuntimeException on non-zero exit
     */
    public function run(array $command, JobLog $log, string $stepName, int $timeout = 600): int
    {
        $command = $this->normalizeCommand($command, $log, $stepName);

        $process = new Process(
            command: $command,
            cwd:     $this->projectDir,
            env:     null,
            input:   null,
            timeout: $timeout,
        );

        $log->info($stepName, '> ' . $process->getCommandLine());

        try {
            $process->run(function ($type, $buffer) use ($log, $stepName) {
                $level = ($type === Process::ERR) ? 'warning' : 'info';
                foreach (preg_split('/\r?\n/', (string) $buffer) ?: [] as $line) {
                    $line = rtrim($line);
                    if ($line === '') {
                        continue;
                    }
                    if ($level === 'warning') {
                        $log->warning($stepName, $line);
                    } else {
                        $log->info($stepName, $line);
                    }
                }
            });
        } catch (\Throwable $e) {
            $log->error($stepName, 'Process exception: ' . $e->getMessage());
            throw new \RuntimeException("Command failed: {$e->getMessage()}", 0, $e);
        }

        $exit = $process->getExitCode() ?? -1;
        if ($exit !== 0) {
            $log->error($stepName, "Command exited with code {$exit}");
            throw new \RuntimeException("Command exited with code {$exit}: " . implode(' ', $command));
        }

        $log->info($stepName, "Command finished (exit 0)");
        return $exit;
    }

    /**
     * Run a command and capture stdout/stderr without throwing on non-zero
     * exit. Used by diagnostic steps (compatibility / dry-run / boot checks)
     * that need to interpret the output and decide whether the failure is
     * fatal for the job or just informational.
     *
     * stdout/stderr is also streamed to the log (info/warning), same as run(),
     * so the user sees what's happening in real time.
     *
     * @param array<int, string> $command
     * @return array{exit:int, stdout:string, stderr:string}
     */
    public function runCapturing(array $command, JobLog $log, string $stepName, int $timeout = 300): array
    {
        $command = $this->normalizeCommand($command, $log, $stepName);

        $process = new Process(
            command: $command,
            cwd:     $this->projectDir,
            env:     null,
            input:   null,
            timeout: $timeout,
        );

        $log->info($stepName, '> ' . $process->getCommandLine());

        $stdout = '';
        $stderr = '';

        try {
            $process->run(function ($type, $buffer) use ($log, $stepName, &$stdout, &$stderr) {
                if ($type === Process::ERR) {
                    $stderr .= $buffer;
                } else {
                    $stdout .= $buffer;
                }
                foreach (preg_split('/\r?\n/', (string) $buffer) ?: [] as $line) {
                    $line = rtrim($line);
                    if ($line === '') {
                        continue;
                    }
                    if ($type === Process::ERR) {
                        $log->warning($stepName, $line);
                    } else {
                        $log->info($stepName, $line);
                    }
                }
            });
        } catch (\Throwable $e) {
            $log->warning($stepName, 'Process exception: ' . $e->getMessage());
            return [
                'exit'   => -1,
                'stdout' => $stdout,
                'stderr' => $stderr . "\n" . $e->getMessage(),
            ];
        }

        $exit = $process->getExitCode() ?? -1;
        $log->info($stepName, "Command finished (exit {$exit})");

        return [
            'exit'   => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Dry-run: log what would happen, but don't execute.
     */
    public function dryRun(array $command, JobLog $log, string $stepName, string $note = ''): void
    {
        $normalized = $this->normalizeCommand($command, $log, $stepName);
        $log->info($stepName, '[DRY RUN] Would execute: ' . implode(' ', $normalized));
        if ($note !== '') {
            $log->info($stepName, '[DRY RUN] ' . $note);
        }
        // Simulate a short delay so the UI can observe progression
        sleep(1);
    }

    /**
     * If the first argument is a PHP script (vendor/bin/contao-console, composer, etc.)
     * prepend the explicit PHP CLI binary so we don't rely on the script's shebang.
     *
     * This fixes "exec: ...: Permission denied" (code 126) errors on hosts where
     * vendor/bin/ scripts don't have execute bits or where /usr/bin/env can't
     * find php in the PATH.
     */
    private function normalizeCommand(array $command, JobLog $log, string $stepName): array
    {
        if (empty($command)) {
            return $command;
        }

        $firstArg = (string) $command[0];

        // Only intervene for scripts we know are PHP-based
        if (!$this->looksLikePhpScript($firstArg)) {
            return $command;
        }

        // Belt-and-braces check: even if the path looks like it should be
        // a PHP script (e.g. /usr/local/bin/composer), it might actually be
        // a shell wrapper that calls a phar elsewhere. Plesk ships exactly
        // such a wrapper at /usr/local/bin/composer:
        //
        //   #!/bin/sh
        //   COMPOSER_BIN="/opt/psa/var/modules/composer/composer.phar"
        //   "$COMPOSER_BIN" "$@"
        //
        // If we prepend "php" to a shell script, PHP tries to parse it as
        // PHP code, prints it to stdout, and exits 0 — so the actual command
        // never runs. Worse, the job silently "succeeds".
        //
        // Detect this by sniffing the first line of the file.
        if (!$this->isFileExecutableAsPhp($firstArg)) {
            $log->info(
                $stepName,
                'Detected ' . basename($firstArg) . ' is NOT a PHP script (likely a shell wrapper) — '
              . 'invoking directly without explicit PHP binary'
            );
            return $command;
        }

        $php = $this->findPhpBinary();
        if ($php === null) {
            $log->warning(
                $stepName,
                'Could not locate PHP CLI binary to invoke ' . basename($firstArg) . ' explicitly; '
                . 'relying on shebang (may fail with exit code 126 on restricted hosts).'
            );
            return $command;
        }

        // Replace ['vendor/bin/contao-console', 'cache:clear', ...]
        // with     ['/path/to/php', 'vendor/bin/contao-console', 'cache:clear', ...]
        return array_merge([$php], $command);
    }

    /**
     * Decides whether the given path should be invoked via the PHP CLI binary.
     *
     * Returns TRUE only when we have positive evidence that the file is a
     * PHP script or PHAR:
     *   - starts with "<?php" (with or without BOM)
     *   - has a PHP shebang ("#!/usr/bin/env php", "#!/usr/bin/php8.1", etc.)
     *
     * Returns FALSE for:
     *   - shell scripts (any non-PHP shebang like #!/bin/sh, #!/bin/bash)
     *   - files we can't inspect (open_basedir, permissions, doesn't exist)
     *   - any other content we don't recognise
     *
     * Note the default for "can't tell" is FALSE. The old default was TRUE
     * (prepend PHP, hope for the best), which broke Plesk's shell-wrapper at
     * /usr/local/bin/composer — PHP parsed the shell script as PHP, dumped
     * it to stdout, and the actual command never ran. False is the safe
     * default because:
     *   - if the file truly is a PHP script with a PHP shebang, executing it
     *     directly will work (the OS reads the shebang and finds php)
     *   - the only failure mode is hosts without php in $PATH and no exec bit
     *     on the script, which is rare and would already need the user to
     *     supply an explicit PHP binary path
     */
    private function isFileExecutableAsPhp(string $path): bool
    {
        // Try multiple ways to read the file head, defending against open_basedir.
        $head = $this->safeReadHead($path, 256);
        if ($head === null || $head === '') {
            // We genuinely can't tell. Default to false = leave the command alone.
            return false;
        }

        // PHP files start with <?php (possibly after a BOM)
        if (str_starts_with($head, "\xEF\xBB\xBF<?php") || str_starts_with($head, '<?php')) {
            return true;
        }

        // PHAR stub is matched by the opening-tag check above.

        // Check first line for shebang
        $firstLine = strtok($head, "\n");
        if ($firstLine !== false && str_starts_with($firstLine, '#!')) {
            // PHP shebang? Looks for "php" as a word in the interpreter line.
            // Examples that should match:
            //   #!/usr/bin/env php
            //   #!/usr/bin/php
            //   #!/usr/local/bin/php8.1
            //   #!/opt/plesk/php/8.4/bin/php
            if (preg_match('#^\#!.*\bphp[0-9.]*\b#', $firstLine) === 1) {
                return true;
            }
            // Any other shebang → definitely NOT a PHP script. e.g.
            //   #!/bin/sh           (Plesk composer wrapper)
            //   #!/bin/bash
            //   #!/usr/bin/perl
            return false;
        }

        // No shebang, no <?php — not something we should invoke via PHP.
        return false;
    }

    /**
     * Reads up to $maxBytes from the start of $path, defending against
     * open_basedir, missing permissions, and other read failures.
     *
     * Returns the content as a string, or null if no method worked.
     */
    private function safeReadHead(string $path, int $maxBytes): ?string
    {
        // Strategy 1: file_get_contents with length param
        $head = @file_get_contents($path, false, null, 0, $maxBytes);
        if ($head !== false && $head !== '') {
            return $head;
        }

        // Strategy 2: fopen/fread (sometimes works when file_get_contents fails)
        $fh = @fopen($path, 'rb');
        if ($fh !== false) {
            $head = @fread($fh, $maxBytes);
            @fclose($fh);
            if ($head !== false && $head !== '') {
                return $head;
            }
        }

        // Strategy 3: shell out (exec() generally bypasses open_basedir).
        // `head -c` is portable across Linux, macOS, and BSDs.
        if (\function_exists('exec')) {
            $disabled = array_map('trim', explode(',', (string) \ini_get('disable_functions')));
            if (!\in_array('exec', $disabled, true)) {
                $cmd = 'head -c ' . (int) $maxBytes . ' ' . escapeshellarg($path) . ' 2>/dev/null';
                $output = [];
                $exit   = 1;
                @exec($cmd, $output, $exit);
                if ($exit === 0) {
                    $joined = implode("\n", $output);
                    if ($joined !== '') {
                        return $joined;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Heuristic: which first-argument paths should we even consider invoking via PHP?
     */
    private function looksLikePhpScript(string $path): bool
    {
        $base = basename($path);

        // Direct matches for files we ship/use
        $knownPhpScripts = ['contao-console', 'composer', 'composer.phar'];
        if (\in_array($base, $knownPhpScripts, true)) {
            return true;
        }

        // Anything in vendor/bin/ is a PHP script by convention
        if (str_contains($path, '/vendor/bin/')) {
            return true;
        }

        // .phar files
        if (str_ends_with($path, '.phar')) {
            return true;
        }

        return false;
    }

    /**
     * Locates a usable PHP CLI binary. Prefers the explicit runtime config
     * (same one used to spawn the worker), then falls back to autodetection.
     */
    private function findPhpBinary(): ?string
    {
        if ($this->runtimeConfig !== null) {
            $configured = $this->runtimeConfig->getPhpBinary();
            if ($configured !== null && $configured !== '') {
                return $configured;
            }
        }

        // Since we're already running INSIDE the worker (which itself was spawned
        // with the right CLI binary), PHP_BINARY is reliable here.
        if (\defined('PHP_BINARY') && \PHP_BINARY !== '') {
            return \PHP_BINARY;
        }

        $found = (new PhpExecutableFinder())->find();
        return $found !== false && $found !== '' ? $found : null;
    }
}
