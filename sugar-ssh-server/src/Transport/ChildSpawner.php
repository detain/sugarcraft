<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Transport;

use SugarCraft\SshServer\Session;

/**
 * Marker interface for transports that can spawn a child process
 * inside a controlled PTY and pump bytes on its behalf.
 *
 * Currently only {@see InProcessTransport} implements it; the
 * {@see HostSshdTransport} legacy mode runs middleware inline
 * against sshd's own PTY and has no notion of "spawning a child"
 * — middleware that depend on this seam (the shipped `Spawn`) will
 * throw at `handle()` time when running under HostSshd because no
 * spawner was injected.
 *
 * The interface lets the `Spawn` middleware unit-test against a
 * fake without needing a real PTY.
 *
 * It is deliberately narrower than the class behind it (audit MAJOR 18).
 * `InProcessTransport::runChild()` takes two more parameters -- `$stdin`
 * and `$stdout` -- because injecting streams is what makes the transport
 * testable at all. They are not part of this contract, so a caller holding
 * a `ChildSpawner` cannot reach them: narrow with `instanceof
 * InProcessTransport` or accept the concrete class. Widening the interface
 * would leave every fake in the suite implementing parameters it ignores,
 * which is the trade this file keeps making, in writing, rather than by
 * surprise.
 */
interface ChildSpawner
{
    /**
     * Spawn `$cmd` inside a fresh PTY and pump bytes between the
     * supervisor's STDIN/STDOUT and the master fd until the child
     * exits, returns the exit code.
     *
     * Containment scope: teardown escalation SIGKILLs the child's
     * PROCESS GROUP, so backgrounded descendants die with it; see
     * {@see InProcessTransport::runChild()} for the live-leader gate
     * and its honest boundary.
     *
     * The streams are fixed to `STDIN`/`STDOUT` for callers of this
     * signature; see the class note for the concrete transport's wider
     * form.
     *
     * @param list<string>              $cmd
     * @param array<string,string>|null $env  null inherits parent env
     */
    public function runChild(Session $session, array $cmd, ?array $env = null): int;

    /**
     * Forward a signal to the live child process.
     *
     * Pid-scoped by contract: RFC 4254 signals name a process, not a
     * session group — a peer's SIGTERM must not sweep the child's
     * background jobs. Only the teardown escalation (the concrete
     * transport's ladder) reaches the group.
     *
     * No-op for transports that do not spawn a child or when no child
     * is currently running. Delivery must not depend on ext-posix:
     * sugar-pty's Libc::kill() falls back to libc kill(2) over FFI.
     */
    public function signalChild(int $signal): void;
}
