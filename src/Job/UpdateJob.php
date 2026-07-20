<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Job;

/**
 * Represents a single update job — a unit of work the CLI worker executes.
 *
 * Stored as JSON in var/updater/job.json (single active job at a time, by design,
 * because we don't want concurrent updates).
 *
 * Lifecycle:
 *   queued    — created in backend, worker not started yet
 *   running   — worker has started, working through steps
 *   succeeded — finished cleanly
 *   failed    — a step threw / returned non-zero exit
 *   cancelled — user clicked force-kill
 */
class UpdateJob
{
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_DRY_RUN = 'dry_run';
    public const TYPE_UPDATE  = 'update';
    public const TYPE_RESTORE = 'restore';

    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public ?string $currentStep = null,
        public array $steps = [],
        public ?string $createdAt = null,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
        public ?int $workerPid = null,
        public ?string $error = null,
        /** @var array<string, mixed> */
        public array $options = [],
        /**
         * The step at which the first failure occurred. This is pinned the
         * moment a step throws, so cleanup steps that run afterwards (e.g.
         * maintenance_off) don't overwrite the attribution.
         */
        public ?string $failedStep = null,
    ) {
    }

    public static function newDryRun(): self
    {
        return new self(
            id:        self::generateId(),
            type:      self::TYPE_DRY_RUN,
            status:    self::STATUS_QUEUED,
            steps:     ['backup', 'composer', 'cache_clear', 'migrate'],
            createdAt: date('c'),
            options:   ['dry_run' => true],
        );
    }

    /**
     * Update modes:
     *   - 'full':      composer update — everything within composer.json constraints
     *   - 'patch':     composer update --prefer-stable — patch/minor within `~`/`^` constraints
     *   - 'selective': composer update <pkg1> <pkg2> ... — only the chosen packages (+ deps)
     *   - 'major':     edit composer.json constraints first, then composer update.
     *                  Used for jumping across major versions (e.g. Contao 5.3 → 5.7).
     */
    public const UPDATE_MODE_FULL      = 'full';
    public const UPDATE_MODE_PATCH     = 'patch';
    public const UPDATE_MODE_SELECTIVE = 'selective';
    public const UPDATE_MODE_MAJOR     = 'major';

    public static function newUpdate(array $options = []): self
    {
        // Standard update pipeline:
        //   1. backup        — pre-snapshot so a failed update can be rolled back
        //   2. maintenance_on — site to maintenance mode while we tear things down
        //   3. composer       — actual composer update
        //   4. cache_clear    — purge prod cache (DI container, routing, etc.)
        //   5. migrate        — run contao:migrate for DB schema changes
        //   6. maintenance_off — site live again
        //
        // For MAJOR mode we add a bump_constraints step between maintenance_on
        // and composer — that step edits composer.json to bump constraints
        // before composer resolves the dependency tree.

        // Normalise + validate update mode.
        // NOTE: The major-upgrade mode is currently DISABLED product-wise.
        // The pipeline code (bump/dry-run/boot-check steps) is retained so
        // the feature can be re-enabled later, but requests asking for it
        // are rejected here — no UI or route offers it anymore.
        $mode = (string) ($options['update_mode'] ?? self::UPDATE_MODE_FULL);
        if ($mode === self::UPDATE_MODE_MAJOR) {
            throw new \InvalidArgumentException(
                'Der Major-Upgrade-Modus ist in dieser Version deaktiviert.'
            );
        }
        if (!\in_array($mode, [
            self::UPDATE_MODE_FULL,
            self::UPDATE_MODE_PATCH,
            self::UPDATE_MODE_SELECTIVE,
        ], true)) {
            $mode = self::UPDATE_MODE_FULL;
        }
        $options['update_mode'] = $mode;

        if ($mode === self::UPDATE_MODE_MAJOR) {
            // Default major pipeline. Includes two pre-flight check steps:
            //
            //   - dry_run_check: composer update --dry-run after the bump,
            //     catches resolution failures (e.g. "your third-party
            //     bundle X doesn't support Contao 5.7") BEFORE we touch
            //     vendor/. If it fails, the JobRunner rolls back
            //     composer.json automatically.
            //
            //   - boot_check: after the real composer update, before
            //     cache_clear, we verify that vendor/ actually boots.
            //     Catches "split-brain" dependency failures where
            //     composer is happy but PHP can't load the resulting code
            //     because of type-signature incompatibilities. Example:
            //     symfony/monolog-bridge 7.x with monolog/monolog 2.x —
            //     classic killer for Contao major upgrades.
            //
            // Each check can be disabled per job via options
            // skip_dry_run_check / skip_boot_check (the UI exposes these
            // for power users who know what they're doing).
            $steps = ['backup', 'maintenance_on', 'bump_constraints'];
            if (empty($options['skip_dry_run_check'])) {
                $steps[] = 'dry_run_check';
            }
            $steps[] = 'composer';
            if (empty($options['skip_boot_check'])) {
                $steps[] = 'boot_check';
            }
            $steps[] = 'cache_clear';
            $steps[] = 'migrate';
            $steps[] = 'maintenance_off';

            // Normalise constraint_changes — accept either an associative
            // {"vendor/pkg": "5.7.*", ...} object or a list of objects with
            // name+new_constraint keys.
            $rawChanges = $options['constraint_changes'] ?? [];
            $normalised = [];
            if (\is_array($rawChanges)) {
                foreach ($rawChanges as $key => $value) {
                    if (\is_string($key) && \is_string($value)) {
                        $normalised[$key] = $value;
                    } elseif (\is_array($value)
                              && isset($value['name'], $value['new_constraint'])
                              && \is_string($value['name'])
                              && \is_string($value['new_constraint'])) {
                        $normalised[$value['name']] = $value['new_constraint'];
                    }
                }
            }
            $options['constraint_changes'] = $normalised;

            // Major updates ALWAYS include vendor in the pre-snapshot — without
            // it, a rollback can't restore the previous tree, and major
            // upgrades are exactly when you want full rollback capability.
            $options['snapshot_vendor'] = true;
        } else {
            $steps = ['backup', 'maintenance_on', 'composer', 'cache_clear', 'migrate', 'maintenance_off'];
        }

        // For selective mode, ensure packages is an array of strings
        if ($mode === self::UPDATE_MODE_SELECTIVE) {
            $packages = $options['packages'] ?? [];
            $options['packages'] = is_array($packages) ? array_values(array_filter(array_map(
                static fn ($p) => is_string($p) ? trim($p) : '',
                $packages
            ))) : [];
        }

        return new self(
            id:        self::generateId(),
            type:      self::TYPE_UPDATE,
            status:    self::STATUS_QUEUED,
            steps:     $steps,
            createdAt: date('c'),
            options:   $options,
        );
    }

    /**
     * Builds a restore job. Steps are computed dynamically based on options:
     *   - pre_snapshot: include a fresh mini-backup before restoring
     *   - maintenance:  toggle maintenance mode around the restore
     *   - components:   which parts of the backup to restore
     */
    public static function newRestore(string $backupName, array $components, array $options = []): self
    {
        $steps = [];

        if (!empty($options['pre_snapshot'])) {
            $steps[] = 'pre_snapshot';
        }

        $needsMaintenance = !empty($components['vendor']) || !empty($options['force_maintenance']);
        if ($needsMaintenance) {
            $steps[] = 'maintenance_on';
        }

        $steps[] = 'restore';
        $steps[] = 'cache_clear';

        if ($needsMaintenance) {
            $steps[] = 'maintenance_off';
        }

        return new self(
            id:        self::generateId(),
            type:      self::TYPE_RESTORE,
            status:    self::STATUS_QUEUED,
            steps:     $steps,
            createdAt: date('c'),
            options:   array_merge($options, [
                'backup_name' => $backupName,
                'components'  => $components,
            ]),
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id:          (string) ($data['id']     ?? ''),
            type:        (string) ($data['type']   ?? ''),
            status:      (string) ($data['status'] ?? self::STATUS_QUEUED),
            currentStep: $data['current_step'] ?? null,
            steps:       \is_array($data['steps'] ?? null) ? $data['steps'] : [],
            createdAt:   $data['created_at']  ?? null,
            startedAt:   $data['started_at']  ?? null,
            finishedAt:  $data['finished_at'] ?? null,
            workerPid:   isset($data['worker_pid']) ? (int) $data['worker_pid'] : null,
            error:       $data['error'] ?? null,
            options:     \is_array($data['options'] ?? null) ? $data['options'] : [],
            failedStep:  $data['failed_step'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'type'         => $this->type,
            'status'       => $this->status,
            'current_step' => $this->currentStep,
            'steps'        => $this->steps,
            'created_at'   => $this->createdAt,
            'started_at'   => $this->startedAt,
            'finished_at'  => $this->finishedAt,
            'worker_pid'   => $this->workerPid,
            'error'        => $this->error,
            'options'      => $this->options,
            'failed_step'  => $this->failedStep,
        ];
    }

    public function isFinished(): bool
    {
        return \in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function isDryRun(): bool
    {
        return ($this->options['dry_run'] ?? false) === true;
    }

    public function getUpdateMode(): string
    {
        return (string) ($this->options['update_mode'] ?? self::UPDATE_MODE_FULL);
    }

    /**
     * @return array<int, string>
     */
    public function getSelectedPackages(): array
    {
        $packages = $this->options['packages'] ?? [];
        return is_array($packages) ? array_values(array_filter(array_map(
            static fn ($p) => is_string($p) ? $p : '',
            $packages
        ))) : [];
    }

    /**
     * Returns the pre-snapshot backup name once the backup step has produced one.
     * Used by the rollback flow to know which backup to restore from.
     */
    public function getPreSnapshotName(): ?string
    {
        $name = $this->options['pre_snapshot_name'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    public function setPreSnapshotName(string $name): void
    {
        $this->options['pre_snapshot_name'] = $name;
    }

    private static function generateId(): string
    {
        return date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
