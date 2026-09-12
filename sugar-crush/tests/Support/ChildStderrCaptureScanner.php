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
 * `src/`. This scanner is about the other kind.
 *
 * THAT SENTENCE USED TO END "and reports honestly that under
 * `tests/Integration/` there is currently none of it". WHAT IS TRUE NOW: the
 * guard that points this scanner at the tree covers many more directories
 * than that one - how many is {@see ChildStderrCaptureTest::SCOPE}'s business
 * rather than a scanner's, and a count repeated here would be the stale
 * numeral E235 makes a rule against. WHY THE REST OF THE PARAGRAPH STILL EARNS ITS PLACE: the
 * distinction it draws does not move with the scope. A reader who takes a
 * green run as evidence that the suite's `sugarcrush: ` lines are gone has
 * misread what this instrument can see, and most of those lines are the kind
 * it cannot.
 */
final class ChildStderrCaptureScanner
{
    use SplitsTopLevelArgumentsTrait;

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
                    'shape' => self::classifyShellCommand($tokens, $i, $end),
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

            $close = TokenFunctionRanges::matching($tokens, $open, '(', ')');
            if ($close === null) {
                continue;
            }

            $sites[] = [
                'line' => $token[2],
                'call' => $name,
                'shape' => $isShell
                    ? self::classifyShellCall($tokens, $open, $close)
                    : self::classifyProcOpen($tokens, $open, $close, $functions),
            ];
        }

        return $sites;
    }

    /** The child's fd 2 lands on the null device. */
    private const FD_TWO_SINK = 'sink';

    /** The child's fd 2 lands on a target the command names. */
    private const FD_TWO_NAMED = 'named';

    /** The command says nothing about fd 2. */
    private const FD_TWO_NONE = 'none';

    /**
     * A shell spawn's shape, read from its COMMAND argument - the first one.
     *
     * WHAT THIS USED TO BE: a text search of the whole argument list. E205
     * measured two ways that answered wrong, both in the false-positive
     * direction, and both are fixed here rather than merely documented now:
     *
     *  - a `2>/dev/null` INSIDE QUOTES belongs to an inner shell and says
     *    nothing about the outer child's fd 2 - `proc_open("sh -c 'inner
     *    2>/dev/null'", [2 => ["pipe", "w"]], $p)` used to report `discarded`
     *    while the outer fd 2 really is the pipe;
     *  - only the `>/dev/null` + `2>&1` PAIR was order-checked, so a LATER
     *    `2>` that overrides an earlier sink was not consulted - `exec("sh
     *    -c 'inner 2>/dev/null' 2>$err")` used to report `discarded` though
     *    the shell's last fd 2 redirection wins.
     *
     * WHAT IT IS NOW: {@see shellFdTwo()} walks the command as words with
     * quoting and honours every redirection in order, and only for
     * REDIRECTIONS - operators outside the command string's argument are not
     * the command's either, which is why the walk reads argument one instead
     * of the call's text.
     */
    private static function classifyShellCall(array $tokens, int $open, int $close): string
    {
        $arguments = self::topLevelArguments($tokens, $open, $close);
        if (!isset($arguments[0])) {
            return self::SHAPE_INHERITED;
        }

        return self::classifyShellCommand($tokens, $arguments[0][0], $arguments[0][1]);
    }

    /**
     * The shape of a shell execution given the token range of its command
     * expression - the whole backtick pair, or argument one of a shell spawn.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function classifyShellCommand(array $tokens, int $from, int $to): string
    {
        return match (self::shellFdTwo(self::commandFragments($tokens, $from, $to))) {
            self::FD_TWO_SINK => self::SHAPE_DISCARDED,
            self::FD_TWO_NAMED => self::SHAPE_CAPTURED,
            default => self::SHAPE_INHERITED,
        };
    }

    /**
     * The command string as the shell would receive it, as far as these
     * tokens say.
     *
     * Literals are decoded and concatenated - `"php x" . " 2>&1"` is one
     * command. Anything the scanner cannot read - a variable, an interpolated
     * run, a constant, a call - becomes a `\x00` GAP: a character that can be
     * a redirect's unreadable TARGET but never lets an operator or a path
     * form across it. `"php {$v} 2>/dev/null"` still reads as a sink (the
     * gap sits well clear of the operator); `exec("php $v>x")` does not read
     * as one.
     *
     * Comments are dropped, not gapified: a PHP comment between the command
     * and the closing paren - `shell_exec("php x" /asterisk 2> here asterisk/)`
     * - has no redirection, the `2>` is comment text the shell never sees,
     * and this is the same rule {@see codeText()} applies everywhere else in
     * the scanner.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function commandFragments(array $tokens, int $from, int $to): string
    {
        $stream = '';
        foreach (\range($from, $to) as $index) {
            $token = $tokens[$index];
            $text = match (true) {
                !\is_array($token) => match ($token) {
                    '"', "'", '`', '.', '{', '}' => '',
                    default => "\x00",
                },
                $token[0] === \T_CONSTANT_ENCAPSED_STRING => self::decodeStringLiteral($token[1]),
                $token[0] === \T_ENCAPSED_AND_WHITESPACE => $token[1],
                \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_START_HEREDOC, \T_END_HEREDOC, \T_STRING_VARNAME], true) => '',
                default => "\x00",
            };
            if ($text === "\x00" && \str_ends_with($stream, "\x00")) {
                continue; // one gap is as unreadable as many
            }
            $stream .= $text;
        }

        return $stream;
    }

    /**
     * A PHP string literal's content as the bytes the shell receives.
     */
    private static function decodeStringLiteral(string $literal): string
    {
        $body = \substr($literal, 1, -1);
        if ($literal[0] === "'") {
            return \str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
        }

        return \preg_replace_callback(
            '~\\\\(.)~s',
            static fn (array $m): string => match ($m[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'v' => "\v",
                'f' => "\f",
                'e' => "\x1b",
                default => $m[1],
            },
            $body,
        ) ?? $body;
    }

    /**
     * Where a shell command sends fd 2: sink, named target, or nowhere.
     *
     * A miniature of the shell's redirection rules read as TOKENS OF A
     * STREAM, because the E205 defects were both failures to parse: the old
     * predicate searched TEXT and so could not tell a quoted `2>/dev/null`
     * (an inner shell's business) from an outer one, nor a superseded
     * redirection from a final one. This walk carries only what the answer
     * needs - where fd 1 and fd 2 land, updated in ORDER - and models the
     * forms in use:
     *
     *  - `N> FILE`, `N>> FILE` - N (default 1) lands on FILE; `/dev/null` is
     *    the sink, any other or unreadable target is named;
     *  - `N>& WORD` - N lands where WORD says: a digit duplicates that fd's
     *    CURRENT destination (`>/dev/null 2>&1` sinks fd 2, `2>&1 >/dev/null`
     *    does not - which is the whole order-honouring point), anything else
     *    names a target;
     *  - `&> FILE`, `&>> FILE` - both streams land on FILE;
     *  - `<` input redirections are consumed (their target is read as a
     *    word, so the walker cannot mistake `cmd<f 2>/dev/null`'s parts) and
     *    change nothing about fd 2.
     *
     * DELIBERATELY UNREAD, and gap-safe because the scanner never sees the
     * text anyway: a `$(...)` or backtick substitution's contents (their
     * redirect belongs to the inner command), and everything behind a
     * `\x00` gap. A word glued to an operator (`cmd2>/dev/null`) is the
     * shell's own reading - `2` is part of the word `cmd2`, so the operator
     * is a bare `>` on fd 1, and fd 2 inherits. The old text regex saw a
     * sink there; bash does not.
     */
    private static function shellFdTwo(string $stream): string
    {
        $stdout = self::FD_TWO_NONE;
        $stderr = self::FD_TWO_NONE;

        $length = \strlen($stream);
        $index = 0;
        $wordStarted = false;

        while ($index < $length) {
            $char = $stream[$index];

            if ($char === "\x00" || $char === ' ' || $char === "\t" || $char === "\n" || $char === ';' || $char === '|' || $char === '(' || $char === ')') {
                $wordStarted = $char === "\x00" ? $wordStarted : false;
                $index++;
                continue;
            }

            // `&>` and `&>>`: both streams to the following target.
            if ($char === '&' && ($stream[$index + 1] ?? '') === '>') {
                $index += 2;
                if (($stream[$index] ?? '') === '>') {
                    $index++;
                }
                [$target, $index] = self::readRedirectTarget($stream, $index);
                if ($target === '/dev/null') {
                    $stdout = self::FD_TWO_SINK;
                    $stderr = self::FD_TWO_SINK;
                } elseif (\ctype_digit($target) && $target !== '') {
                    // `&>1` - both streams share fd 1's current destination.
                    $stderr = $stdout === self::FD_TWO_NONE ? self::FD_TWO_NAMED : $stdout;
                } else {
                    $stdout = self::FD_TWO_NAMED;
                    $stderr = self::FD_TWO_NAMED;
                }
                $wordStarted = false;
                continue;
            }

            // `&&` and a bare `&` are separators; an `&` mid-word is a char.
            if ($char === '&') {
                $wordStarted = true;
                $index++;
                continue;
            }

            // Substituted text belongs to an INNER command: `$(x 2>/dev/null)`
            // and `` `x 2>/dev/null` `` silence the substitution's stderr, not
            // the outer command's, so the whole run is skipped unread.
            if ($char === '`') {
                $index++;
                while ($index < $length && $stream[$index] !== '`') {
                    $index++;
                }
                $index++;
                $wordStarted = true;
                continue;
            }
            if ($char === '$' && ($stream[$index + 1] ?? '') === '(') {
                $index += 2;
                $depth = 1;
                while ($index < $length && $depth > 0) {
                    $depth += match ($stream[$index]) { '(' => 1, ')' => -1, default => 0 };
                    $index++;
                }
                $wordStarted = true;
                continue;
            }

            $redirect = $char === '>' || $char === '<';
            $fd = null;
            if (!$redirect && !$wordStarted && $char >= '0' && $char <= '9') {
                $digits = '';
                $cursor = $index;
                while ($cursor < $length && $stream[$cursor] >= '0' && $stream[$cursor] <= '9') {
                    $digits .= $stream[$cursor++];
                }
                if (($stream[$cursor] ?? '') === '>' || ($stream[$cursor] ?? '') === '<') {
                    $fd = $digits;
                    $index = $cursor;
                    $char = $stream[$index];
                    $redirect = true;
                }
            }

            if (!$redirect) {
                [, $index] = self::readWord($stream, $index);
                $wordStarted = true;
                continue;
            }

            $input = $char === '<';
            $index++;
            $append = ($stream[$index] ?? '') === ($input ? '<' : '>');
            if ($append) {
                $index++;
            }
            $duplicates = !$input && ($stream[$index] ?? '') === '&';
            if ($duplicates) {
                $index++;
            }
            if ($input) {
                // `<`, `<<`, `<<<`: consume the target, touch no output fd.
                [$target, $index] = self::readRedirectTarget($stream, $index);
                unset($target);
                $wordStarted = false;
                continue;
            }

            [$target, $index] = self::readRedirectTarget($stream, $index);
            if ($duplicates) {
                // `N>& WORD`: N lands where WORD's fd CURRENTLY lands - the
                // order-honouring step. Duplication of a stream that has no
                // redirection yet points it at the parent's own, which for
                // these spawns is the pipe the caller reads: NAMED, not none.
                $lands = \ctype_digit($target) && $target !== ''
                    ? ($target === '1' ? $stdout : ($target === '2' ? $stderr : self::FD_TWO_NAMED))
                    : self::FD_TWO_NAMED;
                $lands = $lands === self::FD_TWO_NONE ? self::FD_TWO_NAMED : $lands;
            } else {
                $lands = $target === '/dev/null' ? self::FD_TWO_SINK : self::FD_TWO_NAMED;
            }
            if ($fd === null || $fd === '1') {
                $stdout = $lands;
            }
            if ($fd === '2') {
                $stderr = $lands;
            }
            $wordStarted = false;
        }

        return $stderr;
    }

    /**
     * The word following a redirection operator - its target, which may be
     * separated from the operator by spaces and may be quoted. An unreadable
     * or missing target answers as one that NAMES something: the conservative
     * direction, because a bare `2>` is a capture nobody can call a discard.
     *
     * @return array{0:string,1:int} [target, new index]
     */
    private static function readRedirectTarget(string $stream, int $index): array
    {
        $length = \strlen($stream);
        while ($index < $length && ($stream[$index] === ' ' || $stream[$index] === "\t")) {
            $index++;
        }
        if ($index >= $length || $stream[$index] === "\x00") {
            return ['' . "\x00", $index + 1];
        }

        return self::readWord($stream, $index);
    }

    /**
     * One shell word, honouring quotes: quoted runs contribute their content
     * as literal characters and can never form an operator.
     *
     * @return array{0:string,1:int} [word, new index]
     */
    private static function readWord(string $stream, int $index): array
    {
        $length = \strlen($stream);
        $word = '';
        while ($index < $length) {
            $char = $stream[$index];
            if ($char === "'") {
                $index++;
                while ($index < $length && $stream[$index] !== "'") {
                    $word .= $stream[$index];
                    $index++;
                }
                $index++;
                continue;
            }
            if ($char === '"') {
                $index++;
                while ($index < $length && $stream[$index] !== '"') {
                    if ($stream[$index] === '\\' && $index + 1 < $length) {
                        $index++;
                    }
                    $word .= $stream[$index];
                    $index++;
                }
                $index++;
                continue;
            }
            if ($char === '\\' && $index + 1 < $length) {
                $word .= $stream[$index + 1];
                $index += 2;
                continue;
            }
            if ($char === '`' || ($char === '$' && ($stream[$index + 1] ?? '') === '(')) {
                break; // a substitution starts a new word, and its body is not this command's
            }
            if ($char === "\x00" || \str_contains(" \t\n;|&<>()", $char)) {
                break;
            }
            $word .= $char;
            $index++;
        }

        return [$word, $index];
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
        $arguments = self::topLevelArguments($tokens, $open, $close);

        // A redirection in the COMMAND decides the matter for the fd it names
        // before the descriptor spec is ever consulted - but only a real one:
        // read from the command argument, in shell order, quoting honoured
        // (see {@see shellFdTwo()}; the E205 pair of false positives lived in
        // searching the whole call's text for `2>` instead).
        if (isset($arguments[0])) {
            $destination = self::shellFdTwo(self::commandFragments($tokens, $arguments[0][0], $arguments[0][1]));
            if ($destination === self::FD_TWO_SINK) {
                return self::SHAPE_DISCARDED;
            }
            if ($destination === self::FD_TWO_NAMED) {
                return self::SHAPE_CAPTURED;
            }
        }

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
     *
     * FD 2 BEING *NAMED* IS NOT FD 2 BEING *READ*, and until round 48 this
     * method treated the two as the same thing. It answered `captured` for
     * any spec that mentioned `2 =>` and did not contain the literal
     * `/dev/null` - so an entry the scanner cannot actually read came back as
     * compliant on the strength of the key alone. The live example is
     * `Integration/BinSugarcrushDispatchTest::armWatchdog()`'s
     * `2 => $devNull('w')`, a CLOSURE returning `['file','/dev/null','w']`:
     * the truth there is a discard and the answer was `captured`, the
     * polarity that waves a real offender through.
     *
     * Now an entry that is not an inline literal array is
     * {@see SHAPE_UNCLASSIFIED} - a failure, which is the honest answer to
     * "I cannot tell". A guard that quietly ignores what it cannot parse has
     * a hole shaped exactly like the next defect.
     *
     * AND THE SAME IS TRUE OF THE MEMBERS, which the first version of this
     * paragraph asserted and the first version of the code did not do. It
     * closed `2 => $devNull('w')` and left `2 => ['file', $devNull, 'w']`
     * reading `captured` - the nearest sibling of the very site it was
     * written for, and the same wave-a-real-offender-through polarity the
     * paragraph above condemns. Measured on PHP 8.3.6 against the code as it
     * then stood: `['file', $devNull, 'w']`, `['file', self::DEV_NULL, 'w']`,
     * `['file', DEV_NULL, 'w']` and `['file', '/dev' . '/null', 'w']` all
     * came back `captured`, because the decision was `str_contains($entry,
     * '/dev/null')` over the entry's SOURCE TEXT and none of those four spell
     * it. {@see fdTwoEntryIsAllLiteral()} is the fix: every member must be a
     * quoted string or a number, or the answer is again "I cannot tell".
     *
     * WHAT STILL READS `captured` AND IS MEANT TO, so the limit is named
     * rather than discovered: `2 => ['redirect', 1]` merges fd 2 into fd 1
     * and this scanner does not model where fd 1 went, and
     * `2 => ['file', '/tmp/whatever.log', 'w']` is a real file the test may
     * or may not read back. Both are all-literal, both are judged by the
     * `/dev/null` text alone, and closing either needs fd-1 destination
     * modelling that nothing in the tree currently exercises.
     *
     * MEASURED BEFORE IT WAS WRITTEN, per the rule that a prescription is a
     * hypothesis - and the measurement corrected the sentence that stood here
     * first. A per-site census of EVERY spawn site under `tests/` was taken
     * with the old decision and with each of the two widenings: ZERO sites
     * moved either time. Not one, as the first draft of this paragraph
     * claimed. No total is written down here on purpose - a cardinality
     * measured over `tests/` in one lane's worktree is wrong by the next
     * merge, and the load-bearing half of that measurement is the zero.
     * `armWatchdog()`'s site - the only non-literal fd-2 entry anywhere in
     * `tests/` - reads `discarded` on every side, because its command string
     * carries `>/dev/null 2>&1` and {@see classifyProcOpen()} settles that on
     * an earlier branch before any descriptor spec is consulted. So the one
     * occurrence this change was written for never reaches this method at
     * all. It closes the SHAPE and adds no exemption row anywhere, which is
     * also why it needed synthetic fixtures to be provable: there is nothing
     * in the tree that exercises it.
     */
    private static function classifySpec(string $spec): string
    {
        if (!self::namesFdTwo($spec)) {
            return self::positionalShape($spec);
        }

        $entry = self::fdTwoEntry($spec);
        if ($entry === null || !self::fdTwoEntryIsAllLiteral($entry)) {
            return self::SHAPE_UNCLASSIFIED;
        }

        return \str_contains($entry, '/dev/null') ? self::SHAPE_DISCARDED : self::SHAPE_CAPTURED;
    }

    /**
     * The shape of a descriptor spec that names no `2 =>` key.
     *
     * WHAT THIS BRANCH DID, and it is the hole this whole doc-block's thesis
     * is about, one branch earlier than the thesis looks: a spec without a
     * literal `2 =>` returned {@see SHAPE_INHERITED} outright. But
     * `proc_open()` reads a POSITIONAL descriptor array by position - element
     * 2 IS fd 2 - so `[['file','/dev/null','r'], ['file','/dev/null','w'],
     * ['file','/dev/null','w']]` is a discard, and it came back `inherited`.
     *
     * WHAT IS TRUE NOW, measured on PHP 8.3.6 against the code as it then
     * stood: EVERY positional spelling collapsed to `inherited` regardless of
     * what element 2 actually was. A positional `/dev/null` (truth:
     * discarded), a positional pipe (truth: captured), a two-element spec
     * (truth: inherited) and a positional spec whose third element is a
     * variable (truth: unreadable) all returned the same answer. Four
     * different truths, one reply - and `inherited` is a DEFINITE claim, not
     * an "I cannot tell", so it was wrong in both polarities at once: it
     * understates a real discard, and it reds a real capture.
     *
     * WHY THIS EARNS ITS PLACE: the paragraph above says "a guard that
     * quietly ignores what it cannot parse has a hole shaped exactly like the
     * next defect", and then the very first branch of the method did exactly
     * that. So the same rule is applied here - a positional element 2 is read
     * when it can be read, and anything this splitter cannot follow is
     * {@see SHAPE_UNCLASSIFIED} rather than a confident `inherited`.
     *
     * FEWER THAN THREE ELEMENTS IS A REAL `inherited`, not a failure to read:
     * a spec that supplies only fds 0 and 1 leaves fd 2 pointing wherever the
     * parent's was, which is the definition of the shape.
     *
     * WHAT THE ARROW BRANCH SAID, and it is the same hole one branch further
     * in - the paragraphs above are kept whole because they are still true of
     * the branch they describe. It said: an element carrying a `=>` means the
     * spec is keyed, a keyed spec that does not name `2` leaves fd 2 alone,
     * therefore return {@see SHAPE_INHERITED}. Every clause of that is true of
     * a spec whose elements ALL carry keys.
     *
     * WHAT IS TRUE NOW: it was applied on the FIRST element carrying an arrow,
     * so a spec that MIXES the two spellings took it too. PHP gives a
     * positional element ONE GREATER THAN THE LARGEST INTEGER KEY IT HAS
     * ASSIGNED SO FAR, measured on PHP 8.3.6: `[0 => a, b, c]` has keys 0, 1,
     * 2, `[1 => a, b]` has keys 1 and 2, and `[5 => a, b, c]` has keys 5, 6
     * and 7. So in a mixed spec fd 2 may be the second element, the third, or
     * absent entirely - the position of an element no longer tells you its fd,
     * and two of those three spellings put a pipe on fd 2 while the branch
     * answered `inherited` for all three.
     *
     * (THAT RULE USED TO BE WRITTEN HERE AS "the next free integer key", which
     * is a DIFFERENT rule that agrees with the real one on all three examples
     * above and disagrees elsewhere: `[5 => 'a', 0 => 'b', 'c']` has keys 5, 0
     * and 6, where "next free" predicts 1. Measured, PHP 8.3.6. Nothing in the
     * conclusion moves - if anything a running maximum is even less
     * recoverable from an element's position than occupancy would be.)
     *
     * WHY THIS EARNS ITS PLACE, AND IT IS NOT THE REASON FIRST WRITTEN HERE.
     * WHAT IT SAID: "`inherited` is the shape this scanner's guards FLAG, so a
     * wrong `inherited` reds correct code, and an exemption row written for
     * correct code is where the next real offender hides." WHAT IS TRUE NOW:
     * that is not how the consumer works. {@see ChildStderrCaptureTest}'s
     * `testNoChildLaunchedInScopeLeavesItsStderrOnTheSuites()` treats
     * everything that is not `captured` - and not an exempted `discarded` - as
     * an offender, {@see SHAPE_UNCLASSIFIED} included, and says so in its own
     * failure text. MEASURED: the same correct mixed spec, injected into a file
     * in that guard's scope, reds it either way - as
     * `(proc_open -> unclassified)` with this branch and as
     * `(proc_open -> inherited)` with it reverted. The change relabels an
     * offender; it does not stop one.
     *
     * WHY IT STILL EARNS ITS PLACE ANYWAY, on the two grounds that survive the
     * measurement: `inherited` is a DEFINITE claim about where fd 2 goes and
     * `unclassified` is an admission that this splitter cannot tell, which is
     * rule 14 - what a guard cannot parse must be visible as unparsed rather
     * than dressed as an answer. And it is the answer
     * {@see ChildLifetimeScanner::keysOf()} already gives the same shape,
     * reached independently. Two instruments walking one syntax disagreeing
     * about what they cannot read is the disagreement worth removing.
     *
     * THE ARROW TEST IS TOP-LEVEL, not `str_contains()`. An arrow nested
     * inside an element is not that element's key separator, and a text search
     * cannot tell the two apart.
     */
    private static function positionalShape(string $spec): string
    {
        $elements = self::topLevelArrayElements($spec);

        if ($elements === null) {
            // Not an array literal here at all - a method call, a constant, a
            // variable that resolved to something this scanner cannot follow.
            // It may well redirect fd 2; nothing here can say it does not.
            return self::SHAPE_UNCLASSIFIED;
        }

        if ($elements === []) {
            // An explicitly empty spec supplies no descriptors at all.
            return self::SHAPE_INHERITED;
        }

        $keyed = 0;
        foreach ($elements as $element) {
            if (self::hasTopLevelArrow($element)) {
                $keyed++;
            }
        }

        if ($keyed > 0 && $keyed === \count($elements)) {
            // EVERY element carries its own key and none of them is `2` - the
            // caller already established that - so fd 2 genuinely goes
            // untouched. This is the one reading of an arrow that survives.
            return self::SHAPE_INHERITED;
        }

        if ($keyed > 0) {
            // MIXED. Position no longer names the fd; see the doc-block.
            return self::SHAPE_UNCLASSIFIED;
        }

        if (!isset($elements[2])) {
            return self::SHAPE_INHERITED;
        }

        $entry = \trim($elements[2]);
        if (\str_starts_with($entry, '[') && \str_ends_with($entry, ']')) {
            $entry = \substr($entry, 1, -1);
        } elseif (\preg_match('/^array\s*\((.*)\)$/s', $entry, $inner) === 1) {
            $entry = $inner[1];
        } else {
            // `STDERR`, a variable, a function call: not a descriptor triple
            // this scanner can read.
            return self::SHAPE_UNCLASSIFIED;
        }

        if (!self::fdTwoEntryIsAllLiteral($entry)) {
            return self::SHAPE_UNCLASSIFIED;
        }

        return \str_contains($entry, '/dev/null') ? self::SHAPE_DISCARDED : self::SHAPE_CAPTURED;
    }

    /** Whether an array element's own top level carries a `=>`. */
    private static function hasTopLevelArrow(string $element): bool
    {
        $depth = 0;

        foreach (\token_get_all('<?php ' . $element . ';') as $token) {
            if (\is_array($token) && $token[0] === \T_DOUBLE_ARROW && $depth === 0) {
                return true;
            }
            if (!\is_string($token)) {
                continue;
            }
            if (\in_array($token, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (\in_array($token, [']', ')', '}'], true)) {
                $depth--;
            }
        }

        return false;
    }

    /**
     * The source text of each top-level element of an array literal, or null
     * if $spec is not an array literal.
     *
     * Lexed rather than split on commas, because a descriptor spec's elements
     * are themselves arrays and a nested `,` must not end one.
     *
     * @return list<string>|null
     */
    private static function topLevelArrayElements(string $spec): ?array
    {
        $tokens = \token_get_all('<?php ' . $spec . ';');
        $start = null;
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            if (self::tokenText($token) === '[') {
                $start = $i + 1;
            } elseif (\is_array($token) && $token[0] === \T_ARRAY) {
                $next = self::next($tokens, $i);
                if ($next === null || self::tokenText($tokens[$next]) !== '(') {
                    return null;
                }
                $start = $next + 1;
            }

            break;
        }

        if ($start === null) {
            return null;
        }

        $elements = [];
        $current = '';
        $depth = 0;

        for ($i = $start; $i < $count; $i++) {
            $text = self::tokenText($tokens[$i]);

            if ($depth === 0 && ($text === ']' || $text === ')')) {
                $current = \trim($current);
                if ($current !== '') {
                    $elements[] = $current;
                }

                return $elements;
            }

            if ($text === '[' || $text === '(') {
                $depth++;
            } elseif ($text === ']' || $text === ')') {
                $depth--;
            }

            if ($depth === 0 && $text === ',') {
                $elements[] = \trim($current);
                $current = '';

                continue;
            }

            $current .= $text;
        }

        // Ran off the end without closing - not something to guess about.
        return null;
    }

    /**
     * Whether every member of fd 2's entry is a quoted string or a number.
     *
     * THE DECISION BELOW IS MADE ON SOURCE TEXT, which is only sound when the
     * text IS the value. `['file', $devNull, 'w']` is an inline literal array
     * whose second member is a variable; searching its source for
     * `/dev/null` answers "no" and the entry is then reported as a capture,
     * which is the polarity that hides a discard. A concatenation, a class
     * constant and a global constant fail the same way. So a member that is
     * not its own value makes the whole entry unreadable.
     *
     * Lexed rather than pattern-matched: a `2 => ['file', "/dev/{$name}", 'w']`
     * is a T_ENCAPSED_AND_WHITESPACE run and not a T_CONSTANT_ENCAPSED_STRING,
     * so interpolation is rejected without needing a rule of its own.
     */
    private static function fdTwoEntryIsAllLiteral(string $entry): bool
    {
        $allowed = [\T_CONSTANT_ENCAPSED_STRING, \T_LNUMBER, \T_DNUMBER];

        foreach (\token_get_all('<?php ' . $entry . ';') as $token) {
            if (\is_string($token)) {
                if ($token === ',' || $token === ';') {
                    continue;
                }

                return false;
            }

            if (\in_array($token[0], [\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT], true)) {
                continue;
            }

            if (!\in_array($token[0], $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The inside of fd 2's entry when that entry is an inline literal array,
     * null when it is anything else.
     *
     * Null is the load-bearing return: it is what makes a `2 => $spec()`,
     * a `2 => self::PIPE` or a `2 => array('pipe','w')` (long syntax, which
     * this deliberately does not accept) fail rather than pass. Widening it
     * to a shape is a decision somebody should make with a census in hand,
     * not something a scanner should assume.
     *
     * IT IS NOT THE WHOLE READABILITY TEST, and reading it as one is how
     * `['file', $devNull, 'w']` passed for a round. This method answers "is
     * fd 2's entry an inline literal array"; whether its MEMBERS are literal
     * is {@see fdTwoEntryIsAllLiteral()}'s question, and both have to be yes
     * before the `/dev/null` text search below means anything.
     */
    private static function fdTwoEntry(string $spec): ?string
    {
        if (\preg_match('~(?:^|[\[,\s])2\s*=>\s*\[([^\]]*)\]~', $spec, $entry) !== 1) {
            return null;
        }

        return $entry[1];
    }

    private static function namesFdTwo(string $spec): bool
    {
        return \preg_match('/(^|[\[,\s])2\s*=>/', $spec) === 1;
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
                    $end = TokenFunctionRanges::matching($tokens, $j, '[', ']');
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

    // The depth walk that splits a call into its top-level arguments lives in
    // {@see SplitsTopLevelArgumentsTrait} (E174) - this class asked for one of
    // the three copies of it; the consolidation records why they were one rule
    // wearing three spellings, and which openers the running PHP produces.

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

    // The bracket matcher used to be a copy of the one in {@see TokenFunctionRanges}.
    // fd widened the canonical onto heredoc bodies and attribute groups (3555a3940);
    // this class had asked for a second copy of the plain rule that predates it.
    // Third copies are how rule 7 dies, so this file now calls the canonical.

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
