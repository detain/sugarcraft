<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Contract;

/**
 * Byte pump that forwards stdin ↔ master PTY with back-pressure,
 * EOF grace, and optional SIGWINCH / keepalive / child-exit callbacks.
 *
 * @see portable-pty.Pump
 */
interface Pump
{
    /**
     * Run the byte pump until the child exits.
     *
     * @param MasterPty  $master
     * @param resource  $stdinStream  PHP stream resource (e.g. STDIN)
     * @param resource  $stdoutStream PHP stream resource (e.g. STDOUT)
     * @param Child|null $child  null when no child to monitor (stdin→master only)
     * @return int exit code from the child, or -1 if no child
     * @see portable-pty.Pump.Run()
     */
    public function run(
        MasterPty $master,
        $stdinStream,
        $stdoutStream,
        ?Child $child = null,
    ): int;
}
