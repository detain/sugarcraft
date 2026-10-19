<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Sessions\Schedule;
use SugarCraft\Code\Sessions\ScheduleRegistry;

/**
 * `/loop <interval> <prompt>` (AUT-1) — re-run a prompt in THIS session every
 * interval, for the life of the process.
 *
 * The row it registers is EPHEMERAL: in the {@see ScheduleRegistry} but never
 * written to schedules.json, needing no fire lock (exactly one process can
 * know it exists), and dying with the TUI. Its prompt rides Chat's
 * `queuedPrompts` door — the same one settled `/bg` announcements use — so it
 * becomes the next turn when the session is idle and simply waits while a turn
 * is running. That is why `/loop` is NOT read-only-safe: it programs future
 * turns, which is a write against the session.
 *
 * The interval grammar is a bare number of seconds or a number with an
 * `s`/`m`/`h`/`d` suffix; the floor and ceiling are {@see Schedule}'s, checked
 * there (parse, don't re-validate) and surfaced as a refusal naming them.
 * First fire is ONE interval out, not immediately — `/loop` repeats, it does
 * not run-and-repeat (that is `/bg` plus another `/loop`).
 *
 * Names auto-issue `loop-1`, `loop-2`, … never colliding with a persisted row
 * or a live loop; rows delete with `/schedules delete <name>` (or vanish at
 * exit).
 */
final class LoopCommand implements HostCommand
{
    /** `120`, `30s`, `15m`, `2h`, `1d` — the whole grammar, no cron fields. */
    private const INTERVAL_PATTERN = '/^([0-9]{1,9})([smhd])?$/';

    /** @var array<string, int> suffix multipliers in seconds */
    private const UNIT_SECONDS = ['s' => 1, 'm' => 60, 'h' => 3_600, 'd' => 86_400];

    public function run(CommandContext $context, string $text): CommandResult
    {
        $registry = $context->scheduleRegistry;
        if ($registry === null) {
            return CommandResult::errorReply($text, Lang::t('host.loop.not_configured'));
        }

        $argument = CommandText::argument($text);
        if (preg_match('/^(\S+)\s+(.+)$/s', $argument, $m) !== 1) {
            return CommandResult::reply($text, Lang::t('host.loop.usage'));
        }
        $seconds = self::intervalSeconds($m[1]);
        if ($seconds === null) {
            return CommandResult::errorReply($text, Lang::t('host.loop.bad_interval', ['interval' => $m[1]]));
        }
        // The remainder rides VERBATIM (only trimmed): a multi-line loop
        // prompt stays a multi-line prompt, the same contract `/bg` gives its
        // task — re-splitting on whitespace would silently rewrite the task.
        $prompt = trim($m[2]);
        if ($prompt === '') {
            return CommandResult::reply($text, Lang::t('host.loop.usage'));
        }

        try {
            $schedule = new Schedule(
                name: self::nextLoopName($registry),
                everySeconds: $seconds,
                prompt: $prompt,
                nextDue: time() + $seconds,
                persisted: false,
            );
            $registry->add($schedule);
        } catch (\InvalidArgumentException $e) {
            return CommandResult::errorReply($text, $e->getMessage());
        }

        return CommandResult::reply($text, Lang::t('host.loop.registered', [
            'name' => $schedule->name,
            'every' => self::everyLabel($seconds),
            'prompt' => $prompt,
        ]));
    }

    /** Seconds for `60`/`90s`/`15m`/`2h`/`1d`, null when the word is not an interval at all. */
    public static function intervalSeconds(string $word): ?int
    {
        if (preg_match(self::INTERVAL_PATTERN, $word, $m) !== 1) {
            return null;
        }

        return ((int) $m[1]) * self::UNIT_SECONDS[$m[2] ?? 's'];
    }

    /** `loop-<n>` above every existing row's loop suffix, persisted or not — a name is a name. */
    public static function nextLoopName(ScheduleRegistry $registry): string
    {
        $highest = 0;
        foreach ($registry->all() as $row) {
            if (preg_match('/^loop-([0-9]{1,9})$/', $row->name, $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return 'loop-' . ($highest + 1);
    }

    /** Coarse human cadence for the tables: `45m`, `2h`, `1d`, bare seconds below a minute. */
    public static function everyLabel(int $seconds): string
    {
        foreach ([['d', 86_400], ['h', 3_600], ['m', 60]] as [$unit, $size]) {
            if ($seconds >= $size && $seconds % $size === 0) {
                return intdiv($seconds, $size) . $unit;
            }
        }

        return $seconds . 's';
    }
}
