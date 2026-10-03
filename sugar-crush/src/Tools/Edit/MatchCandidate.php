<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * One place a {@see MatchStage} found `old_string`: the byte span in the file
 * (`$offset`, `$length` — the FILE's spelling of the text), the replacement to
 * put there, and an optional clause describing what the stage adjusted, for the
 * Edit result.
 */
final readonly class MatchCandidate
{
    public function __construct(
        public int $offset,
        public int $length,
        public string $newString,
        public ?string $detail = null,
    ) {}

    /** The matched text as it stands in $content. */
    public function matchedIn(string $content): string
    {
        return substr($content, $this->offset, $this->length);
    }
}
