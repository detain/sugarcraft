<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg carrying the outcome of one auto-memory consolidation run
 * (roadmap 5.2): the background call
 * {@see \SugarCraft\Crush\Memory\AutoMemoryConsolidator::call()} builds and
 * {@see Chat}'s settled-turn arm schedules on the tool-less summary backend.
 *
 * The notes were already written by the time this arrives — in the parent
 * process, as the call's promise settled, never in the forked request child
 * (a child's write would race the parent's `/memory` commands). What is left
 * for {@see Chat::update()} is the bookkeeping: account $usage whatever the
 * outcome, the rule every provider call on the user's key follows, and tell
 * the user in one transcript notice when a note was saved, changed or
 * removed. A run that saved nothing says nothing.
 */
final class MemoryConsolidatedMsg implements Msg
{
    /**
     * @param list<string>       $saved   ids of the notes added
     * @param list<string>       $updated ids of the notes rewritten
     * @param list<string>       $deleted ids of the notes removed
     * @param array<string, int> $skipped skip reason => how many operations it covered
     * @param ?Usage             $usage   what the call cost, or null when unreported
     * @param ?string            $sessionId the session the run consolidated
     * @param ?string            $error   why the call failed, or null when it answered
     */
    public function __construct(
        public readonly array $saved = [],
        public readonly array $updated = [],
        public readonly array $deleted = [],
        public readonly array $skipped = [],
        public readonly ?Usage $usage = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $error = null,
    ) {}

    /** Whether the run changed any note. */
    public function changed(): bool
    {
        return $this->saved !== [] || $this->updated !== [] || $this->deleted !== [];
    }
}
