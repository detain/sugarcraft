<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Transport;

use SugarCraft\Wish\Session;

/**
 * Marker interface for transports that can spawn a child process
 * inside a controlled PTY and pump bytes on its behalf.
 *
 * Currently only {@see InProcessTransport} implements it; the
 * {@see HostSshdTransport} legacy mode runs middleware inline
 * against sshd's own PTY and has no notion of "spawning a child"
 * — middleware that depend on this seam (the PR3 `Spawn`) will
 * throw at `handle()` time when running under HostSshd because no
 * spawner was injected.
 *
 * The interface lets the `Spawn` middleware unit-test against a
 * fake without needing a real PTY.
 */
interface ChildSpawner
{
    /**
     * Spawn `$cmd` inside a fresh PTY and pump bytes between the
     * supervisor's STDIN/STDOUT and the master fd until the child
     * exits, returns the exit code.
     *
     * @param list<string>              $cmd
     * @param array<string,string>|null $env  null inherits parent env
     */
    public function runChild(Session $session, array $cmd, ?array $env = null): int;

    /**
     * Forward a signal to the live child process.
     *
     * No-op for transports that do not spawn a child or when no child
     * is currently running. Guarded with function_exists('posix_kill')
     * and a null-check on the stored child PID.
     */
    public function signalChild(int $signal): void;
}
