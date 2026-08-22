<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Reads the three facts that make {@see ReapsForkedChildrenTrait} actually
 * catch anything, as CODE rather than as text.
 *
 * WHY A SCANNER AND NOT THREE REGEXES. The first version of the adoption
 * guard was two line-anchored regexes, and both of them were satisfiable
 * without the net existing:
 *
 *  - `^[ \t]*use ... ReapsForkedChildrenTrait;` also matches a NAMESPACE
 *    IMPORT at the top of a file. Import the name, never use the trait, and
 *    the guard called the file adopting.
 *  - `^[ \t]*$this->reapTrackedForkedChildren(` matches the call ANYWHERE in
 *    the file. Moving it out of `tearDown()` into a private method nothing
 *    calls left the guard green (measured: mutation R2 SURVIVED), even though
 *    the entire argument for `tearDown()` is that PHPUnit swallows the
 *    time-limit `TimeoutException` and runs after-test hooks anyway. A call
 *    somewhere else in the file runs on no path at all.
 *
 * Both distinctions are structural, and a line anchor cannot express either.
 * PHP's own lexer can: a trait `use` sits at class-body depth, and a method
 * body has a first statement.
 *
 * NO SILENT SKIP. {@see reapPositionInTearDown()} answers with one of four
 * named verdicts and never with "cannot tell" - a file this scanner does not
 * understand comes back as a verdict the guard fails on, because a hole in an
 * instrument is shaped exactly like the next defect.
 */
final class ReaperAdoptionScanner
{
    /** No `tearDown()` method in the file at all. */
    public const TEARDOWN_MISSING = 'no-teardown-method';

    /** A `tearDown()` exists but never calls the reaper. */
    public const REAP_ABSENT = 'no-reap-call-in-teardown';

    /** The reaper is called, but something else in `tearDown()` runs first. */
    public const REAP_NOT_FIRST = 'reap-call-is-not-first';

    /** The reaper is the first statement of `tearDown()`. */
    public const REAP_FIRST = 'reap-call-is-first';

    /**
     * Whether the file USES the trait inside a class/trait/enum body, as
     * opposed to merely importing its name at namespace scope.
     */
    public static function adoptsTrait(string $source, string $trait): bool
    {
        $tokens = \token_get_all($source);

        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || !\in_array($token[0], [\T_CLASS, \T_TRAIT, \T_ENUM], true)) {
                continue;
            }
            // `$x instanceof Foo::class` and anonymous classes both reach
            // here; the body walk below simply finds nothing in that case.
            $body = self::bodyOf($tokens, $i);
            if ($body === null) {
                continue;
            }

            [$open, $close] = $body;
            $depth = 0;
            for ($j = $open + 1; $j < $close; $j++) {
                $text = self::text($tokens[$j]);
                if (\is_array($tokens[$j])
                    && \in_array($tokens[$j][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    // `"{$x}"` OPENS with an array token and CLOSES with a
                    // plain '}', so counting only the closer drives this
                    // count negative and every later class-body `use` sits at
                    // a depth that is no longer 0. Measured: a trait `use`
                    // placed after any method containing an interpolated
                    // string reported `adoptsTrait() = false`, i.e. the guard
                    // reddened a file that WAS adopting. Every other brace
                    // walker in this suite - {@see bodyOf()} below,
                    // {@see ForkedChildExitScanner::matching()},
                    // {@see ChildStderrCaptureScanner::topLevelArguments()} -
                    // already counted this token; this walk was the one that
                    // did not.
                    $depth++;

                    continue;
                }
                if (\is_string($tokens[$j]) && $text === '{') {
                    $depth++;

                    continue;
                }
                if (\is_string($tokens[$j]) && $text === '}') {
                    $depth--;

                    continue;
                }
                if ($depth !== 0 || !\is_array($tokens[$j]) || $tokens[$j][0] !== \T_USE) {
                    continue;
                }

                // A trait `use` at class-body depth. Collect the names it
                // lists until the clause ends.
                for ($k = $j + 1; $k < $close; $k++) {
                    $t = $tokens[$k];
                    if (\is_string($t) && ($t === ';' || $t === '{')) {
                        break;
                    }
                    if (!\is_array($t)) {
                        continue;
                    }
                    if (!\in_array($t[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                        continue;
                    }
                    $name = \ltrim($t[1], '\\');
                    $last = \strrchr($name, '\\');
                    if (($last === false ? $name : \substr($last, 1)) === $trait) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Where the reaper sits inside `tearDown()`.
     *
     * FIRST, and not merely present, because the ordering is the point: a
     * `tearDown()` that removes the test's temp tree before reaping deletes
     * the directory the orphans are still writing into, which is the observed
     * failure shape this whole mechanism exists for.
     */
    public static function reapPositionInTearDown(string $source, string $call): string
    {
        $tokens = \token_get_all($source);

        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== \T_FUNCTION) {
                continue;
            }
            $nameAt = self::nextSignificant($tokens, $i);
            if ($nameAt === null || !\is_array($tokens[$nameAt]) || $tokens[$nameAt][1] !== 'tearDown') {
                continue;
            }
            $body = self::bodyOf($tokens, $nameAt);
            if ($body === null) {
                continue;
            }

            [$open, $close] = $body;

            $first = self::nextSignificant($tokens, $open);
            $isFirst = $first !== null && $first < $close && self::isCallTo($tokens, $first, $call);

            if ($isFirst) {
                return self::REAP_FIRST;
            }

            for ($j = $open + 1; $j < $close; $j++) {
                if (self::isCallTo($tokens, $j, $call)) {
                    return self::REAP_NOT_FIRST;
                }
            }

            return self::REAP_ABSENT;
        }

        return self::TEARDOWN_MISSING;
    }

    /**
     * Whether `$tokens[$at]` begins `$this-><call>(`.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function isCallTo(array $tokens, int $at, string $call): bool
    {
        if (!\is_array($tokens[$at]) || $tokens[$at][0] !== \T_VARIABLE || $tokens[$at][1] !== '$this') {
            return false;
        }
        $arrow = self::nextSignificant($tokens, $at);
        if ($arrow === null || !\is_array($tokens[$arrow]) || $tokens[$arrow][0] !== \T_OBJECT_OPERATOR) {
            return false;
        }
        $name = self::nextSignificant($tokens, $arrow);
        if ($name === null || !\is_array($tokens[$name]) || $tokens[$name][1] !== $call) {
            return false;
        }
        $paren = self::nextSignificant($tokens, $name);

        return $paren !== null && self::text($tokens[$paren]) === '(';
    }

    /**
     * The `{ ... }` body that follows the declaration at $from, as a token
     * index pair, or null when there is none (an abstract method, an
     * interface, a `Foo::class` reference).
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:int,1:int}|null
     */
    private static function bodyOf(array $tokens, int $from): ?array
    {
        $open = null;
        for ($i = $from + 1, $n = \count($tokens); $i < $n; $i++) {
            $text = self::text($tokens[$i]);
            if (\is_string($tokens[$i]) && $text === ';') {
                return null;
            }
            if (\is_string($tokens[$i]) && $text === '{') {
                $open = $i;

                break;
            }
        }
        if ($open === null) {
            return null;
        }

        $depth = 0;
        for ($i = $open, $n = \count($tokens); $i < $n; $i++) {
            $text = self::text($tokens[$i]);
            if (\is_string($tokens[$i]) && $text === '{') {
                $depth++;
            } elseif (\is_array($tokens[$i])
                && \in_array($tokens[$i][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                // `{$x}` inside a string opens a brace whose closer is a
                // plain '}'; missing this makes the depth count go negative
                // and the body end early.
                $depth++;
            } elseif (\is_string($tokens[$i]) && $text === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$open, $i];
                }
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = \count($tokens); $i < $n; $i++) {
            if (\is_array($tokens[$i])
                && \in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param array{0:int,1:string,2:int}|string $token */
    private static function text(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}
