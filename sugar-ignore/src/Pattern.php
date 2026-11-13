<?php

declare(strict_types=1);

namespace SugarCraft\Ignore;

/**
 * One compiled gitignore line: the token list plus the flags the matcher needs.
 *
 * A pattern is compiled to a token list ({@see tokenize()}) that serves two
 * matchers with the SAME language — PCRE for the cheap shapes
 * ({@see backtracksSafely()}) and the backtrack-free {@see Automaton}
 * otherwise. WHY two: a cloned repository supplies both the ignore file and
 * the paths it is matched against, so a pattern is hostile input; one 45-byte
 * `**a**a**a…**c` line cost ~70 ms per path through PCRE and then ended in a
 * backtrack-limit error that `=== 1` read as "no match" (fail OPEN).
 */
final class Pattern
{
    /**
     * @param list<array{0: int, 1: string}> $tokens
     */
    private function __construct(
        public readonly string $regex,
        public readonly array $tokens,
        public readonly bool $pcre,
        public readonly bool $dirOnly,
        public readonly bool $negate,
        public readonly string $source,
    ) {}

    /**
     * Parse one ignore-file line; null for blanks, comments and lines git
     * itself would skip (a malformed bracket expression is a broken line in
     * someone's file, not a reason to fail the search).
     */
    public static function parse(string $line): ?self
    {
        // Trailing whitespace is not part of a pattern unless escaped. Leading
        // whitespace IS, so only the right side is trimmed.
        $line = self::trimUnescapedTrailingSpace($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }
        $source = $line;

        $negate = str_starts_with($line, '!');
        if ($negate) {
            $line = substr($line, 1);
        } elseif (str_starts_with($line, '\\#') || str_starts_with($line, '\\!')) {
            // `\#foo` / `\!foo` are literals; the backslash is not part of the
            // name and would otherwise escape the character into the regex
            // twice.
            $line = substr($line, 1);
        }

        $dirOnly = str_ends_with($line, '/');
        if ($dirOnly) {
            $line = rtrim($line, '/');
        }

        if ($line === '') {
            return null;
        }

        $anchored = str_starts_with($line, '/');
        if ($anchored) {
            $line = ltrim($line, '/');
        } elseif (str_contains($line, '/')) {
            // git: a separator anywhere but the end anchors the pattern to the
            // .gitignore's own directory.
            $anchored = true;
        }

        if ($line === '') {
            return null;
        }

        $tokens = self::tokenize($line);
        // Classified BEFORE the implicit prefix is added: that prefix only
        // ever splits at `/`, so it multiplies the cost by the path's depth
        // rather than compounding with the pattern's own wildcards.
        $pcre = self::backtracksSafely($tokens);
        if (!$anchored) {
            // git's "match at any level below": a bare `build` matches
            // `a/b/build`. An anchored pattern gets no such prefix, so
            // `/build` is the top-level one only.
            array_unshift($tokens, [Token::DIRS, '']);
        }

        $regex = self::regexFor($tokens);
        if (!self::compiles($regex)) {
            // A malformed bracket expression is a broken line in someone's
            // .gitignore, not a reason to fail the search. Git skips what it
            // cannot parse; so does this.
            return null;
        }

        return new self($regex, $tokens, $pcre, $dirOnly, $negate, $source);
    }

    /** Does the compiled regex accept $subject? Null when PCRE errored. */
    public function pcreMatch(string $subject): ?bool
    {
        $result = preg_match($this->regex, $subject);

        return $result === false ? null : $result === 1;
    }

    /**
     * Can PCRE run this pattern without catastrophic backtracking?
     *
     * Backtracking explodes when unbounded wildcards can trade the same text
     * between them: `.*a.*a.*c` tries every split of the path among its `.*`s.
     * Allowed: at most two wildcards, at most one of which crosses `/` (`**`
     * or `**\/`). That keeps every real-world shape — `*.log`, `*.min.*`,
     * `**\/*.php`, `foo/**`, `a/**\/b` — on the fast path, and its worst case
     * is quadratic, which PCRE's backtrack limit then bounds and
     * {@see IgnoreRules} answers exactly via the NFA. Anything more goes straight
     * to the NFA rather than paying the limit first on every path.
     *
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function backtracksSafely(array $tokens): bool
    {
        $wildcards = 0;
        $crossing = 0;
        foreach ($tokens as [$type]) {
            if ($type === Token::STAR) {
                $wildcards++;
            } elseif ($type === Token::GLOBSTAR || $type === Token::DIRS) {
                $wildcards++;
                $crossing++;
            }
        }

        return $wildcards <= 2 && $crossing <= 1;
    }

    /**
     * Strip trailing spaces and tabs that are not backslash-escaped.
     *
     * `foo\ ` is a pattern for a name ending in a space; `foo   ` is a pattern
     * for `foo` written carelessly. Only the second may be trimmed.
     */
    private static function trimUnescapedTrailingSpace(string $line): string
    {
        $end = strlen($line);
        while ($end > 0 && ($line[$end - 1] === ' ' || $line[$end - 1] === "\t")) {
            // Count the backslashes immediately before this space: an odd
            // number means the space is escaped and the trim stops here.
            $slashes = 0;
            $probe = $end - 2;
            while ($probe >= 0 && $line[$probe] === '\\') {
                $slashes++;
                $probe--;
            }
            if ($slashes % 2 === 1) {
                break;
            }
            $end--;
        }

        return substr($line, 0, $end);
    }

    /**
     * Split one gitignore pattern into matcher tokens, each byte of it
     * accounted for exactly once.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function tokenize(string $pattern): array
    {
        $tokens = [];
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $tokens[] = [Token::LITERAL, $pattern[++$i]];
                continue;
            }

            if ($char === '*') {
                if (($pattern[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($pattern[$i + 1] ?? '') === '/') {
                        $i++;
                        // Zero OR MORE directories, so `a/**\/b` matches `a/b`.
                        $tokens[] = [Token::DIRS, ''];
                    } else {
                        $tokens[] = [Token::GLOBSTAR, ''];
                    }
                    continue;
                }

                $tokens[] = [Token::STAR, ''];
                continue;
            }

            if ($char === '?') {
                $tokens[] = [Token::ONE, ''];
                continue;
            }

            if ($char === '[') {
                $compiled = self::compileClass($pattern, $i);
                if ($compiled !== null) {
                    $tokens[] = [Token::CLASS_, $compiled['regex']];
                    $i = $compiled['end'];
                    continue;
                }
                // Unterminated `[` is a literal, as it is to fnmatch().
            }

            $tokens[] = [Token::LITERAL, $char];
        }

        return $tokens;
    }

    /**
     * The anchored PCRE for $tokens, matched against a path relative to the
     * `.gitignore`'s own directory.
     *
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function regexFor(array $tokens): string
    {
        $out = '';
        foreach ($tokens as [$type, $payload]) {
            $out .= match ($type) {
                Token::LITERAL => preg_quote($payload, '#'),
                Token::ONE => '[^/]',
                Token::CLASS_ => $payload,
                Token::STAR => '[^/]*',
                Token::GLOBSTAR => '.*',
                Token::DIRS => '(?:[^/]+/)*',
            };
        }

        return '#^' . $out . '$#';
    }

    /**
     * Translate the bracket expression starting at $start into a PCRE class.
     *
     * POSIX puts a literal `]` first in the class, so `[]]` means "a literal
     * ]"; searching for the terminator from position 1 would find that leading
     * `]` and emit the empty — and invalid — class `[]`.
     *
     * @return array{regex: string, end: int}|null null when unterminated
     */
    private static function compileClass(string $pattern, int $start): ?array
    {
        $bodyStart = $start + 1;
        if (($pattern[$bodyStart] ?? '') === '!' || ($pattern[$bodyStart] ?? '') === '^') {
            $bodyStart++;
        }
        if (($pattern[$bodyStart] ?? '') === ']') {
            $bodyStart++;
        }

        $close = strpos($pattern, ']', $bodyStart);
        if ($close === false) {
            return null;
        }

        $body = substr($pattern, $start + 1, $close - $start - 1);
        if (str_starts_with($body, '!')) {
            $body = '^' . substr($body, 1);
        }

        // `#` is the delimiter and ends the pattern even inside a class, so it
        // has to be escaped here as well as outside.
        return [
            'regex' => '[' . str_replace(['\\', '#', ']'], ['\\\\', '\\#', '\\]'], $body) . ']',
            'end' => $close,
        ];
    }

    /**
     * Does $regex compile?
     *
     * The handler swap is not belt-and-braces over `@`: `@` only lowers
     * error_reporting, so an ambient convert-warnings-to-exceptions handler
     * still sees the compilation failure. Probing a stranger's `.gitignore`
     * must be silent by construction.
     *
     * Only a COMPILE failure (PREG_INTERNAL_ERROR) counts. The probe also
     * runs a match, and a pattern that exhausts the backtrack or JIT-stack
     * limit even against '' is valid but expensive — {@see IgnoreRules} decides
     * it via the automaton. Reading that as "malformed" dropped the rule, the
     * same fail-open the match path had.
     */
    private static function compiles(string $regex): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($regex, '') !== false || preg_last_error() !== PREG_INTERNAL_ERROR;
        } finally {
            restore_error_handler();
        }
    }
}
