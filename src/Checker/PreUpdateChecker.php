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

namespace Vtinnovations\Guardian\Checker;

use Contao\System;

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
            'label'   => $this->msg('label_php_version'),
            'status'  => $ok ? 'ok' : 'error',
            'message' => $ok
                ? $this->msg('php_version_ok', ['%current%' => $current])
                : $this->msg('php_version_too_old', ['%current%' => $current, '%required%' => $required]),
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
                    'label'   => $this->msg('label_composer'),
                    'status'  => 'ok',
                    'message' => $this->msg('composer_found', ['%path%' => $path]),
                ];
            }
        }

        $which = @shell_exec('which composer 2>/dev/null');
        if ($which !== null && trim((string) $which) !== '') {
            return [
                'label'   => $this->msg('label_composer'),
                'status'  => 'ok',
                'message' => $this->msg('composer_found', ['%path%' => trim((string) $which)]),
            ];
        }

        return [
            'label'   => $this->msg('label_composer'),
            'status'  => 'warning',
            'message' => $this->msg('composer_not_found'),
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
            'label'   => $this->msg('label_write_permissions'),
            'status'  => empty($issues) ? 'ok' : 'error',
            'message' => empty($issues)
                ? $this->msg('permissions_ok')
                : $this->msg('permissions_issues', ['%paths%' => implode(', ', $issues)]),
        ];
    }

    private function checkDiskSpace(): array
    {
        $free = @disk_free_space($this->projectDir);
        if ($free === false) {
            return [
                'label'   => $this->msg('label_disk_space'),
                'status'  => 'warning',
                'message' => $this->msg('disk_space_unknown'),
            ];
        }

        $freeMb     = (int) round($free / 1024 / 1024);
        $requiredMb = 500;
        $ok         = $freeMb >= $requiredMb;

        return [
            'label'   => $this->msg('label_disk_space'),
            'status'  => $ok ? 'ok' : 'warning',
            'message' => $ok
                ? $this->msg('disk_space_ok', ['%free%' => (string) $freeMb])
                : $this->msg('disk_space_low', ['%free%' => (string) $freeMb, '%required%' => (string) $requiredMb]),
        ];
    }

    private function checkPackages(): array
    {
        $installedJson = $this->projectDir . '/vendor/composer/installed.json';

        if (!file_exists($installedJson)) {
            return [
                'label'   => $this->msg('label_composer_packages'),
                'status'  => 'warning',
                'message' => $this->msg('installed_json_missing'),
            ];
        }

        $data = json_decode((string) @file_get_contents($installedJson), true);
        if (!\is_array($data)) {
            return [
                'label'   => $this->msg('label_composer_packages'),
                'status'  => 'warning',
                'message' => $this->msg('installed_json_unexpected'),
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
                'label'   => $this->msg('label_composer_packages'),
                'status'  => 'warning',
                'message' => $this->msg('packages_abandoned', [
                    '%count%' => (string) \count($abandoned),
                    '%names%' => implode(', ', \array_slice($abandoned, 0, 3)) . (\count($abandoned) > 3 ? '…' : ''),
                ]),
            ];
        }

        return [
            'label'   => $this->msg('label_composer_packages'),
            'status'  => 'ok',
            'message' => $this->msg('packages_ok', [
                '%total%'  => (string) \count($packages),
                '%contao%' => (string) $contaoPkgs,
            ]),
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
                    'label'   => $this->msg('label_database'),
                    'status'  => 'ok',
                    'message' => $this->msg('database_url_set', ['%filename%' => $filename]),
                ];
            }
        }

        return [
            'label'   => $this->msg('label_database'),
            'status'  => 'warning',
            'message' => $this->msg('database_url_missing'),
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
                'label'   => $this->msg('label_legacy_modules'),
                'status'  => 'ok',
                'message' => $this->msg('legacy_none_found'),
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
                'label'   => $this->msg('label_legacy_modules'),
                'status'  => 'ok',
                'message' => $this->msg('legacy_dir_empty'),
            ];
        }

        $names = array_map(static fn ($m) => $m['name'], $modules);

        return [
            'label'   => $this->msg('label_legacy_modules'),
            'status'  => 'warning',
            'message' => $this->msg('legacy_modules_found', [
                '%count%' => (string) \count($modules),
                '%names%' => implode(', ', \array_slice($names, 0, 5)) . (\count($names) > 5 ? '…' : ''),
            ]),
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
                    ? $this->msg('summary_warnings')
                    : $this->msg('summary_ready'))
                : $this->msg('summary_critical'),
        ];
    }

    /**
     * Looks up a per-locale string from the `guardian` language file's
     * `checker` section. Uses `strtr()` rather than Symfony's translator
     * parameter substitution — Contao's `contao_*` domain decorator feeds
     * parameters through `vsprintf()`, which misparses readable `%name%`
     * tokens as sprintf format specifiers.
     */
    private function msg(string $key, array $params = []): string
    {
        System::loadLanguageFile('guardian');

        $value = $GLOBALS['TL_LANG']['checker'][$key] ?? $key;

        return [] === $params ? $value : strtr($value, $params);
    }
}
