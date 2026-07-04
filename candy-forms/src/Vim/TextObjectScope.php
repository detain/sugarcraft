<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Vim;

/**
 * Scope selector for vim text objects: `i` (inner) vs `a` (around).
 *
 * Inner selects the content between the delimiters; around also
 * includes the delimiters themselves (for words: adjacent whitespace).
 *
 * Mirrors vim text-object modifiers (:help text-objects).
 */
enum TextObjectScope: string
{
    /** Content between the delimiters (vim i — "inner"). */
    case Inner = 'inner';

    /** Content including the delimiters (vim a — "around"). */
    case Around = 'around';

    /**
     * Map a scope key (`i` / `a`) to its scope, or null when the key
     * is not a text-object scope selector.
     */
    public static function fromKey(string $key): ?self
    {
        return match ($key) {
            'i'     => self::Inner,
            'a'     => self::Around,
            default => null,
        };
    }
}
