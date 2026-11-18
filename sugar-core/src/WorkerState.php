<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use React\EventLoop\TimerInterface;

/**
 * @internal
 *
 * Pool-side bookkeeping for one subprocess worker (sc-29 contract doc):
 * the child speaks the two-stage envelope protocol written at
 * {@see WorkerPool::sendToWorker()} and in WorkerPool's script heredoc;
 * `buffer` holds a partial response line between polls, `idle` +
 * `currentJobId` are the pool's sole scheduling truth, and `scriptPath`
 * names the temp bootstrap the child runs (unlinked exactly once on
 * close). Streams are `resource|nullable` because failed-spawn synthetic
 * states exist by design (see the closeWorker guard).
 */
final class WorkerState
{
    public bool $idle = true;

    public ?string $currentJobId = null;

    public string $buffer = '';

    /** Offset of the first unread byte in $buffer (line cursor, P1-1). */
    public int $bufferOffset = 0;

    /** Queued writer for this worker's stdin; created on first dispatch. */
    public ?Writer $writer = null;

    /** Fires when a payload has sat queued past the write deadline. */
    public ?TimerInterface $writeDeadline = null;

    /**
     * sc-30: bounded keep-last capture of this worker's stderr (the pool
     * appends at most until WorkerPool::STDERR_TAIL_BYTES and slides the
     * window), surfaced by handleWorkerDeath so a child-side fatal is no
     * longer invisible to the caller holding the rejected promise.
     */
    public string $stderrTail = '';

    /** Path to the worker's temp script, for cleanup on close. */
    public ?string $scriptPath = null;

    public function __construct(
        public readonly int $id,
        public $process,
        public $stdin,
        public $stdout,
        public $stderr,
    ) {
    }
}
