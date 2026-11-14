<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Cherry-pick state — tracks whether we're collecting a commit ref
 * and any in-progress cherry-pick result.
 *
 * @readonly
 */
final readonly class CherryPick
{
    /**
     * @param bool   $collecting Whether we're currently collecting a commit ref
     * @param string $commitRef The accumulated commit ref being typed
     */
    public function __construct(
        public bool $collecting = false,
        public string $commitRef = '',
    ) {}

    /**
     * Enter commit-ref collection mode.
     */
    public static function collecting(): self
    {
        return new self(collecting: true, commitRef: '');
    }

    /**
     * Return a new CherryPick with the given commit ref.
     *
     * Dead-code sweep part 1 (candy-git-029): withChar()/cancel() are gone —
     * the keyboard path accumulated in App and handed the full buffer to
     * withCommitRef(), and Escape closes the overlay directly; nothing ever
     * called the removed pair.
     */
    public function withCommitRef(string $ref): self
    {
        return new self(collecting: $this->collecting, commitRef: $ref);
    }
}
