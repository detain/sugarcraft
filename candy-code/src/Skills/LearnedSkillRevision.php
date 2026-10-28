<?php

declare(strict_types=1);

namespace SugarCraft\Code\Skills;

/**
 * One saved revision of a learned skill: the exact bytes {@see LearnedSkills}
 * wrote to `history/<n>.md`, with the name, number, size and timestamp a
 * listing needs without re-reading the file.
 *
 * The VO exists so the store's two write doors (`create`, `patch`) answer the
 * same shape (`undo` answers with its own action array): a tool announcement
 * and a slash notice both print the number and path, and a test can assert the
 * pair without knowing the layout. Nothing here is user-facing text; callers
 * pass these fields to `Lang::t()` or to the tool's public format constants.
 */
final readonly class LearnedSkillRevision
{
    public function __construct(
        public string $name,
        public int $number,
        public string $path,
        public int $bytes,
    ) {
    }

    public static function new(string $name, int $number, string $path, int $bytes): self
    {
        return new self($name, $number, $path, $bytes);
    }
}
