<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The pids of hook child processes that are running right now, so a quit can
 * reap them instead of leaving them to outlive the session.
 *
 * A hook is spawned as a `setsid` wrapper that leads its own process group, so
 * {@see ProcessContainment::killTreeAsync()} on the recorded pid reaches what
 * the hook backgrounded too. Registration is paired with release around the
 * one `proc_close()` a hook run makes, so an entry only ever names a child the
 * process still owns.
 */
final class HookProcessGroups
{
    /** @var array<int, true> */
    private static array $live = [];

    public static function register(int $pid): void
    {
        if ($pid > 0) {
            self::$live[$pid] = true;
        }
    }

    public static function release(int $pid): void
    {
        unset(self::$live[$pid]);
    }

    /** @return list<int> the pids currently registered, in registration order */
    public static function live(): array
    {
        return array_map('intval', array_keys(self::$live));
    }
}
