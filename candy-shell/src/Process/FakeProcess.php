<?php

declare(strict_types=1);

namespace CandyCore\Shell\Process;

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

    public function exitCode(): ?int   { return $this->exitCode; }
    public function terminate(): void  { $this->terminated = true; }
    public function close(): int       { $this->closed = true; return $this->exitCode ?? 0; }

    /** Convenience: simulate the child finishing with the given code. */
    public function finish(int $code = 0): void
    {
        $this->exitCode = $code;
    }
}
