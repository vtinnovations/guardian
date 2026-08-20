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

use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Writes the important Guardian bundle events to Contao's system log (tl_log table).
 *
 * Log entries appear in the Contao backend under "System → System log" with a
 * recognisable action so admins can filter for them.
 *
 * Why a wrapper instead of injecting LoggerInterface everywhere:
 *   - We always want a ContaoContext attached (otherwise it goes only to monolog files,
 *     not to the Contao tl_log table)
 *   - We want consistent action labels — e.g. all entries from us use "VTINNOVATIONS_GUARDIAN"
 *     so the admin can filter for our entries in one click
 *   - Logger is optional (NullLogger fallback) so unit tests stay simple and the bundle
 *     keeps working even if monolog services are unavailable for any reason
 *
 * Distinction from JobLog:
 *   - JobLog = detailed, per-line stream of EVERY step output, used by the live UI.
 *   - SystemLogger = high-level summary of important events, used by the admin
 *     for retrospective troubleshooting via the regular Contao UI.
 */
class SystemLogger
{
    /**
     * Custom action label so all our entries are grouped/filterable in tl_log.
     */
    public const ACTION = 'VTINNOVATIONS_GUARDIAN';

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $contaoLogger = null)
    {
        // The contao.contao monolog channel is the one that ends up in tl_log.
        // If it's not available for any reason, fall back to NullLogger so
        // calling code doesn't have to null-check.
        $this->logger = $contaoLogger ?? new NullLogger();
    }

    public function info(string $message, string $caller = ''): void
    {
        $this->logger->info($message, [
            'contao' => new ContaoContext($caller !== '' ? $caller : $this->guessCaller(), self::ACTION),
        ]);
    }

    public function warning(string $message, string $caller = ''): void
    {
        $this->logger->warning($message, [
            'contao' => new ContaoContext($caller !== '' ? $caller : $this->guessCaller(), self::ACTION),
        ]);
    }

    public function error(string $message, string $caller = ''): void
    {
        $this->logger->error($message, [
            'contao' => new ContaoContext(
                $caller !== '' ? $caller : $this->guessCaller(),
                ContaoContext::ERROR
            ),
        ]);
    }

    /**
     * Inspects the call stack to find the immediate caller of the public log method.
     * Used so the system log shows e.g. "JobRunner::run" rather than every entry
     * pointing back to SystemLogger.
     */
    private function guessCaller(): string
    {
        $trace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 4);

        // Skip frames inside this class
        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';
            if ($class === self::class) {
                continue;
            }

            $function = $frame['function'] ?? '';
            return $class !== '' ? "{$class}::{$function}" : $function;
        }

        return 'Vtinnovations\\Guardian';
    }
}
