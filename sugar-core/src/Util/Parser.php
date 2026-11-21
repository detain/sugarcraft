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
 * The legacy cap is kept. {@see MAX_PENDING_BYTES} is judged on the whole
 * buffer (held bytes plus the chunk), as the original state machine did: when
 * that total is over the cap and a sequence is still open, the held bytes come
 * back as one TEXT token on that call and the next call starts clean. The
 * tokenizer alone judges the held partial, so callers that need that rule use
 * {@see \SugarCraft\AnsiParser\AnsiTokenizer} directly.
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
     * Tokenise `$chunk`. An unfinished trailing sequence is held for the next call,
     * unless held bytes plus `$chunk` exceed {@see MAX_PENDING_BYTES}, in which case
     * the held bytes are returned as TEXT.
     *
     * @return list<Token>
     */
    public function parse(string $chunk): array
    {
        $whole = $this->tokenizer->pendingBytes() + strlen($chunk);
        $tokens = $this->tokenizer->parse($chunk);
        if ($whole > self::MAX_PENDING_BYTES) {
            // flush() is empty unless the tokenizer just held a partial. That
            // partial is the tail the legacy parser recovered as text.
            $tokens = [...$tokens, ...$this->tokenizer->flush()];
        }
        return array_map(self::toLegacy(...), $tokens);
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
