<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Tests\Support\SpawnBounded;

/**
 * The deadline harness proves its own teeth (candy-kv-032 acceptance).
 *
 * A bound that never fires is decoration: these legs show a stalled child is
 * terminated inside the deadline window (the suite FAILS fast instead of
 * hanging the shard — the named law: "a test built on unbounded read loops
 * can only hang, never fail"), while healthy children keep their exit codes
 * and bytes exactly as the pre-helper runners reported them.
 */
final class SpawnBoundedTest extends TestCase
{
    use SpawnBounded;

    public function testAStalledChildIsTerminatedInsideItsDeadline(): void
    {
        $started = \microtime(true);
        $run = self::spawnBounded([PHP_BINARY, '-r', 'sleep(30);'], null, null, 0.4);
        $elapsed = \microtime(true) - $started;

        $this->assertTrue($run['timedOut'], 'a child that outstays its deadline must be reported as timed out');
        $this->assertNotSame(0, $run['exit'], 'a killed child must never answer with a success code');
        $this->assertLessThan(10.0, $elapsed, 'the SIGTERM→SIGKILL ladder must bound the kill well inside any CI timeout');
    }

    public function testAHealthyChildKeepsItsExitCodeAndBothStreams(): void
    {
        $run = self::spawnBounded(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err");'],
        );

        $this->assertFalse($run['timedOut']);
        $this->assertSame(0, $run['exit']);
        $this->assertSame('out', $run['stdout']);
        $this->assertSame('err', $run['stderr']);
    }

    public function testStdinBytesReachTheChildAndExitCodesOfFailureSurvive(): void
    {
        $run = self::spawnBounded(
            [PHP_BINARY, '-r', 'stream_copy_to_stream(STDIN, STDOUT); exit(3);'],
            null,
            "fed\n",
        );

        $this->assertSame('fed', \rtrim($run['stdout']));
        $this->assertSame(3, $run['exit'], 'an explicit child failure must not be laundered by the deadline path');
    }

    public function testAForkingChildIsStillBoundedAndItsBytesSurvive(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('pcntl fork is not modelled on Windows runners.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl required to model the grandchild leg.');
        }

        // The reap law with teeth (the audit-#2 shape: a child that outsits
        // its own usefulness). A grandchild writes to the inherited stdout
        // pipe and exits; the traced parent then stalls past its deadline.
        // The harness must still return bounded, report the timeout, carry
        // the descendant's bytes, and never answer success for a killed tree.
        $code = 'if (pcntl_fork() === 0) { fwrite(STDOUT, "grandchild\n"); exit(0); } sleep(30);';

        $started = \microtime(true);
        $run = self::spawnBounded([PHP_BINARY, '-r', $code], null, null, 0.4);
        $elapsed = \microtime(true) - $started;

        $this->assertTrue($run['timedOut']);
        $this->assertNotSame(0, $run['exit']);
        $this->assertStringContainsString('grandchild', $run['stdout'], 'bytes written through the inherited pipe before the kill must be drained, not dropped');
        $this->assertLessThan(15.0, $elapsed);
    }
}
