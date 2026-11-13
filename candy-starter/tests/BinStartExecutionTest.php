<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Execution-contract guard for the reference bin (candy-starter-014).
 *
 * BinStartExitTailTest pins the tail's SOURCE SHAPE; nothing in the suite
 * ever RAN bin/start end to end — the autoload-failure message was
 * unexercised prose, and the README's exit-code story ("the quit keypress
 * exits cooperatively with 0 … 130 belongs to a real SIGINT delivered by
 * the operating system") had no witness. This file runs the real binary:
 *
 *  - the autoload-absent probe copies the bin into a vendor-less scratch
 *    tree and asserts the guard's exit 1 + help text;
 *  - the pty arms drive `bin/start` under util-linux `script(1)`: a fed
 *    `q` — and a fed Ctrl+C byte, which in raw mode is a KeyMsg, not a
 *    signal — must exit cooperatively with 0;
 *  - the signal arm delivers a genuine external SIGINT and must exit 130
 *    through the stopSignal() tail.
 *
 * Linux-gated: the pty legs need util-linux script(1); elsewhere they
 * skip LOUDLY (the finding sanctions the gate with report) rather than
 * quietly green-lighting an unrun contract.
 */
final class BinStartExecutionTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/start';

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/starter-bin-' . getmypid() . '-' . spl_object_id($this);
        if (!is_dir($this->scratchDir) && !mkdir($this->scratchDir, 0700) && !is_dir($this->scratchDir)) {
            $this->fail('could not create scratch dir ' . $this->scratchDir);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir . '/' . chr(42)) ?: [] as $leftover) {
            is_file($leftover) ? @unlink($leftover) : @rmdir($leftover);
        }
        @rmdir($this->scratchDir);
    }

    public function testAnAppWithoutVendorFailsWithTheComposerInstallHint(): void
    {
        // The autoload guard is the first thing a fresh clone without
        // `composer install` ever sees — its contract is exit 1 plus the
        // hint, and it has never run here. Copy the bin into a tree whose
        // ../vendor does not exist so __DIR__-relative resolution misses.
        mkdir($this->scratchDir . '/bin');
        copy(self::BIN, $this->scratchDir . '/bin/start');

        $out = [];
        $status = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->scratchDir . '/bin/start')
            . ' 2>&1 </dev/null',
            $out,
            $status,
        );

        $message = implode("\n", $out);
        $this->assertSame(1, $status, 'the autoload-absent guard must exit 1, not fatal deeper: ' . $message);
        $this->assertStringContainsString('composer install', $message, 'the failure must tell the newcomer what to run: ' . $message);
    }

    public function testQuitKeyOnAPtyExitsCooperativelyWithZero(): void
    {
        $this->requireLinuxPtyTooling();

        // README contract half one: a fed `q` reaches update() as a KeyMsg
        // on the raw-mode pty, Cmd::quit() stops the loop, and the tail
        // keeps the cooperative 0.
        [$status, $output] = $this->runBinInPty('printf %s q');

        $this->assertSame(0, $status, 'cooperative quit must exit 0, saw ' . $status . ' output: ' . $output);
        $this->assertStringContainsString('count: 0', $output, 'the pty must have carried the rendered frame');
    }

    public function testCtrlCOnARawModePtyAlsoQuitsCooperativelyNotBySignal(): void
    {
        $this->requireLinuxPtyTooling();

        // README contract half two (the 022-adjacent story): in raw mode
        // ISIG is off, so the 0x03 byte arrives as a Char rune-c + ctrl
        // KeyMsg and the demo exits through Cmd::quit() with 0 — 130 is
        // reserved for a signal the OS really delivered (next test).
        [$status, $output] = $this->runBinInPty('printf "\003"');

        $this->assertSame(
            0,
            $status,
            'Ctrl+C through the raw-mode pty must quit cooperatively (0), not signal-style (130): '
                . $status . ' output: ' . $output,
        );
    }

    public function testExternalSigintExitsOneThirtyThroughTheStopSignalTail(): void
    {
        $this->requireLinuxPtyTooling();

        // README contract half three: kill -INT from the outside is the
        // OS-delivered path the tail exists for — 128+2 = 130, so shell
        // supervisors read this app like any signal-terminated child.
        // Delete the null-guarded 128+N leg of bin/start's tail (or the
        // whole tail) and this reddens at exit 0: exactly the shape the
        // template shipped before the tail landed.
        $pidFile = $this->scratchDir . '/child.pid';
        $outFile = $this->scratchDir . '/out.txt';
        $fifo    = $this->scratchDir . '/fifo';

        // script(1) holds the pty; the child announces its real pid (the
        // exec'd php, not the wrapper) so the killer loop can deliver a
        // genuine external SIGINT once the app is up.
        $child = 'echo $$ > ' . escapeshellarg($pidFile)
            . '; exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::BIN);

        $runner = 'bash -c ' . escapeshellarg(
            'mkfifo ' . escapeshellarg($fifo)
            . '; sleep 30 > ' . escapeshellarg($fifo) . ' & FEEDER=$!'
            . '; timeout 20 script -qec ' . escapeshellarg($child) . ' /dev/null'
            . ' < ' . escapeshellarg($fifo) . ' > ' . escapeshellarg($outFile) . ' 2>&1; RC=$?'
            . '; kill $FEEDER 2>/dev/null; exit $RC'
        );

        // Wait for BOTH the announced pid and the first rendered frame —
        // same readiness law as runBinInPty: a SIGINT before the loop arms
        // is the boot-window race, not the contract under test.
        $killer = '( n=0; while [ $n -lt 100 ] && { [ ! -s ' . escapeshellarg($pidFile) . '] || ! grep -q "count:" ' . escapeshellarg($outFile) . ' 2>/dev/null; }; do n=$((n+1)); sleep 0.1; done; '
            . 'sleep 0.5; '
            . 'kill -INT $(cat ' . escapeshellarg($pidFile) . ') 2>/dev/null ) &';

        $lines  = [];
        $status = 0;
        exec('{ ' . $killer . ' ' . $runner . '; echo "STATUS=$?"; wait; } 2>&1', $lines, $status);
        $output = implode("\n", $lines);
        $this->assertSame(0, $status, 'the composite runner shell itself must not die: ' . $output);
        $this->assertSame(
            1,
            preg_match('/STATUS=(\d+)/', $output, $tail),
            'the runner must report an exit status: ' . $output,
        );

        $this->assertSame(130, (int) $tail[1], 'an external SIGINT must exit 128+2 through the tail — ' . $output);
        $this->assertStringContainsString('count: 0', (string) @file_get_contents($this->scratchDir . '/out.txt'));
    }

    /**
     * Drive bin/start under script(1) in a pty. The feeder subshell does
     * NOT blind-sleep: it waits until the captured output carries the
     * first rendered frame (count:), then a half-second grace, then sends
     * $printfBody — raw mode and signal handlers are installed by then.
     * Escape discipline: the ctrl byte rides in printf's QUOTED format
     * (printf "\003"); an unquoted printf \003 lets the shell eat the
     * backslash and feeds the app literal "003" — a trap that cost this
     * file a false signal-loss investigation on 2026-11-13 before the
     * diagnosis lands here in its comment.
     *
     * @return array{0:int, 1:string}
     */
    private function runBinInPty(string $printfBody): array
    {
        $outFile = $this->scratchDir . '/pty-out.txt';
        $out     = [];
        $status  = 0;
        $command = '( n=0; while [ $n -lt 100 ] && ! grep -q "count:" ' . escapeshellarg($outFile) . ' 2>/dev/null; do n=$((n+1)); sleep 0.1; done; sleep 0.5; ' . $printfBody . ' )'
            . ' | timeout 20 script -qec ' . escapeshellarg(PHP_BINARY . ' ' . self::BIN)
            . ' /dev/null > ' . escapeshellarg($outFile) . ' 2>&1';
        exec($command, $out, $status);

        return [$status, (string) @file_get_contents($outFile)];
    }

    private function requireLinuxPtyTooling(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped(
                'pty execution contract is Linux-gated (util-linux script(1)); '
                . 'this platform skips it loudly — the bin still ships, the witness does not run (candy-starter-014 gate, disclosed 2026-11-13)',
            );
        }

        $probe = [];
        $probeStatus = 0;
        exec('command -v script >/dev/null 2>&1 && script --version 2>&1 | head -1', $probe, $probeStatus);
        if ($probeStatus !== 0 || !str_contains(implode(' ', $probe), 'util-linux')) {
            $this->markTestSkipped('util-linux script(1) is not available on this Linux box — pty legs gated off (disclosed, not silently green): ' . implode(' ', $probe));
        }
    }
}
