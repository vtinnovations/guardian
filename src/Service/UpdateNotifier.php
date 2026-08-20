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
 * Reads cached update information from PackageInspector's cache file.
 * Used by both the backend menu badge and the system messages hook.
 *
 * IMPORTANT: never triggers a Packagist refresh. The cache is populated only
 * when the user clicks "Check for updates" in the dashboard. This keeps every
 * backend page-load free of HTTP overhead.
 */
class UpdateNotifier
{
    private string $cacheFile;

    public function __construct(private readonly string $projectDir)
    {
        $this->cacheFile = $projectDir . '/var/updater/outdated.json';
    }

    /**
     * @return array{count: int, packages: array<int, array{name: string, current: string, latest: string}>, checked_at: ?string, has_data: bool}
     */
    public function getAvailableUpdates(): array
    {
        $empty = [
            'count'      => 0,
            'packages'   => [],
            'checked_at' => null,
            'has_data'   => false,
        ];

        if (!file_exists($this->cacheFile)) {
            return $empty;
        }

        $content = @file_get_contents($this->cacheFile);
        if ($content === false) {
            return $empty;
        }

        $data = json_decode($content, true);
        if (!\is_array($data) || !isset($data['packages'])) {
            return $empty;
        }

        $updates = [];
        foreach ($data['packages'] as $pkg) {
            if (!empty($pkg['has_update'])) {
                $updates[] = [
                    'name'    => (string) ($pkg['name']    ?? ''),
                    'current' => (string) ($pkg['current'] ?? ''),
                    'latest'  => (string) ($pkg['latest']  ?? ''),
                ];
            }
        }

        return [
            'count'      => \count($updates),
            'packages'   => $updates,
            'checked_at' => $data['checked_at'] ?? null,
            'has_data'   => true,
        ];
    }
}
