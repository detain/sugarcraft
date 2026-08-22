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
                // `{$x}` inside a string opens a brace whose closer is a plain '}'.
                $depth++;
            }
        }

        return null;
    }
}
