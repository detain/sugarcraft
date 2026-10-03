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
 *
 * $offset is where a FUZZY match sits in the file: a fuzzy stage proves one
 * place, so the edit is applied at that place rather than by value (the same
 * bytes may also occur elsewhere, unmatched by the stage's rule). Null for an
 * exact match, which is applied by value under `replace_all`.
 *
 * $refusal, when set, is a complete error message: the chain found something
 * and declines to apply it — several candidates at a fuzzy stage
 * ($count > 1, $lines naming where), or one candidate out of all proportion to
 * `old_string` ({@see EditMatcher::isDisproportionateMatch()}).
 */
final readonly class EditMatch
{
    /**
     * @param list<int> $lines 1-based first line of each candidate, for refusals
     */
    public function __construct(
        public string $stage,
        public string $oldString,
        public string $newString,
        public int $count,
        public ?string $note = null,
        public ?int $offset = null,
        public ?string $refusal = null,
        public array $lines = [],
    ) {}

    public function isExact(): bool
    {
        return $this->stage === EditMatcher::STAGE_EXACT;
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    /**
     * $content with this match applied: by value for an exact match (every
     * occurrence — the caller has already enforced uniqueness or `replace_all`),
     * at $offset for a fuzzy one.
     */
    public function applyTo(string $content): string
    {
        if ($this->offset === null) {
            return str_replace($this->oldString, $this->newString, $content);
        }

        return substr_replace($content, $this->newString, $this->offset, strlen($this->oldString));
    }
}
