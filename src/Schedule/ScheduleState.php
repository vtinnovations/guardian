<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Schedule;

/**
 * Tracks the last execution of each schedule.
 *
 * Stored as JSON in var/updater/schedule_state.json. Used to:
 *   - decide whether the cron should run (avoiding double-runs in the same window)
 *   - show "last run" / "next run" in the dashboard
 *
 * Structure:
 *   {
 *     "mini": { "last_run": "2026-05-04T03:00:11+00:00", "last_status": "success", "last_message": "..." },
 *     "full": { ... }
 *   }
 */
class ScheduleState
{
    private string $stateFile;

    public function __construct(private readonly string $projectDir)
    {
        $this->stateFile = $projectDir . '/var/updater/schedule_state.json';
    }

    public function load(): array
    {
        if (!file_exists($this->stateFile)) {
            return $this->empty();
        }

        $content = @file_get_contents($this->stateFile);
        if ($content === false) {
            return $this->empty();
        }

        $data = json_decode($content, true);
        return \is_array($data) ? array_merge($this->empty(), $data) : $this->empty();
    }

    public function recordRun(string $type, string $status, string $message = '', ?string $backupName = null): void
    {
        $state = $this->load();

        $state[$type] = [
            'last_run'     => date('c'),
            'last_status'  => $status,
            'last_message' => $message,
            'last_backup'  => $backupName,
            'in_progress'  => false,
            'started_at'   => null,
        ];

        $this->writeState($state);
    }

    /**
     * Marks a backup as currently running. Useful for the UI to show progress
     * indicators when the cron triggers a backup in kernel.terminate.
     */
    public function markStarted(string $type): void
    {
        $state = $this->load();

        $existing = $state[$type] ?? [];
        $state[$type] = array_merge($existing, [
            'in_progress' => true,
            'started_at'  => date('c'),
        ]);

        $this->writeState($state);
    }

    private function writeState(array $state): void
    {
        $dir = \dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(
            $this->stateFile,
            json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE)
        );
    }

    private function empty(): array
    {
        return [
            'mini' => null,
            'full' => null,
        ];
    }
}
