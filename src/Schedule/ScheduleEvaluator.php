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
 * Decides whether a scheduled backup is currently due.
 *
 * Logic per type (mini / full):
 *   - if disabled: never due
 *   - else: compute the most recent scheduled occurrence (today's HH:MM, or last weekday/dom)
 *   - if last_run is null OR last_run < scheduled occurrence: DUE
 *   - if scheduled occurrence is in the future relative to now: NOT DUE
 *
 * The cron job runs every hour. Each invocation asks this evaluator whether
 * it should actually do a backup. Worst case: backup runs up to ~1h late.
 */
class ScheduleEvaluator
{
    private readonly \DateTimeZone $timezone;

    public function __construct()
    {
        $this->timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
    }

    public function isDue(array $schedule, ?array $state, ?\DateTimeImmutable $now = null): bool
    {
        if (empty($schedule['enabled'])) {
            return false;
        }

        $now ??= new \DateTimeImmutable('now', $this->timezone);

        // Sub-hourly frequencies use interval-based logic instead of slot-based.
        $intervalSec = $this->intervalSeconds($schedule['frequency'] ?? '');
        if ($intervalSec !== null) {
            // No previous run -> due immediately
            if ($state === null || empty($state['last_run'])) {
                return true;
            }
            $lastRun = $this->parseDate($state['last_run']);
            if ($lastRun === null) {
                return true;
            }
            $elapsed = $now->getTimestamp() - $lastRun->getTimestamp();
            return $elapsed >= $intervalSec;
        }

        // Slot-based logic for daily/weekly/monthly
        $scheduled = $this->mostRecentOccurrence($schedule, $now);
        if ($scheduled === null) {
            return false;
        }

        // Future occurrence — not due yet
        if ($scheduled > $now) {
            return false;
        }

        // No previous run — due now
        if ($state === null || empty($state['last_run'])) {
            return true;
        }

        $lastRun = $this->parseDate($state['last_run']);
        if ($lastRun === null) {
            return true;
        }

        return $lastRun < $scheduled;
    }

    public function nextOccurrence(array $schedule, ?\DateTimeImmutable $now = null, ?array $state = null): ?\DateTimeImmutable
    {
        if (empty($schedule['enabled'])) {
            return null;
        }

        $now ??= new \DateTimeImmutable('now', $this->timezone);

        // Interval-based: next run is last_run + interval (or now if never run)
        $intervalSec = $this->intervalSeconds($schedule['frequency'] ?? '');
        if ($intervalSec !== null) {
            if ($state === null || empty($state['last_run'])) {
                return $now;
            }
            $lastRun = $this->parseDate($state['last_run']);
            if ($lastRun === null) {
                return $now;
            }
            return $lastRun->modify('+' . $intervalSec . ' seconds');
        }

        // Slot-based
        $today = $this->occurrenceForDate($schedule, $now);
        if ($today !== null && $today > $now) {
            return $today;
        }

        return $this->advanceFromMostRecent($schedule, $now);
    }

    /**
     * Returns the interval in seconds for sub-hourly frequencies, or null
     * if the frequency uses slot-based scheduling (daily/weekly/monthly).
     */
    private function intervalSeconds(string $frequency): ?int
    {
        return match ($frequency) {
            ScheduleConfig::FREQ_5MIN   => 300,
            ScheduleConfig::FREQ_15MIN  => 900,
            ScheduleConfig::FREQ_HOURLY => 3600,
            default                     => null,
        };
    }

    /**
     * Returns the most recent past or present occurrence of this schedule.
     */
    private function mostRecentOccurrence(array $schedule, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $today = $this->occurrenceForDate($schedule, $now);

        // Today's slot has already passed → that's the most recent
        if ($today !== null && $today <= $now) {
            return $today;
        }

        // Today's slot is in the future, so step backward to find the previous one
        $freq = $schedule['frequency'] ?? ScheduleConfig::FREQ_DAILY;

        $cursor = $now->modify('-1 day');
        for ($i = 0; $i < 366; ++$i) {
            $occ = $this->occurrenceForDate($schedule, $cursor);
            if ($occ !== null && $occ <= $now) {
                return $occ;
            }
            $cursor = $cursor->modify('-1 day');

            // For monthly we don't want to scan day-by-day forever
            if ($freq === ScheduleConfig::FREQ_MONTHLY && $i > 32) {
                break;
            }
            if ($freq === ScheduleConfig::FREQ_WEEKLY && $i > 7) {
                break;
            }
        }

        return null;
    }

    /**
     * Computes the next occurrence after $now.
     */
    private function advanceFromMostRecent(array $schedule, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $cursor = $now->modify('+1 day');
        for ($i = 0; $i < 366; ++$i) {
            $occ = $this->occurrenceForDate($schedule, $cursor);
            if ($occ !== null && $occ > $now) {
                return $occ;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return null;
    }

    /**
     * If $date is a valid scheduled day (matches frequency criteria), returns
     * the DateTime at HH:MM on that day. Otherwise null.
     */
    private function occurrenceForDate(array $schedule, \DateTimeImmutable $date): ?\DateTimeImmutable
    {
        $freq = $schedule['frequency'] ?? ScheduleConfig::FREQ_DAILY;

        $matches = match ($freq) {
            ScheduleConfig::FREQ_DAILY   => true,
            ScheduleConfig::FREQ_WEEKLY  => ((int) $date->format('w')) === ((int) ($schedule['weekday']      ?? 1)),
            ScheduleConfig::FREQ_MONTHLY => ((int) $date->format('j')) === ((int) ($schedule['day_of_month'] ?? 1)),
            default                      => false,
        };

        if (!$matches) {
            return null;
        }

        [$h, $m] = explode(':', (string) ($schedule['time'] ?? '03:00'));
        return $date->setTime((int) $h, (int) $m, 0);
    }

    private function parseDate(string $iso): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            return null;
        }
    }
}
