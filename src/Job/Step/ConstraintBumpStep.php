<?php

declare(strict_types=1);

/**
 * @package   [updater]
 * @author    V&T Innovations Team
 * @license   GNU/LGPL
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\Guardian\Job\Step;

use Vtinnovations\Guardian\Job\JobLog;
use Vtinnovations\Guardian\Job\UpdateJob;

/**
 * Step for major updates: edits composer.json to bump the constraints the
 * admin selected in the UI, then lets the regular composer step run.
 *
 * Always creates a backup copy of composer.json BEFORE modifying it
 * (composer.json.bak), and if anything in this step or any subsequent step
 * fails, the JobRunner's cleanup-on-failure logic restores it.
 *
 * Expects $job->options['constraint_changes'] = [
 *   'vendor/package-name' => 'new-constraint-string',
 *   ...
 * ]
 */
class ConstraintBumpStep implements StepInterface
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function name(): string
    {
        return 'bump_constraints';
    }

    public function execute(UpdateJob $job, JobLog $log): void
    {
        $changes = $job->options['constraint_changes'] ?? [];
        if (!\is_array($changes) || empty($changes)) {
            // Defensive: a major update without changes is a no-op
            $log->info('bump_constraints', 'No constraint changes requested — nothing to do.');
            return;
        }

        $composerFile = $this->projectDir . '/composer.json';
        if (!file_exists($composerFile)) {
            throw new \RuntimeException('composer.json not found at: ' . $composerFile);
        }

        $original = @file_get_contents($composerFile);
        if ($original === false) {
            throw new \RuntimeException('Could not read composer.json');
        }

        // Backup file. The post-failure cleanup uses this to restore.
        $backupFile = $composerFile . '.bak.updater';
        if (@file_put_contents($backupFile, $original) === false) {
            throw new \RuntimeException('Could not write composer.json backup at: ' . $backupFile);
        }
        $log->info('bump_constraints', 'composer.json backup saved at: ' . $backupFile);

        // Parse, modify, write — preserving JSON formatting as much as possible.
        // We use json_decode/encode (no preg) because the resulting file is
        // what composer reads anyway, and composer doesn't care about formatting.
        $data = json_decode($original, true);
        if (!\is_array($data)) {
            throw new \RuntimeException('composer.json is not valid JSON');
        }

        $applied = [];
        $missing = [];

        foreach ($changes as $name => $newConstraint) {
            if (!\is_string($name) || !\is_string($newConstraint)) {
                continue;
            }

            // Reject obviously invalid constraint strings — defence against
            // an attacker who somehow got past the request validation.
            // Composer constraints are made of digits, dots, *, ^, ~, |, &,
            // commas, spaces, dashes, and a few comparison operators.
            if (!preg_match('#^[0-9.*^~|&,\s\-<>=!a-zA-Z]+$#', $newConstraint) || strlen($newConstraint) > 64) {
                throw new \RuntimeException(sprintf(
                    'Invalid constraint syntax for %s: %s',
                    $name,
                    $newConstraint
                ));
            }

            // Find which section it's in (require or require-dev)
            $found = false;
            foreach (['require', 'require-dev'] as $section) {
                if (isset($data[$section][$name])) {
                    $old = $data[$section][$name];
                    if ($old !== $newConstraint) {
                        $data[$section][$name] = $newConstraint;
                        $applied[] = sprintf('%s: %s → %s', $name, $old, $newConstraint);
                    }
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $missing[] = $name;
            }
        }

        if (!empty($missing)) {
            $log->warning('bump_constraints', sprintf(
                'These packages were in the change list but not in composer.json: %s. Skipped.',
                implode(', ', $missing)
            ));
        }

        if (empty($applied)) {
            $log->info('bump_constraints', 'No constraints actually changed (all values were already correct).');
            return;
        }

        // Write back. Use JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES to match
        // composer's own writer style as closely as possible. We add the
        // trailing newline so diff tools don't mark a "no newline at EOF".
        $newJson = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n";

        if (@file_put_contents($composerFile, $newJson) === false) {
            throw new \RuntimeException('Could not write modified composer.json');
        }

        $log->info('bump_constraints', sprintf('Applied %d constraint change(s):', count($applied)));
        foreach ($applied as $line) {
            $log->info('bump_constraints', '  - ' . $line);
        }

        // Stamp the job so we know constraints were edited — used by the
        // cleanup-on-failure logic to roll the file back.
        $job->options['constraint_bump_applied'] = true;
        $job->options['constraint_bump_backup']  = $backupFile;
    }
}
