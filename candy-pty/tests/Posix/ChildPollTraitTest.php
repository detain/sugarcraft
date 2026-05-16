<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\ChildPollTrait;

/**
 * Bare stub exercising {@see ChildPollTrait} without the PTY syscall surface.
 *
 * The trait reads `$this->process` and `$this->pid`, both wired up by this
 * stub's constructor — the same shape {@see \SugarCraft\Pty\Child} uses.
 */
final class ChildPollTraitStub
{
    use ChildPollTrait;

    /**
     * @param resource $process
     */
    public function __construct(
        public readonly int $pid,
        $process,
    ) {
        if (!\is_resource($process)) {
            throw new \InvalidArgumentException('stub requires live proc_open() resource');
        }
        $this->process = $process;
    }

    public function __destruct()
    {
        $this->pollDestruct();
    }

    /**
     * Test-only escape hatch — true while the underlying resource is alive.
     */
    public function hasLiveProcess(): bool
    {
        return \is_resource($this->process);
    }
}

final class ChildPollTraitTest extends TestCase
{
    private function requireSh(): void
    {
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is required to exercise the trait.');
        }
    }

    /**
     * @return array{0: resource, 1: int} [proc, pid]
     */
    private function spawn(string $script): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = \proc_open(['/bin/sh', '-c', $script], $descriptors, $pipes);
        if (!\is_resource($proc)) {
            $this->fail('proc_open failed');
        }
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                \fclose($pipe);
            }
        }
        $status = \proc_get_status($proc);
        return [$proc, (int) $status['pid']];
    }

    public function testPidReturnsConstructorValue(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('exit 0');
        $stub = new ChildPollTraitStub($pid, $proc);

        $this->assertSame($pid, $stub->pid());
        $stub->wait();
    }

    public function testExitCodeNullBeforeWaitThenCaptured(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('exit 7');
        $stub = new ChildPollTraitStub($pid, $proc);

        $this->assertNull($stub->exitCode());
        $this->assertSame(7, $stub->wait());
        $this->assertSame(7, $stub->exitCode());
    }

    public function testExitedFalseWhileRunningTrueAfterExit(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('sleep 0.2; exit 0');
        $stub = new ChildPollTraitStub($pid, $proc);

        $this->assertFalse($stub->exited());
        $stub->wait();
        $this->assertTrue($stub->exited());
    }

    public function testWaitReturnsCorrectExitCode(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('exit 0');
        $stub = new ChildPollTraitStub($pid, $proc);

        $this->assertSame(0, $stub->wait());
    }

    public function testWaitIsIdempotent(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('exit 13');
        $stub = new ChildPollTraitStub($pid, $proc);

        $first  = $stub->wait();
        $second = $stub->wait();
        $third  = $stub->wait();

        $this->assertSame(13, $first);
        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
        $this->assertFalse($stub->hasLiveProcess(), 'wait() should null the process after first call');
    }

    public function testPollDestructIsNoopWhenProcessAlreadyClosed(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('exit 0');
        $stub = new ChildPollTraitStub($pid, $proc);

        $stub->wait(); // closes the proc
        $this->assertFalse($stub->hasLiveProcess());

        // Destructor here would call pollDestruct on a null process — no throw.
        unset($stub);
        $this->addToAssertionCount(1);
    }

    public function testPollDestructReapsExitedButUnclosedProcess(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('exit 0');
        $stub = new ChildPollTraitStub($pid, $proc);

        // Give the child time to exit naturally without calling wait().
        $deadline = \microtime(true) + 2.0;
        while (\microtime(true) < $deadline) {
            $status = \proc_get_status($proc);
            if ($status['running'] === false) {
                break;
            }
            \usleep(10_000);
        }

        $this->assertTrue($stub->hasLiveProcess(), 'process should still be a live resource pre-destruct');

        // Trigger the destructor path explicitly via unset.
        unset($stub);
        $this->addToAssertionCount(1);
    }

    public function testExitedClosesProcessLazilyWhenNaturallyExited(): void
    {
        $this->requireSh();

        [$proc, $pid] = $this->spawn('exit 4');
        $stub = new ChildPollTraitStub($pid, $proc);

        // Wait for natural exit without calling wait().
        $deadline = \microtime(true) + 2.0;
        while (\microtime(true) < $deadline) {
            $s = \proc_get_status($proc);
            if ($s['running'] === false) {
                break;
            }
            \usleep(10_000);
        }

        $this->assertTrue($stub->exited());
        $this->assertSame(4, $stub->exitCode());
    }
}
