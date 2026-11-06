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
        $blob = \str_repeat('x', StdioPipe::PENDING_TO_CHILD_MAX_BYTES + StdioPipe::CHUNK_BYTES);
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
        $in = $this->filePointer('in3.txt', 'wb');
        $out = $this->filePointer('out3.txt', 'rb');

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

    /** @return resource a readable pointer over a freshly written file */
    private function readPointer(string $name, string $contents = '')
    {
        \file_put_contents($this->tmpDir . '/' . $name, $contents);

        return $this->filePointer($name, 'rb');
    }

    /** @return resource */
    private function filePointer(string $name, string $mode)
    {
        $handle = \fopen($this->tmpDir . '/' . $name, $mode);
        $this->assertIsResource($handle, "fixture pointer {$name} must open");

        return $handle;
    }
}
