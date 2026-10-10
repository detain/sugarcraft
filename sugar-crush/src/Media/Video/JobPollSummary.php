<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Video;

/**
 * Bookkeeping of one finished {@see JobPollLoop::run()} pass.
 *
 * Mirrors {@see \SugarCraft\Crush\Media\Sd\ProgressSummary} on purpose —
 * same counters, same termination vocabulary, minus the preview axis (video
 * jobs have no preview-frame channel; the ladder of frames is the progress
 * ring on MediaJob instead).
 */
final readonly class JobPollSummary
{
    /** The loop ended because the caller cancelled between polls. */
    public const TERMINATION_DONE = 'done';

    /** The server itself reported a terminal job state (done/failed/cancelled). */
    public const TERMINATION_SERVER_FINISHED = 'server-finished';

    public function __construct(
        public int $polls,
        public int $heartbeats,
        public ?JobSnapshot $last,
        public string $termination,
    ) {
    }

    /** The heartbeat law: one beat per status poll (mirrors ProgressSummary's strict equality). */
    public function beatPerPoll(): bool
    {
        return $this->heartbeats === $this->polls;
    }
}
