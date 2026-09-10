<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use SugarCraft\Vcr\Tape\Ast\ParseError;

/**
 * Line-oriented tape tokenizer.
 *
 * Each non-empty line that doesn't start with # is a directive.
 * Token types: TYPE, ENTER, TAB, BACKSPACE, SLEEP, SET, ENV, OUTPUT,
 * ARROW, CTRL, SPACE, ESCAPE, HIDE, SHOW, WAIT, SCREEN, SCREENSHOT, SOURCE,
 * JSON, REGEX, UNKNOWN.
 * Comments are preserved for round-trip.
 *
 * ## Grammar port (E15 / tracker #81)
 *
 * The two value-delimiter kinds the original port omitted — JSON (`{` … `}`)
 * and REGEX (`/` … `/`) — are upstream first-class token kinds, measured
 * against the real `vhs` binary by the oracle sweep in
 * `sugar-crush/tests/VhsTapeContractTest.php`. The facts that port with them:
 *
 * - **Tab is whitespace.** Upstream's stream has no newline token and its
 *   whitespace class includes `\t`, so `Type "x"\tSleep 1s` is two
 *   directives. Our peel loop's `ltrim` uses PHP's default char class
 *   (`" \t\n\r\0\x0B`), which matches.
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
 *   `./bin/sugarcrush` (identifier run-class bytes hold it together) and
 *   `Set Shell /bin/sh` (no boundary after `/bin/`) lex as one token, never
 *   as an opened regex.
 * - **Five delimiter pairs make `#` literal:** `"` `"`, `'` `'`, `` ` `` `` ` ``,
 *   `/` `/`, `{` `}`. All three quotes are literal INSIDE a regex, and a `#`
 *   inside any of them cannot hide the directive that follows the closer.
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
    public const TOKEN_JSON = 'JSON';
    public const TOKEN_REGEX = 'REGEX';
    public const TOKEN_UNKNOWN = 'UNKNOWN';
    public const TOKEN_COMMENT = 'COMMENT';

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
            $trimmed = trim($raw);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $tokens[] = new Token(self::TOKEN_COMMENT, $raw, $lineNum);
                continue;
            }

            // A single line may carry several directives, e.g.
            //   Down  Sleep 200ms        # move, then pause
            //   Type "f"  Sleep 300ms    # press a key, then pause
            // Upstream VHS treats the tape as a whitespace-delimited token
            // stream where newlines aren't significant, so we peel
            // directives off the front of the line until it's consumed.
            foreach ($this->lexLine($trimmed, $lineNum) as $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Tokenize one non-empty, non-comment line into one or more tokens.
     *
     * @return list<Token>
     */
    private function lexLine(string $line, int $lineNum): array
    {
        $tokens = [];
        $rest = $line;

        while (true) {
            $rest = ltrim($rest);
            if ($rest === '') {
                break;
            }
            // An inline comment runs to the end of the line.
            if ($rest[0] === '#') {
                $tokens[] = new Token(self::TOKEN_COMMENT, $rest, $lineNum);
                break;
            }

            [$token, $consumed] = $this->matchDirective($rest, $lineNum);
            if ($token === null || $consumed <= 0) {
                // Unclassifiable remainder — emit it whole as UNKNOWN and
                // stop so we never spin on a token we can't advance past.
                $tokens[] = new Token(self::TOKEN_UNKNOWN, $rest, $lineNum);
                break;
            }
            $tokens[] = $token;
            $rest = substr($rest, $consumed);
        }

        return $tokens;
    }

    /**
     * Match a single directive at the front of $s.
     *
     * @return array{0: ?Token, 1: int} the token (null when unmatched) and
     *         the number of bytes consumed off the front of $s.
     */
    private function matchDirective(string $s, int $lineNum): array
    {
        // Type takes one quoted argument — all three upstream quote kinds;
        // only the quoted run is consumed so a trailing Sleep / comment
        // stays on the line for the next pass.
        if (preg_match('/^Type\s+([\'"`])(.*?)\1/s', $s, $m)) {
            return [new Token(self::TOKEN_TYPE, $m[2], $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Sleep\s+(\d+(?:\.\d+)?)\s*(s|ms|m)(?=\s|$)/i', $s, $m)) {
            $seconds = $this->durationSeconds((float) $m[1], strtolower($m[2]));
            return [new Token(self::TOKEN_SLEEP, (string) $seconds, $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Wait\s+(\d+(?:\.\d+)?)\s*(s|ms|m)?(?=\s|$)/i', $s, $m)) {
            $seconds = $this->durationSeconds((float) $m[1], isset($m[2]) ? strtolower($m[2]) : 's');
            return [new Token(self::TOKEN_WAIT, (string) $seconds, $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Ctrl\+([A-Za-z@\[\]\\\\^_])(?=\s|$)/', $s, $m)) {
            return [new Token(self::TOKEN_CTRL, $m[1], $lineNum), strlen($m[0])];
        }

        // Bare keyword directives — anchored with a trailing boundary so
        // "Down" never swallows the start of a longer word.
        if (preg_match('/^(Enter|Tab|Backspace|Space|Escape|Hide|Show|Up|Down|Left|Right)(?=\s|$)/', $s, $m)) {
            $keyword = $m[1];
            $type = match ($keyword) {
                'Enter'     => self::TOKEN_ENTER,
                'Tab'       => self::TOKEN_TAB,
                'Backspace' => self::TOKEN_BACKSPACE,
                'Space'     => self::TOKEN_SPACE,
                'Escape'    => self::TOKEN_ESCAPE,
                'Hide'      => self::TOKEN_HIDE,
                'Show'      => self::TOKEN_SHOW,
                default     => self::TOKEN_ARROW, // Up / Down / Left / Right
            };
            return [new Token($type, $keyword, $lineNum), strlen($keyword)];
        }

        // `Set key <value>`: a `{` value is a JSON literal and a `/…/` value
        // is a regex literal — each ends AT ITS CLOSER, so a directive or
        // comment after it stays live on the same line (upstream's measured
        // delimiter behaviour). Anything else keeps the free-form
        // run-to-end-of-line value.
        if (preg_match('/^Set\s+(\S+)(\s+)/', $s, $m)) {
            $prefixLen = strlen($m[0]);
            $tail = substr($s, $prefixLen);
            if ($this->startsValueToken($tail, '{')) {
                [$value, $len] = $this->readJson($tail);
                return [new Token(self::TOKEN_SET, $m[1] . "\x00" . $value, $lineNum), $prefixLen + $len];
            }
            if ($this->startsValueToken($tail, '/')) {
                [$value, $len] = $this->readRegex($tail);
                return [new Token(self::TOKEN_SET, $m[1] . "\x00" . $value, $lineNum), $prefixLen + $len];
            }
            return [new Token(self::TOKEN_SET, $m[1] . "\x00" . $tail, $lineNum), strlen($s)];
        }

        if (preg_match('/^Env\s+(\S+)\s+["\'](.*?)["\']\s*$/', $s, $m)) {
            return [new Token(self::TOKEN_ENV, $m[1] . "\x00" . $m[2], $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Output\s+(.+)$/', $s, $m)) {
            return [new Token(self::TOKEN_OUTPUT, trim($m[1]), $lineNum), strlen($m[0])];
        }

        // Screenshot must be tried before Screen (shared prefix).
        if (preg_match('/^Screenshot\s+(.+)$/i', $s, $m)) {
            return [new Token(self::TOKEN_SCREENSHOT, $m[1], $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Screen\s+(.+)$/i', $s, $m)) {
            return [new Token(self::TOKEN_SCREEN, $m[1], $lineNum), strlen($m[0])];
        }

        if (preg_match('/^Source\s+(.+)$/i', $s, $m)) {
            return [new Token(self::TOKEN_SOURCE, trim($m[1]), $lineNum), strlen($m[0])];
        }

        // `Wait /re/` / `Wait {json}`: upstream opens the value token wherever
        // a token starts, and the duration arm above already declined, so peel
        // the keyword and emit the VALUE token by kind. candy-vcr has no
        // pattern-wait AST directive yet — the Parser's kind gate drops these
        // instead of the old TOKEN_UNKNOWN swallow hiding them.
        if (preg_match('/^Wait\s+/', $s, $m)) {
            $tail = substr($s, strlen($m[0]));
            if ($this->startsValueToken($tail, '{')) {
                [$value, $len] = $this->readJson($tail);
                return [new Token(self::TOKEN_JSON, $value, $lineNum), strlen($m[0]) + $len];
            }
            if ($this->startsValueToken($tail, '/')) {
                [$value, $len] = $this->readRegex($tail);
                return [new Token(self::TOKEN_REGEX, $value, $lineNum), strlen($m[0]) + $len];
            }
        }

        // Value tokens stand wherever a token starts, not only behind the
        // keywords that happen to read them upstream (`Set WaitPattern /re/`
        // and a line-initial regex are the same token). Reaching these arms
        // means no keyword matched, so a bare JSON/REGEX token is emitted
        // and the Parser's kind gate drops it.
        if ($this->startsValueToken($s, '{')) {
            [$value, $len] = $this->readJson($s);
            return [new Token(self::TOKEN_JSON, $value, $lineNum), $len];
        }
        if ($this->startsValueToken($s, '/')) {
            [$value, $len] = $this->readRegex($s);
            return [new Token(self::TOKEN_REGEX, $value, $lineNum), $len];
        }

        return [null, 0];
    }

    /**
     * Does $s open a value token of this delimiter that legitimately ENDS the
     * token — i.e. the closer is followed by whitespace / line-end? That
     * boundary gate is what keeps an identifier-run-class `/` (a `/` mid-run
     * never opens a regex upstream) from splitting `/bin/sh` into `/bin/` +
     * `sh`. Checked by scanning with the same terminator rules as the
     * reader, so synthesized-closer forms (`{` to EOL) pass too.
     */
    private function startsValueToken(string $s, string $open): bool
    {
        if (!str_starts_with($s, $open)) {
            return false;
        }
        if ($open === '{') {
            // JSON: either depth closes on a `}` (boundary-checked) or the
            // run reaches end-of-line and the closer is synthesized.
            $depth = 0;
            $len = strlen($s);
            for ($i = 0; $i < $len; $i++) {
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
        $len = strlen($s);
        for ($i = 1; $i < $len; $i++) {
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
     * Read a brace-balanced JSON literal at the front of $s (which starts
     * with `{`). `#` is literal inside the braces — only the balanced `}`
     * ends it — and a run with no closer consumes the rest of the line and
     * gets its closers synthesized (mirrors upstream `readJSON`).
     *
     * @return array{0: string, 1: int} the JSON text and bytes consumed.
     */
    private function readJson(string $s): array
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
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
                return [substr($s, 0, $i + 1), $i + 1];
            }
        }
        return [substr($s, 0, $len) . str_repeat('}', $depth), $len];
    }

    /**
     * Read a `/…/` regex literal at the front of $s. All three quote kinds are
     * literal inside it; only an unescaped closing `/` ends the token. An
     * unterminated run consumes the rest of the line unchanged (no closer is
     * synthesized — that courtesy belongs to the JSON reader only).
     *
     * @return array{0: string, 1: int} the literal (slashes kept) and bytes consumed.
     */
    private function readRegex(string $s): array
    {
        $len = strlen($s);
        for ($i = 1; $i < $len; $i++) {
            if ($s[$i] === '\\') {
                $i++;
                continue;
            }
            if ($s[$i] === '/') {
                return [substr($s, 0, $i + 1), $i + 1];
            }
        }
        return [$s, $len];
    }

    /**
     * Convert a tape duration + unit to seconds. The three upstream time
     * units are `s`, `ms`, `m` — anything else never reaches here (the
     * keyword regexes gate it).
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

/**
 * A single token from the lexer.
 */
final readonly class Token
{
    public function __construct(
        public string $type,
        public string $value,
        public int $line,
    ) {
    }
}
