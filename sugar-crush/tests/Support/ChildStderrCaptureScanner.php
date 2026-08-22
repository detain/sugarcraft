<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Finds every child-process launch in a test file and says whether the child's
 * stderr is CAPTURED or left to fall through onto the PHPUnit process's own.
 *
 * A full `vendor/bin/phpunit` prints a run of `sugarcrush: ` lines - count
 * them with `vendor/bin/phpunit 2>&1 | grep -ac 'sugarcrush: '`, where the
 * `-a` is load-bearing: plain `grep` classifies that log as binary and prints
 * NOTHING, which is indistinguishable from a real zero. The figure itself is
 * deliberately not written down here, because a count taken over the suite is
 * invalidated by the next lane that merges and this doc-block would then be
 * confidently wrong; the command is the measurement.
 *
 * Some of those lines come from a child whose stderr nobody plugged in, and
 * for THOSE the line is not a finding about `src/` at all - it is a spawn site
 * that forgot a pipe.
 *
 * SILENCING IS THE WRONG DEFAULT and this scanner is deliberately not built to
 * support it: for most of these shapes the line IS the assertion, and a
 * `2>/dev/null` would delete the evidence the test exists to read. What is
 * required is that the line goes somewhere the TEST can read - a pipe, a file,
 * a `2>&1` merge - so it is available to be asserted on rather than dumped on
 * whoever is watching the suite.
 *
 * WHAT IT CANNOT SEE, said out loud because the number above is mostly NOT
 * this: an in-process `fwrite(\STDERR, ...)`. `src/Cli/NonInteractive.php`
 * writes on it directly in several places, and a test that calls that code - as
 * `tests/Cli/NonInteractiveProviderFailureTest.php` and
 * `tests/Cli/NonInteractiveTest.php` do, between them for most of the run -
 * is writing on the suite's own stderr with no child process anywhere in the
 * picture. No amount of
 * per-spawn redirection touches one of those; they need a sink seam in
 * `src/`. This scanner is about the other kind, and reports honestly that
 * under `tests/Integration/` there is currently none of it.
 */
final class ChildStderrCaptureScanner
{
    /** Launch functions whose command is a shell string. */
    private const SHELL_SPAWNS = ['exec', 'shell_exec', 'passthru', 'system', 'popen'];

    /** The child's stderr reaches the test. */
    public const SHAPE_CAPTURED = 'captured';

    /** The child's stderr lands on the PHPUnit process's own. */
    public const SHAPE_INHERITED = 'inherited';

    /** The scanner could not resolve where fd 2 goes. */
    public const SHAPE_UNCLASSIFIED = 'unclassified';

    /**
     * The `call` reported for a backtick shell execution, which has no
     * function name to report.
     *
     * Backticks were outside this scanner's alphabet entirely - not
     * `unclassified`, which is a failure and would have been fine, but SILENT:
     * no site, nothing to act on. A guard must go red on what it cannot parse,
     * because a hole in the alphabet is shaped exactly like the next defect,
     * and an alphabet is usually written to match the cases already known.
     */
    public const CALL_BACKTICK = 'shell_exec (backticks)';

    /**
     * @return list<array{line:int,call:string,shape:string}>
     */
    public static function scan(string $source): array
    {
        $tokens = \token_get_all($source);
        $sites = [];

        foreach ($tokens as $i => $token) {
            if (\is_string($token) && $token === '`') {
                $end = self::matchingBacktick($tokens, $i);
                if ($end === null) {
                    continue;
                }
                $sites[] = [
                    'line' => self::lineOf($tokens, $i),
                    'call' => self::CALL_BACKTICK,
                    'shape' => self::classifyShell(self::codeText($tokens, $i, $end)),
                ];
                continue;
            }

            // T_NAME_FULLY_QUALIFIED, not T_STRING, is what PHP 8's lexer
            // hands back for the `\proc_open(...)` spelling - and that is the
            // spelling `BinSugarcrushDispatchTest` uses throughout. A first
            // version of this scanner matched only T_STRING and reported that
            // file as having no spawn sites at all, which is exactly the
            // shape of a confident false green: the answer was already known
            // (its runBin() pipes fd 2) and the instrument said nothing.
            if (!\is_array($token)
                || !\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = \ltrim(\strtolower($token[1]), '\\');
            $isShell = \in_array($name, self::SHELL_SPAWNS, true);
            if (!$isShell && $name !== 'proc_open') {
                continue;
            }

            $open = self::next($tokens, $i);
            if ($open === null || self::tokenText($tokens[$open]) !== '(') {
                continue;
            }

            // `$this->exec(...)`, `Foo::exec(...)` and `function exec(...)`
            // are not the global launcher.
            $prev = self::prev($tokens, $i);
            if ($prev !== null && \is_array($tokens[$prev])
                && \in_array($tokens[$prev][0], [\T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $close = self::matching($tokens, $open, '(', ')');
            if ($close === null) {
                continue;
            }

            $sites[] = [
                'line' => $token[2],
                'call' => $name,
                'shape' => $isShell
                    ? self::classifyShell(self::codeText($tokens, $open, $close))
                    : self::classifyProcOpen($tokens, $open, $close),
            ];
        }

        return $sites;
    }

    /**
     * A shell command captures stderr when it says where fd 2 goes: `2>&1`
     * onto the stdout the caller is already reading, or `2>` a file it opens
     * afterwards. Anything else inherits.
     */
    private static function classifyShell(string $argumentList): string
    {
        return \str_contains($argumentList, '2>') ? self::SHAPE_CAPTURED : self::SHAPE_INHERITED;
    }

    /**
     * `proc_open()` captures stderr when its descriptor spec names fd 2 - as
     * a pipe or as a file. The spec is resolved rather than searched for:
     * either it is an inline array in argument 2, or argument 2 is a variable
     * whose nearest preceding assignment holds the array. Neither, and the
     * site is `unclassified` rather than assumed innocent.
     *
     * THE LIMIT OF "RESOLVED": {@see nearestAssignment()} walks BACKWARDS
     * through the token stream with no notion of scope, so a `$descriptors`
     * assigned in an earlier method can answer for a spawn in a later one. No
     * file in the tree currently has that shape - every resolved spec is
     * assigned in the same method that spawns - but the word "resolved" is
     * doing more work in the sentence above than the implementation does, and
     * the next reader should know which.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function classifyProcOpen(array $tokens, int $open, int $close): string
    {
        // A `2>` in the command string redirects before the descriptor spec
        // is ever consulted, so it counts too.
        if (\str_contains(self::codeText($tokens, $open, $close), '2>')) {
            return self::SHAPE_CAPTURED;
        }

        $arguments = self::topLevelArguments($tokens, $open, $close);
        if (!isset($arguments[1])) {
            return self::SHAPE_UNCLASSIFIED;
        }

        [$from, $to] = $arguments[1];
        $first = self::next($tokens, $from - 1);
        if ($first === null || $first > $to) {
            return self::SHAPE_UNCLASSIFIED;
        }

        if (self::tokenText($tokens[$first]) === '[' || (\is_array($tokens[$first]) && $tokens[$first][0] === \T_ARRAY)) {
            return self::namesFdTwo(self::codeText($tokens, $from, $to))
                ? self::SHAPE_CAPTURED
                : self::SHAPE_INHERITED;
        }

        if (\is_array($tokens[$first]) && $tokens[$first][0] === \T_VARIABLE) {
            $spec = self::nearestAssignment($tokens, $first, $tokens[$first][1]);

            if ($spec === null) {
                return self::SHAPE_UNCLASSIFIED;
            }

            return self::namesFdTwo($spec) ? self::SHAPE_CAPTURED : self::SHAPE_INHERITED;
        }

        return self::SHAPE_UNCLASSIFIED;
    }

    private static function namesFdTwo(string $spec): bool
    {
        return \preg_match('/(^|[\[,\s])2\s*=>/', $spec) === 1;
    }

    /**
     * The source of the nearest `$var = ...;` before $before.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function nearestAssignment(array $tokens, int $before, string $variable): ?string
    {
        for ($i = $before - 1; $i >= 0; $i--) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== \T_VARIABLE || $tokens[$i][1] !== $variable) {
                continue;
            }

            $equals = self::next($tokens, $i);
            if ($equals === null || self::tokenText($tokens[$equals]) !== '=') {
                continue;
            }

            for ($j = $equals + 1, $n = \count($tokens); $j < $n; $j++) {
                if (\is_string($tokens[$j]) && $tokens[$j] === ';') {
                    return self::codeText($tokens, $equals + 1, $j);
                }
                if (\is_string($tokens[$j]) && $tokens[$j] === '[') {
                    $end = self::matching($tokens, $j, '[', ']');
                    if ($end === null) {
                        return null;
                    }
                    $j = $end;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * The token spans of the call's top-level arguments.
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
            $text = self::tokenText($tokens[$i]);
            if (\is_array($tokens[$i])
                && \in_array($tokens[$i][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                // `"{$x}"` opens with an ARRAY token and closes with a plain
                // '}'. Counting only the closer sent the depth negative, and
                // top-level commas after an interpolated string stopped being
                // seen at all: a correctly-capturing
                // `proc_open("php {$script}", [... 2 => ['pipe','w']], $p)`
                // came back `unclassified`. A guard that reds correct code
                // invites an exemption, and the exemption is where the next
                // real offender hides.
                $depth++;
            } elseif (\is_string($tokens[$i]) && \in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (\is_string($tokens[$i]) && \in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif (\is_string($tokens[$i]) && $text === ',' && $depth === 0) {
                $args[] = [$start, $i - 1];
                $start = $i + 1;
            }
        }
        $args[] = [$start, $close - 1];

        return $args;
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
            if (!\is_string($tokens[$i])) {
                if ($open === '{' && \is_array($tokens[$i])
                    && \in_array($tokens[$i][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }

                continue;
            }
            if ($tokens[$i] === $open) {
                $depth++;
            } elseif ($tokens[$i] === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The closing backtick of the shell execution opened at $openAt.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function matchingBacktick(array $tokens, int $openAt): ?int
    {
        for ($i = $openAt + 1, $n = \count($tokens); $i < $n; $i++) {
            if (\is_string($tokens[$i]) && $tokens[$i] === '`') {
                return $i;
            }
        }

        return null;
    }

    /**
     * The line a token sits on; punctuation carries none, so the nearest
     * preceding token that does answers for it.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function lineOf(array $tokens, int $at): int
    {
        for ($i = $at; $i >= 0; $i--) {
            if (\is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 0;
    }

    /**
     * Source text with COMMENTS REMOVED.
     *
     * The ONLY renderer in this scanner - the raw one it superseded had no
     * remaining callers once every `2>` window moved here, so the two were
     * consolidated rather than left side by side for the next reader to pick
     * the wrong one.
     *
     * `2>` is looked for with comments dropped because a
     * doc-block wraps and a comment is source text: `shell_exec("php x" /* 2> *\/)`
     * read as CAPTURED while its child's stderr went straight onto the
     * suite's. Prose about a redirection is not a redirection.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function codeText(array $tokens, int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            if (\is_array($tokens[$i])
                && \in_array($tokens[$i][0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= self::tokenText($tokens[$i]);
        }

        return $out;
    }

}
