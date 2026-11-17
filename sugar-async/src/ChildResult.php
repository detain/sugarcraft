<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * What {@see ChildRun} read from a child and how the child ended.
 */
final class ChildResult
{
    /** The child exited on its own, with its output read to EOF. */
    public const EXITED = 'exited';

    /** The deadline passed first, or the child kept running after closing its output. The child was killed. */
    public const TIMED_OUT = 'timed-out';

    /** The pipes failed (too many consecutive select failures); the child was killed. */
    public const PIPE_FAILED = 'pipe-failed';

    /**
     * @param null|int $exitCode  The exit status, or null when it could not be reaped
     * @param int $droppedBytes  Bytes refused by the per-stream cap, both streams together
     */
    public function __construct(
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly ?int $exitCode,
        public readonly string $status,
        public readonly int $droppedBytes,
    ) {
    }

    public function timedOut(): bool
    {
        return $this->status === self::TIMED_OUT;
    }

    public function truncated(): bool
    {
        return $this->droppedBytes > 0;
    }

    /** Exited on its own with status 0 — a timeout or a pipe failure is never success. */
    public function succeeded(): bool
    {
        return $this->status === self::EXITED && $this->exitCode === 0;
    }
}
