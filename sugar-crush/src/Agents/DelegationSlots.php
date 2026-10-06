<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Support\PrivateDir;

/**
 * The cap on how many delegated runs one session has going at once, across
 * every process they run in (roadmap 4.7-3).
 *
 * WHY ACROSS PROCESSES. A `Task` run happens in the turn's forked child; a
 * parallel batch's members each run in a grandchild; a nested delegation runs
 * inside its parent's process or forks again; a background agent runs in a
 * daemon. The per-batch cap ({@see AgentPoolConfig::$maxConcurrent}, step
 * 0.16) bounds one batch only, so with nesting the tree multiplied it — five
 * members each delegating five is twenty-five runs billing at once. Every
 * process can see the same directory, so the cap is a directory of N slot
 * files, one per seat, and a run holds a seat by holding an exclusive flock()
 * on one ({@see DelegationSlot}).
 *
 * REFUSE, NEVER WAIT. A run that finds every seat taken is refused at once
 * with a reason the model reads, as Claude Code refuses past its limit. Waiting
 * would deadlock: a parent holds its seat while it waits on the children it
 * delegated, so a full tree of waiting parents could never be served (Zed's
 * note on rate-limit permits held across tool calls is the same trap). Because
 * a refusal is what a full tree costs, the cap is opt-in: $max null (or below
 * 1) enforces nothing.
 *
 * Keyed by $scope — the session the runs belong to — under a per-uid 0700
 * directory, so two sessions never share seats and a stranger cannot hold one.
 */
final class DelegationSlots
{
    /** Name stem of the per-uid directory under the temp root. */
    public const DIR_PREFIX = 'sugar_crush_slots_';

    /** A scope directory untouched this long, with no seat held, is removed. */
    public const STALE_SECONDS = 86_400;

    private function __construct()
    {
    }

    /**
     * Take a free seat of $scope's $max, or null when all are taken. Also
     * null — no cap enforced — when $max is null or below 1, and when the slot
     * directory cannot be made private: a cap that cannot be kept safely is not
     * kept at all, rather than turning every delegation into a refusal.
     *
     * @param string|null $root the temp root; null is the system temp dir
     * @return DelegationSlot|false|null the seat; false when every seat is
     *         taken; null when no cap is enforced here
     */
    public static function acquire(string $scope, ?int $max, ?string $root = null): DelegationSlot|false|null
    {
        if ($max === null || $max < 1 || !\function_exists('posix_getuid')) {
            return null;
        }

        $uid = posix_getuid();
        $base = rtrim($root ?? sys_get_temp_dir(), '/') . '/' . self::DIR_PREFIX . $uid;
        try {
            PrivateDir::ensure($base, 'delegation slots', $uid);
        } catch (\RuntimeException) {
            return null;
        }

        $dir = $base . '/' . substr(hash('sha256', $scope), 0, 24);
        if (!is_dir($dir)) {
            self::sweep($base);
            if (!@mkdir($dir, 0o700) && !is_dir($dir)) {
                return null;
            }
        }
        if (!PrivateDir::isPrivate($dir, $uid)) {
            return null;
        }
        @touch($dir);

        for ($i = 0; $i < $max; $i++) {
            // `e`: close-on-exec, so a command the run spawns never holds a
            // seat after the run is gone.
            $handle = @fopen($dir . '/slot-' . $i . '.lock', 'ce');
            if ($handle === false) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new DelegationSlot($handle, $i);
            }
            fclose($handle);
        }

        return false;
    }

    /**
     * How many of $scope's first $max seats are held right now — a probe for
     * tests and diagnostics; it takes and drops each free seat to find out.
     * A null $max caps nothing, so nothing is held.
     */
    public static function held(string $scope, ?int $max, ?string $root = null): int
    {
        if ($max === null) {
            return 0;
        }

        $taken = [];
        while (count($taken) < $max && ($slot = self::acquire($scope, $max, $root)) instanceof DelegationSlot) {
            $taken[] = $slot;
        }
        if (($slot ?? null) === null && $taken === []) {
            return 0;
        }
        $held = $max - count($taken);
        foreach ($taken as $seat) {
            $seat->release();
        }

        return $held;
    }

    /**
     * Remove scope directories untouched for {@see STALE_SECONDS} whose every
     * seat is free. Run when a new scope directory is about to be made, so the
     * directory count follows the sessions in use rather than growing forever.
     */
    private static function sweep(string $base): void
    {
        foreach (glob($base . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
            $mtime = @filemtime($dir);
            if ($mtime === false || time() - $mtime < self::STALE_SECONDS || is_link($dir)) {
                continue;
            }
            $files = glob($dir . '/slot-*.lock', GLOB_NOSORT) ?: [];
            $handles = [];
            $free = true;
            foreach ($files as $file) {
                $handle = @fopen($file, 'ce');
                if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
                    $free = false;
                    if ($handle !== false) {
                        fclose($handle);
                    }
                    break;
                }
                $handles[] = $handle;
            }
            if ($free) {
                foreach ($files as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
            }
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
    }
}
