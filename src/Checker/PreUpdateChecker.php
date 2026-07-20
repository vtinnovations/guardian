<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Checker;

class PreUpdateChecker
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function runAll(): array
    {
        $checks = [
            'php_version'    => $this->checkPhpVersion(),
            'composer'       => $this->checkComposer(),
            'permissions'    => $this->checkWritePermissions(),
            'disk_space'     => $this->checkDiskSpace(),
            'packages'       => $this->checkPackages(),
            'database'       => $this->checkDatabaseAccess(),
            'legacy_modules' => $this->checkLegacyModules(),
        ];

        return [
            'checks'  => $checks,
            'summary' => $this->buildSummary($checks),
        ];
    }

    private function checkPhpVersion(): array
    {
        $current  = \PHP_VERSION;
        $required = '8.2.0';
        $ok       = version_compare($current, $required, '>=');

        return [
            'label'   => 'PHP version',
            'status'  => $ok ? 'ok' : 'error',
            'message' => $ok
                ? "PHP {$current} is compatible with Contao 5"
                : "PHP {$current} is too old — Contao 5 requires at least PHP {$required}",
        ];
    }

    private function checkComposer(): array
    {
        $candidates = [
            $this->projectDir . '/composer.phar',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_readable($path)) {
                return [
                    'label'   => 'Composer',
                    'status'  => 'ok',
                    'message' => "Composer found: {$path}",
                ];
            }
        }

        $which = @shell_exec('which composer 2>/dev/null');
        if ($which !== null && trim((string) $which) !== '') {
            return [
                'label'   => 'Composer',
                'status'  => 'ok',
                'message' => 'Composer found: ' . trim((string) $which),
            ];
        }

        return [
            'label'   => 'Composer',
            'status'  => 'warning',
            'message' => 'Composer not found in usual paths — updates may need to be run differently',
        ];
    }

    private function checkWritePermissions(): array
    {
        $paths = [
            'vendor/' => $this->projectDir . '/vendor',
            'var/'    => $this->projectDir . '/var',
            'public/' => $this->projectDir . '/public',
        ];

        $issues = [];
        foreach ($paths as $label => $path) {
            if (is_dir($path) && !is_writable($path)) {
                $issues[] = $label;
            }
        }

        return [
            'label'   => 'Write permissions',
            'status'  => empty($issues) ? 'ok' : 'error',
            'message' => empty($issues)
                ? 'All important directories are writable'
                : 'No write access: ' . implode(', ', $issues),
        ];
    }

    private function checkDiskSpace(): array
    {
        $free = @disk_free_space($this->projectDir);
        if ($free === false) {
            return [
                'label'   => 'Disk space',
                'status'  => 'warning',
                'message' => 'Could not determine free disk space',
            ];
        }

        $freeMb     = (int) round($free / 1024 / 1024);
        $requiredMb = 500;
        $ok         = $freeMb >= $requiredMb;

        return [
            'label'   => 'Disk space',
            'status'  => $ok ? 'ok' : 'warning',
            'message' => $ok
                ? "{$freeMb} MB free — sufficient for backup and update"
                : "Only {$freeMb} MB free — at least {$requiredMb} MB recommended",
        ];
    }

    private function checkPackages(): array
    {
        $installedJson = $this->projectDir . '/vendor/composer/installed.json';

        if (!file_exists($installedJson)) {
            return [
                'label'   => 'Composer packages',
                'status'  => 'warning',
                'message' => 'vendor/composer/installed.json not found',
            ];
        }

        $data = json_decode((string) @file_get_contents($installedJson), true);
        if (!\is_array($data)) {
            return [
                'label'   => 'Composer packages',
                'status'  => 'warning',
                'message' => 'installed.json has unexpected format',
            ];
        }

        $packages   = $data['packages'] ?? $data;
        $abandoned  = [];
        $contaoPkgs = 0;

        foreach ($packages as $pkg) {
            $name = $pkg['name'] ?? '';
            if (str_contains($name, 'contao')) {
                $contaoPkgs++;
            }
            if (isset($pkg['abandoned'])) {
                $abandoned[] = $name;
            }
        }

        if (!empty($abandoned)) {
            return [
                'label'   => 'Composer packages',
                'status'  => 'warning',
                'message' => \count($abandoned) . ' abandoned package(s): ' . implode(', ', \array_slice($abandoned, 0, 3))
                    . (\count($abandoned) > 3 ? '…' : ''),
            ];
        }

        return [
            'label'   => 'Composer packages',
            'status'  => 'ok',
            'message' => \count($packages) . ' packages installed (' . $contaoPkgs . ' Contao packages) — none abandoned',
        ];
    }

    private function checkDatabaseAccess(): array
    {
        foreach (['.env.local', '.env'] as $filename) {
            $file = $this->projectDir . '/' . $filename;
            if (!file_exists($file)) {
                continue;
            }

            $content = (string) @file_get_contents($file);
            if (preg_match('/^\s*DATABASE_URL\s*=/m', $content)) {
                return [
                    'label'   => 'Database configuration',
                    'status'  => 'ok',
                    'message' => "DATABASE_URL is set in {$filename}",
                ];
            }
        }

        return [
            'label'   => 'Database configuration',
            'status'  => 'warning',
            'message' => 'DATABASE_URL not found in .env / .env.local — backup will have to be skipped',
        ];
    }

    /**
     * Detects legacy Contao 3 extensions in system/modules/.
     * These are deprecated in Contao 5 and will be removed in a future major version.
     * Pure-PHP extensions like these were the standard before Composer/Symfony bundles.
     */
    private function checkLegacyModules(): array
    {
        $legacyDir = $this->projectDir . '/system/modules';

        if (!is_dir($legacyDir)) {
            return [
                'label'   => 'Legacy modules (system/modules/)',
                'status'  => 'ok',
                'message' => 'No legacy module directory found — installation is fully bundle-based',
            ];
        }

        $entries = @scandir($legacyDir) ?: [];
        $modules = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $modulePath = $legacyDir . '/' . $entry;
            if (!is_dir($modulePath)) {
                continue;
            }

            // Quick fingerprint: classify what kind of legacy module this is
            $info = [
                'name'         => $entry,
                'has_config'   => is_dir($modulePath . '/config'),
                'has_dca'      => is_dir($modulePath . '/dca'),
                'has_classes'  => is_dir($modulePath . '/classes'),
                'has_composer' => file_exists($modulePath . '/composer.json'),
            ];

            $modules[] = $info;
        }

        if (empty($modules)) {
            return [
                'label'   => 'Legacy modules (system/modules/)',
                'status'  => 'ok',
                'message' => 'system/modules/ exists but is empty',
            ];
        }

        $names = array_map(static fn ($m) => $m['name'], $modules);

        return [
            'label'   => 'Legacy modules (system/modules/)',
            'status'  => 'warning',
            'message' => \count($modules) . ' legacy module(s) detected: ' . implode(', ', \array_slice($names, 0, 5))
                . (\count($names) > 5 ? '…' : '')
                . '. These use the old Contao 3 extension format and should be migrated to Composer/Symfony bundles before upgrading to a higher version.',
            'modules' => $modules,
        ];
    }

    private function buildSummary(array $checks): array
    {
        $counts = ['ok' => 0, 'warning' => 0, 'error' => 0];

        foreach ($checks as $check) {
            $status = $check['status'] ?? 'ok';
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        $canProceed = $counts['error'] === 0;

        return [
            'ok'          => $counts['ok'],
            'warnings'    => $counts['warning'],
            'errors'      => $counts['error'],
            'can_proceed' => $canProceed,
            'message'     => $canProceed
                ? ($counts['warning'] > 0
                    ? 'Update generally possible — please review warnings'
                    : 'Everything ready — update can be started')
                : 'Critical issues found — please fix first',
        ];
    }
}
