<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Jobs;

use SugarCraft\Serve\Lang;

/**
 * Background-job schedule parsed from config (plan item 7.5).
 *
 * Mirrors the subset of robfig/cron descriptors charmbracelet/soft-serve
 * actually uses for `jobs.mirror_pull` (default `@every 10m`):
 *
 *   - `@every <duration>` with a Go-style duration (`30s`, `10m`, `8h`,
 *     `1h30m`, …)
 *   - the fixed-interval aliases `@hourly`, `@daily`, `@midnight`,
 *     `@weekly`, `@monthly`, `@yearly`, `@annually`
 *
 * Full five-field cron expressions are NOT supported and throw — the
 * schedule is modelled as a plain interval, which is all the mirror-pull
 * job needs (aliases are treated as intervals, not wall-clock anchors).
 */
final class Schedule
{
    private const ALIASES = [
        '@hourly'   => 3600,
        '@daily'    => 86400,
        '@midnight' => 86400,
        '@weekly'   => 604800,
        '@monthly'  => 2592000,   // 30 days
        '@yearly'   => 31536000,  // 365 days
        '@annually' => 31536000,
    ];

    private function __construct(
        public readonly int $intervalSeconds,
        public readonly string $expression,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on unsupported/malformed expressions
     */
    public static function parse(string $expression): self
    {
        $expr = \trim($expression);

        if (isset(self::ALIASES[$expr])) {
            return new self(self::ALIASES[$expr], $expr);
        }

        if (\preg_match('/^@every\s+(\S+)$/', $expr, $m) === 1) {
            $seconds = self::parseDuration($m[1]);
            if ($seconds === null || $seconds <= 0) {
                throw new \InvalidArgumentException(Lang::t('jobs.invalid_schedule', [
                    'schedule' => $expression,
                    'reason'   => 'duration must be a positive Go-style duration like "10m" or "1h30m"',
                ]));
            }

            return new self($seconds, $expr);
        }

        throw new \InvalidArgumentException(Lang::t('jobs.invalid_schedule', [
            'schedule' => $expression,
            'reason'   => 'expected "@every <duration>" or an @-alias; cron expressions are not supported',
        ]));
    }

    /** Whether a job last run at $lastRun (null = never) is due at $now. */
    public function isDue(?int $lastRun, int $now): bool
    {
        return $lastRun === null || ($now - $lastRun) >= $this->intervalSeconds;
    }

    /** Parse a Go-style duration ("8h", "10m", "1h30m", "90s") into seconds. */
    private static function parseDuration(string $duration): ?int
    {
        if (\preg_match('/^(?:\d+[hms])+$/', $duration) !== 1) {
            return null;
        }

        \preg_match_all('/(\d+)([hms])/', $duration, $matches, \PREG_SET_ORDER);

        $seconds = 0;
        foreach ($matches as [, $amount, $unit]) {
            $seconds += (int) $amount * match ($unit) {
                'h' => 3600,
                'm' => 60,
                's' => 1,
            };
        }

        return $seconds;
    }
}
