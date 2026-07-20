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
 * Read-only access to the updater status file.
 * In Step 1, we only READ. Writing comes in Step 5 (CLI worker).
 */
class StatusManager
{
    public const STATE_IDLE = 'idle';

    private string $statusFile;

    public function __construct(private readonly string $projectDir)
    {
        $this->statusFile = $projectDir . '/var/updater/status.json';
    }

    public function getStatus(): array
    {
        if (!file_exists($this->statusFile)) {
            return $this->defaultStatus();
        }

        $content = @file_get_contents($this->statusFile);
        if ($content === false) {
            return $this->defaultStatus();
        }

        $data = json_decode($content, true);
        return \is_array($data) ? $data : $this->defaultStatus();
    }

    public function isRunning(): bool
    {
        $state = $this->getStatus()['state'] ?? self::STATE_IDLE;
        return \in_array($state, ['analysing', 'backup', 'updating', 'migrating', 'rollback'], true);
    }

    private function defaultStatus(): array
    {
        return [
            'state'       => self::STATE_IDLE,
            'message'     => '',
            'progress'    => 0,
            'started_at'  => null,
            'updated_at'  => null,
            'backup_path' => null,
            'error'       => null,
        ];
    }
}
