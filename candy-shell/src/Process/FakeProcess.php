<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Process;

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

    public function pid(): int          { return 12345; }
    public function exited(): bool      { return $this->exitCode !== null; }
    public function wait(): int         { $this->closed = true; return $this->exitCode ?? 0; }
    public function kill(int $signal): void { $this->terminated = true; }
    public function exitCode(): ?int    { return $this->exitCode; }
    public function terminate(): void  { $this->terminated = true; }
    public function close(): int      { $this->closed = true; return $this->exitCode ?? 0; }
    public function stdout(): string    { return $this->bufferedStdout; }
    public function stderr(): string    { return $this->bufferedStderr; }
    public function stdoutBytes(): string { return $this->bufferedStdout; }
    public function stderrBytes(): string { return $this->bufferedStderr; }

    /** Convenience: simulate the child finishing with the given code. */
    public function finish(int $code = 0): void
    {
        $this->exitCode = $code;
    }
}
