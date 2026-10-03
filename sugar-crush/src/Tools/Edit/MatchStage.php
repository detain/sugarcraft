<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * One stage of {@see EditMatcher}'s chain: a way of finding where an Edit's
 * `old_string` sits in a file when the bytes do not match as given.
 *
 * A stage reports EVERY place it would match, never a pick: uniqueness is the
 * chain's rule, applied the same way to every stage, so a forgiving stage can
 * never choose one of several candidates and edit a place the model did not
 * name. Each candidate carries the replacement already adjusted to the file
 * (re-indented, line endings matched) where the stage knows how, or is left out
 * when the stage cannot place `new_string` consistently.
 */
interface MatchStage
{
    /** The stage's name, as the Edit result reports it. */
    public function name(): string;

    /**
     * @return list<MatchCandidate>
     */
    public function find(string $content, string $old, string $new): array;
}
