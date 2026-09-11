<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * One implementation of the token depth walk that splits a call's argument
 * list into its top-level pieces, and the UNION of the justifications the
 * three copies carried.
 *
 * WHY THIS EXISTS AS A TRAIT AND NOT AS A FOURTH COPY (E174). The tree held
 * THREE declarations of this walk: `ChildStderrCaptureScanner::topLevelArguments()`,
 * `ChildLifetimeScanner::topLevelArguments()` and
 * `HeadlessPermissionPromptAttachmentTest::topLevelArguments()`. The first two
 * were already token-for-token the same rule spelled twice, and the third was
 * the same rule wearing a different contract - it returns token LISTS where the
 * scanners return index SPANS. Two different contracts is a legitimate reason
 * for two methods; two copies of the depth rules is the drift E174 recorded,
 * and the shape that removes it without touching any consumer's contract is
 * this: the WALK lives here once, and a consumer whose answer shape differs
 * adapts it in three lines beside its own call.
 *
 * THE ARRAY-TOKEN OPENERS ARE PART OF THE DEPTH WALK, and they are here because
 * the sibling stderr census shipped this same walk WITHOUT them and mis-counted
 * (E161): PHP 8.3.6 lexes `#[`, and the `{`/`${` of `"{$a}"` and `"${a}"`, as
 * ARRAY tokens whose closers it lexes as plain one-byte strings - so a walk
 * counting only `( [ {` sees a close with no open, reaches a spurious depth 0
 * and stops early. An interpolated first argument is an everyday shape, and
 * `Bootstrap::backendFor("p{$x}", …)` is exactly a call one consumer reads.
 *
 * THE THREE COPIES DISAGREED ABOUT WHICH OPENERS THEY NAMED, and the
 * consolidation had to decide. MEASURED before the move (round 67 lane dc,
 * PHP 8.3.6, both scanners run over every `.php` under `tests/` and `src/`):
 * the only `#[` inside any parenthesised range in the tree is a constructor
 * PARAMETER attribute (`src/Registry/Tool.php`'s `#[\SensitiveParameter]`),
 * no spawn call's argument range contains one, and re-running both full
 * censuses with the widened walk moved ZERO sites - stderr's 215 and
 * lifetime's 1706 answered line-for-line identically - so widening the two
 * scanners' alphabet to include `T_ATTRIBUTE` moves no answer in the tree -
 * the full set is the default for ALL consumers rather than a parameter,
 * because a shared walk whose opener set is chosen per caller is the same
 * "two instruments, one syntax, two views" disagreement E420 paid down at the
 * mixed-spec branch. `T_ATTRIBUTE` rides along for every walker: it is the
 * one spelling of `#[` PHP produces anywhere in a token stream, and a closer
 * for an opener a walker never counted is how the walk loses a level.
 *
 * WHY THE SCANNERS KEEP PASSING A BOUNDED RANGE AND THE TEST AN OPEN ONE.
 * {@see topLevelArguments()} walks between a caller-supplied pair of indices;
 * the scanners resolve their own closer through their own `matching()` before
 * asking for the split, and a guard that re-derived the closer here would be
 * a second closer algorithm to keep in step. {@see balancedClose()} is the
 * forward walk for callers that do not already have one, and it is the half
 * {@see topLevelArguments()}'s range contract assumes.
 *
 * EVERY CONSUMER KEEPS ITS OWN KNOWN-POSITIVE CONTROL, and sharing the code
 * is not sharing the control (rule 15, set by {@see FlattensSourceProseTrait}
 * and {@see DropsInsignificantTokensTrait} before it). A walk that silently
 * returned `[]` would turn every consuming census into "the call passes
 * nothing", and a census of an absence cannot tell that from a clean tree;
 * each consumer pins this method's answers on fixtures of its own before it
 * trusts them on a real file.
 */
trait SplitsTopLevelArgumentsTrait
{
    /**
     * The array tokens that OPEN a bracket whose closer is a plain string.
     *
     * `T_CURLY_OPEN` is `{$`, `T_DOLLAR_OPEN_CURLY_BRACES` is the deprecated
     * `${`, and `T_ATTRIBUTE` is `#[` - all three close with a one-byte
     * `}`/`]` that the lexer hands back as a string token.
     */
    private const ARRAY_TOKEN_OPENERS = [\T_ATTRIBUTE, \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES];

    /**
     * The token spans of the call's top-level arguments: the inclusive index
     * pairs of every argument between the `(` at $open and the closer at
     * $close, in order, with the `()` themselves excluded.
     *
     * A span with start > end is an EMPTY argument slot (it happens only on
     * the zero-argument call `( )` and on a trailing comma); consumers that
     * want non-empty pieces filter it, which is what the list-shaped consumer
     * does - the span contract itself stays the faithful one, because the
     * scanners render spans back to source text with their own index-based
     * `codeText()`.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{0:int,1:int}>
     */
    private static function topLevelArguments(array $tokens, int $open, int $close): array
    {
        $args = [];
        $depth = 0;
        $start = $open + 1;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];

            if (\is_array($token) && \in_array($token[0], self::ARRAY_TOKEN_OPENERS, true)) {
                // The opener was lexed as an ARRAY token while its closer is
                // a plain string; counting only the closer sends the depth
                // negative, and top-level commas after the interpolation
                // stop being seen at all.
                $depth++;

                continue;
            }

            if (!\is_string($token)) {
                continue;
            }

            if (\in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($token === ',' && $depth === 0) {
                $args[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        $args[] = [$start, $close - 1];

        return $args;
    }

    /**
     * The index at which the bracket opened at $openAt balances to depth 0,
     * or null when the stream ends first.
     *
     * The closer's own token answers which bracket closed - a caller walking
     * a call's argument list can demand `)` and treat any other answer as a
     * stream this walk will not guess about.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function balancedClose(array $tokens, int $openAt): ?int
    {
        $depth = 0;

        for ($i = $openAt, $n = \count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                if (\in_array($token[0], self::ARRAY_TOKEN_OPENERS, true)) {
                    $depth++;
                }

                continue;
            }

            if (\in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
