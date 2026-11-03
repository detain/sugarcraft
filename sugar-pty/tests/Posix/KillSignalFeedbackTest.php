<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Posix\PosixProcess;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Tests\Support\PtyCapability;

/**
 * m7 (round lib-fixes-1103): kill() must stop signalling into the void.
 * A handle whose child we observed exiting never signals again (pid
 * reuse hazard), and a failed kill on a live child surfaces as a typed
 * exception instead of vanishing.
 */
final class KillSignalFeedbackTest extends TestCase
{
    public function testKillOnLiveProcessWithBogusSignalThrows(): void
    {
        $proc = PosixProcess::spawn(['/bin/sleep', '30']);
        try {
            $this->expectException(PtyException::class);
            $this->expectExceptionMessageMatches('/kill\(signal 999999\) failed for pid/');
            $proc->kill(999999); // EINVAL on a live pid — must NOT be swallowed
        } finally {
            $proc->kill(Child::SIGKILL);
            $proc->wait();
        }
    }

    public function testKillAfterExitIsANoOp(): void
    {
        $proc = PosixProcess::spawn(['/bin/true']);
        $proc->wait(); // reaps; handle knows the child is gone
        $this->assertTrue($proc->exited());

        // Post-reap pid may already belong to another process; the guard
        // must return silently rather than signal it.
        $proc->kill(Child::SIGTERM);
        $this->assertTrue($proc->exited());
    }

    public function testPtyChildKillReportsFailureAndGuardsAfterExit(): void
    {
        if (!PtyCapability::ptySyscallsUsable()) {
            $this->markTestSkipped('Requires usable FFI PTY syscalls.');
        }

        $system = new \SugarCraft\Pty\Posix\PosixPtySystem();
        $pair = $system->open(80, 24);
        try {
            $child = $pair->slave()->spawn(['/bin/sh', '-c', 'exec sleep 30']);
            $this->expectException(PtyException::class);
            $this->expectExceptionMessageMatches('/kill\(signal 999999\) failed for pid/');
            $child->kill(999999);
        } finally {
            if (isset($child)) {
                $child->kill(Child::SIGKILL);
                $child->wait();
            }
            $pair->master()->close();
        }
    }

    public function testPtyChildKillAfterReapDoesNotSignal(): void
    {
        if (!PtyCapability::ptySyscallsUsable()) {
            $this->markTestSkipped('Requires usable FFI PTY syscalls.');
        }

        $system = new \SugarCraft\Pty\Posix\PosixPtySystem();
        $pair = $system->open(80, 24);
        try {
            $child = $pair->slave()->spawn(['/bin/true']);
            $child->wait();
            $this->assertTrue($child->exited());
            $child->kill(Child::SIGKILL); // must be a silent no-op, never a foreign-pid signal
            $this->assertTrue($child->exited());
        } finally {
            $pair->master()->close();
        }
    }
}
