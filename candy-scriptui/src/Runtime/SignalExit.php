<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

/**
 * POSIX exit-code mapping for a Program run that ended because the OS
 * delivered a signal to the process (Ctrl-C / SIGINT, SIGTERM, …).
 *
 * sugar-core's Program catches interrupts (every candy-scriptui prompt
 * runs with `catchInterrupts: true`) and records the terminating signal
 * via `stopSignal()`. Programmatic stops — `kill()` (which is what
 * {@see TimeoutGuard()} fires) and a QuitMsg from the model — leave it
 * null, so the two paths can be told apart at the exit-code level:
 * a deadline yields {@see TimeoutGuard::EXIT_TIMEOUT} while a signal
 * yields the shell convention `128 + signum` (SIGINT → 130, SIGTERM → 143).
 * That is the number `set -o pipefail` scripts branch on, so it must not
 * be flattened into the generic abort codes.
 */
final class SignalExit
{
    /**
     * The conventional `128 + SIGINT` code for a run the user cancelled
     * with Ctrl+C — including the typed-Ctrl+C path, where raw mode has
     * ISIG cleared so no OS signal is delivered at all: the byte arrives
     * as a KeyMsg and the model records the interruption itself
     * ({@see isInterrupted()} on the interactive models). Commands map
     * that state to this code so scripts see one 130 regardless of
     * whether the interrupt came from a keystroke or `kill -INT`.
     */
    public const EXIT_INTERRUPTED = 130; // 128 + SIGINT(2)

    /**
     * @param ?int $stopSignal The signal that stopped the Program, if any.
     * @return ?int The 128+signum exit code, or null when the run was not
     *              stopped by a signal and normal result mapping applies.
     */
    public static function code(?int $stopSignal): ?int
    {
        return $stopSignal === null ? null : 128 + $stopSignal;
    }
}
