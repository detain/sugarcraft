<?php

declare(strict_types=1);

namespace SugarCraft\McpClient;

use SugarCraft\Core\Util\Tty\Kernel32;

/**
 * The single home of the signal-0 liveness contract this library leans on
 * in three places: who may claim an id sequence, whether a lock's owner
 * still exists, and whether a forked observer still has a server to talk
 * to. One kernel contract deserves one probe — the pre-extraction tree
 * carried three hand-rolled variants whose fail-open shapes drifted apart,
 * so a fix to one (say, a stricter EPERM reading) silently failed to reach
 * the others.
 *
 * The law every consumer shares: only the CERTAINTY that the kernel
 * reports "no such process" (ESRCH) says dead. EPERM is a live process
 * belonging to someone else; any other errno is not an answer at all.
 * Without ext-posix, Linux /proc can still certify absence; on platforms
 * where nothing can, the honest answer is "alive" — the callers' damage
 * models all prefer a stale-but-live refusal over killing a working
 * exchange on an unreadable system call.
 */
final class ProcessLiveness
{
    /** The errno kill(pid, 0) reports for a pid no process has. */
    public const ERRNO_ESRCH = 3;

    /**
     * Refuse-only-on-certainty polarity: true unless the kernel can prove
     * the pid is absent.
     */
    public static function ownerAlive(int $pid): bool
    {
        if (function_exists('posix_kill') && function_exists('posix_get_last_error')) {
            if (@posix_kill($pid, 0)) {
                return true;
            }

            return posix_get_last_error() !== self::ERRNO_ESRCH;
        }

        if (is_dir('/proc/self')) {
            return file_exists("/proc/{$pid}");
        }

        // Windows has neither: ask kernel32 (OpenProcess + exit code), which
        // certifies absence the way ESRCH does. Without it a crashed
        // session's lock file was never reclaimed there.
        if (\PHP_OS_FAMILY === 'Windows' && \extension_loaded('ffi')) {
            try {
                $alive = Kernel32::self()->processAlive($pid);
                if ($alive !== null) {
                    return $alive;
                }
            } catch (\Throwable) {
                // No answer is "alive", like every other uncertain arm.
            }
        }

        return true;
    }

    private function __construct()
    {
    }
}
