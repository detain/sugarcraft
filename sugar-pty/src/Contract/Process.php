<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Contract;

/**
 * Handle to a non-PTY child process (for candy-scriptui migration).
 *
 * Extends {@see Child}: a non-PTY child IS a child — the lifecycle five
 * (pid/exited/wait/exitCode/kill) and the signal constants live there once.
 * This contract only adds the captured-output pair. (Before that, every
 * member and constant was re-declared here byte-for-byte, so a `Child|Process`
 * type-hint dance and downstream instanceof-reflection — the pid probe in
 * sugar-dashboard's ExternalModule is the exhibit — existed only to bridge a
 * duplication the type system never needed.)
 *
 * @see creack/pty.Cmd
 * @see portable-pty.Process
 */
interface Process extends Child
{
    /**
     * Bytes captured from stdout.
     */
    public function stdoutBytes(): string;

    /**
     * Bytes captured from stderr.
     */
    public function stderrBytes(): string;
}
