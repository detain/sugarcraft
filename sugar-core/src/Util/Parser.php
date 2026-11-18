<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\AnsiParser\AnsiTokenizer;
use SugarCraft\AnsiParser\Token as AnsiToken;

/**
 * Deprecated byte-stream tokenizer, kept as a shim over
 * {@see \SugarCraft\AnsiParser\AnsiTokenizer}.
 *
 * The tokenizing moved to sugar-ansi-parser; this class delegates to it and
 * converts each token into the legacy {@see Token} so existing callers keep
 * their type checks. Nothing in sugar-core uses it any more except the
 * legacy-token consumers that have not yet moved.
 *
 * One difference from the original state machine: a held partial that is
 * short while the backlog before it is over {@see MAX_PENDING_BYTES} is kept
 * for the next call rather than returned as text straight away. The
 * tokenizer judges the cap on the held bytes, not the whole buffer.
 *
 * @deprecated Use {@see \SugarCraft\AnsiParser\AnsiTokenizer} directly.
 */
final class Parser
{
    /** Upper bound on a held partial sequence; see {@see AnsiTokenizer::MAX_PENDING_BYTES}. */
    public const MAX_PENDING_BYTES = AnsiTokenizer::MAX_PENDING_BYTES;

    private AnsiTokenizer $tokenizer;

    public function __construct()
    {
        $this->tokenizer = AnsiTokenizer::new();
    }

    /**
     * Tokenise `$chunk`. An unfinished trailing sequence is held for the next call.
     *
     * @return list<Token>
     */
    public function parse(string $chunk): array
    {
        return array_map(self::toLegacy(...), $this->tokenizer->parse($chunk));
    }

    /**
     * Return any held partial sequence as one TEXT token and clear the buffer.
     *
     * @return list<Token>
     */
    public function flush(): array
    {
        return array_map(self::toLegacy(...), $this->tokenizer->flush());
    }

    private static function toLegacy(AnsiToken $t): Token
    {
        return new Token($t->type, $t->data, $t->intermediate, $t->params, $t->final, $t->terminator);
    }
}
