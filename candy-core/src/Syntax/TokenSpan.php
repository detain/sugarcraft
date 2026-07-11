<?php

declare(strict_types=1);

namespace SugarCraft\Core\Syntax;

/**
 * An immutable, style-agnostic slice of tokenised source.
 *
 * A {@see Highlighter} returns a `list<TokenSpan>` that fully covers the
 * input in order: concatenating every span's {@see self::$text} reproduces
 * the original code byte-for-byte. This carries the classification but not
 * the presentation — a renderer maps {@see self::$kind} to whatever colour
 * or ANSI style it wants, keeping tokenisation and styling decoupled.
 */
final readonly class TokenSpan
{
    /**
     * @param int $offset Byte offset of $text within the original input.
     */
    public function __construct(
        public TokenKind $kind,
        public string $text,
        public int $offset = 0,
    ) {
    }

    /**
     * Convenience factory mirroring the value-object shape used elsewhere.
     */
    public static function of(TokenKind $kind, string $text, int $offset = 0): self
    {
        return new self($kind, $text, $offset);
    }
}
