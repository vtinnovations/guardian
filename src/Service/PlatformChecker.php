<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Service;

/**
 * Detects mismatches between PHP extensions available in the WEB (FPM) context
 * and those available in the CLI context.
 *
 * Common on Plesk and cPanel: the same PHP version has different module sets
 * for web and CLI. Composer runs under CLI but the actual application runs
 * under FPM, so it's often safe to tell composer "ignore the missing extension"
 * because the web tier has it.
 *
 * This class is consulted by ComposerUpdateStep to auto-generate
 * --ignore-platform-req flags for missing CLI extensions.
 */
class PlatformChecker
{
    public function __construct(
        private readonly string $projectDir,
        private readonly RuntimeConfig $runtimeConfig,
    ) {
    }

    /**
     * Returns the list of extensions that composer.json requires
     * but are NOT available in the CLI PHP.
     *
     * For each missing extension, also reports whether it's present in
     * the current (web) PHP — if YES, we can safely ignore in composer.
     * If NO, the extension is genuinely missing and updating is unsafe.
     *
     * @return array<int, array{name: string, in_web: bool, in_cli: bool}>
     */
    public function detectExtensionMismatches(): array
    {
        $requiredExtensions = $this->getRequiredExtensions();
        if (empty($requiredExtensions)) {
            return [];
        }

        $cliExtensions = $this->getCliExtensions();
        $webExtensions = $this->getWebExtensions();

        $mismatches = [];
        foreach ($requiredExtensions as $ext) {
            $inWeb = \in_array($ext, $webExtensions, true);
            $inCli = \in_array($ext, $cliExtensions, true);

            // Only report if it's missing somewhere
            if (!$inCli || !$inWeb) {
                $mismatches[] = [
                    'name'   => $ext,
                    'in_web' => $inWeb,
                    'in_cli' => $inCli,
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Extensions required by composer.json under "require" (and root composer.lock if needed).
     * Returns names WITHOUT the "ext-" prefix.
     *
     * @return array<int, string>
     */
    public function getRequiredExtensions(): array
    {
        $composerFile = $this->projectDir . '/composer.json';
        if (!file_exists($composerFile)) {
            return [];
        }

        $data = json_decode((string) @file_get_contents($composerFile), true);
        if (!\is_array($data)) {
            return [];
        }

        $extensions = [];
        foreach (['require', 'require-dev'] as $section) {
            $deps = $data[$section] ?? [];
            if (!\is_array($deps)) {
                continue;
            }
            foreach (array_keys($deps) as $name) {
                if (\is_string($name) && str_starts_with($name, 'ext-')) {
                    $extensions[] = substr($name, 4);
                }
            }
        }

        // Also check composer.lock — packages required by dependencies often need extensions too
        $lockFile = $this->projectDir . '/composer.lock';
        if (file_exists($lockFile)) {
            $lock = json_decode((string) @file_get_contents($lockFile), true);
            if (\is_array($lock) && isset($lock['platform']) && \is_array($lock['platform'])) {
                foreach (array_keys($lock['platform']) as $name) {
                    if (\is_string($name) && str_starts_with($name, 'ext-')) {
                        $extensions[] = substr($name, 4);
                    }
                }
            }
        }

        return array_values(array_unique($extensions));
    }

    /**
     * Web PHP's loaded extensions — easy: just ask the current process.
     * @return array<int, string>
     */
    public function getWebExtensions(): array
    {
        return array_map('strtolower', get_loaded_extensions());
    }

    /**
     * CLI PHP's extensions — we have to exec the binary to find out.
     * Caches result in-memory for one request.
     * @return array<int, string>
     */
    public function getCliExtensions(): array
    {
        $php = $this->runtimeConfig->getPhpBinary();
        if ($php === null) {
            // Fall back to the same PHP binary as this process
            $php = \PHP_BINARY;
        }

        if ($php === '' || !\function_exists('exec')) {
            return [];
        }

        $cmd = escapeshellarg($php) . ' -r "echo implode(PHP_EOL, get_loaded_extensions());" 2>&1';
        $output = [];
        $exit   = 1;
        @exec($cmd, $output, $exit);

        if ($exit !== 0) {
            return [];
        }

        return array_map('strtolower', array_filter(array_map('trim', $output)));
    }

    /**
     * Returns the --ignore-platform-req flags safe to add to composer commands.
     * Only includes extensions that ARE in web PHP but missing in CLI —
     * not extensions missing in both (which would be a real problem).
     *
     * @return array{flags: array<int, string>, warnings: array<int, string>, real_missing: array<int, string>}
     */
    public function getComposerIgnoreFlags(): array
    {
        $mismatches = $this->detectExtensionMismatches();

        $flags    = [];
        $warnings = [];
        $real     = [];

        foreach ($mismatches as $m) {
            if ($m['in_web'] && !$m['in_cli']) {
                // Safe to ignore: web has it, CLI doesn't
                $flags[] = '--ignore-platform-req=ext-' . $m['name'];
                $warnings[] = sprintf(
                    'ext-%s is missing in CLI PHP but available in web PHP — adding --ignore-platform-req for composer.',
                    $m['name']
                );
            } elseif (!$m['in_web']) {
                // Genuinely missing — flag as a real problem
                $real[] = $m['name'];
            }
        }

        return [
            'flags'        => $flags,
            'warnings'     => $warnings,
            'real_missing' => $real,
        ];
    }
}
