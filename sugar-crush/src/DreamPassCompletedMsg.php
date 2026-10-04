<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * Internal Msg carrying the outcome of one dream pass (roadmap 5.4-3): the
 * restricted-tool engine turn {@see \SugarCraft\Crush\Memory\DreamPass::call()}
 * builds and {@see Chat}'s settled-turn arm schedules.
 *
 * Everything with a side effect is already done when this arrives — the notes
 * written through {@see \SugarCraft\Crush\Memory\AutoMemoryConsolidator::apply()},
 * the memory history committed, the journal cursor moved — all in the parent
 * process as the turn's promise settled. What is left for {@see Chat::update()}
 * is the bookkeeping every provider call on the user's key gets: account
 * $usage whatever the outcome, and one display-only notice when a note was
 * saved, changed or removed. A pass that changed nothing says nothing.
 */
final class DreamPassCompletedMsg implements Msg
{
    /**
     * @param list<string>       $saved    ids of the notes added
     * @param list<string>       $updated  ids of the notes rewritten
     * @param list<string>       $deleted  ids of the notes removed
     * @param array<string, int> $skipped  skip reason => how many operations it covered
     * @param int                $entries  the journal entries the pass was shown
     * @param bool               $advanced whether the journal cursor moved past them (only a completed pass moves it)
     * @param ?string            $commit   the memory-history commit recording the pass, or null when none was made
     * @param ?Usage             $usage    what the turn cost, or null when unreported
     * @param ?string            $sessionId the session whose settled turn scheduled the pass
     * @param ?string            $error    why the turn failed, or null when it answered
     * @param ?string            $warning  why the memory history could not record the pass, or null
     */
    public function __construct(
        public readonly array $saved = [],
        public readonly array $updated = [],
        public readonly array $deleted = [],
        public readonly array $skipped = [],
        public readonly int $entries = 0,
        public readonly bool $advanced = false,
        public readonly ?string $commit = null,
        public readonly ?Usage $usage = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $error = null,
        public readonly ?string $warning = null,
    ) {}

    /** Whether the pass changed any note. */
    public function changed(): bool
    {
        return $this->saved !== [] || $this->updated !== [] || $this->deleted !== [];
    }
}
