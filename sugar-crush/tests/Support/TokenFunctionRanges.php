<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * The token range of every named function/method in a `token_get_all()`
 * stream, so a site found anywhere in that stream can be attributed to the
 * function it sits in - and so a backwards walk from that site can be stopped
 * at the function's own opening brace.
 *
 * WHY IT IS SHARED RATHER THAN PRIVATE TO ONE SCANNER. It arrived inside
 * {@see ForkedChildExitScanner}, where a fork site's enclosing function is
 * what tells a fork WRAPPER's `return 0` from a child returning into the test
 * runner. {@see ChildStderrCaptureScanner} needed exactly the same bound for a
 * different reason - its `nearestAssignment()` walked backwards through the
 * whole file with no notion of scope, so a `$descriptors` assigned in an
 * earlier METHOD could answer for a `proc_open()` in a later one - and
 * re-deriving it there would have left two copies to drift apart. The
 * duplication is the defect this class exists to prevent, not a cost it pays.
 *
 * ANONYMOUS AND ARROW FUNCTIONS ARE DELIBERATELY ABSENT. They have no name to
 * attribute anything to, so a site inside one is attributed to the innermost
 * NAMED function around it, which is the honest answer for both callers: the
 * fork scanner asks "is this function called `forkTracked`", and the stderr
 * scanner asks "may I look back this far", and a closure is not a scope
 * boundary for either question - PHP closures capture by `use`, so an
 * assignment before the closure really is visible inside it.
 *
 * THE BRACE HANDLING IS THE POINT OF THIS CLASS, NOT AN AUXILIARY. Every
 * counter here dispatches on the two array tokens PHP uses to OPEN a brace
 * that a plain `}` closes, and {@see InterpolationOpenerTokenTest} requires
 * every file that counts brace depth to name every opener the running PHP
 * produces. E208 recorded that {@see ForkedChildExitScanner} carried a second
 * copy of the `matching()` walk beside this one, plus its own copy of the
 * opener pair inside `lastStatement()` - two instruments, one syntax, and
 * three edits to make when the language changes it. Both now call through
 * here: {@see matching()} is the single closer, and {@see opensBraceDepth()}
 * / {@see closesBraceDepth()} are the single depth rule. That fold is the
 * reason those three methods are public.
 */
final class TokenFunctionRanges
{
    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{name:string,from:int,to:int}> `from`/`to` are the
     *         indices of the body's opening and closing brace
     */
    public static function scan(array $tokens): array
    {
        $ranges = [];

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

            // Walk to the body, stepping over a return type; an abstract or
            // interface method ends at `;` and has no body to record.
            $brace = null;
            for ($j = $closeParen + 1, $n = \count($tokens); $j < $n; $j++) {
                $text = self::tokenText($tokens[$j]);
                if (\is_string($tokens[$j]) && $text === ';') {
                    break;
                }
                if (\is_string($tokens[$j]) && $text === '{') {
                    $brace = $j;

                    break;
                }
            }
            if ($brace === null) {
                continue;
            }
            $end = self::matching($tokens, $brace, '{', '}');
            if ($end === null) {
                continue;
            }

            $ranges[] = ['name' => $tokens[$nameAt][1], 'from' => $brace, 'to' => $end];
        }

        return $ranges;
    }

    /**
     * The INNERMOST named function whose body contains $at, or null when $at
     * is at file scope.
     *
     * @param list<array{name:string,from:int,to:int}> $ranges
     * @return array{name:string,from:int,to:int}|null
     */
    public static function enclosing(array $ranges, int $at): ?array
    {
        $best = null;
        $bestFrom = -1;

        foreach ($ranges as $range) {
            if ($at > $range['from'] && $at < $range['to'] && $range['from'] > $bestFrom) {
                $best = $range;
                $bestFrom = $range['from'];
            }
        }

        return $best;
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

    /**
     * The index of the token closing the bracket opened at $openAt, or null
     * when the walk reaches the end of the stream without levelling out.
     *
     * PUBLIC BY E208'S FOLD: this walk existed twice - here and inside
     * {@see ForkedChildExitScanner} - byte-for-byte, opener roster and all.
     * One instrument, named in one place; the other copy was deleted rather
     * than kept in step.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    public static function matching(array $tokens, int $openAt, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $openAt, $n = \count($tokens); $i < $n; $i++) {
            if ($open === '{' ? self::opensBraceDepth($tokens[$i]) : (\is_string($tokens[$i]) && $tokens[$i] === $open)) {
                $depth++;
            } elseif ($close === '}' ? self::closesBraceDepth($tokens[$i]) : (\is_string($tokens[$i]) && $tokens[$i] === $close)) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Whether a token adds a level to a brace-depth count: the bare `{`, or
     * one of the array tokens PHP uses to open an interpolation whose closer
     * is a plain `}`.
     *
     * THE ROSTER IS DERIVED, NOT WRITTEN, and {@see InterpolationOpenerTokenTest}
     * asks the running interpreter what the list is and requires this file -
     * the one home of the rule - to name all of it. A missed opener is not a
     * crash: a walk loses a level and the scanner silently stops seeing
     * anything past the first interpolated string.
     */
    public static function opensBraceDepth(array|string $token): bool
    {
        if (\is_string($token)) {
            return $token === '{';
        }

        return \in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true);
    }

    /**
     * Whether a token removes a level from a brace-depth count. The CLOSER of
     * an interpolation is always the bare `}` - which is the asymmetry that
     * makes {@see opensBraceDepth()} the half a walker forgets.
     */
    public static function closesBraceDepth(array|string $token): bool
    {
        return \is_string($token) && $token === '}';
    }
}
