<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * What {@see EditMatcher} found for one `old_string`: which stage matched, the
 * text to replace and what to replace it with (both as the FILE spells them,
 * so a re-indented match carries the file's indentation), and how many times
 * that text occurs.
 *
 * $note is a sentence for the success message when the match was not exact,
 * so the model learns what was adjusted instead of believing its `old_string`
 * was right; null for an exact match.
 */
final readonly class EditMatch
{
    public function __construct(
        public string $stage,
        public string $oldString,
        public string $newString,
        public int $count,
        public ?string $note = null,
    ) {}

    public function isExact(): bool
    {
        return $this->stage === EditMatcher::STAGE_EXACT;
    }
}
