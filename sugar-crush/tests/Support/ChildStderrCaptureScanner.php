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
 * guard that points this scanner at the tree covers six directories, not one,
 * and which ones is {@see ChildStderrCaptureTest::SCOPE}'s business rather
 * than a scanner's. WHY THE REST OF THE PARAGRAPH STILL EARNS ITS PLACE: the
 * distinction it draws does not move with the scope. A reader who takes a
 * green run as evidence that the suite's `sugarcrush: ` lines are gone has
 * misread what this instrument can see, and most of those lines are the kind
 * it cannot.
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
     *
     * TWO LIMITS ON THE ORDER CHECK, measured on this scanner rather than
     * reasoned about, and both in the FALSE-POSITIVE direction - this reports
     * a discard where the truth is a capture, which is the polarity that reds
     * correct code and buys the next real offender its exemption.
     *
     *  - NESTING IS NOT SEEN. The whole argument text is searched, so a
     *    redirection belonging to an INNER shell counts as if it were the
     *    outer command's. `proc_open("sh -c 'inner 2>/dev/null'", [2 =>
     *    ["pipe", "w"]], $p)` reports `discarded`, and the outer child's fd 2
     *    really is the pipe the caller reads. Telling them apart needs quote
     *    awareness this scanner does not have.
     *  - ONLY THE `>/dev/null` + `2>&1` PAIR IS ORDER-CHECKED. A bare
     *    `2>/dev/null` matches wherever it appears, so a LATER fd 2
     *    redirection that overrides it is not consulted:
     *    `exec("sh -c 'inner 2>/dev/null' 2>$err", ...)` reports `discarded`
     *    though the shell's last fd 2 redirection wins and it is `$err`.
     *    (The reverse composition is already right for the reason that makes
     *    this wrong: `cmd 2>$err 2>/dev/null` really is discarded.)
     *
     * NEITHER IS LIVE UNDER {@see ChildStderrCaptureTest::SCOPE} - no spawn
     * in the guarded directories has either shape - which is why the fix is
     * recorded rather than attempted here: changing this predicate moves
     * every site in the tree at once, and a shape with no occurrence to
     * verify against is not a change worth making blind.
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
