<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

/**
 * btop's `Menu::P_Signals` for Linux on the common architectures (x86,
 * arm, riscv, ...): index = signal number, 1-31. Index 0 is btop's "0"
 * placeholder; 16 (SIGSTKFLT) is listed but skipped in the chooser grid.
 *
 * Mirrors aristocratos/btop Menu::P_Signals (src/btop_menu.cpp:64-131).
 */
final class Signals
{
    public const NAMES = [
        '0',
        'SIGHUP', 'SIGINT', 'SIGQUIT', 'SIGILL',
        'SIGTRAP', 'SIGABRT', 'SIGBUS', 'SIGFPE',
        'SIGKILL', 'SIGUSR1', 'SIGSEGV', 'SIGUSR2',
        'SIGPIPE', 'SIGALRM', 'SIGTERM', 'SIGSTKFLT',
        'SIGCHLD', 'SIGCONT', 'SIGSTOP', 'SIGTSTP',
        'SIGTTIN', 'SIGTTOU', 'SIGURG', 'SIGXCPU',
        'SIGXFSZ', 'SIGVTALRM', 'SIGPROF', 'SIGWINCH',
        'SIGIO', 'SIGPWR', 'SIGSYS',
    ];

    public const SIGTERM = 15;

    public const SIGKILL = 9;

    /** btop errno values signalReturn distinguishes (Linux). */
    public const EPERM = 1;

    public const ESRCH = 3;

    public const EINVAL = 22;

    private function __construct()
    {
    }

    /** The name btop prints for `$signal`, or null outside 1-31. */
    public static function name(int $signal): ?string
    {
        return $signal >= 1 && $signal <= 31 ? self::NAMES[$signal] : null;
    }
}
