<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

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
     * Return a new CherryPick with an added character to the commit ref.
     */
    public function withChar(string $rune): self
    {
        return new self(collecting: true, commitRef: $this->commitRef . $rune);
    }

    /**
     * Return a new CherryPick with the given commit ref.
     */
    public function withCommitRef(string $ref): self
    {
        return new self(collecting: $this->collecting, commitRef: $ref);
    }

    /**
     * Cancel cherry-pick collection.
     */
    public function cancel(): self
    {
        return new self(collecting: false, commitRef: '');
    }
}
