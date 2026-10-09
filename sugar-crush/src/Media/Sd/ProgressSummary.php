<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

/**
 * What a ProgressLoop run did — counts first, because the loop's contract is
 * cadence (one heartbeat per poll), and this is the receipt for it.
 */
final class ProgressSummary
{
    public const TERMINATION_DONE = 'done';

    public const TERMINATION_SERVER_FINISHED = 'server-finished';

    private function __construct(
        public readonly int $polls,
        public readonly int $heartbeats,
        public readonly int $previews,
        public readonly ?ProgressState $lastState,
        public readonly string $termination,
    ) {
    }

    public static function new(
        int $polls,
        int $heartbeats,
        int $previews,
        ?ProgressState $lastState,
        string $termination,
    ): self {
        return new self($polls, $heartbeats, $previews, $lastState, $termination);
    }

    /** The cadence law made checkable: every poll beat exactly once. */
    public function beatPerPoll(): bool
    {
        return $this->heartbeats === $this->polls;
    }
}
