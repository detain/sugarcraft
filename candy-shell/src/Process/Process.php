<?php

declare(strict_types=1);

namespace CandyCore\Shell\Process;

/**
 * Abstraction over a child process so {@see \CandyCore\Shell\Model\SpinModel}
 * can be tested without spawning a real OS process.
 *
 * Implementations:
 *
 * - {@see RealProcess}  — wraps `proc_open` / `proc_get_status`.
 * - {@see FakeProcess}  — fully in-memory; tests flip `$exitCode` to
 *   simulate the child finishing.
 */
interface Process
{
    /** Null while still running; otherwise the child's exit code. */
    public function exitCode(): ?int;

    /** Send SIGTERM (or platform equivalent) to the child. */
    public function terminate(): void;

    /** Close the process handle and return the final exit code. */
    public function close(): int;

    /**
     * Captured stdout when the process was spawned with `captureStdout`;
     * empty otherwise. Available after {@see exitCode()} resolves.
     */
    public function stdout(): string;

    /** Captured stderr; same semantics as {@see stdout()}. */
    public function stderr(): string;
}
