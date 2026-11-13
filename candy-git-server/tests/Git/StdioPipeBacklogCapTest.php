<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Git;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Git\StdioPipe;

/**
 * n11: the client→child stdin backlog must be a bounded pile, not an
 * unbounded one.
 *
 * StdioPipe pumps a client stream into a short-lived git child over a
 * non-blocking stdin pipe. Before the cap, bytes read from the client were
 * appended to the pending buffer with no ceiling whenever the child refused
 * more input, so a stalled (or hostile) child on the authenticated push
 * path let the pile grow to memory exhaustion, restrained only by the
 * 300-second wall clock. These pins exercise both breach points — the
 * seed handed in already past the cap, and traffic appended during the
 * pump — plus a healthy-flow control proving the bound never fires under
 * normal consumption.
 *
 * @covers \SugarCraft\GitServer\Git\StdioPipe
 */
final class StdioPipeBacklogCapTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-stdio-backlog-' . \uniqid('', true);
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->tmpDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @\unlink($this->tmpDir . '/' . $entry);
            }
        }
        @\rmdir($this->tmpDir);
    }

    /**
     * Seed-site breach: a seed already past the cap must be refused before
     * a byte is pushed at the child. The child here never reads stdin at
     * all, so nothing else could stop the pile but the cap itself.
     */
    public function testSeedBeyondTheCapRefusesBeforePumping(): void
    {
        $in = $this->readPointer('in.txt');
        $out = $this->filePointer('out.txt', 'wb');

        $code = StdioPipe::run(
            $in,
            $out,
            ['php', '-r', 'usleep(600000000);'],
            \sys_get_temp_dir(),
            \str_repeat('y', StdioPipe::PENDING_TO_CHILD_MAX_BYTES + 1),
        );

        $this->assertSame(1, $code, 'an over-cap seed fails the stream at once');
        $this->assertSame(0, \filesize($this->tmpDir . '/out.txt'), 'the child was never fed');
        \fclose($in);
        \fclose($out);
    }

    /**
     * Append-site breach: the client stream carries more bytes than the cap
     * and the child consumes none. The pump must fail the stream (code 1)
     * with a bounded pile instead of buffering the whole file until the
     * wall-clock stop. A regular file is always select-ready, so the read
     * side needs no socket choreography.
     */
    public function testUnconsumedClientBacklogIsCappedAndFailsTheStream(): void
    {
        // Margin: the child's stdin pipe itself absorbs roughly one pipe
        // buffer of the pile before stalling, so a cap+1 blob can leave the
        // backlog one byte short of the breach under real timing. Four extra
        // chunks make the overrun arithmetic, not race-conditions.
        $blob = \str_repeat('x', StdioPipe::PENDING_TO_CHILD_MAX_BYTES + 4 * StdioPipe::CHUNK_BYTES);
        $in = $this->readPointer('blob.txt', $blob);
        $out = $this->filePointer('out2.txt', 'wb');

        $code = StdioPipe::run(
            $in,
            $out,
            ['php', '-r', 'usleep(600000000);'],
            \sys_get_temp_dir(),
        );

        $this->assertSame(1, $code, 'the stalled pile is capped, the stream failed');
        \fclose($in);
        \fclose($out);
    }

    /**
     * Negative control: a healthy child that reads its seed and echoes
     * lands nowhere near the cap and the pump completes normally — the
     * bound must not bite on the good path.
     */
    public function testHealthyFlowUnderTheCapIsUnaffected(): void
    {
        $in = $this->readPointer('in3.txt');
        $out = $this->filePointer('out3.txt', 'wb');

        $code = StdioPipe::run(
            $in,
            $out,
            ['php', '-r', '$s = fread(STDIN, 4); fwrite(STDOUT, $s);'],
            \sys_get_temp_dir(),
            'ping',
        );

        $this->assertSame(0, $code, 'a consuming child finishes clean');
        $this->assertSame('ping', \file_get_contents($this->tmpDir . '/out3.txt'));
        \fclose($in);
        \fclose($out);
    }

    /**
     * S02: the pump feeds one CHUNK_BYTES per fwrite in BOTH directions. A
     * seed many chunks wide must round-trip through the child verbatim —
     * slice boundaries may never truncate, duplicate, or reorder bytes.
     */
    public function testMultiChunkSeedRoundTripsWithByteFidelity(): void
    {
        $blob = \random_bytes(3 * StdioPipe::CHUNK_BYTES + 999);
        $in = $this->readPointer('blob-in.txt', $blob);
        $out = $this->filePointer('blob-out.txt', 'wb');

        $code = StdioPipe::run(
            $in,
            $out,
            ['php', '-r', 'while (!feof(STDIN)) { $c = fread(STDIN, 65536); if (!$c) break; fwrite(STDOUT, $c); }'],
            \sys_get_temp_dir(),
        );

        \fclose($out);
        \fclose($in);

        $this->assertSame(0, $code, 'a consuming child finishes clean');
        $this->assertSame($blob, (string) \file_get_contents($this->tmpDir . '/blob-out.txt'));
    }

    /**
     * S04 (finding 004): the REVERSE direction gets the cap the stdin
     * side already had. A client that never reads its socket must not be
     * able to make the pump buffer a child's entire (potentially
     * gigabyte) answer in parent memory: at the cap the fread pauses, the
     * child blocks on its stdout pipe, and the byte-less idle window ends
     * the session through the reap ladder. 64 MiB of /dev/zero through an
     * 16 MiB cap into an unread socketpair — the pre-fix shape buffered
     * the whole 64 MiB; the capped shape must refuse to grow and must
     * FAIL the session (killed child, non-zero exit) well inside the
     * ceiling. The healthy path (client reading, pile under cap) is the
     * existing multi-chunk round-trip pin above.
     */
    public function testUnreadChildAnswerIsCappedAndEndsOnTheIdleLadder(): void
    {
        $in = $this->readPointer('quiet-in.txt');          // EOF input immediately
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        // $out is WRITTEN by the pump but never READ: the client side that
        // stopped consuming. ~200 KiB of socket buffer is all that drains.
        [$muteNear, $clientEnd] = $pair;

        $started = \microtime(true);
        $code = StdioPipe::run(
            $in,
            $clientEnd,
            ['head', '-c', (string) (64 * 1048576), '/dev/zero'],
            \sys_get_temp_dir(),
            '',
            1.0, // squeeze the idle give-up so the pin measures teardown, not the 10 s window
        );
        $elapsed = \microtime(true) - $started;

        $this->assertNotSame(0, $code, 'a stalled reader ends the session on the ladder, not a clean zero');
        $this->assertLessThan(8.0, $elapsed, 'cap + idle ladder must end the stall within the census ceiling');

        \fclose($in);
        \fclose($muteNear);
        \fclose($clientEnd);
    }

    /**
     * S04 structural: the child→client read must be GATED at the backlog
     * cap in source — the ordering (gate before the fread on pipes[1]) is
     * the whole fix, and a future reshuffle back to drain-at-any-cost
     * must go red here, not in a 64 MiB buffer under production load.
     */
    public function testTheChildOutboundReadIsGatedAtTheCapInSource(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Git/StdioPipe.php');

        $gate = \strpos($src, 'strlen($pendingOut) <= self::PENDING_TO_CHILD_MAX_BYTES');
        $this->assertIsInt($gate, 'the child->client backlog gate must exist');

        $readBranch = \strpos($src, 'if (!$childOutDone && \\in_array($pipes[1]');
        $firstOutFread = \strpos($src, 'fread($pipes[1]');
        $this->assertIsInt($readBranch);
        $this->assertIsInt($firstOutFread);
        $this->assertLessThan($firstOutFread, $gate, 'the cap must be consulted BEFORE the stdout is drained');
    }

    /** @return resource a readable pointer over a freshly written file */
    private function readPointer(string $name, string $contents = '')
    {
        \file_put_contents($this->tmpDir . '/' . $name, $contents);

        return $this->filePointer($name, 'rb');
    }

    /**
     * S15 (finding 023): a live, wedged child that never writes stderr used
     * to be starved behind the pre-reap stderr drain — 64 guarded ticks of
     * usleep before the TERM that could actually end it. The reap ladder
     * now runs FIRST, so escalation to KILL is bounded by the ladder alone,
     * not by a full drain window stacked on top.
     */
    public function testWedgedLiveChildIsReapedByTheLadderNotBehindADrainWindow(): void
    {
        $in = $this->readPointer('in.txt');
        $out = $this->filePointer('out.txt', 'wb');

        $started = \microtime(true);
        // A TERM-ignoring child: forces TERM -> KILL, exercising the ladder.
        // (The SIG_IGN disposition survives exec, so `exec sleep` keeps it.)
        // The pump's own idle budget is squeezed to 0.3 s so the pin
        // measures the TEARDOWN window, not the legitimate long-run window.
        $code = StdioPipe::run(
            $in,
            $out,
            ['/bin/sh', '-c', 'trap "" TERM; exec sleep 30'],
            \sys_get_temp_dir(),
            '',
            0.3,
        );
        $elapsed = \microtime(true) - $started;

        $this->assertNotSame(0, $code, 'a killed child is a failed stream');
        // teardown: TERM grace (1s) + a KILL tick. The old drain-before-reap
        // ordering stacked a 64-tick (6.4 s) stderr stall in front of the
        // first signal — the breach this step cured.
        $this->assertLessThan(3.5, $elapsed, 'the ladder must escalate immediately, not after a drain stall');

        \fclose($in);
        \fclose($out);
    }

    /**
     * S15 structural: the reap ladder (proc_terminate) must appear before
     * the honest-failure stderr drain in run()'s finally — the ORDER is the
     * fix, so pin it against a future reshuffle that silently reverts to
     * drain-first.
     */
    public function testTheLadderPrecedesTheStderrDrainInSource(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Git/StdioPipe.php');
        $termPos = \strpos($src, 'self::childRunning($proc, $observedCode)');
        $drainPos = \strpos($src, 'if (isset($pipes[2])');

        $this->assertIsInt($termPos, 'the reap ladder guard must exist');
        $this->assertIsInt($drainPos, 'the stderr drain must exist');
        $this->assertLessThan($drainPos, $termPos, 'ladder must precede the drain (S15 finding 023)');
    }


    /** @return resource */
    private function filePointer(string $name, string $mode)
    {
        $handle = \fopen($this->tmpDir . '/' . $name, $mode);
        $this->assertIsResource($handle, "fixture pointer {$name} must open");

        return $handle;
    }
}
