<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Process;

/**
 * Test double for {@see Process}. Holds a mutable `$exitCode` (null while
 * running) plus flags recording whether {@see terminate()} or
 * {@see close()} have been called.
 */
final class FakeProcess implements Process
{
    public ?int $exitCode = null;
    public bool $terminated = false;
    public bool $closed = false;
    public string $bufferedStdout = '';
    public string $bufferedStderr = '';

    /** Last signal passed to {@see kill()} — lets pins assert WHICH signal was sent, not just that terminate() ran. */
    public ?int $lastSignal = null;

    /** Spawn arguments recorded by {@see recordSpawn()} — pins the command line SpinCommand actually built. */
    public array $spawnArgv = [];
    public bool $spawnCaptureStdout = false;
    public bool $spawnCaptureStderr = false;

    /**
     * Test harnesses register this from their processFactory so a command
     * building the WRONG argv (or dropping the capture flags) goes red —
     * the factory previously discarded its arguments, which made every
     * spawn-shape assertion vacuous.
     */
    public function recordSpawn(array $argv, bool $captureStdout, bool $captureStderr): void
    {
        $this->spawnArgv = $argv;
        $this->spawnCaptureStdout = $captureStdout;
        $this->spawnCaptureStderr = $captureStderr;
    }

    /**
     * Record whether the captured streams were consumed. {@see \SugarCraft\ScriptUi\Command\SpinCommand}
     * only reads stdout()/stderr() inside its `--show-output`/`--show-error`
     * branches, so these flags let tests assert those branches ran even though
     * stderr is written to the real STDERR fd and can't be captured via
     * CommandTester's display buffer.
     */
    public bool $stdoutRead = false;
    public bool $stderrRead = false;

    /** Count of {@see drain()} calls — lets pins assert the run-loop pump actually ran. */
    public int $drainCount = 0;

    /** Pump bookkeeping (scriptui-02): records that SpinCommand wired the loop drain. */
    public bool $pumpAttached = false;
    public bool $pumpDetached = false;
    public ?\React\EventLoop\LoopInterface $pumpLoop = null;

    public function drain(): void       { $this->drainCount++; }

    public function attachPump(\React\EventLoop\LoopInterface $loop): void
    {
        $this->pumpLoop     = $loop;
        $this->pumpAttached = true;
    }

    public function detachPump(): void  { $this->pumpDetached = true; }

    public function pid(): int          { return 12345; }
    public function exited(): bool      { return $this->exitCode !== null; }
    public function wait(): int         { $this->closed = true; return $this->exitCode ?? 0; }
    public function kill(int $signal): void
    {
        $this->lastSignal = $signal;
        $this->terminated = true;
    }
    public function exitCode(): ?int    { return $this->exitCode; }
    /** Mirrors RealProcess: terminate() == SIGTERM to the child, recorded. */
    public function terminate(): void  { $this->kill(\defined('SIGTERM') ? \SIGTERM : 15); }
    public function close(): int      { $this->closed = true; return $this->exitCode ?? 0; }
    public function stdout(): string    { $this->stdoutRead = true; return $this->bufferedStdout; }
    public function stderr(): string    { $this->stderrRead = true; return $this->bufferedStderr; }
    public function stdoutBytes(): string { return $this->bufferedStdout; }
    public function stderrBytes(): string { return $this->bufferedStderr; }
    public int $droppedStdout = 0;
    public int $droppedStderr = 0;
    public function stdoutDropped(): int  { return $this->droppedStdout; }
    public function stderrDropped(): int  { return $this->droppedStderr; }

    /** Convenience: simulate the child finishing with the given code. */
    public function finish(int $code = 0): void
    {
        $this->exitCode = $code;
    }
}
