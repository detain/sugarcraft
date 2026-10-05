<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * One change inside an `*** Update File:` section ({@see PatchParser}).
 *
 * `$anchors` are the texts of the `@@ …` lines in front of it, outermost
 * first (`@@ class Cart` then `@@ function total`): each names a line that
 * must be found, in order, before the hunk's own lines are looked for, which
 * is how a patch says WHICH of several identical blocks it means. A bare `@@`
 * carries no anchor.
 *
 * `$oldLines` are the context and `-` lines as they must stand in the file,
 * `$newLines` the context and `+` lines that replace them, both without the
 * one-character prefix. `$endOfFile` is set by `*** End of File`: the block
 * sits at the end of the file, so of several matches the last is meant.
 */
final readonly class PatchHunk
{
    /**
     * @param list<string> $anchors
     * @param list<string> $oldLines
     * @param list<string> $newLines
     */
    public function __construct(
        public array $anchors,
        public array $oldLines,
        public array $newLines,
        public bool $endOfFile = false,
    ) {
    }

    /** The lines the hunk replaces, newline-terminated, '' for a pure insertion. */
    public function oldText(): string
    {
        return self::text($this->oldLines);
    }

    /** The lines the hunk puts in their place, newline-terminated. */
    public function newText(): string
    {
        return self::text($this->newLines);
    }

    /** @param list<string> $lines */
    private static function text(array $lines): string
    {
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }
}
