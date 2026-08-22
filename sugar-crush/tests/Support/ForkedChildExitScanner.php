<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Finds every IN-PROCESS fork in a test file and classifies how its child
 * branch leaves.
 *
 * WHY A TOKENIZER AND NOT A REGEX. Half the `pcntl_fork()` occurrences in
 * `tests/` are TEXT: they sit inside a heredoc that a test writes to disk and
 * runs as a separate `php` process (see `tests/Chat/ToolChildReapTest.php`,
 * `tests/Hooks/ScriptHookTest.php`, `tests/Providers/TransientFailureTest.php`,
 * `tests/Sessions/BackgroundSessionRunnerTest.php`). A child forked inside a
 * standalone script is not inside PHPUnit and a plain `exit()` there is
 * correct - the convention this scanner exists to police does not apply to
 * them at all, and a regex cannot tell the two apart. PHP's own lexer can:
 * heredoc bodies come back as `T_ENCAPSED_AND_WHITESPACE`, so `pcntl_fork`
 * inside one never becomes a `T_STRING`.
 *
 * WHAT IT REFUSES TO GUESS. Every site is classified into one of the shapes
 * below and there is no "skip" - a site whose child branch the scanner cannot
 * find, or whose terminator it does not recognise, comes back as
 * `unclassified` or `falls-through`, both of which are failures. A guard that
 * quietly ignores what it cannot parse has a hole shaped exactly like the next
 * defect.
 */
final class ForkedChildExitScanner
{
    /**
     * The call spellings that put a second copy of the PHPUnit process on the
     * machine. `forkTracked()` is {@see ReapsForkedChildrenTrait}'s wrapper
     * around `pcntl_fork()`; it is listed here because adopting the reaper
     * trait replaces the raw call at a site and must not thereby make the site
     * invisible to this scanner.
     */
    private const FORK_SPELLINGS = ['pcntl_fork', 'forkTracked'];

    /** Leaving shapes that never run PHP's shutdown sequence. */
    public const SHAPE_EXIT_NOW = 'exitNow';

    /** A call to a same-file method declared `: never`. */
    public const SHAPE_NEVER_HELPER = 'never-helper';

    /** A bare `exit(...)` / `die(...)`: runs PHPUnit's shutdown a second time. */
    public const SHAPE_BARE_EXIT = 'bare-exit';

    /** The branch ends without leaving at all: returns into the test runner. */
    public const SHAPE_FALLS_THROUGH = 'falls-through';

    /** The scanner could not find a child branch it understands. */
    public const SHAPE_UNCLASSIFIED = 'unclassified';

    /**
     * @return list<array{line:int,spelling:string,shape:string}>
     */
    public static function scan(string $source): array
    {
        $tokens = \token_get_all($source);
        $never = self::neverReturningMethods($tokens);

        /** @var list<array{line:int,spelling:string,shape:string}> $sites */
        $sites = [];

        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== \T_STRING || !\in_array($token[1], self::FORK_SPELLINGS, true)) {
                continue;
            }
            if (self::next($tokens, $i) === null || self::tokenText($tokens[self::next($tokens, $i)]) !== '(') {
                continue;
            }
            // `function forkTracked()` is the definition, not a call site.
            $prev = self::prev($tokens, $i);
            if ($prev !== null && \is_array($tokens[$prev]) && $tokens[$prev][0] === \T_FUNCTION) {
                continue;
            }

            $sites[] = [
                'line' => $token[2],
                'spelling' => $token[1],
                'shape' => self::classify($tokens, $i, $never),
            ];
        }

        return $sites;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param list<string> $never
     */
    private static function classify(array $tokens, int $forkAt, array $never): string
    {
        $openBrace = null;
        $closeBrace = null;

        // A fork site is conventionally followed by TWO branches - the
        // `=== -1` failure branch and the `=== 0` child branch - in either
        // order, and sometimes with only one of them present. So this walks
        // the `if`s that follow rather than assuming the first one is the
        // child's, and stops at whichever boundary comes first.
        for ($i = $forkAt + 1, $n = \count($tokens); $i < $n; $i++) {
            if (\is_array($tokens[$i]) && $tokens[$i][0] === \T_STRING
                && \in_array($tokens[$i][1], self::FORK_SPELLINGS, true)) {
                // The next fork begins; this one never got a child branch.
                return self::SHAPE_UNCLASSIFIED;
            }
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_IF) {
                continue;
            }

            $openParen = self::next($tokens, $i);
            if ($openParen === null || self::tokenText($tokens[$openParen]) !== '(') {
                return self::SHAPE_UNCLASSIFIED;
            }
            $closeParen = self::matching($tokens, $openParen, '(', ')');
            if ($closeParen === null) {
                return self::SHAPE_UNCLASSIFIED;
            }

            $condition = self::text($tokens, $openParen + 1, $closeParen - 1);

            // The fork-FAILED branch. Skipped rather than treated as a
            // mystery: it is half the convention, and it never contains a
            // child.
            if (\preg_match('/[=!]==?\s*-\s*1\b|\B-\s*1\s*[=!]==?/', $condition) === 1) {
                $i = $closeParen;

                continue;
            }

            // The CHILD branch: an equality against literal 0, in either
            // operand order. `!== 0` is the parent's branch and is not it.
            $isChild = \preg_match('/(?<![!])===?\s*0\b|\b0\s*===?(?![=])/', $condition) === 1
                && \preg_match('/!==?\s*0\b|\b0\s*!==?/', $condition) !== 1;

            if (!$isChild) {
                return self::SHAPE_UNCLASSIFIED;
            }

            $brace = self::next($tokens, $closeParen);
            if ($brace === null || self::tokenText($tokens[$brace]) !== '{') {
                return self::SHAPE_UNCLASSIFIED;
            }
            $end = self::matching($tokens, $brace, '{', '}');
            if ($end === null) {
                return self::SHAPE_UNCLASSIFIED;
            }

            $openBrace = $brace;
            $closeBrace = $end;
            break;
        }

        if ($openBrace === null || $closeBrace === null) {
            return self::SHAPE_UNCLASSIFIED;
        }

        $body = self::text($tokens, $openBrace, $closeBrace);

        if (\str_contains($body, 'ForkedChild::exitNow')) {
            return self::SHAPE_EXIT_NOW;
        }

        foreach ($never as $method) {
            if (\str_contains($body, '$this->' . $method . '(') || \str_contains($body, 'self::' . $method . '(')) {
                return self::SHAPE_NEVER_HELPER;
            }
        }

        for ($i = $openBrace + 1; $i < $closeBrace; $i++) {
            if (\is_array($tokens[$i]) && $tokens[$i][0] === \T_EXIT) {
                return self::SHAPE_BARE_EXIT;
            }
        }

        return self::SHAPE_FALLS_THROUGH;
    }

    /**
     * Method names in this file declared with a `never` return type - the
     * language's own way of saying "does not come back", which is exactly the
     * property a child branch delegating to a helper needs.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<string>
     */
    private static function neverReturningMethods(array $tokens): array
    {
        $names = [];
        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== \T_FUNCTION) {
                continue;
            }
            $nameAt = self::next($tokens, $i);
            if ($nameAt === null || !\is_array($tokens[$nameAt]) || $tokens[$nameAt][0] !== \T_STRING) {
                continue;
            }
            $openParen = self::next($tokens, $nameAt);
            if ($openParen === null || self::tokenText($tokens[$openParen]) !== '(') {
                continue;
            }
            $closeParen = self::matching($tokens, $openParen, '(', ')');
            if ($closeParen === null) {
                continue;
            }
            $colon = self::next($tokens, $closeParen);
            if ($colon === null || self::tokenText($tokens[$colon]) !== ':') {
                continue;
            }
            $type = self::next($tokens, $colon);
            if ($type !== null && \is_array($tokens[$type]) && $tokens[$type][0] === \T_STRING
                && \strtolower($tokens[$type][1]) === 'never') {
                $names[] = $tokens[$nameAt][1];
            }
        }

        return $names;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private static function tokenText(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function next(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = \count($tokens); $i < $n; $i++) {
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function prev(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function matching(array $tokens, int $openAt, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $openAt, $n = \count($tokens); $i < $n; $i++) {
            $text = self::tokenText($tokens[$i]);
            if (\is_string($tokens[$i]) && $text === $open) {
                $depth++;
            } elseif (\is_string($tokens[$i]) && $text === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            } elseif ($open === '{' && \is_array($tokens[$i])
                && \in_array($tokens[$i][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                // `{$x}` inside a string opens a brace the closer is a plain '}'.
                $depth++;
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function text(array $tokens, int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            $out .= self::tokenText($tokens[$i]);
        }

        return $out;
    }
}
