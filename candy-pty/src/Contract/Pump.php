<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Contract;

/**
 * Byte pump that forwards stdin ↔ master PTY with back-pressure,
 * EOF grace, and optional onIdle / onSigwinch / child-exit callbacks.
 *
 * The {@see run()} loop fires {@see PumpOptions::$onIdle} on every
 * stream_select idle tick and {@see PumpOptions::$onSigwinch} when a
 * real terminal-resize signal arrives via the consumer's
 * {@see SignalForwarder::attachSigwinch} callback. These two hooks
 * are independent — onIdle is for keepalive / polling / housekeeping;
 * onSigwinch carries real (cols, rows) from the host TTY.
 *
 * @see portable-pty.Pump
 */
interface Pump
{
    /**
     * Run the byte pump until pump conditions trigger: child exits,
     * STDOUT hits EPIPE, or STDIN reaches EOF and the post-EOF grace
     * window elapses. Does NOT block on {@see Child::wait()} when the
     * child is still alive — the caller is responsible for kill /
     * PTY close / final wait() so supervisors can enforce kill-on-
     * STDIN-EOF policy without the pump holding them hostage.
     *
     * @param MasterPty  $master
     * @param resource  $stdinStream  PHP stream resource (e.g. STDIN)
     * @param resource  $stdoutStream PHP stream resource (e.g. STDOUT)
     * @param Child|null $child  null when no child to monitor (stdin→master only)
     * @return int  the child's exit code if it has already exited by
     *              the time the pump returns; 0 if there is no child
     *              to monitor (stdin→master only); -1 if a child was
     *              supplied but is still running (caller must kill +
     *              wait()).
     * @see portable-pty.Pump.Run()
     */
    public function run(
        MasterPty $master,
        $stdinStream,
        $stdoutStream,
        ?Child $child = null,
    ): int;
}
