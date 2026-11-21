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
     * The cancellation token fired before the child finished; the child was killed
     * through the same kill ladder as a deadline, and reaped.
     */
    public const CANCELLED = 'cancelled';

    /**
     * @param null|int $exitCode  The exit status, or null when it could not be reaped
     * @param int $droppedBytes  Bytes refused by the per-stream cap, both streams together
     * @param int $droppedStderrBytes  The part of $droppedBytes that came from stderr, so a caller
     *                                 that discards stderr can report stdout's drops alone
     * @param bool $abandonedPipes  Output pipes were abandoned after the child exited: a descendant
     *                              still held them, and they were cut once silent for the exit grace
     * @param null|int $signal  The number of the signal that killed the child, when the status poll
     *                          that reaped it saw one. $exitCode is -1 for that death; this is the
     *                          number the -1 stands for, and null when no signal was observed
     */
    public function __construct(
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly ?int $exitCode,
        public readonly string $status,
        public readonly int $droppedBytes,
        public readonly int $droppedStderrBytes = 0,
        public readonly bool $abandonedPipes = false,
        public readonly ?int $signal = null,
    ) {
    }

    /**
     * True exactly when a pipe was abandoned after the child exited, before it
     * reached EOF. A pipe closed by the child's exit alone, or cut by a kill on the
     * deadline, idle budget or cancel, is not abandoned. The Windows poll arm never
     * reports an abandonment (see {@see ChildRun}).
     */
    public function abandonedPipes(): bool
    {
        return $this->abandonedPipes;
    }

    public function timedOut(): bool
    {
        return $this->status === self::TIMED_OUT;
    }

    public function cancelled(): bool
    {
        return $this->status === self::CANCELLED;
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
