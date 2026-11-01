<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\PtySystemFactory;

/**
 * The single PTY opener for this application: hand back a CONTRACT pair from
 * the platform factory, never the deprecated POSIX-only facade.
 *
 * WHY A BACKEND SEAM (C-TUI-6.d): the app's two live openers —
 * {@see PtyDriver::run()} and the Bash-interactive capture path in
 * {@see \SugarCraft\Code\Tools\Concerns\CapturesProcessOutput} — each called
 * the deprecated `SugarCraft\Pty\Pty` facade directly. That facade hardwires
 * `PosixPtySystem` and can never answer on Windows. `PtySystemFactory::new()`
 * picks the OS-safe system instead: the ConPTY pair there, and on Linux and
 * macOS the SAME canonical `PosixPtySystem` path the facade always used, so
 * every opener that goes through the pair gains the Windows arm without this
 * class carrying a single platform branch.
 *
 * The class does not spawn, read, or inspect — it is the factory call with a
 * name, kept deliberately as the one grep target for "where does this app
 * allocate a terminal". `$system` is the injection seam tests use to hand
 * back a scripted pair without a real terminal; production passes nothing
 * and gets the factory's platform answer.
 */
final class PtyBackend
{
    /**
     * Allocate a pseudo-terminal of the requested size on whichever system
     * this host answers to.
     *
     * @param int          $cols   requested window width, applied by the pair
     * @param int          $rows   requested window height, applied by the pair
     * @param PtySystem|null $system injection seam; null asks the factory
     *
     * @throws \Throwable whatever the chosen system throws on allocation (a
     *                    sealed `/dev/ptmx`, an unavailable ConPTY, a
     *                    mislinked vendor) — the callers refuse on the
     *                    failure; this seam never swallows it.
     */
    public static function open(int $cols = 80, int $rows = 24, ?PtySystem $system = null): PtyPair
    {
        return ($system ?? PtySystemFactory::new())->open($cols, $rows);
    }
}
