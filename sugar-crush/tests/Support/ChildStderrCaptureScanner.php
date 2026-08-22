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
 * THAT WAS A DOC-BLOCK AND IS NOW A SHAPE. It used to be stated here and
 * contradicted by the implementation, which read any `2>` as a capture and so
 * reported `2>/dev/null` - the exact thing the paragraph forbids - as
 * compliant. {@see SHAPE_DISCARDED} is the enforcement, and it names what
 * remains unenforced.
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

    /**
     * fd 2 is redirected somewhere NOBODY can read it - the null device.
     *
     * A distinct shape rather than folded into `inherited`, because it is a
     * different mistake with a different fix and a different argument for the
     * odd deliberate case. `inherited` says "you forgot a pipe"; this says
     * "you silenced the evidence". The guard reds both.
     *
     * WHAT THIS SCANNER USED TO SAY, in its own doc-block and in
     * {@see ChildStderrCaptureTest}'s: "treats `2>/dev/null` as captured
     * because it cannot tell a sink from a file". WHAT IS TRUE NOW: it can,
     * for the null device specifically, which is the only sink anybody
     * actually writes. WHY THE OLD SENTENCE STILL MATTERS: the general
     * statement is unchanged - `2>$someVariable` is a file to this scanner
     * whatever the variable holds, and a `2>` onto a path under `/proc` or a
     * fifo nothing reads is captured as far as the tokens go. The standard
     * being defended is "the TEST can read it", and only the commonest way of
     * failing it is now mechanised.
     */
    public const SHAPE_DISCARDED = 'discarded';

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
        $functions = TokenFunctionRanges::scan($tokens);
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
                    : self::classifyProcOpen($tokens, $open, $close, $functions),
            ];
        }

        return $sites;
    }

    /**
     * A shell command captures stderr when it says where fd 2 goes: `2>&1`
     * onto the stdout the caller is already reading, or `2>` a file it opens
     * afterwards. The null device is named separately; anything else inherits.
     */
    private static function classifyShell(string $argumentList): string
    {
        if (self::sendsFdTwoToTheNullDevice($argumentList)) {
            return self::SHAPE_DISCARDED;
        }

        return \str_contains($argumentList, '2>') ? self::SHAPE_CAPTURED : self::SHAPE_INHERITED;
    }

    /**
     * Whether a shell command sends fd 2 to `/dev/null`.
     *
     * THE ORDER OF REDIRECTIONS IS HONOURED, because the two orderings mean
     * opposite things and both appear in real commands. `>/dev/null 2>&1`
     * points fd 1 at the sink and then fd 2 at fd 1: discarded. `2>&1
     * >/dev/null` points fd 2 at whatever fd 1 was AT THAT MOMENT - for a
     * child of `exec()`/`proc_open()` that is the pipe the caller reads - and
     * only then moves fd 1 to the sink: a capture, and reporting it as a
     * discard would be a guard reddening correct code, which is how the next
     * real offender buys its exemption.
     *
     * The literal `/dev/null` is what is matched, not a general notion of a
     * sink. A path in a variable is a file as far as these tokens go; see
     * {@see SHAPE_DISCARDED} for what that leaves open.
     */
    private static function sendsFdTwoToTheNullDevice(string $text): bool
    {
        // `2>/dev/null`, `2>> /dev/null`, quoted or not.
        if (\preg_match('~2>>?\s*([\'"]?)/dev/null\1~', $text) === 1) {
            return true;
        }

        // bash's both-streams forms.
        if (\preg_match('~(?:&>>?|>&)\s*([\'"]?)/dev/null\1~', $text) === 1) {
            return true;
        }

        // `>/dev/null` FOLLOWED BY `2>&1`. The offset comparison is the order
        // check described above; without it the reversed form reads the same.
        return \preg_match(
            '~(?<![0-9&])1?>>?\s*([\'"]?)/dev/null\1~',
            $text,
            $stdout,
            \PREG_OFFSET_CAPTURE,
        ) === 1
            && \preg_match('~2>&1~', $text, $dup, \PREG_OFFSET_CAPTURE) === 1
            && $dup[0][1] > $stdout[0][1];
    }

    /**
     * `proc_open()` captures stderr when its descriptor spec names fd 2 - as
     * a pipe or as a file. The spec is resolved rather than searched for:
     * either it is an inline array in argument 2, or argument 2 is a variable
     * whose nearest preceding assignment holds the array. Neither, and the
     * site is `unclassified` rather than assumed innocent.
     *
     * WHAT "RESOLVED" USED TO MEAN, and what it means now.
     * {@see nearestAssignment()} walked BACKWARDS through the whole token
     * stream with no notion of scope, so a `$descriptors` assigned in an
     * earlier METHOD could answer for a spawn in a later one - and the
     * doc-block said so, adding that no file in the tree had that shape. It
     * still does not; that is the point. A guard whose only defence is "no
     * caller has this shape yet" is a guard with a hole waiting for the shape.
     * The walk is now floored at the opening brace of the enclosing named
     * function ({@see TokenFunctionRanges}), and an assignment it cannot find
     * inside that function makes the site `unclassified` - a failure - rather
     * than an answer borrowed from a different method.
     *
     * A CLOSURE IS NOT A FLOOR, deliberately: PHP closures capture by `use`,
     * so an assignment before the closure genuinely IS the one in effect
     * inside it, and the enclosing NAMED function is the honest boundary.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param list<array{name:string,from:int,to:int}> $functions
     */
    private static function classifyProcOpen(array $tokens, int $open, int $close, array $functions): string
    {
        $whole = self::codeText($tokens, $open, $close);

        // A redirection in the command string is applied by the shell and
        // decides the matter for whatever fd it names, before the descriptor
        // spec is ever consulted.
        if (self::sendsFdTwoToTheNullDevice($whole)) {
            return self::SHAPE_DISCARDED;
        }
        if (\str_contains($whole, '2>')) {
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
            return self::classifySpec(self::codeText($tokens, $from, $to));
        }

        if (\is_array($tokens[$first]) && $tokens[$first][0] === \T_VARIABLE) {
            // The floor: this spawn's own function body. A spec assigned
            // outside it is not this call's spec, and guessing that it is was
            // the defect this bound closes.
            $enclosing = TokenFunctionRanges::enclosing($functions, $first);
            $spec = self::nearestAssignment($tokens, $first, $tokens[$first][1], $enclosing['from'] ?? 0);

            if ($spec === null) {
                return self::SHAPE_UNCLASSIFIED;
            }

            return self::classifySpec($spec);
        }

        return self::SHAPE_UNCLASSIFIED;
    }

    /**
     * A `proc_open()` descriptor spec, read for what fd 2 is pointed at.
     */
    private static function classifySpec(string $spec): string
    {
        if (!self::namesFdTwo($spec)) {
            return self::SHAPE_INHERITED;
        }

        return self::fdTwoSpecIsTheNullDevice($spec) ? self::SHAPE_DISCARDED : self::SHAPE_CAPTURED;
    }

    private static function namesFdTwo(string $spec): bool
    {
        return \preg_match('/(^|[\[,\s])2\s*=>/', $spec) === 1;
    }

    /**
     * Whether fd 2's entry in a descriptor spec is `['file', '/dev/null', …]`.
     *
     * Only fd 2's own entry is inspected, so a spec that parks fd 0 on the
     * null device - which is ordinary and correct, a child with no stdin -
     * is not mistaken for a silenced stderr.
     */
    private static function fdTwoSpecIsTheNullDevice(string $spec): bool
    {
        if (\preg_match('~(?:^|[\[,\s])2\s*=>\s*\[([^\]]*)\]~', $spec, $entry) !== 1) {
            return false;
        }

        return \str_contains($entry[1], '/dev/null');
    }

    /**
     * The source of the nearest `$var = ...;` before $before, searching no
     * further back than $floor - the opening brace of the function the spawn
     * sits in, or 0 at file scope.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function nearestAssignment(array $tokens, int $before, string $variable, int $floor = 0): ?string
    {
        for ($i = $before - 1; $i >= $floor; $i--) {
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
