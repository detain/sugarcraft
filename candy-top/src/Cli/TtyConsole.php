<?php

declare(strict_types=1);

namespace SugarCraft\Top\Cli;

/**
 * Decides whether STDIN is a real console for btop's tty-mode auto-enable.
 *
 * btop enables tty mode on a `/dev/tty*` console (VT or serial). A macOS
 * terminal session is a pty slave named `/dev/ttysNNN`, which matches that
 * prefix, so the test answered true on every Mac terminal. tty mode then
 * freezes the backdrop under the boot OS notice, and the dashboard (clock
 * included) never repaints. macOS is not a console at all, so Darwin is
 * never one.
 */
final class TtyConsole
{
    /**
     * @param ?string $ttyName posix_ttyname(STDIN), or null when ext-posix is absent
     * @param string  $osFamily PHP_OS_FAMILY
     */
    public static function isConsole(?string $ttyName, string $osFamily): bool
    {
        if ($osFamily === 'Darwin') {
            return false;
        }
        if ($ttyName === null) {
            // Without ext-posix the console name is unreadable; the stream_isatty
            // gate upstream already refused anything that is not a terminal.
            return $osFamily !== 'Windows';
        }

        return str_starts_with($ttyName, '/dev/tty');
    }
}
