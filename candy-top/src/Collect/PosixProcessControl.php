<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The live {@see ProcessControl}: `posix_kill()` and
 * `pcntl_setpriority(PRIO_PROCESS)` — PHP's `proc_nice()` can only renice
 * the calling process. A pid outside 1..PID_MAX is refused as ESRCH before
 * any syscall (0 and negatives address process groups / the caller). A missing extension reports ENOSYS (38) instead of
 * failing, which the signal-failure box prints as "Unknown error".
 *
 * Mirrors aristocratos/btop Menu::signalChoose's kill() and
 * Proc::set_priority (src/linux/btop_collect.cpp).
 */
final class PosixProcessControl implements ProcessControl
{
    private const ENOSYS = 38;

    private const ESRCH = 3;

    /** Linux PID_MAX_LIMIT (2^22): no pid can be larger. */
    public const PID_MAX = 4_194_304;

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    public function signal(int $pid, int $signal): int
    {
        // kill(0) / kill(-n) would hit a whole process group: never a real pid.
        if ($pid < 1 || $pid > self::PID_MAX) {
            return self::ESRCH;
        }
        if (!function_exists('posix_kill')) {
            return self::ENOSYS;
        }

        return @posix_kill($pid, $signal) ? 0 : (function_exists('posix_get_last_error') ? posix_get_last_error() : self::ENOSYS);
    }

    public function renice(int $pid, int $nice): int
    {
        // setpriority(PRIO_PROCESS, 0) would renice candy-top itself.
        if ($pid < 1 || $pid > self::PID_MAX) {
            return self::ESRCH;
        }
        if (!function_exists('pcntl_setpriority')) {
            return self::ENOSYS;
        }

        return @pcntl_setpriority($nice, $pid, PRIO_PROCESS) ? 0 : (function_exists('pcntl_get_last_error') ? pcntl_get_last_error() : self::ENOSYS);
    }
}
