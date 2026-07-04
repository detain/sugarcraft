<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Vim;

/**
 * A resolved vim text object: a character range over a single-line buffer.
 *
 * `start`/`end` are UTF-8 character indices (`mb_*` semantics, matching
 * how TextInput/TextPrompt measure their buffers); `end` is exclusive.
 * The range may be empty (`start === end`) — e.g. `ci(` on `()` — which
 * still succeeds in vim: change enters insert mode between the delimiters.
 *
 * Supported targets:
 * - quotes: `"` `'` ` — pairs formed left-to-right along the line
 *   (1st+2nd quote, 3rd+4th, ...); the first pair whose closing quote is
 *   at or after the cursor wins, so a cursor before a quoted region jumps
 *   forward to it, mirroring vim.
 * - brackets: `(` `)` `b` · `[` `]` · `{` `}` `B` · `<` `>` — the
 *   innermost pair containing the cursor (nesting-aware); a cursor ON
 *   either delimiter counts as inside that pair.
 * - word: `w` — the run of word characters (or whitespace / punctuation
 *   run) under the cursor; `aw` also takes the trailing whitespace run,
 *   falling back to leading whitespace when there is none.
 *
 * Simplifications vs vim (documented deliberately):
 * - `a"` / `a'` / `a`` do NOT absorb trailing whitespace after the
 *   closing quote; they select exactly the quotes plus content.
 * - Backslash-escaped quotes are not special (no `quoteescape` support).
 * - `aw` on whitespace selects the whitespace run plus the following
 *   word run (vim-like), falling back to the preceding run at EOL.
 *
 * Mirrors vim text objects (:help text-objects); no direct
 * charmbracelet/bubbles counterpart (upstream has no text objects).
 */
final class TextObject
{
    private const QUOTES = ['"', "'", '`'];

    /** target key (either side or vim alias) → [opener, closer] */
    private const BRACKETS = [
        '(' => ['(', ')'],
        ')' => ['(', ')'],
        'b' => ['(', ')'],
        '[' => ['[', ']'],
        ']' => ['[', ']'],
        '{' => ['{', '}'],
        '}' => ['{', '}'],
        'B' => ['{', '}'],
        '<' => ['<', '>'],
        '>' => ['<', '>'],
    ];

    private function __construct(
        public readonly int $start,
        public readonly int $end,
    ) {
    }

    /** Whether the key names a supported text-object target (delimiter or `w`). */
    public static function isTarget(string $key): bool
    {
        return $key === 'w'
            || in_array($key, self::QUOTES, true)
            || isset(self::BRACKETS[$key]);
    }

    /**
     * Resolve a text object at the cursor into a character range,
     * or null when no object exists there (unmatched delimiter,
     * cursor outside every pair, empty buffer).
     *
     * @param string $buffer Single-line UTF-8 buffer
     * @param int    $cursor Character index of the cursor (clamped into the buffer)
     * @param string $target Delimiter key or `w` (see class doc)
     */
    public static function resolve(string $buffer, int $cursor, TextObjectScope $scope, string $target): ?self
    {
        $chars = $buffer === '' ? [] : mb_str_split($buffer, 1, 'UTF-8');
        $len = count($chars);
        if ($len === 0) {
            return null;
        }
        $cursor = max(0, min($cursor, $len - 1));

        if ($target === 'w') {
            return self::resolveWord($chars, $len, $cursor, $scope);
        }

        if (in_array($target, self::QUOTES, true)) {
            return self::resolveQuote($chars, $len, $cursor, $scope, $target);
        }

        if (isset(self::BRACKETS[$target])) {
            [$open, $close] = self::BRACKETS[$target];
            return self::resolveBracket($chars, $len, $cursor, $scope, $open, $close);
        }

        return null;
    }

    /**
     * Quotes pair up left-to-right; pick the first pair whose closing
     * quote is at or after the cursor (vim jumps forward to a quoted
     * region later on the line). An unpaired trailing quote is ignored.
     *
     * @param list<string> $chars
     */
    private static function resolveQuote(array $chars, int $len, int $cursor, TextObjectScope $scope, string $quote): ?self
    {
        $positions = [];
        for ($i = 0; $i < $len; $i++) {
            if ($chars[$i] === $quote) {
                $positions[] = $i;
            }
        }

        $pairs = intdiv(count($positions), 2);
        for ($p = 0; $p < $pairs; $p++) {
            $open = $positions[2 * $p];
            $close = $positions[2 * $p + 1];
            if ($cursor <= $close) {
                return $scope === TextObjectScope::Inner
                    ? new self($open + 1, $close)
                    : new self($open, $close + 1);
            }
        }

        return null;
    }

    /**
     * Innermost bracket pair containing the cursor, nesting-aware.
     * A cursor sitting ON the opener or closer belongs to that pair.
     *
     * @param list<string> $chars
     */
    private static function resolveBracket(array $chars, int $len, int $cursor, TextObjectScope $scope, string $open, string $close): ?self
    {
        // Backward scan for the unmatched opener enclosing the cursor.
        // A closer at the cursor itself is the pair's own closer, not a
        // nested one, so it must not bump the depth.
        $openPos = null;
        $depth = 0;
        for ($i = $cursor; $i >= 0; $i--) {
            $c = $chars[$i];
            if ($c === $close && $i !== $cursor) {
                $depth++;
            } elseif ($c === $open) {
                if ($depth === 0) {
                    $openPos = $i;
                    break;
                }
                $depth--;
            }
        }
        if ($openPos === null) {
            return null;
        }

        // Forward scan for the matching closer.
        $closePos = null;
        $depth = 0;
        for ($i = $openPos + 1; $i < $len; $i++) {
            $c = $chars[$i];
            if ($c === $open) {
                $depth++;
            } elseif ($c === $close) {
                if ($depth === 0) {
                    $closePos = $i;
                    break;
                }
                $depth--;
            }
        }
        if ($closePos === null) {
            return null;
        }

        return $scope === TextObjectScope::Inner
            ? new self($openPos + 1, $closePos)
            : new self($openPos, $closePos + 1);
    }

    /**
     * Word object: the run of same-class characters under the cursor
     * (word chars / whitespace / punctuation — vim's three classes).
     * Around extends over the trailing whitespace run, falling back to
     * the leading one; on whitespace it takes the following run instead.
     *
     * @param list<string> $chars
     */
    private static function resolveWord(array $chars, int $len, int $cursor, TextObjectScope $scope): ?self
    {
        $class = self::charClass($chars[$cursor]);

        $start = $cursor;
        while ($start > 0 && self::charClass($chars[$start - 1]) === $class) {
            $start--;
        }
        $end = $cursor + 1;
        while ($end < $len && self::charClass($chars[$end]) === $class) {
            $end++;
        }

        if ($scope === TextObjectScope::Inner) {
            return new self($start, $end);
        }

        if ($class === 'space') {
            // aw from whitespace: whitespace + the following word/punct run,
            // or the preceding run when the whitespace ends the line.
            if ($end < $len) {
                $nextClass = self::charClass($chars[$end]);
                while ($end < $len && self::charClass($chars[$end]) === $nextClass) {
                    $end++;
                }
            } elseif ($start > 0) {
                $prevClass = self::charClass($chars[$start - 1]);
                while ($start > 0 && self::charClass($chars[$start - 1]) === $prevClass) {
                    $start--;
                }
            }
            return new self($start, $end);
        }

        // aw from a word/punct run: absorb trailing whitespace, else leading.
        if ($end < $len && self::charClass($chars[$end]) === 'space') {
            while ($end < $len && self::charClass($chars[$end]) === 'space') {
                $end++;
            }
        } elseif ($start > 0 && self::charClass($chars[$start - 1]) === 'space') {
            while ($start > 0 && self::charClass($chars[$start - 1]) === 'space') {
                $start--;
            }
        }
        return new self($start, $end);
    }

    /**
     * Vim's three character classes: word, whitespace, punctuation.
     * Word class matches ViMode/TextInput word motions ([a-zA-Z0-9_] +
     * any Unicode letter) so `iw` agrees with `w`/`b`.
     */
    private static function charClass(string $char): string
    {
        if (preg_match('/^\s$/u', $char) === 1) {
            return 'space';
        }
        if (preg_match('/^[a-zA-Z0-9_\p{L}]$/u', $char) === 1) {
            return 'word';
        }
        return 'punct';
    }
}
