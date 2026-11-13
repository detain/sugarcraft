<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use RuntimeException;
use SugarCraft\Vcr\Event;

/**
 * Line-oriented tape tokenizer.
 *
 * Each non-empty line that doesn't start with # is a directive.
 * Token types: TYPE, ENTER, TAB, BACKSPACE, SLEEP, SET, ENV, OUTPUT,
 * ARROW, CTRL, SPACE, ESCAPE, HIDE, SHOW, WAIT, SCREEN, SCREENSHOT, SOURCE,
 * KEY, MODIFIER, COPY, PASTE, REQUIRE, JSON, REGEX, UNKNOWN, COMMENT.
 *
 * The KEY/MODIFIER/COPY/PASTE/REQUIRE arms (finding 005) carry the twelve
 * upstream command keywords the original port never lexed — the roster is
 * the measured 60-name set pinned in {@see \SugarCraft\Vcr\Tests\Tape\KeywordRosterParityTest}
 * against `candy-code/tests/VhsTapeContractTest.php`; nothing upstream-legal
 * may fall to UNKNOWN's silent drop.
 * Comments are preserved for round-trip.
 *
 * ## Grammar port (E15 / tracker #81)
 *
 * The two value-delimiter kinds the original port omitted — JSON (`{` … `}`)
 * and REGEX (`/` … `/`) — are upstream first-class token kinds, measured
 * against the real `vhs` binary by the oracle sweep in
 * `candy-code/tests/VhsTapeContractTest.php`. The facts that port with them:
 *
 * - **Tab is whitespace.** Upstream's stream has no newline token and its
 *   whitespace class includes `\t`, so `Type "x"\tSleep 1s` is two
 *   directives. The peel loop skips with `strspn`, whose default char class
 *   (`" \t\n\r\0\x0B`) matches.
 * - **Three time units, not two.** `s`, `ms`, `m` — see
 *   {@see durationSeconds()} for the scale each carries.
 * - **The JSON closer is synthesized.** A `{`-opened run with no matching `}`
 *   swallows the remainder of the line and the lexer fabricates the missing
 *   closers, mirroring upstream `readJSON` returning a well-formed literal at
 *   EOF. A `#` inside the braces is literal, never a comment opener.
 * - **Gates are on token KIND, not text.** Upstream's predicate set
 *   (`isLetter`/`isDigit`/`isDot`/`isDash`/`isUnderscore`/`isSlash`/`isPercent`
 *   feeding `readIdentifier`/`readNumber`/`readJSON`/`readRegex`) decides
 *   what a directive accepted by name may carry; here the same idea is the
 *   match chain — a delimiter token is only recognized at a token START, and
 *   only when a whitespace/line-end boundary follows its closer, so
 *   `./bin/candy-code` (identifier run-class bytes hold it together) and
 *   `Set Shell /bin/sh` (no boundary after `/bin/`) lex as one token, never
 *   as an opened regex.
 * - **Five delimiter pairs make `#` literal:** `"` `"`, `'` `'`, `` ` `` `` ` ``,
 *   `/` `/`, `{` `}`. All three quotes are literal INSIDE a regex, and a `#`
 *   inside any of them cannot hide the directive that follows the closer.
 *
 * ## Escape law (finding 006 / 032 / 091)
 *
 * Quoted values are scanned escape-aware: a backslash consumes the NEXT byte
 * as an escaped pair, so an escaped delimiter can never close the run —
 * `Type "echo \"hello\" world"` lexes the whole string, and `Env FOO "bar'`
 * (mismatched pair) no longer eats an apostrophe and mis-emit a bogus value.
 * The token VALUE keeps its escapes verbatim; the Compiler unescapes at
 * compile time via {@see Lexer::unescapeValue()}, so the tape text a
 * {@see \SugarCraft\Vcr\Tape\Decompiler} emits (`\"`/`\\`) re-parses back to
 * the bytes it came from — round-trip honesty, not just truncation-free lexing.
 *
 * ## Case law (finding 092)
 *
 * Keyword arms are strict-case, upstream-faithful: `sleep 1s` is no directive
 * and rides the UNKNOWN → ParseError route that S05 opened, exactly like a
 * typo. The five `/i` arms the original port carried (Sleep/Wait/Screen/
 * Screenshot/Source) dropped that leniency here; tape validity must never
 * depend on which keyword happened to be lowercased.
 *
 * ## Size law (finding 032)
 *
 * The peel loop is offset-anchored (every arm matches with `\G` at a running
 * offset — no per-directive suffix copy, so a line of N directives costs
 * O(line), not O(N × line)). Defence in depth on top of that: a single tape
 * line over {@see MAX_LINE_BYTES} aborts the tokenize by name, and both tape
 * READ doors ({@see \SugarCraft\Vcr\Format\CassetteLoader},
 * {@see \SugarCraft\Vcr\Encode\TapeToGif}) refuse files over
 * {@see MAX_TAPE_BYTES} before slurping.
 */
final readonly class Lexer
{
    public const TOKEN_TYPE = 'TYPE';
    public const TOKEN_ENTER = 'ENTER';
    public const TOKEN_TAB = 'TAB';
    public const TOKEN_BACKSPACE = 'BACKSPACE';
    public const TOKEN_SLEEP = 'SLEEP';
    public const TOKEN_SET = 'SET';
    public const TOKEN_ENV = 'ENV';
    public const TOKEN_OUTPUT = 'OUTPUT';
    public const TOKEN_ARROW = 'ARROW';
    public const TOKEN_CTRL = 'CTRL';
    public const TOKEN_SPACE = 'SPACE';
    public const TOKEN_ESCAPE = 'ESCAPE';
    public const TOKEN_HIDE = 'HIDE';
    public const TOKEN_SHOW = 'SHOW';
    public const TOKEN_WAIT = 'WAIT';
    public const TOKEN_SCREEN = 'SCREEN';
    public const TOKEN_SCREENSHOT = 'SCREENSHOT';
    public const TOKEN_SOURCE = 'SOURCE';
    public const TOKEN_KEY = 'KEY';
    public const TOKEN_MODIFIER = 'MODIFIER';
    public const TOKEN_COPY = 'COPY';
    public const TOKEN_PASTE = 'PASTE';
    public const TOKEN_REQUIRE = 'REQUIRE';
    public const TOKEN_JSON = 'JSON';
    public const TOKEN_REGEX = 'REGEX';
    public const TOKEN_UNKNOWN = 'UNKNOWN';
    public const TOKEN_COMMENT = 'COMMENT';

    /**
     * Hard ceiling on a whole tape file, enforced at every read door before
     * the bytes are slurped. Terminal tapes are text directives; a file this
     * large is an attack or a mistake, and reading it is the harm (finding 032).
     */
    public const MAX_TAPE_BYTES = 8 * 1024 * 1024;

    /**
     * Hard ceiling on one tape line. Even with the offset-anchored scan, a
     * megabyte-scale quoted run is pathological; refuse it by name instead of
     * spending regex time on it (finding 032).
     */
    public const MAX_LINE_BYTES = 1024 * 1024;

    /**
     * Unescape the sequences {@see matchDirective()} consumed as escaped
     * pairs: a backslash before a backslash or any quote character carries
     * only the quote's meaning, never its own. Every other backslash run
     * (`\t`, `\x1b`) stays two literal bytes — upstream's Go quoting does
     * not expand those inside these strings either, and the old port didn't.
     */
    public static function unescapeValue(string $value): string
    {
        return preg_replace_callback(
            '/\\\\([\\\\\'"`])/',
            static fn (array $m): string => $m[1],
            $value,
        ) ?? $value;
    }

    /**
     * @return list<Token>
     */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $lines = explode("\n", $source);
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $lineNum = $i + 1;
            $raw = $lines[$i];

            if (strlen($raw) > self::MAX_LINE_BYTES) {
                throw new RuntimeException(
                    'tape line ' . $lineNum . ' exceeds the ' . self::MAX_LINE_BYTES . '-byte line cap',
                );
            }

            $trimmed = trim($raw);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $tokens[] = new Token(self::TOKEN_COMMENT, $raw, $lineNum);
                continue;
            }

            // A single line may carry several directives, e.g.
            //   Down  Sleep 200ms        # move, then pause
            //   Type "f"  Sleep 300ms    # press a key, then pause
            // Upstream VHS treats the tape as a whitespace-delimited token
            // stream where newlines aren't significant, so directives peel
            // off the line at a running offset until it's consumed.
            foreach ($this->lexLine($trimmed, $lineNum) as $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Tokenize one non-empty, non-comment line into one or more tokens.
     *
     * Offset-anchored (finding 032): the line is never re-sliced per peel —
     * each arm matches with `\G` at the running offset, so the scan is
     * linear in the line length no matter how many directives it carries.
     *
     * @return list<Token>
     */
    private function lexLine(string $line, int $lineNum): array
    {
        $tokens = [];
        $length = strlen($line);
        $offset = 0;

        while ($offset < $length) {
            $offset += strspn($line, " \t\n\r\0\x0B", $offset);
            if ($offset >= $length) {
                break;
            }
            // An inline comment runs to the end of the line.
            if ($line[$offset] === '#') {
                $tokens[] = new Token(self::TOKEN_COMMENT, substr($line, $offset), $lineNum);
                break;
            }

            [$token, $consumed] = $this->matchDirective($line, $offset, $lineNum);
            if ($token === null || $consumed <= 0) {
                // Unclassifiable remainder — emit it whole as UNKNOWN and
                // stop so we never spin on a token we can't advance past.
                $tokens[] = new Token(self::TOKEN_UNKNOWN, substr($line, $offset), $lineNum);
                break;
            }
            $tokens[] = $token;
            $offset += $consumed;
        }

        return $tokens;
    }

    /**
     * Match a single directive at $offset in $s.
     *
     * @return array{0: ?Token, 1: int} the token (null when unmatched) and
     *         the number of bytes consumed at $offset.
     */
    private function matchDirective(string $s, int $offset, int $lineNum): array
    {
        // Type takes one quoted argument — all three upstream quote kinds;
        // the body is escape-aware, so an escaped delimiter consumes as a
        // pair and cannot close the run (finding 006). Only the quoted run
        // is consumed, so a trailing Sleep / comment stays live.
        if (preg_match('/\GType\s+([\'"`])((?:\\\\.|[^\\\\])*?)\1/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_TYPE, $m[2], $lineNum), strlen($m[0])];
        }

        if (preg_match('/\GSleep\s+(\d+(?:\.\d+)?)\s*(s|ms|m)(?=\s|$)/', $s, $m, 0, $offset)) {
            $seconds = $this->durationSeconds((float) $m[1], $m[2]);
            // Trust door (finding 001): an absurd duration never becomes a
            // token — the line falls to the UNKNOWN path (and with it, to
            // the parser's gates) instead of advancing the tape clock to
            // geological time.
            if (is_finite($seconds) && $seconds >= 0.0 && $seconds <= Event::MAX_TIMESTAMP_SECONDS) {
                return [new Token(self::TOKEN_SLEEP, (string) $seconds, $lineNum), strlen($m[0])];
            }
        }

        if (preg_match('/\GWait\s+(\d+(?:\.\d+)?)\s*(s|ms|m)?(?=\s|$)/', $s, $m, 0, $offset)) {
            $seconds = $this->durationSeconds((float) $m[1], $m[2] ?? 's');
            if (is_finite($seconds) && $seconds >= 0.0 && $seconds <= Event::MAX_TIMESTAMP_SECONDS) {
                return [new Token(self::TOKEN_WAIT, (string) $seconds, $lineNum), strlen($m[0])];
            }
        }

        if (preg_match('/\GCtrl\+([A-Za-z@\[\]\\\\^_])(?=\s|$)/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_CTRL, $m[1], $lineNum), strlen($m[0])];
        }

        // Bare keyword directives — anchored with a trailing boundary so
        // "Down" never swallows the start of a longer word. The roster is
        // upstream's measured command set (finding 005): the seven KEY
        // words and two MODIFIER words the original port never lexed, plus
        // argument-less Paste. ScrollUp/ScrollDown must precede nothing —
        // the alternation is fixed and longest-first per word — but note
        // the boundary gate keeps "PageUpward" out.
        if (preg_match('/\G(Enter|Tab|Backspace|Space|Escape|Hide|Show|Up|Down|Left|Right|Delete|Insert|PageUp|PageDown|End|ScrollUp|ScrollDown|Alt|Shift|Paste)(?=\s|$)/', $s, $m, 0, $offset)) {
            $keyword = $m[1];
            $type = match ($keyword) {
                'Enter'     => self::TOKEN_ENTER,
                'Tab'       => self::TOKEN_TAB,
                'Backspace' => self::TOKEN_BACKSPACE,
                'Space'     => self::TOKEN_SPACE,
                'Escape'    => self::TOKEN_ESCAPE,
                'Hide'      => self::TOKEN_HIDE,
                'Show'      => self::TOKEN_SHOW,
                'Alt'       => self::TOKEN_MODIFIER,
                'Shift'     => self::TOKEN_MODIFIER,
                'Paste'     => self::TOKEN_PASTE,
                'Delete', 'Insert', 'PageUp', 'PageDown', 'End', 'ScrollUp', 'ScrollDown' => self::TOKEN_KEY,
                default     => self::TOKEN_ARROW, // Up / Down / Left / Right
            };
            return [new Token($type, $keyword, $lineNum), strlen($keyword)];
        }

        // `Copy "<text>"` — upstream's parseCopy measures as string-argument
        // only (`Copy expects string`, quoted at the candy-code oracle). A
        // bare `Copy` still lexes as COPY with an empty value so the Parser
        // can answer upstream's own phrase instead of the head-guessing
        // UNKNOWN path. The body is escape-aware exactly like Type's.
        if (preg_match('/\GCopy\s+([\'"`])((?:\\\\.|[^\\\\])*?)\1/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_COPY, $m[2], $lineNum), strlen($m[0])];
        }
        if (preg_match('/\GCopy(?=\s|$)/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_COPY, '', $lineNum), strlen($m[0])];
        }

        // `Require <module>` — upstream's precondition loader (measured
        // phrase `Require expects one string`, and the acceptance form
        // `Require git` spells the module bare). Quoted and bare-word
        // values both lex; a bare trailing `Require` keeps the boundary
        // gate honest so `Required` is never split into a value.
        if (preg_match('/\GRequire\s+([\'"`])((?:\\\\.|[^\\\\])*?)\1/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_REQUIRE, $m[2], $lineNum), strlen($m[0])];
        }
        if (preg_match('/\GRequire\s+(\S+)(?=\s|$)/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_REQUIRE, $m[1], $lineNum), strlen($m[0])];
        }
        if (preg_match('/\GRequire(?=\s|$)/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_REQUIRE, '', $lineNum), strlen($m[0])];
        }


        // `Set key <value>`: a `{` value is a JSON literal and a `/…/` value
        // is a regex literal — each ends AT ITS CLOSER, so a directive or
        // comment after it stays live on the same line (upstream's measured
        // delimiter behaviour). Anything else keeps the free-form
        // run-to-end-of-line value.
        if (preg_match('/\GSet\s+(\S+)(\s+)/', $s, $m, 0, $offset)) {
            $prefixLen = strlen($m[0]);
            $tailOffset = $offset + $prefixLen;
            if ($this->startsValueToken($s, $tailOffset, '{')) {
                [$value, $len] = $this->readJson($s, $tailOffset);
                return [new Token(self::TOKEN_SET, $m[1] . "\x00" . $value, $lineNum), $prefixLen + $len];
            }
            if ($this->startsValueToken($s, $tailOffset, '/')) {
                [$value, $len] = $this->readRegex($s, $tailOffset);
                return [new Token(self::TOKEN_SET, $m[1] . "\x00" . $value, $lineNum), $prefixLen + $len];
            }
            return [new Token(self::TOKEN_SET, $m[1] . "\x00" . substr($s, $tailOffset), $lineNum), strlen($s) - $offset];
        }

        // `Env KEY "value"` — the delimiter back-references its opener and
        // the body is escape-aware (finding 091): `Env FOO "bar'` can no
        // longer answer `'` where `"` asked, and a prose apostrophe after a
        // directive cannot re-lex into a bogus ENV token (finding 032).
        // Upstream's bare unquoted form stays a disclosed delta (091).
        if (preg_match('/\GEnv\s+(\S+)\s+([\'"])((?:\\\\.|[^\\\\])*?)\2\s*/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_ENV, $m[1] . "\x00" . $m[3], $lineNum), strlen($m[0])];
        }

        if (preg_match('/\GOutput\s+(.+)$/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_OUTPUT, trim($m[1]), $lineNum), strlen($m[0])];
        }

        // Screenshot must be tried before Screen (shared prefix).
        if (preg_match('/\GScreenshot\s+(.+)$/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_SCREENSHOT, $m[1], $lineNum), strlen($m[0])];
        }

        if (preg_match('/\GScreen\s+(.+)$/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_SCREEN, $m[1], $lineNum), strlen($m[0])];
        }

        if (preg_match('/\GSource\s+(.+)$/', $s, $m, 0, $offset)) {
            return [new Token(self::TOKEN_SOURCE, trim($m[1]), $lineNum), strlen($m[0])];
        }

        // `Wait /re/` / `Wait {json}`: upstream opens the value token wherever
        // a token starts, and the duration arm above already declined, so peel
        // the keyword and emit the VALUE token by kind. candy-vcr has no
        // pattern-wait AST directive yet — the Parser's kind gate drops these
        // instead of the old TOKEN_UNKNOWN swallow hiding them.
        if (preg_match('/\GWait\s+/', $s, $m, 0, $offset)) {
            $tailOffset = $offset + strlen($m[0]);
            if ($this->startsValueToken($s, $tailOffset, '{')) {
                [$value, $len] = $this->readJson($s, $tailOffset);
                return [new Token(self::TOKEN_JSON, $value, $lineNum), strlen($m[0]) + $len];
            }
            if ($this->startsValueToken($s, $tailOffset, '/')) {
                [$value, $len] = $this->readRegex($s, $tailOffset);
                return [new Token(self::TOKEN_REGEX, $value, $lineNum), strlen($m[0]) + $len];
            }
        }

        // Value tokens stand wherever a token starts, not only behind the
        // keywords that happen to read them upstream (`Set WaitPattern /re/`
        // and a line-initial regex are the same token). Reaching these arms
        // means no keyword matched, so a bare JSON/REGEX token is emitted
        // and the Parser's kind gate drops it.
        if ($this->startsValueToken($s, $offset, '{')) {
            [$value, $len] = $this->readJson($s, $offset);
            return [new Token(self::TOKEN_JSON, $value, $lineNum), $len];
        }
        if ($this->startsValueToken($s, $offset, '/')) {
            [$value, $len] = $this->readRegex($s, $offset);
            return [new Token(self::TOKEN_REGEX, $value, $lineNum), $len];
        }

        return [null, 0];
    }

    /**
     * Does $s open a value token of this delimiter at $offset that legitimately
     * ENDS the token — i.e. the closer is followed by whitespace / line-end? That
     * boundary gate is what keeps an identifier-run-class `/` (a `/` mid-run
     * never opens a regex upstream) from splitting `/bin/sh` into `/bin/` +
     * `sh`. Checked by scanning in place with the same terminator rules as the
     * reader, so synthesized-closer forms (`{` to EOL) pass too.
     */
    private function startsValueToken(string $s, int $offset, string $open): bool
    {
        $len = strlen($s);
        if ($offset >= $len || $s[$offset] !== $open) {
            return false;
        }
        if ($open === '{') {
            // JSON: either depth closes on a `}` (boundary-checked) or the
            // run reaches end-of-line and the closer is synthesized.
            $depth = 0;
            for ($i = $offset; $i < $len; $i++) {
                $c = $s[$i];
                if ($c === '"') {
                    for ($i++; $i < $len; $i++) {
                        if ($s[$i] === '\\') {
                            $i++;
                            continue;
                        }
                        if ($s[$i] === '"') {
                            break;
                        }
                    }
                    continue;
                }
                if ($c === '{') {
                    $depth++;
                    continue;
                }
                if ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $i === $len - 1 || ctype_space($s[$i + 1]);
                    }
                }
            }
            return true; // EOF: synthesized closer
        }
        // Regex: closer `/` not escaped by a backslash, then boundary or EOL.
        for ($i = $offset + 1; $i < $len; $i++) {
            if ($s[$i] === '\\') {
                $i++;
                continue;
            }
            if ($s[$i] === '/') {
                return $i === $len - 1 || ctype_space($s[$i + 1]);
            }
        }
        return true; // EOF: run simply ends at the line end
    }

    /**
     * Read a brace-balanced JSON literal at $offset in $s (which starts
     * with `{`). `#` is literal inside the braces — only the balanced `}`
     * ends it — and a run with no closer consumes the rest of the line and
     * gets its closers synthesized (mirrors upstream `readJSON`).
     *
     * @return array{0: string, 1: int} the JSON text and bytes consumed.
     */
    private function readJson(string $s, int $offset): array
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = $offset; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '"') {
                for ($i++; $i < $len; $i++) {
                    if ($s[$i] === '\\') {
                        $i++;
                        continue;
                    }
                    if ($s[$i] === '"') {
                        break;
                    }
                }
                continue;
            }
            if ($c === '{') {
                $depth++;
                continue;
            }
            if ($c === '}' && --$depth === 0) {
                return [substr($s, $offset, $i + 1 - $offset), $i + 1 - $offset];
            }
        }
        return [substr($s, $offset) . str_repeat('}', $depth), $len - $offset];
    }

    /**
     * Read a `/…/` regex literal at $offset in $s. All three quote kinds are
     * literal inside it; only an unescaped closing `/` ends the token. An
     * unterminated run consumes the rest of the line unchanged (no closer is
     * synthesized — that courtesy belongs to the JSON reader only).
     *
     * @return array{0: string, 1: int} the literal (slashes kept) and bytes consumed.
     */
    private function readRegex(string $s, int $offset): array
    {
        $len = strlen($s);
        for ($i = $offset + 1; $i < $len; $i++) {
            if ($s[$i] === '\\') {
                $i++;
                continue;
            }
            if ($s[$i] === '/') {
                return [substr($s, $offset, $i + 1 - $offset), $i + 1 - $offset];
            }
        }
        return [substr($s, $offset), $len - $offset];
    }

    /**
     * Convert a tape duration + unit to seconds. The three upstream time
     * units are `s`, `ms`, `m` — anything else never reaches here (the
     * keyword regexes gate it). Units are strict-case: upstream's duration
     * reader accepts exactly these three lowercase spellings (finding 092).
     */
    private function durationSeconds(float $duration, string $unit): float
    {
        return match ($unit) {
            'ms'    => $duration / 1000.0,
            'm'     => $duration * 60.0,
            default => $duration, // 's' or empty
        };
    }
}
