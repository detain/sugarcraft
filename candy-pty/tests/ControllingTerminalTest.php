<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\PtyException;

final class ControllingTerminalTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required for the TIOCSCTTY shim.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is required.');
        }
    }

    public function testChildBecomesItsOwnSessionLeaderViaShim(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/bin/ps') && !\is_executable('/usr/bin/ps')) {
            $this->markTestSkipped('ps binary required to query session id.');
        }

        $pty = Pty::open();
        $tmp = \tempnam(\sys_get_temp_dir(), 'candy-pty-sid-');
        $this->assertNotFalse($tmp);

        try {
            // The shim does setsid() before exec — making the child its
            // own session leader. After exec, the shell's $$ (the pid)
            // and the kernel's reported sid for the same pid must match.
            $child = $pty->spawn(
                ['/bin/sh', '-c', "echo \$\$ > {$tmp}; ps -o sid= -p \$\$ >> {$tmp}"],
                null,
                80,
                24,
                controllingTerminal: true,
            );
            $exit = $child->wait();
            $this->assertSame(0, $exit, 'shim-wrapped sh -c pipeline must exit zero');

            $out = (string) \file_get_contents($tmp);
            $lines = \array_values(\array_filter(\array_map('trim', \explode("\n", $out))));
            $this->assertCount(2, $lines, "expected pid + sid on separate lines, got: " . \var_export($out, true));

            $pid = (int) $lines[0];
            $sid = (int) $lines[1];
            $this->assertGreaterThan(0, $pid);
            $this->assertSame($pid, $sid, "child should be its own session leader (pid={$pid} sid={$sid})");
        } finally {
            if (\file_exists($tmp)) {
                \unlink($tmp);
            }
            $pty->close();
        }
    }

    public function testChildWithoutShimSharesParentSession(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/bin/ps') && !\is_executable('/usr/bin/ps')) {
            $this->markTestSkipped('ps binary required.');
        }

        $pty = Pty::open();
        $tmp = \tempnam(\sys_get_temp_dir(), 'candy-pty-sid-');
        $this->assertNotFalse($tmp);

        try {
            // WITHOUT controllingTerminal:true, the child inherits the
            // PHPUnit / parent process's session — pid !== sid.
            $child = $pty->spawn(
                ['/bin/sh', '-c', "echo \$\$ > {$tmp}; ps -o sid= -p \$\$ >> {$tmp}"],
            );
            $child->wait();

            $out = (string) \file_get_contents($tmp);
            $lines = \array_values(\array_filter(\array_map('trim', \explode("\n", $out))));
            $this->assertCount(2, $lines);

            $pid = (int) $lines[0];
            $sid = (int) $lines[1];
            $this->assertGreaterThan(0, $pid);
            $this->assertNotSame(
                $pid,
                $sid,
                "child should inherit parent's session when controllingTerminal is off (pid={$pid} sid={$sid})",
            );
        } finally {
            if (\file_exists($tmp)) {
                \unlink($tmp);
            }
            $pty->close();
        }
    }

    public function testCtrlCDeliversSigintToControllingChild(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/bin/sleep') && !\is_executable('/usr/bin/sleep')) {
            $this->markTestSkipped('sleep binary required.');
        }

        $pty = Pty::open();
        try {
            $start = \microtime(true);
            // sleep 10 — should normally take 10 seconds. With the
            // shim claiming the slave as ctty, writing 0x03 to the
            // master makes the kernel deliver SIGINT to the child's
            // process group, which terminates `sleep` immediately.
            $child = $pty->spawn(
                ['/bin/sleep', '10'],
                null,
                80,
                24,
                controllingTerminal: true,
            );

            // A 0x03 only generates SIGINT once the shim has claimed the
            // slave as ctty (setsid + TIOCSCTTY), and under full-suite load
            // that can take a while — so retry the write instead of trusting
            // a single shot after a fixed sleep. The 8s deadline is safely
            // below sleep's natural 10s exit, so a natural exit still fails
            // the exited() assertion below.
            $deadline = $start + 8.0;
            while (!$child->exited() && \microtime(true) < $deadline) {
                $pty->write("\x03");
                \usleep(100_000);
            }
            $elapsed = \microtime(true) - $start;

            $this->assertTrue(
                $child->exited(),
                "Ctrl+C should kill `sleep 10` well before its natural exit (elapsed: {$elapsed}s)",
            );
            $child->wait();
            $this->assertLessThan(8.5, $elapsed, "sleep should be killed by Ctrl+C in well under 10s (elapsed: {$elapsed}s)");
        } finally {
            $pty->close();
        }
    }

    public function testCtrlCWithoutShimDoesNotKillChild(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            // Without controllingTerminal:true, the child has no ctty,
            // so writing Ctrl+C to the master goes nowhere — the child
            // runs to completion. We use a short sleep so the test
            // doesn't take forever to confirm.
            $start = \microtime(true);
            $child = $pty->spawn(['/bin/sleep', '0.5']);

            \usleep(50_000);
            $pty->write("\x03");

            $child->wait();
            $elapsed = \microtime(true) - $start;

            // sleep 0.5 ran to completion; Ctrl+C did NOT short-circuit.
            $this->assertGreaterThanOrEqual(0.4, $elapsed, "without TIOCSCTTY, sleep 0.5 must run to completion (elapsed: {$elapsed}s)");
        } finally {
            $pty->close();
        }
    }

    public function testControllingTerminalDefaultsOff(): void
    {
        // Spec assertion via reflection — default param value is false.
        $reflection = new \ReflectionClass(Pty::class);
        $method = $reflection->getMethod('spawn');
        $params = $method->getParameters();
        $ctParam = null;
        foreach ($params as $p) {
            if ($p->getName() === 'controllingTerminal') {
                $ctParam = $p;
                break;
            }
        }
        $this->assertNotNull($ctParam, 'spawn() must accept a controllingTerminal parameter');
        $this->assertTrue($ctParam->isDefaultValueAvailable());
        $this->assertFalse($ctParam->getDefaultValue(), 'controllingTerminal must default to false (opt-in)');
    }

    public function testShimExitsZeroForSimpleCommand(): void
    {
        $this->requirePtySyscalls();

        // /bin/true via the shim must exit cleanly. This catches shim
        // bugs early — if setsid / TIOCSCTTY / pcntl_exec misfires,
        // /bin/true would either not run or exit non-zero.
        $pty = Pty::open();
        try {
            $child = $pty->spawn(
                ['/bin/true'],
                null,
                80,
                24,
                controllingTerminal: true,
            );
            $this->assertSame(0, $child->wait());
        } finally {
            $pty->close();
        }
    }

    public function testShimPropagatesEnvironment(): void
    {
        $this->requirePtySyscalls();

        $pty = Pty::open();
        try {
            // pcntl_exec inherits env from the calling proc (the shim),
            // which inherits from proc_open. So a custom env var set
            // via spawn() must reach the actual cmd's view of $ENV.
            $child = $pty->spawn(
                ['/bin/sh', '-c', 'exit ${SHIM_TEST_CODE:-9}'],
                ['SHIM_TEST_CODE' => '42'],
                80,
                24,
                controllingTerminal: true,
            );
            $this->assertSame(42, $child->wait());
        } finally {
            $pty->close();
        }
    }
}
