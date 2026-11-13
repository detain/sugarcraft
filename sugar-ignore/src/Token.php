<?php

declare(strict_types=1);

namespace SugarCraft\Ignore;

/**
 * Token-type tags of a compiled {@see Pattern}; a token is `[type, payload]`.
 */
final class Token
{
    /** One literal byte; payload is the byte. */
    public const LITERAL = 0;
    /** `?` — one byte that is not `/`. */
    public const ONE = 1;
    /** `[...]` — one byte in the class; payload is the compiled PCRE class. */
    public const CLASS_ = 2;
    /** `*` — any run of bytes without `/`. */
    public const STAR = 3;
    /** `**` NOT followed by `/` — any run of bytes except `\n` (PCRE `.*`). */
    public const GLOBSTAR = 4;
    /** `**\/`, and an unanchored pattern's implicit prefix — `(?:[^/]+/)*`. */
    public const DIRS = 5;

    private function __construct() {}
}
