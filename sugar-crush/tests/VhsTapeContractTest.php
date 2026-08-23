<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The contract between `.vhs/*.tape` and the renderer that actually runs them.
 *
 * `.github/workflows/vhs.yml`'s `render` job carries sugar-crush in its matrix,
 * runs `working-directory: ${{ matrix.lib }}`, drives the upstream
 * charmbracelet/vhs binary, then copies `${{ matrix.lib }}/.vhs/*.gif` into
 * `/tmp/staged/${{ matrix.lib }}/` and uploads that staging directory. (The
 * staging hop exists so the lib name travels inside the archive rather than only
 * in the artifact's name — `download-artifact` drops the name when a single
 * artifact matches its pattern. It does not change which files are collected:
 * the glob is still `<lib>/.vhs/*.gif`, so everything below still holds.) Three
 * things follow, and until this test existed nothing in the repo checked any
 * of them — no linter reads tapes, no test walks them, and the examples were
 * never smoke-run. A tape that broke all three shipped green, and did:
 *
 *   * `Set Shell "sh"` — vhs validates the shell name against a fixed nine-key
 *     map in a pre-pass and exits 1 with `invalid shell sh`. The render step is
 *     a `set -euo pipefail` loop over `.vhs/*.tape`, so one rejected tape takes
 *     every later tape, the artifact upload, and the job down with it.
 *   * `Type "php sugar-crush/examples/x.php"` — repo-root-relative, but the
 *     working directory is `sugar-crush/`, so the shell answers `not found`.
 *   * `Output x.gif` — vhs stores the argument verbatim and never chdirs, so
 *     the GIF lands at `sugar-crush/x.gif`, outside both the artifact path and
 *     the `git add <lib>/.vhs/<name>.gif` glob the commit job stages.
 *
 * None of these is visible in a rendered frame, which is why they need a test
 * rather than a reviewer.
 *
 * The fourth thing is geometry, and it is the one that has moved most: every
 * frame these tapes record was checked at a specific measured grid, and the
 * grid is a product of `Set FontSize` / `Set Width` / `Set Height` that no
 * rendered GIF makes obviously wrong. `--help` overflowing its frame looks like
 * a shorter `--help`, not like a broken tape. So the geometry is pinned here
 * too — see {@see GRID_ROWS_BY_HEIGHT} and {@see GEOMETRY}.
 *
 * The fifth is the escape hatch out of all of the above: vhs's `Source`
 * directive pulls directives in from a file this suite does not walk, so
 * {@see testNoTapeSourcesAnotherFile()} closes it.
 *
 * All of which rests on reading a tape the way vhs reads one. The lexical
 * grammar is written out in full in {@see tokenize()}, derived from upstream's
 * own `lexer/lexer.go` rather than probed; how each rule was then confirmed
 * against the binary is in {@see directiveValues()}; the keyword set that ends
 * a directive's value, taken verbatim from `token/token.go`, is
 * {@see KEYWORDS}.
 *
 * Of that, the part that can cost this suite a MISS rather than a false alarm
 * is neither of the two the comments here historically laboured over. It is
 * WHERE A TOKEN ENDS, in two forms, and both live in {@see tokenize()}:
 *
 *   * the five characters that can hide a `#` — `"` `'` `` ` `` `/` `{` —
 *     because whatever hides a `#` hides every directive behind it;
 *   * the two POSITIVE byte classes upstream reads a bare token with,
 *     {@see IDENT_BYTES} and {@see NUMBER_BYTES}, because a run that ends one
 *     byte late swallows the next directive's HEAD and loses that occurrence
 *     just as completely.
 *
 * The second is where to look first when this file next reports OK on a tape CI
 * rejects, and it is the newer half of the answer for a reason worth keeping:
 * the `#`-hiding set was declared complete BY CONSTRUCTION and is, but round
 * after round read that completeness as covering the whole miss surface. It
 * does not. A model can agree with upstream on all five delimiters and still
 * lose a directive on any of 192 GLUE BYTES — the figure measured over the
 * whole byte domain, `Set Padding <B>Output x.gif` for every B in 1-255 — which
 * is exactly what shipped for twelve rounds. The figure this docblock quoted
 * instead was 64, which is that same set restricted to B < 0x80 — the SEVEN-BIT
 * slice, and still exactly 64 on a re-run of the same sweep. HOW MANY REVISIONS
 * carried it is not restated here, because it is not checkable from this repo:
 * the file was ADDED in `48e0690c` (`git log --diff-filter=A -- <this file>`),
 * so a `git log -S` over it cannot reach anything earlier, and the rounds before
 * that one never landed as commits at all. A count of commits is deliberately
 * not quoted either — it goes stale on the next commit, which is the same trap
 * as the tally {@see testSetShellIsTheMostQueriedTwoWordHead()} exists to close.
 *
 * What IS checkable is the LABEL those 64 were given, and it was wrong at four
 * sites: `48e0690c` called them the printable-ASCII slice here, in
 * {@see SINGLE_BYTE_TOKENS}, in {@see testAGluedDirectiveHeadIsStillAHead()}
 * and in {@see tokenize()}. DOMAIN OF THAT ATTRIBUTION, written out so it is
 * re-derived rather than recalled: `git show 48e0690c:<this file> | grep -n
 * printable` answers FIVE lines — 70, 208, 1681 and 2646 wrong, plus 2582, the
 * one CORRECT use of the term (the 94-printable sweep in {@see tokenize()}) —
 * and forward-scanning each hit to the first member declaration at or after it
 * gives, in order, the class docblock, {@see SINGLE_BYTE_TOKENS},
 * {@see testAGluedDirectiveHeadIsStillAHead()}, and {@see tokenize()} for both
 * 2582 and 2646. A previous revision of this sentence named
 * {@see gluedHeadProvider()} for line 1681 — the member AFTER the one that
 * docblock belongs to, and a docblock attributed by scanning BACKWARDS instead
 * of forwards. The COUNT was right and the attribution was not, which is the
 * same defect as a figure without its domain wearing different clothes. Only
 * 35 of the 64 are printable; the other 29 are
 * `\x01`-`\x08` `\x0b` `\x0c` `\x0e`-`\x1f` and `\x7f`. `2bd2263f` fixed three
 * of the four and asserted in its own text that there were three, which is the
 * fourth site's whole reason for surviving — the sweep behind that number was a
 * `grep printable`, which DID list the fourth line and classified it by its
 * first clause. See the byte-class section in {@see tokenize()} and
 * {@see testAGluedDirectiveHeadIsStillAHead()}.
 *
 * WHICH RENDERER THIS DESCRIBES. Upstream charmbracelet/vhs is a TRANSITIONAL
 * dependency. `.github/workflows/vhs.yml` still renders the whole matrix with
 * the `vhs` baked into its vhs-runner image, and this repo's own `candy-vcr`
 * runs beside it as a deliberately non-blocking candy-core soak whose purpose
 * is to replace it outright — see `candy-vcr/README.md` → "CI integration".
 * So upstream is the validation authority TODAY, and every rule here is
 * written against upstream v0.11.0.
 *
 * Two kinds of rule are therefore mixed in this file, and whoever flips the
 * renderer has to be able to tell them apart, so each rule that holds only
 * while upstream renders says so in its own docblock under the heading
 * UPSTREAM-ONLY. None of them may be weakened or dropped on that account —
 * they are true of the renderer in use, and the label is there so the day they
 * stop being true is a deliberate edit rather than a silent one. The two are
 * {@see testShellIsOneUpstreamVhsAccepts()} (`Set Shell` is a candy-vcr
 * FEATURE, not a candy-vcr error) and {@see testNoTapeEndsInUpstreamsRegexPanic()}
 * (a crash in upstream's lexer that a PHP lexer cannot reproduce). The
 * geometry table is a third, softer case: it was measured inside upstream's
 * headless browser, so it describes upstream's grid and will need re-measuring
 * against whatever renders next — see {@see GRID_ROWS_BY_HEIGHT}.
 */
final class VhsTapeContractTest extends TestCase
{
    /**
     * The shells upstream vhs accepts, from its `Set Shell` lookup map.
     * Anything else aborts the render before ttyd is started.
     *
     * Pinned by {@see testTheValidShellSetIsUpstreamsOwn()}, and it is the pin
     * this constant most needed: `sh` is the name every lexer regression in this
     * file weaponises, and until that test existed ADDING `'sh'` here left the
     * whole suite green — which would have turned the one sentinel that can be
     * PROVED to have been seen into a directive this suite approves of.
     */
    private const VALID_SHELLS = [
        'bash', 'zsh', 'fish', 'powershell', 'pwsh', 'cmd', 'nu', 'osh', 'xonsh',
    ];

    /**
     * The PHPUnit group a MUTATION SWEEP must exclude — of the parsing model,
     * and now of the head scan too.
     *
     * TWO tests carry it. {@see testTheModelsBoundsAndConjunctsAreCountedNotNarrated()}
     * counts the leaves of the four PARSING-MODEL methods and
     * {@see testTheHeadScanSweepRegisterIsMeasuredNotNarrated()} counts the
     * leaves of the four HEAD-SCAN methods, so between them every conjunct-drop
     * mutation this file has a register for reds one of them whatever the
     * mutation MEANS. Left in the run they therefore report "killed" for every
     * mutant and neither the EQUIVALENT-MUTANT REGISTER in {@see tokenize()} nor
     * the one on {@see literalHeadArguments()} can be exercised again. Sweeps run
     * `--exclude-group syntax-census`, and this constant is what that instruction
     * refers to rather than a group name spelled out twice. WHICH methods carry
     * it is asserted rather than assumed — the second census could otherwise have
     * shipped ungrouped and silently made every head-scan sweep unreadable.
     */
    private const SWEEP_EXCLUDE_GROUP = 'syntax-census';

    /**
     * The tokens that ANCHOR a boolean condition — each one carries the BASE
     * leaf of the condition it introduces.
     *
     * Shared, rather than spelled out twice, because this file runs TWO
     * instruments over the same idea and they have twice disagreed about it in
     * prose: {@see guardCensus()} counts the leaves a conjunct-drop sweep of the
     * PARSING MODEL must visit, and {@see sweepLeafCensus()} counts the leaves of
     * the same operator over the four methods of the HEAD SCAN, which is what
     * {@see literalHeadArguments()}'s register is an inventory of. Round 19 left
     * the first excluding loop conditions and `??=` while the second swept them;
     * round 20 fixed that by editing one list. A shared constant is what stops
     * the third round of it: widen or narrow the domain here and BOTH figures
     * move together, so the two can no longer drift apart silently.
     *
     * `T_DO` is deliberately absent — a `do … while (…)` carries one condition
     * and its `T_WHILE` already counts it. `T_FOREACH` is absent because it has
     * no condition to drop. Ternaries and `match` arms are absent because they
     * are a DIFFERENT mutation operator; {@see sweepLeafCensus()} adds them back
     * for the head-scan register, which sweeps both operators under one figure,
     * and that difference is asserted rather than described — see
     * {@see testTheHeadScanSweepRegisterIsMeasuredNotNarrated()}.
     */
    private const CONJUNCT_ANCHORS = [
        \T_IF, \T_ELSEIF,
        \T_FOR, \T_WHILE,
        \T_COALESCE, \T_COALESCE_EQUAL,
    ];

    /**
     * The tokens that JOIN one more leaf onto a boolean condition.
     *
     * `and`/`or`/`xor` are here beside `&&`/`||` because they are conjunct
     * spellings a drop-sweep must visit and every one of them was invisible to
     * the regex these censuses replaced.
     */
    private const CONJUNCT_OPERATORS = [
        \T_BOOLEAN_AND, \T_BOOLEAN_OR,
        \T_LOGICAL_AND, \T_LOGICAL_OR, \T_LOGICAL_XOR,
    ];

    /**
     * The registered SURVIVORS of the mutation sweep recorded on
     * {@see literalHeadArguments()} — one entry per leaf that lives, with the
     * method it lives in and the class of equivalence that keeps it alive.
     *
     * HERE RATHER THAN IN PROSE because a count in a docblock is a count that
     * drifts, and this one has: round 19's register said thirty-eight leaves over
     * a sentence enumerating thirty-seven, one killed count out with it, and one
     * survivor — {@see headArgument()}'s `$index <= 1` bound — missing from every
     * class while the rule above it said an unclassified survivor IS a gap. Round
     * 20 corrected all three by rewriting the sentence, which is the same
     * instrument that produced the drift. {@see
     * testTheHeadScanSweepRegisterIsMeasuredNotNarrated()} now DERIVES every
     * figure the register quotes from this list and from the code: the leaf total
     * per method is measured by {@see sweepLeafCensus()}, the survivor total is
     * this list's length, and the KILLED total is the subtraction of the two
     * rather than a third number anybody types.
     *
     * The three class names are the register's own headings and are asserted to
     * be exactly three, because "a survivor in none of the three classes is a
     * gap" is the rule the register is enforced by — a fourth class added here
     * without a reader is that rule quietly relaxing.
     *
     * `fragment` IS WHY THIS IS NOT A LIST OF NAMES. `leaf` is prose for a
     * reader; `fragment` is the leaf's own bytes, and it is asserted to still
     * occur in the method named beside it. Without that half the register would
     * be an instrument that measures PRESENCE — seventeen rows exist, so the
     * count reconciles — while a row could name a leaf deleted three rounds ago
     * and nothing would say so. Counting rows is not checking them.
     *
     * @var list<array{method: string, leaf: string, fragment: string, class: string}>
     */
    private const SWEEP_SURVIVORS = [
        [
            'method' => 'literalHeadArguments',
            'leaf' => '!\is_array($t) in the token filter',
            'fragment' => '!\is_array($t)',
            'class' => 'type guard PHP makes redundant',
        ],
        [
            'method' => 'literalHeadArguments',
            'leaf' => '!\is_array($token) in the entry-point gate',
            'fragment' => '!\is_array($token)',
            'class' => 'type guard PHP makes redundant',
        ],
        [
            'method' => 'literalHeadArguments',
            'leaf' => '!\is_array($previous) in the accessor test',
            'fragment' => '!\is_array($previous)',
            'class' => 'type guard PHP makes redundant',
        ],
        [
            'method' => 'literalHeadArguments',
            'leaf' => '\is_array($head[0]) in the literal gate',
            'fragment' => '\is_array($head[0])',
            'class' => 'type guard PHP makes redundant',
        ],
        [
            'method' => 'callArgument',
            'leaf' => '\is_array($token) in the array-token arm',
            'fragment' => '\is_array($token)',
            'class' => 'type guard PHP makes redundant',
        ],
        [
            'method' => 'splitNamedArgument',
            'leaf' => '\is_array($argument[0])',
            'fragment' => '\is_array($argument[0])',
            'class' => 'type guard PHP makes redundant',
        ],
        [
            'method' => 'literalHeadArguments',
            'leaf' => '$token[0] !== \T_STRING',
            'fragment' => '$token[0] !== \T_STRING',
            'class' => 'redundant against a companion conjunct',
        ],
        [
            'method' => 'literalHeadArguments',
            'leaf' => '$tokens[$k + 1] !== \'(\'',
            'fragment' => '$tokens[$k + 1] !== \'(\'',
            'class' => 'redundant against a companion conjunct',
        ],
        [
            'method' => 'callArgument',
            'leaf' => 'the $depth === 1 early-continue',
            'fragment' => '$depth === 1',
            'class' => 'redundant against a companion conjunct',
        ],
        [
            'method' => 'splitNamedArgument',
            'leaf' => '\count($argument) >= 3',
            'fragment' => '\count($argument) >= 3',
            'class' => 'redundant against a companion conjunct',
        ],
        [
            'method' => 'literalHeadArguments',
            'leaf' => 'the $k + 1 < $count loop bound, relaxed',
            'fragment' => '$k + 1 < $count',
            'class' => 'unreachable from this file\'s content',
        ],
        [
            'method' => 'literalHeadArguments',
            'leaf' => 'the $k > 0 ternary condition',
            'fragment' => '$k > 0',
            'class' => 'unreachable from this file\'s content',
        ],
        [
            'method' => 'callArgument',
            'leaf' => 'the bare { opener',
            'fragment' => '$token === \'{\'',
            'class' => 'unreachable from this file\'s content',
        ],
        [
            'method' => 'callArgument',
            'leaf' => 'the $k < \count($tokens) loop bound, relaxed',
            'fragment' => '$k < \count($tokens)',
            'class' => 'unreachable from this file\'s content',
        ],
        [
            'method' => 'headArgument',
            'leaf' => 'the $index <= 1 loop bound, relaxed',
            'fragment' => '$index <= 1',
            'class' => 'unreachable from this file\'s content',
        ],
        [
            'method' => 'splitNamedArgument',
            'leaf' => '$argument[0][0] === \T_STRING',
            'fragment' => '$argument[0][0] === \T_STRING',
            'class' => 'unreachable from this file\'s content',
        ],
        [
            'method' => 'splitNamedArgument',
            'leaf' => '$argument[1] === \':\'',
            'fragment' => '$argument[1] === \':\'',
            'class' => 'unreachable from this file\'s content',
        ],
    ];

    /**
     * Every identifier upstream vhs lexes as a KEYWORD rather than as a string:
     * all 60 keys of `token.Keywords` in upstream's `token/token.go` (v0.11.0,
     * c6af91a), verbatim.
     *
     * This is the terminator set {@see directiveValues()} needs: vhs gives a
     * directive every following token up to the next keyword, so knowing which
     * words are keywords IS knowing where a value ends. A name missing from
     * here only makes a value run on and fail an assertion loudly; a name in
     * here that upstream does not treat as a keyword would cut a value short
     * and hide a defect.
     *
     * COMPLETENESS IS NOW BY DEFINITION, not by sweep. `token.Keywords` is the
     * map `LookupIdentifier` consults, and it is the whole of the language's
     * keyword set — nothing else in upstream turns a bare word into a keyword.
     * Note what that map does NOT contain: `Home`. It is a token TYPE
     * (`token.go:52`) and `token.IsCommand()` answers true for it
     * (`token.go:193`), so enumerating the token constants — or trusting
     * `IsCommand` — would have invented a keyword the lexer cannot produce:
     * `Type Home zzqq` types `Home zzqq` (measured, `TYPE '' 'Home zzqq'`, no
     * errors).
     *
     * Where the name reaches the language at all is `record.go:30`, which maps
     * the `\x1b[1~` byte sequence to `token.HOME` while RECORDING a session —
     * a different direction entirely from lexing a tape. The string `HOME` does
     * not occur in `parser.go`, and `parseCommand` has no `token.HOME` case, so
     * a `Home` that somehow reached the parser would fall through to
     * `Invalid command`. An earlier revision cited that non-existent
     * `parseCommand` case as the evidence for this rule; the rule is right and
     * the citation was not, which is the fifth time this file has shipped a
     * sentence nobody had checked.
     *
     * The previous revision reached 53 by sweep and claimed there was "no 54th
     * keyword spelled anywhere in the binary to miss". There were seven, and
     * they are the seven lowercase ones below. The sweep generated every
     * CamelCase word (and 2- and 3-segment combination) in the binary —
     * 773,501 candidates — a domain that cannot produce a lowercase keyword at
     * all. The PROBE was fine: `Type ms zzqq` answers `Type expects string`,
     * so it detects all seven. It was the conclusion drawn from the sweep's
     * domain that was wrong, which is why the list now comes from the source
     * of truth and the sweep survives only as this footnote.
     *
     * The probe, kept because it is how a keyword is confirmed without the
     * source to hand: `Type <word> zzqq` under `vhs validate`. `Type` takes a
     * run of string tokens, so a non-keyword is consumed silently
     * (`Type Bogus zzqq`), while a keyword leaves `Type` with no string
     * argument and upstream answers `Type expects string` (`Type Copy zzqq`,
     * `Type Shell zzqq`, `Type ms zzqq`). It does not depend on the word's own
     * arity, which is why it separates argument-less `Hide` and string-taking
     * `Copy` alike from a plain bare word.
     *
     * Its soundness is narrower than the previous revision claimed, though.
     * The phrase is built from `p.cur.Literal`, and `parseEnv` reaches the
     * same site: `Env Type 123` answers exactly `Type expects string`, one
     * error, from a tape with no argument-less trailing `Type` in it. What
     * makes the probe sound is the SHAPE OF THE PROBE TAPE — a one-line
     * `Type <word> zzqq` contains no `Env`, so `parseType` is the only site
     * that can produce the phrase — and not any property of the phrase itself.
     * (`parseCopy` and `parseRequire` build the same message from their own
     * literal: `Copy expects string`, `Require expects one string`.)
     *
     * Neither list the binary ships is usable for any of this. `vhs man` omits
     * `End`, `Env` and seven settings (`LoopOffset`, `MarginFill`, `Margin`,
     * `WindowBar`, `WindowBarSize`, `BorderRadius`, `CursorBlink`); the header
     * `vhs new` writes omits twelve more, `Wait`, `Source`, `Screenshot`,
     * `Copy`, `Paste`, `Alt` and `Shift` among them. Both are hand-written
     * prose, and a value that ran past a `Source` or an `Env` is exactly the
     * kind of miss this file keeps re-admitting.
     *
     * SUBSTITUTION is the hole the count-plus-duplicates-plus-lowercase pins
     * left open, and it is the direction this docblock itself calls the silent
     * one. `End` → `Home` and `Screenshot` → `Screenshots` were both GREEN: the
     * count still says 60, no name repeats, and all seven lowercase names are
     * still there. So {@see testTheKeywordSetIsUpstreamsWholeMap()} asserts the
     * whole byte-sorted list as well — the same drift-detector shape as
     * {@see testTheByteClassesAreUpstreamsOwn()}, and for the same reason. The
     * copy in that assertion is not a copy of this constant: it was extracted
     * from upstream with
     * `awk '/^var Keywords = map/,/^}/' token/token.go | grep -oE '"[^"]+":' |
     * tr -d '":' | LC_ALL=C sort` and compares set-identical to this list, 60
     * names against 60.
     *
     * @var list<string>
     */
    private const KEYWORDS = [
        // Commands.
        'Alt', 'Backspace', 'Copy', 'Ctrl', 'Delete', 'Down', 'End', 'Enter',
        'Env', 'Escape', 'Hide', 'Insert', 'Left', 'Output', 'PageDown',
        'PageUp', 'Paste', 'Require', 'Right', 'Screenshot', 'ScrollDown',
        'ScrollUp', 'Set', 'Shift', 'Show', 'Sleep', 'Source', 'Space', 'Tab',
        'Type', 'Up', 'Wait',
        // `Set` sub-keywords. `Set X` is two tokens and X is a keyword in its
        // own right, so it ends a preceding value just as a command does —
        // `Type abc Shell def` answers `Invalid command: Shell`.
        'BorderRadius', 'CursorBlink', 'FontFamily', 'FontSize', 'Framerate',
        'Height', 'LetterSpacing', 'LineHeight', 'LoopOffset', 'Margin',
        'MarginFill', 'Padding', 'PlaybackSpeed', 'Shell', 'Theme',
        'TypingSpeed', 'WaitPattern', 'WaitTimeout', 'Width', 'WindowBar',
        'WindowBarSize',
        // The seven the CamelCase sweep could not reach. Five unit suffixes
        // and the two booleans, and the lookup is case-sensitive, so these
        // spellings and no others: `Set CursorBlink True` is a bare word.
        'em', 'px', 'ms', 's', 'm', 'true', 'false',
    ];

    /**
     * The nine bytes upstream's `NextToken` switch turns into a token of their
     * own, one byte each — `@` `=` `]` `[` `-` `%` `^` `\` `+`, transcribed
     * from the nine `case` arms above its `default`.
     *
     * They are tried BEFORE either reader, which is the whole reason `-` and
     * `%` behave differently at a token start than they do mid-run: both are
     * in {@see IDENT_BYTES}, so `a-b` and `a%b` are single identifiers, while
     * `Set Padding -` is a `-` token and `Set LoopOffset 50%` is NUMBER `50`
     * followed by a `%`. Nine of the 192 glue bytes that used to hide a
     * directive live in here — 192 over the whole byte domain, `Set Padding
     * <B>Output x.gif` for every byte value B in 1-255, see {@see tokenize()}.
     * This line said "sixty-four" while quoting that set restricted to
     * B < 0x80, and then called those 64 the printable-ASCII slice; 29 of them
     * are control bytes, so it is the seven-bit slice and 35 is the printable
     * count.
     */
    private const SINGLE_BYTE_TOKENS = '@=][-%^\\+';

    /** `isDigit`: the entry test for `readNumber` and part of both run classes. */
    private const DIGIT_BYTES = '0123456789';

    /** `isLetter`, ASCII and case-sensitive: half of `readIdentifier`'s entry test. */
    private const LETTER_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * `readNumber`'s run class: `isDigit(l.ch) || isDot(l.ch)`, eleven bytes
     * and nothing else. So `1.2.3` is ONE number token, `50%` is two tokens
     * and `5em` is two — measured against upstream's lexer, and the reason a
     * negative break set was over-greedy on 235 of the 255 possible bytes here.
     */
    private const NUMBER_BYTES = self::DIGIT_BYTES . '.';

    /**
     * `readIdentifier`'s run class: `isLetter || isDot || isDash ||
     * isUnderscore || isSlash || isPercent || isDigit`, sixty-seven bytes and
     * nothing else.
     *
     * `/` and `%` are the two that surprise: they are what holds
     * `./bin/sugarcrush` and `.vhs/cli.gif` together as single tokens, and
     * they are why a `/` mid-run does NOT open a regex. `-` and `_` are in
     * here too, which is why `Set Padding -Output evil.gif` needs the
     * single-byte arm above to run first — the `-` is at a token start, so it
     * is a token of its own and `Output` begins a live directive.
     *
     * Composed from {@see LETTER_BYTES} and {@see DIGIT_BYTES} rather than
     * written out, so the entry test can never drift out of the run class and
     * turn the walk into an infinite loop. {@see testTheByteClassesAreUpstreamsOwn()}
     * pins the content.
     */
    private const IDENT_BYTES = self::LETTER_BYTES . self::DIGIT_BYTES . '.-_/%';

    /**
     * Grid rows for a `Set Height`, measured under upstream vhs v0.11.0 at
     * `Set FontSize 14` and `Set Width 1200` by rendering a probe tape that ran
     * `stty size`. vhs lays its grid out inside a headless browser, so nothing
     * here is derived from a formula — an unmeasured height has no entry, and
     * a tape asking for one fails rather than being silently approximated.
     * Every height in this table is 117 columns wide.
     *
     * UPSTREAM-ONLY, softly: the numbers are a property of upstream's headless
     * browser layout, so they describe upstream's grid and nothing else. They
     * do not become WRONG when the renderer changes — they become unverified,
     * and the whole table has to be re-measured the same way (a probe tape
     * running `stty size`) against whatever renders next. See the class
     * docblock on the candy-vcr transition.
     */
    private const GRID_ROWS_BY_HEIGHT = [
        380 => 15,
        440 => 19,
        520 => 24,
        560 => 26,
        640 => 31,
        700 => 35,
    ];

    /** Columns at `Set Width 1200`, `Set FontSize 14` — same measurement. */
    private const GRID_COLUMNS = 117;

    /**
     * The two settings the whole table above is measured AT. A tape that
     * changes either is asking for a grid nobody has measured, so the row
     * counts every tape header quotes stop being true of it.
     */
    private const FONT_SIZE = 14;
    private const WIDTH = 1200;

    /**
     * Per tape: `[<Set Height>, <rows the recording needs>, <where that came from>]`.
     *
     * The two numbers do different jobs, and both are needed.
     *
     * The ROW FLOOR is the falsifiable one: it is the measured point below
     * which this tape's beats stop being on the GIF, so it is what catches
     * "cli.tape quietly went back to `Set Height 380`" — 15 rows for a
     * recording that needs 22.
     *
     * It does not bind equally on every tape, and which one it binds on is a
     * property of what each tape draws. Measured by driving each example's
     * model at 117x12, 14, 15, 19, 20, 24, 26, 31 and 35:
     *
     *   * `cli.tape` is the one tape that drives no model — it pipes
     *     `./bin/sugarcrush --help` through `head -20` in the shell vhs
     *     spawns. Its 22 rows are a fixed block of output that a shorter grid
     *     simply truncates, so the floor is the whole check here.
     *   * `chat.tape`, `diff.tape` and `permission.tape` each emit exactly
     *     `$rows` lines at every one of those grids, laying themselves out to
     *     whatever WindowSizeMsg reports. Nothing overflows at any measured
     *     height, so only a beat that needs particular content ON the frame
     *     can make their floor bite — which is why diff's does (its Ctrl+O
     *     toggle changes nothing below 117x20) and the other two's does not.
     *   * `agents.tape` is the opposite shape: the dashboard draws a constant
     *     9 rows from 117x12 all the way to 117x35, so extra height buys blank
     *     rows under the box and nothing else. Its floor covers the typed
     *     command line and prompt above the pane, not fitting the pane.
     *
     * So against the keys {@see GRID_ROWS_BY_HEIGHT} currently admits — 15
     * rows at the smallest — the floor can only ever fail for `cli` (needs 22)
     * and `diff` (needs 20); the 11/15/15 the other three need is satisfied by
     * every admitted key. That is a property of the measured table rather than
     * of the assertion: measure a shorter grid in and chat/permission begin
     * binding at their exact value.
     *
     * The EXACT HEIGHT is the one that carries the rest: each tape's header
     * documents what it records at one specific grid — the four model-driving
     * tapes by having had their model driven there, cli.tape by the lines
     * `head -20` lets through — and a height change silently invalidates that
     * without changing any number the reader can see. Changing it here is
     * meant to be deliberate: re-check the frames, then move the constant.
     *
     * @var array<string, array{int, int, string}>
     */
    private const GEOMETRY = [
        'agents.tape' => [380, 11,
            'the dashboard draws a constant 9 rows at every grid measured from '
            . '117x12 to 117x35, plus the typed command line and the prompt above it'],
        'chat.tape' => [640, 15,
            'the whole frame — tab bar, files pane, transcript, input box, status '
            . 'bar — is already drawn at 117x15, the smallest measured grid'],
        'cli.tape' => [560, 22,
            'the 20 lines `head -20` lets through, plus the typed command line '
            . 'and the prompt drawn under the output'],
        'diff.tape' => [640, 20,
            'the Ctrl+O beat is a still picture below 117x20. Driving the '
            . 'model and diffing the frames either side of the keystroke with '
            . 'the ANSI stripped, they are byte-identical at every grid from '
            . '117x12 up to and including 117x19 — neither the collapse '
            . 'indicator nor the row it toggles to is on the frame at all. '
            . '117x20 is the first grid where `… 1 line hidden (ctrl+o)` gives '
            . 'way to `Applied 1 hunk to src/Tui/TerminalBackground.php`'],
        'permission.tape' => [640, 15,
            'the modal and the post-refusal transcript both render whole at '
            . '117x15, the smallest measured grid'],
    ];

    private static function libRoot(): string
    {
        return \dirname(__DIR__);
    }

    /** @return array<string, array{string}> */
    public static function tapeProvider(): array
    {
        $tapes = glob(self::libRoot() . '/.vhs/*.tape') ?: [];
        self::assertNotSame([], $tapes, 'no tapes found — the glob or the layout moved');

        $cases = [];
        foreach ($tapes as $tape) {
            $cases[basename($tape)] = [$tape];
        }

        return $cases;
    }

    /**
     * A data provider walks whatever it finds, so deleting a tape silently
     * shrinks this suite instead of failing it — the coverage would vanish
     * with the file. Pin the roster separately.
     */
    public function testTheExpectedTapesAreAllPresent(): void
    {
        $found = array_map('basename', glob(self::libRoot() . '/.vhs/*.tape') ?: []);
        sort($found);

        $expected = array_keys(self::GEOMETRY);
        sort($expected);

        self::assertSame(
            $expected,
            $found,
            'the tape roster changed. A deleted tape takes its coverage with it and a new '
            . 'one arrives unchecked, so add or remove its GEOMETRY entry here in the same '
            . 'change — that constant is what tells this suite which grid the tape needs',
        );
    }

    /**
     * `Output` must name a path inside `.vhs/`, or CI renders the GIF into a
     * directory neither the upload nor the commit job looks at.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tapeProvider')]
    public function testOutputStaysInsideTheArtifactDirectory(string $tape): void
    {
        $outputs = self::directiveValues($tape, 'Output');

        self::assertCount(1, $outputs, basename($tape) . ' must declare exactly one Output');

        $expected = '.vhs/' . basename($tape, '.tape') . '.gif';
        self::assertSame(
            $expected,
            $outputs[0],
            basename($tape) . ": Output must be {$expected} — vhs resolves it against the "
            . 'lib root, so a bare filename escapes .vhs/ and the artifact glob misses it',
        );
    }

    /**
     * A `Set Shell` value outside vhs's map is a hard, whole-job failure.
     *
     * None of these tapes carries one, and that absence is itself the safe
     * state rather than a gap: vhs spawns its default bash unconditionally, so
     * a tape that names no shell cannot be rejected by the validator. The
     * assertion is written to say exactly that — an empty set of rejected
     * shells — so it runs on every tape instead of quietly doing nothing on
     * the four-fifths of them a `foreach` would skip, and starts doing real
     * work the moment somebody adds `Set Shell`.
     *
     * UPSTREAM-ONLY. This restriction belongs to upstream vhs, not to this
     * repo. Upstream validates the name against a fixed nine-key map in a
     * pre-pass and exits 1, which is why `Set Shell "sh"` is the payload every
     * lexer regression below weaponises: it is the cheapest directive that can
     * be PROVED to have been seen. `candy-vcr` — the renderer meant to take
     * over, see the class docblock — treats `Set Shell` as a FEATURE with no
     * such whitelist: `Tape\Compiler` records the name, `Encode\TapeToGif`
     * resolves it (CLI `--shell` first, then the tape's own directive) and
     * `Render\FrameStream` runs the typed commands under a real PTY in exec
     * mode, falling back to a default shell when the named one is not found.
     * `Set Shell "sh"` is legal there. So on the day candy-vcr becomes the
     * renderer this assertion stops describing reality and the lexer
     * regressions need a different sentinel directive — until then it is a
     * live, load-bearing check and must not be softened.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tapeProvider')]
    public function testShellIsOneUpstreamVhsAccepts(string $tape): void
    {
        $rejected = array_values(array_diff(
            self::directiveValues($tape, 'Set Shell'),
            self::VALID_SHELLS,
        ));

        self::assertSame(
            [],
            $rejected,
            basename($tape) . ': `Set Shell` names a shell upstream vhs rejects ('
            . 'it validates against ' . implode('/', self::VALID_SHELLS) . ' and exits 1 '
            . 'with `invalid shell`), which aborts the render before ttyd starts and fails '
            . 'every later tape in the workflow loop',
        );
    }

    /**
     * Every path a tape types must resolve from the lib root, because that is
     * the working directory CI renders from.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tapeProvider')]
    public function testTypedPathsResolveFromTheLibRoot(string $tape): void
    {
        $checked = 0;

        foreach (self::directiveValues($tape, 'Type') as $typed) {
            // Only the command lines carry paths; prose typed into an input
            // box (a chat question, a single-key answer) has none.
            if (preg_match('~^(?:php\s+|\./)(\S+)~', $typed, $m) !== 1) {
                continue;
            }

            $path = ltrim($m[1], './');
            ++$checked;

            self::assertFileExists(
                self::libRoot() . '/' . $path,
                basename($tape) . ": typed path `{$m[1]}` does not resolve from the lib root. "
                . 'CI renders with `working-directory: sugar-crush`, so repo-root-relative '
                . 'paths (`sugar-crush/...`) are `not found` at render time',
            );
        }

        self::assertGreaterThan(0, $checked, basename($tape) . ' types no command at all');
    }

    /**
     * `Source` would move a tape's directives outside this suite's reach.
     *
     * vhs v0.11.0 has it, resolves it for real (`vhs validate` on a tape
     * naming a missing file reports `File other.tape not found`) and honours
     * whatever it pulls in — a `Source`d tape carrying `Set Shell "sh"` aborts
     * the PARENT render with `invalid shell sh`, exit 1. Every assertion here
     * walks `.vhs/*.tape` and nothing else, so a directive reached that way is
     * invisible to all of them.
     *
     * Banned rather than followed. Following it would mean re-implementing
     * vhs's file resolution and then re-running the whole suite against files
     * the roster test deliberately does not pin, all for a construct no tape
     * uses; forbidding it keeps every assertion's domain exactly the set of
     * files {@see testTheExpectedTapesAreAllPresent()} enumerates. If a tape
     * ever needs shared setup, this is the assertion to convert.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tapeProvider')]
    public function testNoTapeSourcesAnotherFile(string $tape): void
    {
        self::assertSame(
            [],
            self::directiveValues($tape, 'Source'),
            basename($tape) . ': `Source` pulls directives in from a file this suite never '
            . 'reads, so everything it carries — Set Shell, Output, typed paths, geometry — '
            . 'ships unchecked while the suite stays green. Inline it, or teach '
            . 'directiveValues() to follow it',
        );
    }

    /** Repo convention, and the one setting every other lib's tapes share. */
    #[\PHPUnit\Framework\Attributes\DataProvider('tapeProvider')]
    public function testThemeIsPinned(string $tape): void
    {
        self::assertSame(
            ['TokyoNight'],
            self::directiveValues($tape, 'Set Theme'),
            basename($tape) . ' must pin Set Theme "TokyoNight"',
        );
    }

    /**
     * The grid a tape asks for has to be one that has been measured, and big
     * enough for what the tape records.
     *
     * Nothing about a too-small frame is visible as a defect in the GIF: a
     * `--help` that overflowed 15 rows records as a shorter `--help`, and the
     * `… 1 line hidden (ctrl+o)` row that diff.tape's whole Ctrl+O beat toggles
     * simply is not on the frame. Both look like content, not like a broken
     * tape, which is why they are asserted here instead of reviewed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tapeProvider')]
    public function testGeometryMatchesTheMeasuredGrid(string $tape): void
    {
        $name = basename($tape);
        [$expectedHeight, $rowsNeeded, $why] = self::GEOMETRY[$name];

        self::assertSame(
            [(string) self::FONT_SIZE],
            self::directiveValues($tape, 'Set FontSize'),
            $name . ': every grid figure in this suite and in the tape headers was measured '
            . 'at FontSize ' . self::FONT_SIZE . '. Change it and none of them describes this tape',
        );

        self::assertSame(
            [(string) self::WIDTH],
            self::directiveValues($tape, 'Set Width'),
            $name . ': Width ' . self::WIDTH . ' is what makes the grid '
            . self::GRID_COLUMNS . ' columns wide, which is the width every '
            . 'no-overflow check in the tape headers was made against',
        );

        $heights = self::directiveValues($tape, 'Set Height');
        self::assertCount(1, $heights, $name . ' must declare exactly one Set Height');
        $height = (int) $heights[0];

        self::assertArrayHasKey(
            $height,
            self::GRID_ROWS_BY_HEIGHT,
            $name . ": Set Height {$height} has never been measured. vhs sizes its grid in a "
            . 'headless browser, so the row count cannot be derived — render a probe tape '
            . 'that runs `stty size` at that height and add the result to GRID_ROWS_BY_HEIGHT',
        );

        // Only cli (22) and diff (20) can actually trip this against today's
        // measured table — see {@see GEOMETRY} for the per-tape reason and for
        // what would make the other three bind.
        self::assertGreaterThanOrEqual(
            $rowsNeeded,
            self::GRID_ROWS_BY_HEIGHT[$height],
            $name . ": Set Height {$height} is a " . self::GRID_ROWS_BY_HEIGHT[$height]
            . '-row grid, but this recording needs ' . $rowsNeeded . ' rows — ' . $why,
        );

        self::assertSame(
            $expectedHeight,
            $height,
            $name . ": this tape's header documents what it records at "
            . self::GRID_COLUMNS . 'x' . (self::GRID_ROWS_BY_HEIGHT[$expectedHeight] ?? 0)
            . " (Set Height {$expectedHeight}) — the four tapes that drive a model by "
            . 'driving it at that grid, cli.tape by counting the lines `head -20` lets '
            . "through. Height {$height} silently makes that check describe a grid the "
            . 'tape no longer records at — re-check the frames, then move this constant',
        );
    }

    /**
     * The grid table against the grids the TAPES say they were measured at.
     *
     * {@see GRID_ROWS_BY_HEIGHT} and {@see GRID_COLUMNS} were measured once, by
     * rendering a probe tape that ran `stty size`, and then written down twice —
     * here, and in the comment header of the tape each figure was measured for.
     * Nothing tied the two copies together, so `GRID_COLUMNS` and the four
     * heights no tape ASKS for (`440`, `520`, `700`, and `380` for four of the
     * five tapes) were unasserted: any of them could be edited to a number
     * nobody has measured and the whole suite stayed green.
     *
     * They are not independent numbers, though — they are one measurement
     * written in two places, so they can check each other. Every
     * `<cols>x<rows>` figure any tape header cites is read back out of the tape
     * and required to agree with the table.
     *
     * The one key no header cites is 440. It is left in the table rather than
     * dropped — an unmeasured height must fail
     * {@see testGeometryMatchesTheMeasuredGrid()} loudly rather than be
     * silently approximated, and that only works if the measured ones are all
     * listed — but this test does not cover it, and saying so is the point:
     * the cross-check's domain is "every height a tape header quotes", which is
     * five of the six keys.
     */
    public function testTheMeasuredGridMatchesTheGridsTheTapesCite(): void
    {
        // `1200x640 at FontSize 14 is a 117x31 grid` — one per tape header.
        $declared = '/(\d+)x(\d+) at FontSize (\d+) is a (\d+)x(\d+) grid/';
        // `117x24 at Height 520, 117x26 at 560, …` — cli.tape's probe results.
        $probed = '/(\d+)x(\d+) at (?:Height )?(\d+)/';

        $cited = [];

        foreach (glob(self::libRoot() . '/.vhs/*.tape') ?: [] as $tape) {
            $name = basename($tape);
            $source = file_get_contents($tape);
            self::assertIsString($source, "could not read {$tape}");

            preg_match_all($declared, $source, $ms, PREG_SET_ORDER);

            foreach ($ms as $m) {
                self::assertSame(
                    self::WIDTH,
                    (int) $m[1],
                    $name . ': this header describes a Set Width this suite does not pin',
                );
                self::assertSame(
                    self::FONT_SIZE,
                    (int) $m[3],
                    $name . ': this header describes a FontSize this suite does not pin',
                );
                $cited[] = [$name, (int) $m[4], (int) $m[5], (int) $m[2]];
            }

            preg_match_all($probed, $source, $ms, PREG_SET_ORDER);

            foreach ($ms as $m) {
                $cited[] = [$name, (int) $m[1], (int) $m[2], (int) $m[3]];
            }
        }

        // Nine: one `is a …x… grid` sentence per tape, plus the four probe
        // results cli.tape lists. A header that stops citing its grid takes its
        // half of the cross-check with it, so the roster is pinned like the
        // tape roster is.
        self::assertCount(
            9,
            $cited,
            'the tape headers between them cite nine measured grids — one per tape, plus the '
            . 'four `stty size` results cli.tape lists. Fewer means a header stopped saying '
            . 'which grid it was checked at, which is the drift this test exists to catch',
        );

        $heights = [];

        foreach ($cited as [$name, $cols, $rows, $height]) {
            self::assertSame(
                self::GRID_COLUMNS,
                $cols,
                $name . ": cites a {$cols}-column grid, but GRID_COLUMNS says " . self::GRID_COLUMNS
                . '. Both are the same `stty size` measurement written down twice — re-measure, '
                . 'do not pick one',
            );

            self::assertArrayHasKey(
                $height,
                self::GRID_ROWS_BY_HEIGHT,
                $name . ": cites a grid at Set Height {$height} that GRID_ROWS_BY_HEIGHT has no "
                . 'entry for',
            );

            self::assertSame(
                self::GRID_ROWS_BY_HEIGHT[$height],
                $rows,
                $name . ": cites {$cols}x{$rows} at Set Height {$height}, but "
                . 'GRID_ROWS_BY_HEIGHT[' . $height . '] is ' . self::GRID_ROWS_BY_HEIGHT[$height],
            );

            $heights[$height] = true;
        }

        $heights = array_keys($heights);
        sort($heights);

        self::assertSame(
            [380, 520, 560, 640, 700],
            $heights,
            'the five heights the tape headers cite. 440 is the sixth key and no header quotes '
            . 'it, so it is measured but not cross-checked — re-measure it the same way (a probe '
            . 'tape running `stty size`) and cite it in a header to bring it inside this test',
        );
    }

    /** @var list<string> */
    private array $scratchTapes = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchTapes as $path) {
            @unlink($path);
        }

        $this->scratchTapes = [];
    }

    /**
     * A throwaway tape for the lexer regressions below.
     *
     * Deliberately NOT under `.vhs/` — {@see tapeProvider()} globs that
     * directory, so a scratch file there would join every data-driven test in
     * the suite and fail them all on Output, Theme and geometry it never
     * declares.
     */
    private function scratchTape(string $content): string
    {
        $base = tempnam(sys_get_temp_dir(), 'vhs-lex-');
        self::assertIsString($base, 'could not create a scratch tape');

        // tempnam() has already created $base, so both names get cleaned up.
        $path = $base . '.tape';
        file_put_contents($path, $content);
        $this->scratchTapes[] = $base;
        $this->scratchTapes[] = $path;

        return $path;
    }

    /**
     * The five delimiter pairs that make a `#` literal, as OPEN/CLOSE pairs
     * rather than as single characters.
     *
     * The pair shape is not decoration. Four of the five are symmetric, and a
     * sweep that assumed the opener was also the closer is exactly how `{` … `}`
     * stayed invisible for ten rounds: probing `{a#b{` gives the JSON reader no
     * `}`, so it swallows the sentinel to EOF and the probe scores `{` as
     * harmless.
     *
     * @return array<string, array{string, string}>
     */
    public static function delimiterProvider(): array
    {
        return [
            'double quote' => ['"', '"'],
            'single quote' => ["'", "'"],
            'backtick' => ['`', '`'],
            'regex slash' => ['/', '/'],
            'json braces' => ['{', '}'],
        ];
    }

    /**
     * A `#` inside a delimited token is literal, so a directive AFTER the
     * closing delimiter is still live and must still be seen.
     *
     * This is the defect class that has now come back ten times, and each round
     * found one more delimiter {@see tokenize()} was not modelling: the three
     * quotes for eight revisions, then `/`, then `{` … `}`. `vhs` lexes the tape
     * below as `Set WaitPattern <token a#b>` followed by `Set Shell sh` and
     * aborts the render with `failed to execute command: invalid shell sh`,
     * exit 1, taking every later tape in the workflow loop with it — while a
     * parser that stopped at the opener read `#b… Set Shell "sh"` as a comment
     * and reported OK.
     *
     * Asserting the value is SEEN is the whole point. A test that only checked
     * such a tape still parses would have passed throughout all ten rounds.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('delimiterProvider')]
    public function testAHashInsideADelimitedTokenCannotHideALaterDirective(string $open, string $close): void
    {
        $tape = $this->scratchTape("Set WaitPattern {$open}a#b{$close} Set Shell \"sh\"\n");

        self::assertSame(
            ['sh'],
            self::directiveValues($tape, 'Set Shell'),
            "a `#` between {$open} … {$close} is literal to vhs, so the `Set Shell` "
            . 'after the closing delimiter is a live directive that aborts the render. Missing '
            . 'it hides every assertion in this suite at once, not just this one',
        );
    }

    /**
     * The regex is not tied to `Set WaitPattern` — it opens wherever a token
     * does, so every head must be probed, not just the one that takes a
     * pattern.
     *
     * Measured under `vhs validate` with a trailing `Source nofile.tape`, whose
     * `File nofile.tape not found` proves the head after the regex was lexed:
     * `Wait+Screen`, bare `Wait`, `Type` and a line-initial regex all reach it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('regexHeadProvider')]
    public function testARegexOpensUnderAnyHead(string $prefix): void
    {
        $tape = $this->scratchTape("{$prefix}/a#b/ Set Shell \"sh\"\n");

        self::assertSame(
            ['sh'],
            self::directiveValues($tape, 'Set Shell'),
            "`{$prefix}/a#b/` must not swallow the directive behind it — vhs opens a regex at "
            . 'any token start, so binding this to one keyword would leave the others open',
        );
    }

    /** @return array<string, array{string}> */
    public static function regexHeadProvider(): array
    {
        return [
            'Set WaitPattern' => ['Set WaitPattern '],
            'Wait+Screen' => ['Wait+Screen      '],
            'Wait' => ['Wait '],
            'Type' => ['Type '],
            'line-initial' => [''],
            'after a closing quote' => ['Set WaitPattern "x"'],
        ];
    }

    /**
     * All three quotes are literal inside a regex, so none of them may be
     * mistaken for the token's real delimiter.
     *
     * Measured: `Set WaitPattern /a"b/ Source nofile.tape` resolves the
     * `Source`, as do the `'` and `` ` `` spellings. A tokenizer that let a
     * quote inside a regex open a string would mis-lex the rest of the line.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('quoteInsideRegexProvider')]
    public function testAQuoteInsideARegexIsLiteral(string $quote): void
    {
        $tape = $this->scratchTape("Set WaitPattern /a{$quote}b/ Set Shell \"sh\"\n");

        self::assertSame(
            ['sh'],
            self::directiveValues($tape, 'Set Shell'),
            "a {$quote} inside a regex is ordinary pattern text to vhs, not a string opener",
        );
    }

    /** @return array<string, array{string}> */
    public static function quoteInsideRegexProvider(): array
    {
        return ['double quote' => ['"'], 'single quote' => ["'"], 'backtick' => ['`']];
    }

    /**
     * The control for the fix above: mid-token a `/` is INERT, and the shipped
     * tapes depend on that.
     *
     * `Output .vhs/cli.gif` and `Type ./bin/sugarcrush --help | head -20` are in
     * cli.tape today, so a rule that opened a regex on every `/` would tear
     * both apart and this suite would stop describing the tapes it walks. The
     * inertness is measured, not assumed — `Set WaitPattern a/b#c/d Source
     * nofile.tape` validates clean, i.e. the `#` really did open a comment and
     * the `Source` really was never lexed.
     */
    public function testAMidTokenSlashDoesNotOpenARegex(): void
    {
        $inert = $this->scratchTape("Set WaitPattern a/b#c/d Set Shell \"sh\"\n");

        self::assertSame(
            [],
            self::directiveValues($inert, 'Set Shell'),
            'a mid-token `/` is inert upstream, so the `#` after it opens a comment and the '
            . '`Set Shell` behind it is genuinely dead text. Reporting it would be a false '
            . 'alarm on a tape vhs renders happily',
        );

        $paths = $this->scratchTape(
            "Output .vhs/cli.gif\nType \"./bin/sugarcrush --help | head -20\"\n",
        );

        self::assertSame(
            ['.vhs/cli.gif'],
            self::directiveValues($paths, 'Output'),
            'the slash in an Output path is ordinary text — splitting on it would break '
            . 'every tape in this lib',
        );
        self::assertSame(
            ['./bin/sugarcrush --help | head -20'],
            self::directiveValues($paths, 'Type'),
            'cli.tape types this exact string, pipe included',
        );
    }

    /**
     * An unterminated regex runs to the newline and takes the rest of the line
     * with it — PROVIDED no backslash reaches the newline first.
     *
     * That proviso is the whole correction to this docblock. An earlier revision
     * said an unterminated regex ends at the newline "exactly as an unterminated
     * quote does" and that "the newline closes the regex", flat. Neither is
     * true in general: one backslash before the newline carries the regex onto
     * the next line, which is F3 in {@see tokenize()} and is pinned by
     * {@see testABackslashBeforeANewlineCarriesARegexOntoTheNextLine()}. What is
     * true is the no-backslash case, which is what both halves below measure —
     * and which is exactly why they kept passing throughout the defect.
     *
     * Both halves are measured. `Set WaitPattern /a#b Set Shell "sh"` validates
     * clean and renders: the pattern swallowed `#b Set Shell "sh"`, so there is
     * no `Set Shell` to report and claiming one would be a false alarm. And with
     * no backslash the newline really does close it rather than a `/` on a later
     * line — `Set WaitPattern /a` / `b/ Source nofile.tape` answers both
     * `Invalid command: b/` and `File nofile.tape not found`, so the regex
     * ended at the newline with `a` in it and the next line lexed normally.
     */
    public function testAnUnterminatedRegexEndsAtTheNewline(): void
    {
        $swallowed = $this->scratchTape("Set WaitPattern /a#b Set Shell \"sh\"\n");

        self::assertSame(
            [],
            self::directiveValues($swallowed, 'Set Shell'),
            'an unterminated regex consumes the rest of its line, so upstream never lexes '
            . 'the `Set Shell` and this tape renders clean',
        );

        $nextLine = $this->scratchTape("Set WaitPattern /a\nSource nofile.tape\n");

        self::assertSame(
            ['nofile.tape'],
            self::directiveValues($nextLine, 'Source'),
            'the newline closes the regex, so the following line is live directives — a '
            . 'regex that scanned on for a `/` on a later line would hide them',
        );
    }

    /**
     * A string ends at ITS OWN newline, so a quote on a later line cannot reach
     * back and close it.
     *
     * `readString` breaks on `endChar`, on `0` and on `isNewLine`, and unlike
     * `readRegex` it has no escape branch to skip that test with — so the
     * newline always wins. Which quote a model picks as the closer decides how
     * many directives it can see: below, upstream reads `Type "a` as an
     * unterminated string on line 1, then `b`, `Set`, `Shell` and `"sh"` on
     * line 2, and abort the render on `invalid shell sh`. A model that scanned
     * forward for the next `"` anywhere would take `a<NL>b Set Shell ` as ONE
     * string, leave `sh` a bare word and the trailing `"` an empty string, and
     * report no `Set Shell` at all — a lost occurrence on a tape upstream
     * parses with zero errors and renders.
     *
     * Measured on upstream's lexer and parser: TYPE with Args `a b`, then
     * SET Shell `sh`, no errors. The bound is the `$close < $lineEnd` test in
     * {@see tokenize()}.
     */
    public function testAStringIsBoundedByItsOwnNewline(): void
    {
        $tape = $this->scratchTape("Type \"a\nb Set Shell \"sh\"\n");

        self::assertSame(
            ['sh'],
            self::directiveValues($tape, 'Set Shell'),
            'the newline closes the string on line 1, so line 2 is live directives. A string '
            . 'that scanned on for the next quote would swallow the `Set Shell` and this suite '
            . 'would report OK on a tape whose render aborts',
        );

        self::assertSame(
            ['a b'],
            self::directiveValues($tape, 'Type'),
            'and the value is the two tokens upstream joins with a space, not one string '
            . 'carrying a newline — vhs has no multi-line string',
        );
    }

    /**
     * A TAB separates tokens exactly as a space does, and the file contained no
     * tab byte until this test.
     *
     * Upstream's `isWhitespace` is `' '`, `\t`, `\n`, `\r` (`lexer.go:271-273`)
     * — a wider set than `isNewLine`, which is `\n` and `\r` only
     * (`lexer.go:279`) — and `skipWhitespace` runs it before every token. So a
     * tab is a token boundary everywhere a space is one, and a model that
     * dropped `\t` from its whitespace class left the whole suite GREEN: there
     * were zero literal tab bytes in this file's test data, only the `\t` inside
     * the implementation string that decides the answer.
     *
     * Both rows measured on all three oracles under THE THREE ORACLES in
     * {@see directiveValues()} — upstream's own `lexer.go`/`parser.go` run
     * directly, and `vhs validate` on each of the two v0.11.0 binaries, exit 0
     * everywhere:
     *
     *   `Output<TAB>.vhs/cli.gif`        OUTPUT `.vhs/cli.gif`, zero errors
     *   `Set Padding 1<TAB>Set Shell "sh"`  SET Padding `1` + SET Shell `sh`
     *
     * The first row is the one that ships. `Output` is the directive
     * {@see testOutputStaysInsideTheArtifactDirectory()} reads, and without the
     * tab in the whitespace class the tab becomes a one-byte ILLEGAL token
     * INSIDE the value — so the path assertion stops comparing paths and starts
     * comparing a path with a control byte glued to it. A tab is not exotic in a
     * hand-edited tape; it is what an editor inserts when someone lines a
     * directive up.
     *
     * The second row is the same axis as every finding before it: whether a byte
     * ends a run. The tab ends `1` and the `Set Shell` behind it is live
     * upstream, so it must be live here.
     */
    public function testATabSeparatesTokensLikeASpace(): void
    {
        $separator = $this->scratchTape("Output\t.vhs/cli.gif\n");

        self::assertSame(
            ['.vhs/cli.gif'],
            self::directiveValues($separator, 'Output'),
            'the tab is whitespace, so the value is the path alone. A model without `\t` in its '
            . 'whitespace class emits a one-byte ILLEGAL token for it and folds that byte into '
            . 'the value, which turns every path assertion in this suite into a comparison '
            . 'against a string the tape does not contain',
        );

        $glue = $this->scratchTape("Set Padding 1\tSet Shell \"sh\"\n");

        self::assertSame(
            ['sh'],
            self::directiveValues($glue, 'Set Shell'),
            'and a tab ends a run just as a space does, so the `Set Shell` behind it is a live '
            . 'directive — upstream: SET Padding `1`, SET Shell `sh`, zero errors, and the real '
            . 'render aborts on `sh`',
        );
        self::assertSame(
            ['1'],
            self::directiveValues($glue, 'Set Padding'),
            'same tape, the value side: `1` alone, with no tab and no second directive in it',
        );
    }

    /**
     * A bare `\r` ends a COMMENT, a STRING and a REGEX, exactly as a `\n` does.
     *
     * Upstream's `isNewLine` is `ch == '\n' || ch == '\r'` (`lexer.go:279`, with
     * a doc comment saying the split from `isWhitespace` exists for Windows
     * CRLF), and all three readers break on it: `readComment` (`lexer.go:118`),
     * `readString` (`lexer.go:133`) and `readRegex` (`lexer.go:156`). So `\r` is
     * the same "where does a token end" axis as every one of the twelve findings
     * before it, and until this test there was not ONE `\r` byte in this file's
     * test data — the only two occurrences were the implementation strings that
     * decide the answer. Two sabotages therefore passed the whole suite:
     * deleting `|| $char === "\r"` from {@see scanRegex()}, and shortening
     * {@see tokenize()}'s `$newlines` to `"\n"`.
     *
     * The scenario the first one hides is the first tape below.
     * `Set WaitPattern /a<CR> Set Shell "sh"<LF>` is REGEX `a` — the CR bounds
     * it — then `SET Shell sh`: zero errors, `vhs validate` exit 0, and the real
     * render aborts `failed to execute command: invalid shell sh`, exit 1, no
     * GIF, taking every later tape in the workflow's `set -euo pipefail` loop
     * with it. A regex that scanned past the CR swallows the `Set Shell` and
     * this file reports nothing: a lost occurrence, suite green.
     *
     * Every row measured on all three oracles under THE THREE ORACLES in
     * {@see directiveValues()} — upstream's own lexer and parser run directly,
     * and BOTH v0.11.0 binaries, whose exit status is quoted per row and is the
     * same on each. The three CRLF rows are the control: a CRLF tape must keep
     * working, since anything committed from Windows is one.
     */
    public function testACarriageReturnBoundsEveryTokenAnLfBounds(): void
    {
        $regex = $this->scratchTape("Set WaitPattern /a\r Set Shell \"sh\"\n");

        self::assertSame(
            ['sh'],
            self::directiveValues($regex, 'Set Shell'),
            'the CR closes the regex, so the `Set Shell` behind it is a live directive that '
            . 'aborts the render (measured: exit 1, `invalid shell sh`). A regex that only '
            . 'stopped at a LF would swallow it and report nothing',
        );
        self::assertSame(
            ['a'],
            self::directiveValues($regex, 'Set WaitPattern'),
            'and the pattern is `a` alone — upstream lexes REGEX `a`, not `a Set Shell `',
        );

        $string = $this->scratchTape("Type \"a\rb Set Shell \"sh\"\n");

        self::assertSame(
            ['sh'],
            self::directiveValues($string, 'Set Shell'),
            '`readString` breaks on isNewLine, so the CR closes the string on the first line '
            . 'and everything after it is live directives — upstream: STRING `a`, STRING `b`, '
            . 'SET Shell `sh`, zero errors, render aborts exit 1',
        );
        self::assertSame(
            ['a b'],
            self::directiveValues($string, 'Type'),
            'and the typed value is the two tokens upstream joins with a space, not one string '
            . 'carrying a CR',
        );

        $comment = $this->scratchTape("Set Padding 1 # why\rSet Shell \"sh\"\n");

        self::assertSame(
            ['sh'],
            self::directiveValues($comment, 'Set Shell'),
            'a comment ends at the CR too, so the directive on the far side of it is live. A '
            . 'comment that ran to the LF hides it — the loudest shape of the defect this whole '
            . 'file exists to prevent',
        );

        $crlf = $this->scratchTape("Set Theme \"TokyoNight\"\r\nSet Shell \"sh\"\r\n");

        self::assertSame(
            ['TokyoNight'],
            self::directiveValues($crlf, 'Set Theme'),
            'the control: a CRLF tape is an ordinary tape. Anything committed from Windows is '
            . 'one, and both bytes are whitespace between tokens',
        );
        self::assertSame(['sh'], self::directiveValues($crlf, 'Set Shell'), 'same tape, second row');

        $crOnly = $this->scratchTape("Output .vhs/x.gif\rSet Shell \"sh\"\r");

        self::assertSame(
            ['.vhs/x.gif'],
            self::directiveValues($crOnly, 'Output'),
            'a CR-only tape has no LF in it at all, so every token boundary on it is a CR — '
            . 'upstream parses this one with zero errors',
        );
        self::assertSame(['sh'], self::directiveValues($crOnly, 'Set Shell'), 'same tape, second row');

        $escaped = $this->scratchTape("Set WaitPattern /a\\\r");

        self::assertSame(
            ["a\\\r"],
            self::valuesWithNoPhpDiagnostic($escaped, 'Set WaitPattern'),
            'and the one place a CR does NOT bound a regex is the same place a LF does not: '
            . 'the byte after a backslash run is consumed untested, so upstream keeps the CR '
            . 'INSIDE the pattern (measured literal `a\\` + CR) and does not panic. Routed '
            . 'through valuesWithNoPhpDiagnostic() because THIS tape is the one that reaches '
            . 'scanRegex()\'s loop-header bound first in declaration order: it is the only tape '
            . 'in this file where a regex runs to EOF with neither a delimiter nor a newline to '
            . 'close it, so unrouted it turned that guard\'s mutant into a HANG here rather than '
            . 'an error in the routed panic-detector test further down. See the SCOPE section of '
            . 'valuesWithNoPhpDiagnostic()',
        );
    }

    /**
     * Two directives back to back: the token that ENDS one value BEGINS the
     * next directive, so the walk must stop on it and not step past it.
     *
     * The distinction is invisible on the five shipped tapes, every one of
     * which separates its directives with a newline and gives each a value, so
     * nothing here would notice a walk that consumed its own terminator. It
     * would cost one occurrence per adjacency — and `Output`, `Set Theme` and
     * `Set Height` are all asserted by COUNT, so the lost one is the one that
     * would have failed.
     *
     * All three rows measured on upstream's parser: two commands each, zero
     * errors.
     */
    public function testBackToBackDirectivesAreBothCounted(): void
    {
        $outputs = $this->scratchTape("Output a.gif Output b.gif\n");

        self::assertSame(
            ['a.gif', 'b.gif'],
            self::directiveValues($outputs, 'Output'),
            'upstream emits two OUTPUT commands here. Losing the second is how a second `Output` '
            . 'gets past testOutputStaysInsideTheArtifactDirectory, which asserts there is one',
        );

        $themes = $this->scratchTape("Set Theme \"A\" Set Theme \"B\"\n");

        self::assertSame(
            ['A', 'B'],
            self::directiveValues($themes, 'Set Theme'),
            'the terminator of the first value is the `Set` of the second directive; stepping '
            . 'over it would leave testThemeIsPinned unable to see an unpinned theme',
        );

        $heights = $this->scratchTape("Set Height 380 Set Height 999\n");

        self::assertSame(
            ['380', '999'],
            self::directiveValues($heights, 'Set Height'),
            'and the same for geometry, which is asserted by count before it is asserted by '
            . 'value',
        );
    }

    /**
     * An `@<time>` speed suffix belongs to the HEAD, not to the value.
     *
     * `parseSpeed` runs before the value is read, so `Type@100ms "hello"` types
     * `hello` — upstream: one TYPE command, Options `100ms`, Args `hello`, zero
     * errors. Getting this wrong does not lose a directive, it loses the VALUE:
     * the walk would start at the `@`, run to the `ms` keyword and answer
     * `@ 100`, which fails every path assertion in this suite with a message
     * about a path nobody typed.
     *
     * The unit is optional in `parseTime` (`Type@100 "hello"` is Options `100s`),
     * so both spellings are here. The `Ctrl+O` row is the control for the other
     * half of the rule: a suffix on a token that is NOT a head is ordinary text,
     * and `Ctrl+O` after a value still ends that value because `Ctrl` is a
     * keyword in its own right.
     *
     * ALL THREE UNITS are rowed, ONE ROW EACH, and the claim is worth stating
     * that pedantically because `48e0690c` made it while rowing two.
     * `parseTime` accepts MILLISECONDS, SECONDS or MINUTES (`parser.go:278`)
     * and `token.Keywords` maps `ms`, `s` and `m` respectively
     * (`token.go:114-116`). Each unit is its own conjunct of
     * {@see skipSpeedSuffix()}'s `in_array` list and each has to be killed
     * separately: dropping `ms` fails the `100ms` row, dropping `m` fails the
     * `1m` row, and dropping `s` failed NOTHING until the `Type@1s "abc"` row
     * below went in — the bare-number row above it defaults to seconds without
     * ever lexing an `s` token, so it does not reach the conjunct. Two of three
     * units covered is the same shape as a figure quoted without its domain:
     * true of the rows present, silent about the row that is not.
     *
     * AND THE LIST ITSELF is a conjunct, separately from its three entries.
     * Deleting the whole `in_array` while keeping `kind === 'ident'` makes the
     * walk step over ANY bare word after the number, and that survived every one
     * of this test's other eight rows — MEASURED that way round: with the list
     * deleted AND the killing row below ALSO removed, this test comes out GREEN
     * with every remaining row executed, so all eight really do survive rather
     * than merely running before the failure stops the method. (A row count is
     * quoted here and an assertion count deliberately is not: the first is what
     * the claim is about, the second cannot survive an edit to this file.) What
     * the retired sentence got wrong was the REASON: it said "each of them puts a
     * quoted STRING in that position", which is true of TWO of the eight. The
     * eight split three ways, and only the middle group is about a string:
     *
     *   * THREE put a genuine unit there — `Type@100ms`, `Type@1m`, `Type@1s`.
     *     The mutant steps over `ms`/`m`/`s` because they are `ident` tokens, and
     *     stepping over them is the CORRECT answer, so the list's absence cannot
     *     show.
     *   * TWO put a quoted STRING there — `Type@100 "hello"` and
     *     `Type@1"ms" "abc"`. `kind === 'ident'` turns a STRING away on its own,
     *     without the list ever being consulted. This is the group the retired
     *     sentence described, and it described it correctly.
     *   * THREE never reach that position at all. `Type "@" "abc"` is turned away
     *     at the ENTRY gate by `kind !== 'single'`, and `Set Padding -` and
     *     `Type abc Ctrl+O` contain no `@` for the walk to start on.
     *
     * `Type@100 abc` is the row that reaches it — a BARE typed word after a speed
     * suffix. Upstream is TYPE Options `100s`, Args `abc`,
     * ZERO errors, both binaries `vhs validate` exit 0; the mutant answers ``.
     * That is the silent direction on a tape vhs renders, and the realistic
     * spelling is `Type@100 ./bin/sugarcrush`, where the value that disappears is
     * a path {@see testTypedPathsResolveFromTheLibRoot()} would have checked.
     *
     * THE THREE GATE ROWS run in BOTH directions, which is why
     * {@see skipSpeedSuffix()} tests each token's KIND and its TEXT and needs
     * both: the two quoted rows are tokens whose text says suffix and whose kind
     * says value, the third is a token whose kind says suffix and whose text says
     * value. Pinning only the kind half left the text half GREEN for a round.
     *
     *   * `Type "@" "abc"` lexes STRING `@` then STRING `abc`, so upstream
     *     types `@ abc` — the `@` is content, not a suffix. A model matching on
     *     text alone skipped it and answered `abc`, dropping a character the
     *     tape types; that is the `kind === 'single'` conjunct.
     *   * `Type@1"ms" "abc"` is the UNIT end of it. A quoted `ms` is a STRING,
     *     not MILLISECONDS, so `parseTime` does not consume it and `parseType`'s
     *     `for p.peek.Type == token.STRING` loop takes BOTH strings: upstream is
     *     Options `1s`, Args `ms abc`, zero errors. Dropping the
     *     `kind === 'ident'` conjunct answers `abc` instead, which is the
     *     SILENT direction — {@see testTypedPathsResolveFromTheLibRoot()} then
     *     has one fewer value to check and a bad path inside the dropped token
     *     goes unchecked. This conjunct sat three lines below the two the
     *     previous round fixed, in the same method, in the round that declared
     *     the class closed.
     *   * `Set Padding -` is the TEXT twin of the first of those two, and the
     *     half that stayed unpinned when they went in. A `-` is a single-byte
     *     token exactly as `@` is, so `kind === 'single'` admits it and only
     *     `text === '@'` turns it away. Upstream reads SET Padding `-` with ZERO
     *     errors — `parseSet`'s `default:` arm again, `parser.go:527-528` — and
     *     all three oracles validate the tape clean, so dropping the text half
     *     answers `` on a tape vhs renders: a value lost silently, the direction
     *     that cannot announce itself. `Set Padding =` is the same measurement on
     *     a second of the nine single-byte tokens (upstream `=`, mutant ``), and
     *     `-` is the spelling {@see SINGLE_BYTE_TOKENS} already cites, which is
     *     why it is the row.
     *
     * ALL NINE rows measured on all three oracles under THE THREE ORACLES in
     * {@see directiveValues()}: zero parser errors on every one, and each row's
     * upstream command list is quoted beside its assertion. NINE IS THE COUNT OF
     * `scratchTape()` CALLS IN THE BODY BELOW — that is the domain, it is what
     * `grep -c 'scratchTape('` over the method answers, and it moves every time a
     * row is added. A revision of this file has already shipped "all three units"
     * over a body that rowed two, so the number and the body are checked together
     * or the number is not written.
     */
    public function testASpeedSuffixBelongsToTheHeadNotTheValue(): void
    {
        $withUnit = $this->scratchTape("Type@100ms \"hello\"\n");

        self::assertSame(
            ['hello'],
            self::directiveValues($withUnit, 'Type'),
            'the `@ 100 ms` between the head and the value is the head\'s speed, so it is '
            . 'stepped over rather than read as the value',
        );

        $withoutUnit = $this->scratchTape("Type@100 \"hello\"\n");

        self::assertSame(
            ['hello'],
            self::directiveValues($withoutUnit, 'Type'),
            '`parseTime` makes the unit optional and defaults to seconds, so a bare number is '
            . 'still a speed',
        );

        $minutes = $this->scratchTape("Type@1m \"abc\"\n");

        self::assertSame(
            ['abc'],
            self::directiveValues($minutes, 'Type'),
            '`m` is MINUTES — a real `parseTime` unit (`parser.go:278`, `token.go:116`), not a '
            . 'stray letter. Upstream: Options `1m`, Args `abc`, zero errors. A unit list of '
            . 'only `ms` and `s` leaves the `m` for the value walk, which finds a KEYWORD there '
            . 'and answers `` (measured; the retired message said `m abc`, which is what a '
            . 'non-keyword in that position would give)',
        );

        $seconds = $this->scratchTape("Type@1s \"abc\"\n");

        self::assertSame(
            ['abc'],
            self::directiveValues($seconds, 'Type'),
            'and `s` EXPLICITLY, because the bare-number row above defaults to seconds without '
            . 'lexing an `s` token at all, so it does not reach the unit list. Upstream: Options '
            . '`1s`, Args `abc`, zero errors. Dropping `s` from that list was the one of the '
            . 'three units nothing killed; measured, the mutant answers `` here, because `s` is '
            . 'itself a keyword and so terminates the value it starts',
        );

        $bareWordValue = $this->scratchTape("Type@100 abc\n");

        self::assertSame(
            ['abc'],
            self::directiveValues($bareWordValue, 'Type'),
            'the UNIT LIST is its own conjunct: without it any bare word after the number is '
            . 'read as a unit and stepped over. Every other row here puts a quoted STRING in '
            . 'that position, which the kind gate rejects on its own, so this was the only shape '
            . 'that reached it. Upstream: Options `100s`, Args `abc`, zero errors, both binaries '
            . 'validate clean — and the mutant answers ``, which as `Type@100 ./bin/x` is a '
            . 'typed path that vanishes before any assertion sees it',
        );

        $quotedAt = $this->scratchTape("Type \"@\" \"abc\"\n");

        self::assertSame(
            ['@ abc'],
            self::directiveValues($quotedAt, 'Type'),
            'a QUOTED `@` is a STRING token, so it is content and not a speed suffix — upstream '
            . 'types `@ abc`. This is the F3 defect one construct along: matching the suffix on '
            . 'the token text alone rather than on its KIND drops a character the tape types',
        );

        $quotedUnit = $this->scratchTape("Type@1\"ms\" \"abc\"\n");

        self::assertSame(
            ['ms abc'],
            self::directiveValues($quotedUnit, 'Type'),
            'the same defect at the UNIT end of the suffix. A quoted `ms` is a STRING, not '
            . 'MILLISECONDS, so `parseTime` leaves it and `parseType` takes both strings — '
            . 'upstream: Options `1s`, Args `ms abc`, zero errors. Matching the unit on text '
            . 'alone answers `abc` and SILENTLY drops a typed string, which is one fewer value '
            . 'for testTypedPathsResolveFromTheLibRoot() to check',
        );

        $notAnAt = $this->scratchTape('Set Padding -');

        self::assertSame(
            ['-'],
            self::directiveValues($notAnAt, 'Set Padding'),
            'a `-` is a single-byte token like `@`, so only the SUFFIX GATE\'S TEXT half turns '
            . 'it away. Upstream reads SET Padding `-`, zero errors, all three oracles clean; a '
            . 'gate on the kind alone steps over it and answers `` — a value dropped silently '
            . 'on a tape vhs renders, which is the twin of the quoted rows above',
        );

        $modifier = $this->scratchTape("Type abc Ctrl+O\n");

        self::assertSame(
            ['abc'],
            self::directiveValues($modifier, 'Type'),
            'upstream types `abc` and then presses Ctrl+O, so `Ctrl` ends the value — the `+` '
            . 'and the `O` are the next directive\'s business, not this value\'s',
        );
    }

    /**
     * The THREE BOUNDS in {@see skipSpeedSuffix()}: the walk may not read past
     * the last token of the stream. ALL THREE on tapes upstream ACCEPTS.
     *
     * These are the only guards in this model whose mutant changes NO ANSWER.
     * Dropping one makes PHP read `$tokens[$count]`, which yields null, and the
     * comparison that follows then fails exactly as the in-range comparison it
     * replaced would have — so the value that comes back is byte-identical and
     * the only observable is the diagnostic pair `Undefined array key <n>` plus
     * `Trying to access array offset on null`, two per query, measured on each row
     * below. A test for a bounds guard is therefore a test for a DIAGNOSTIC, which
     * is why every row goes through {@see valuesWithNoPhpDiagnostic()} rather than
     * relying on `failOnWarning="true"`: see that method for what the difference
     * buys.
     *
     * WHAT PUTS THE WALK AT THE END OF THE STREAM is a head whose suffix position
     * runs into EOF, and the reachable spelling is DIFFERENT FOR EACH GUARD, which
     * is why there are three tapes and not one head with three tails. The obvious
     * head is the wrong one at two of the three: `Type@` is two errors upstream
     * (`Expected time after @`, `@ expects string`) and `Type@100` is one
     * (`100 expects string`), because `parseType` demands a STRING after the
     * speed. So `Type` cannot supply an ACCEPTED tape for guards 2 and 3 at all,
     * and an earlier draft of this test used it for both and then claimed only the
     * first guard was reachable on an accepted tape. All three are. Each row
     * therefore names the head that reaches its guard with ZERO parser errors, on
     * all three oracles under THE THREE ORACLES in {@see directiveValues()}:
     *
     *   `$i >= $count`                 `Output a.gif` NL `Hide` NL, query `Hide`
     *     upstream OUTPUT `.gif`/`a.gif` then HIDE with EMPTY Options AND Args.
     *     `parseHide` builds a Command and nothing else (`parser.go:548-551`), so
     *     this row's `['']` is upstream's answer EXACTLY — the only one of the
     *     three where the model and upstream agree on the value as well as on the
     *     occurrence.
     *   `$i < $count` before the NUMBER   `Set Padding @`, query `Set Padding`
     *     upstream SET Padding `@`, via `parseSet`'s ungated `default:` arm
     *     (`parser.go:527-528`). The value divergence is class 3 in
     *     {@see directiveValues()} and is already pinned by
     *     {@see testTheSetPlusAtDivergenceLosesTheValueNotTheHead()}; what is new
     *     here is that the `@` is the LAST token, which is what walks off the end.
     *   `$i < $count` before the UNIT    `Output a.gif` NL `Enter@100`, query `Enter`
     *     upstream ENTER Options `100s`, Args `1`. A KEYPRESS head is what makes
     *     this one accepted — `parseKeypress` takes a speed and then
     *     `parseRepeat`, which returns `1` rather than erroring when no NUMBER
     *     follows (`parser.go:253-261`, `parser.go:406-411`). That synthesized
     *     count is divergence class 6, and it is unavoidable here: every head that
     *     accepts `@<number>` at EOF supplies a default of its own (`Wait@1` is
     *     Args `Line`, measured), so no accepted tape reaches this guard without
     *     one. THIS ROW IS ALSO WHAT MAKES CLASS 6 LIVE rather than inert — it is
     *     the only keypress-head query in the file, and the class's own entry in
     *     {@see directiveValues()} spent a commit calling itself inert three lines
     *     from the row that had just falsified it.
     *
     * Being reachable on accepted tapes is what separates these three from the
     * existence guard pinned in
     * {@see testATwoWordHeadNeedsBothWordsAndBothAsIdents()}, which cannot be:
     * its trigger is a trailing bare `Set`, and `parseSet` answers
     * `Unknown setting: \0` for that in every spelling.
     */
    public function testTheSpeedSuffixWalkStopsAtTheEndOfTheStream(): void
    {
        $headAtEof = $this->scratchTape("Output a.gif\nHide\n");

        self::assertSame(
            [''],
            self::valuesWithNoPhpDiagnostic($headAtEof, 'Hide'),
            'the ENTRY guard `$i >= $count`. `Hide` is the last token, so the suffix walk starts '
            . 'one past the end of the stream; without the guard this model reads $tokens[$count] '
            . 'and raises two PHP diagnostics while answering the same `` it answers now. '
            . 'Upstream: HIDE with empty Options and Args, ZERO errors, `vhs validate` exit 0 on '
            . 'both binaries — so this fires on a tape that renders, and the model agrees with '
            . 'upstream on the value too',
        );
        self::assertSame(
            ['a.gif'],
            self::valuesWithNoPhpDiagnostic($headAtEof, 'Output'),
            'same tape, and the control that it is an ordinary tape rather than a probe: the '
            . '`Output` this suite really does read is still found, with its real value',
        );

        $atAtEof = $this->scratchTape('Set Padding @');

        self::assertSame(
            [''],
            self::valuesWithNoPhpDiagnostic($atAtEof, 'Set Padding'),
            'the guard before the NUMBER test. The `@` is the last token, so the walk steps onto '
            . 'the end of the stream looking for a time. Upstream ACCEPTS this tape — zero '
            . 'errors, SET Padding `@` via parseSet\'s ungated default arm — so the diagnostic '
            . 'this guard prevents is one a rendering tape can raise. `Type@` cannot stand in '
            . 'here: upstream rejects it with two errors',
        );

        $unitPositionAtEof = $this->scratchTape("Output a.gif\nEnter@100");

        self::assertSame(
            [''],
            self::valuesWithNoPhpDiagnostic($unitPositionAtEof, 'Enter'),
            'and the guard before the UNIT test, one token further: `100` is the last token, so '
            . 'the walk steps past it looking for `ms`/`s`/`m`. A KEYPRESS head is what makes '
            . 'this accepted — upstream is ENTER Options `100s`, Args `1`, zero errors, both '
            . 'binaries validate clean. The `1` is upstream\'s own synthesized repeat count '
            . '(divergence class 6), not something this tape wrote',
        );
        self::assertSame(
            ['a.gif'],
            self::valuesWithNoPhpDiagnostic($unitPositionAtEof, 'Output'),
            'same tape, same control: the suffixed keypress at EOF costs no occurrence of the '
            . 'directive this suite actually reads',
        );
    }

    /**
     * The one divergence {@see skipSpeedSuffix()} costs, pinned in BOTH
     * directions: the value goes empty, and the directive behind it stays live.
     *
     * `skipSpeedSuffix()` is modelled for every head, not only upstream's three
     * (`parseType`, `parseKeypress`, `parseWait`). The previous docblock argued
     * that this could only ever over-approximate, because a suffixed head "is
     * one upstream rejects outright". That is true of every COMMAND head and
     * FALSE of every `Set <setting>` head: `parseSet`'s `default:` arm is
     * `cmd.Args = p.peek.Literal` with no type gate (`parser.go:527-528`), so an
     * `@` after a setting name is simply taken as the value.
     *
     * Measured on all three oracles under THE THREE ORACLES in
     * {@see directiveValues()} — upstream's own `lexer.go`/`parser.go` run
     * directly, and `vhs validate` on both v0.11.0 binaries:
     *
     *   `Set Padding @Output x.gif`   ZERO errors, validate exit 0.
     *                                 upstream SET Padding `@` + OUTPUT `x.gif`
     *                                 model    SET Padding ``  + OUTPUT `x.gif`
     *
     * So on a tape upstream ACCEPTS this model under-approximates the value,
     * the opposite of the retired claim. It is pinned rather than fixed because
     * it is not the direction that ships a false green, and this test is what
     * says so out loud: the tokens `skipSpeedSuffix()` steps over are an `@`, a
     * NUMBER and one of three unit keywords, none of which can be a directive
     * head, so NO OCCURRENCE IS LOST. The second assertion of each pair is that
     * claim as an assertion — the glued `Output` and the second `Set Theme` are
     * both still found — and it is the half that would actually matter, because
     * a lost `Output` is how a GIF lands outside the artifact glob.
     *
     * The empty value is asserted too, and deliberately as `['']` rather than
     * `[]`: the OCCURRENCE is still counted, only its text is short. If this is
     * ever narrowed to upstream's three heads the expectations become `['@']`
     * and `['@', 'TokyoNight']`, and that change is intentional — see the
     * trade-off recorded in {@see skipSpeedSuffix()}.
     */
    public function testTheSetPlusAtDivergenceLosesTheValueNotTheHead(): void
    {
        $glued = $this->scratchTape("Set Padding @Output x.gif\n");

        self::assertSame(
            ['x.gif'],
            self::directiveValues($glued, 'Output'),
            'THE HALF THAT MATTERS: `@` is a single-byte token, so the `Output` behind it is a '
            . 'live directive upstream and must be a live directive here. Losing it is the '
            . 'miss-direction defect this whole file exists to prevent',
        );
        self::assertSame(
            [''],
            self::directiveValues($glued, 'Set Padding'),
            'and the cost: upstream reads the `@` as Padding\'s value (`parseSet` default arm, '
            . 'no type gate) while this model steps over it and reports an empty value. One '
            . 'occurrence either way — a value divergence, not a missing directive',
        );

        $twice = $this->scratchTape("Set Theme @Set Theme \"TokyoNight\"\n");

        self::assertSame(
            ['', 'TokyoNight'],
            self::directiveValues($twice, 'Set Theme'),
            'both occurrences, in order, on a tape upstream parses with zero errors — upstream '
            . 'answers `[\'@\', \'TokyoNight\']`. The empty first value is loud wherever any '
            . 'assertion reads it: testThemeIsPinned() would fail on it rather than skip it',
        );
    }

    /**
     * A regex is a value, never a head — the same rule quoted tokens follow.
     *
     * `/Source/ nofile.tape` answers `Invalid command: Source`, exactly as
     * `"Source" nofile.tape` does, so the word inside the delimiters is text.
     * Treating it as a head would invent directives that upstream rejects.
     */
    public function testARegexIsNeverADirectiveHead(): void
    {
        $tape = $this->scratchTape("/Source/ nofile.tape\n");

        self::assertSame(
            [],
            self::directiveValues($tape, 'Source'),
            'a regex token is value text; upstream answers `Invalid command: Source` for it '
            . 'rather than resolving a file',
        );
    }

    /**
     * A keyword INSIDE a value never ends that value, because it is not an
     * `ident` token — which is the half of round 13's headline change that
     * nothing pinned.
     *
     * {@see startsDirective()} and {@see headMatches()} carry the SAME positive
     * `ident` gate, and it is upstream's own rule: `readIdentifier` is the only
     * reader whose result reaches `LookupIdentifier`, so a STRING, REGEX, JSON,
     * NUMBER, single-byte or ILLEGAL token can never be a keyword however its
     * text is spelled. The two gates were added together and only one was
     * measured — deleting the gate from `headMatches()` failed the suite,
     * deleting it from `startsDirective()` left the suite GREEN. That asymmetry
     * is what this test closes.
     *
     * The mutant that survived truncates `Type "abc" "Enter" "def"` to
     * `['abc']`, because the quoted `Enter` matches {@see KEYWORDS} by text.
     * Upstream emits ONE command — TYPE with Args `abc Enter def`, zero errors,
     * `vhs validate` exit 0 — so the truncation is a value the tape never wrote.
     *
     * The `"Source"` row is the same shape aimed at the escape hatch: `Source`
     * is the one keyword that pulls directives in from a file this suite does
     * not walk ({@see testNoTapeSourcesAnotherFile()}), so a model that read a
     * quoted `Source` as a head would report a sourced file on a tape that
     * types the word.
     *
     * The bare-keyword row is the control, and it is the reason this test is
     * about the KIND and not about the text: with the quotes removed, `Enter`
     * IS an `ident`, so it really does end the value AND open a directive of its
     * own — upstream emits TYPE `abc`, ENTER, TYPE `def`. All three tapes
     * measured on all three oracles under THE THREE ORACLES in
     * {@see directiveValues()}: upstream's own `lexer.go`/`parser.go` run
     * directly (zero errors, no panic on each), and `vhs validate` on both
     * v0.11.0 binaries, exit 0 on each. A tape upstream rejects could not be a
     * false green, so all three had to clear that bar before their expectations
     * meant anything.
     */
    public function testAQuotedKeywordIsNeverADirectiveHead(): void
    {
        $quoted = $this->scratchTape("Type \"abc\" \"Enter\" \"def\"\n");

        self::assertSame(
            ['abc Enter def'],
            self::directiveValues($quoted, 'Type'),
            'upstream lexes three STRING tokens and joins them into ONE TYPE whose Args are '
            . '`abc Enter def`. A model that let a quoted keyword end the value stops at '
            . '`Enter` and reports `abc` — a value the tape never wrote, on a tape vhs renders',
        );

        $escapeHatch = $this->scratchTape("Type \"abc\" \"Source\" \"def\"\n");

        self::assertSame(
            [],
            self::directiveValues($escapeHatch, 'Source'),
            'and the sharpest spelling: a quoted `Source` is typed text, not a Source '
            . 'directive. Reporting one here would put testNoTapeSourcesAnotherFile() into a '
            . 'failure on a tape that merely types the word',
        );
        self::assertSame(
            ['abc Source def'],
            self::directiveValues($escapeHatch, 'Type'),
            'same tape, the value side: one TYPE carrying all three strings',
        );

        $bare = $this->scratchTape("Type \"abc\" Enter Type \"def\"\n");

        self::assertSame(
            ['abc', 'def'],
            self::directiveValues($bare, 'Type'),
            'the control, and it must not be dropped: unquoted, `Enter` is an `ident`, so it '
            . 'IS a keyword and DOES end the value — upstream emits TYPE `abc`, ENTER, TYPE '
            . '`def`. Two occurrences here and one above is what makes the gate a test of the '
            . 'token KIND rather than of its text',
        );
    }

    /**
     * The SECOND word of a two-word head is gated the same two ways the first
     * one is: it has to exist, and it has to be an `ident`.
     *
     * {@see directiveValues()} takes a head like `Set Shell` as a run of tokens,
     * and both guards on that run went unpinned while the head's FIRST word had
     * a test of its own ({@see testAQuotedKeywordIsNeverADirectiveHead()}).
     * `Set Shell` is the most-queried two-word head in this file by a wide
     * margin, which is why the run is worth a test even where the mutant is
     * loud. The margin is not quoted here on purpose:
     * {@see testSetShellIsTheMostQueriedTwoWordHead()} ASSERTS it instead,
     * after this sentence shipped a tally of `16 of 28` against `Set Theme`'s
     * `4` that was measured on the PARENT commit and then written into the
     * commit that changed it — the round adding rows to this very test moved
     * all three of those numbers.
     *
     * THE KIND GUARD. `Set "Shell" "sh"` and `Set /Shell/ "sh"` lex a STRING and
     * a REGEX where the setting name belongs, and upstream's `parseSet` gates on
     * the TOKEN TYPE, so both answer `Unknown setting: Shell` — one parser
     * error, `SET` with empty Options, and CI's `set -euo pipefail` render loop
     * dies on the tape. Dropping the `kind !== 'ident'` conjunct makes this
     * model report `Set Shell` = `['sh']` on both, so the mutant can only ever
     * raise a FALSE ALARM on a tape CI already rejects — the benign twin of the
     * silent unit-kind gate in
     * {@see testASpeedSuffixBelongsToTheHeadNotTheValue()}. Both tapes here are
     * therefore ones upstream REJECTS, deliberately and unavoidably: `parseSet`
     * accepts no spelling of a quoted setting name, so there is no accepted tape
     * that exercises this conjunct.
     *
     * THE EXISTENCE GUARD, `$i + $arity <= $count`. A tape whose last token is a
     * bare `Set` has a head whose second word is off the end of the stream.
     * Upstream rejects that too (`Unknown setting: \0`, one error, `parseSet`
     * reading the EOF literal), but the guard is not about the answer — without
     * it this model reads `$tokens[$count]`, and a suite that raises
     * `Undefined array key` inside its own model is one whose next reader
     * silences the warning rather than restoring the bound. `phpunit.xml` sets
     * `failOnWarning="true"`, so that IS a non-zero exit — but the summary line
     * PHPUnit prints for it is `OK, but there were issues!`, which a human
     * scanning for the word FAILURES reads as a pass. This row therefore goes
     * through {@see valuesWithNoPhpDiagnostic()}, which promotes the diagnostic
     * to a thrown `\ErrorException` and so puts the guard's name in the ERRORS
     * block instead. {@see testTheSpeedSuffixWalkStopsAtTheEndOfTheStream()}
     * pins three more guards of exactly this class, all three on tapes upstream
     * ACCEPTS; THIS one cannot be, and that is a property of `parseSet` rather
     * than of the test — a trailing bare `Set` is `Unknown setting: \0` in every
     * spelling.
     *
     * ALL FOUR rows measured on all three oracles listed under THE THREE
     * ORACLES in {@see directiveValues()}: one `Unknown setting: Shell` error
     * each for the two quoted-name rows, one `Unknown setting: \0` for the
     * bare-`Set` row, and zero errors with SET Options `Shell` / Args `sh` for
     * the control.
     */
    public function testATwoWordHeadNeedsBothWordsAndBothAsIdents(): void
    {
        $quoted = $this->scratchTape("Set \"Shell\" \"sh\"\n");

        self::assertSame(
            [],
            self::directiveValues($quoted, 'Set Shell'),
            'a quoted setting name is not a setting name: upstream answers `Unknown setting: '
            . 'Shell` and emits SET with EMPTY Options, so there is no `Set Shell` here to '
            . 'report. A model that matched the head tail on text alone reports `sh` and fails '
            . 'testShellIsOneUpstreamVhsAccepts() on a tape that never set a shell',
        );

        $regex = $this->scratchTape("Set /Shell/ \"sh\"\n");

        self::assertSame(
            [],
            self::directiveValues($regex, 'Set Shell'),
            'and the same for a REGEX token — same error, same empty Options. Two kinds rather '
            . 'than one because the conjunct is positive: it admits `ident` and nothing else',
        );

        $truncated = $this->scratchTape("Set Theme \"A\"\nSet");

        self::assertSame(
            ['A'],
            self::valuesWithNoPhpDiagnostic($truncated, 'Set Theme'),
            'the last token is a bare `Set`, so the head\'s second word is past the end of the '
            . 'stream. The earlier occurrence is still reported and nothing is read out of '
            . 'bounds — the bare `Set` is upstream\'s `Unknown setting: \\0`, not a `Set Theme`',
        );

        $control = $this->scratchTape("Set Shell \"sh\"\n");

        self::assertSame(
            ['sh'],
            self::directiveValues($control, 'Set Shell'),
            'the control, so the three rows above cannot pass by a head that stopped matching '
            . 'altogether',
        );
    }

    /**
     * A regex honours backslash escapes, so an escaped `/` does NOT close it and
     * the directive behind the real closer is still live.
     *
     * Upstream counts the run of consecutive backslashes and an ODD count
     * escapes the delimiter — its own doc comment spells `/foo\/bar/`. A model
     * that closed at the first `/` read the rest as a comment and reported OK
     * while the render aborted, which is F2.
     *
     * The even-count row is the control and it is not decoration: an even run
     * escapes the BACKSLASH, not the delimiter, so `/a\\/` really does end
     * there and the `#` behind it really does open a comment. Upstream and this
     * model agreed on that row before the fix and must still agree after it —
     * reporting a `Set Shell` there would be a false alarm on a tape vhs renders
     * happily.
     *
     * Every row measured on both oracles: the token stream out of upstream's own
     * `lexer.go` and, for the three escaping rows, a real `vhs` run answering
     * `failed to execute command: invalid shell sh`, exit 1, no GIF.
     *
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('regexEscapeProvider')]
    public function testAnEscapedDelimiterDoesNotCloseARegex(string $pattern, array $expected, string $why): void
    {
        $tape = $this->scratchTape("Set WaitPattern {$pattern} Set Shell \"sh\"\n");

        self::assertSame($expected, self::directiveValues($tape, 'Set Shell'), $why);
    }

    /** @return array<string, array{string, list<string>, string}> */
    public static function regexEscapeProvider(): array
    {
        return [
            'one backslash escapes' => [
                '/a\\/#b/',
                ['sh'],
                'one backslash is an odd run, so the `/` after it is escaped and the regex runs '
                . 'on to the LAST `/`. Closing at the escaped one hides the `Set Shell` behind '
                . 'a comment that upstream never sees',
            ],
            'three backslashes escape' => [
                '/a\\\\\\/#b/',
                ['sh'],
                'three is odd too — upstream counts the run rather than looking at one byte',
            ],
            'escaped url' => [
                '/https:\\/\\/x#y/',
                ['sh'],
                'the spelling this actually shows up as: a URL pattern with escaped slashes and '
                . 'a `#` fragment, which is a plausible `Set WaitPattern` rather than a probe',
            ],
            'two backslashes do NOT escape' => [
                '/a\\\\/#b/',
                [],
                'an even run escapes the backslash, not the delimiter, so this regex genuinely '
                . 'ends at that `/` and the `#` genuinely opens a comment. Both oracles agree '
                . 'and so must this model — a `Set Shell` here is a false alarm',
            ],
        ];
    }

    /**
     * A backslash immediately before a newline carries a regex ONTO THE NEXT
     * LINE, so a `#` at the start of that line is inside the pattern and the
     * directive after it is still live.
     *
     * This one is a consequence of upstream's control flow rather than of its
     * intent: the escape branch of `readRegex` ends in `continue`, which
     * re-enters the loop at `readChar()` and so skips the `isNewLine` break for
     * the byte after the newline. It is the only place in the grammar where a
     * newline fails to bound a newline-bounded token.
     *
     * Measured on both oracles: upstream lexes ONE regex token spanning two
     * lines, `a\<NL>#b`, then `Set Shell sh`; `vhs validate` exits 0 and the
     * real render aborts with `invalid shell sh`, exit 1, no GIF. The
     * no-backslash control on the same shape does NOT cross the line, which is
     * exactly why {@see testAnUnterminatedRegexEndsAtTheNewline()} kept passing
     * throughout the defect.
     */
    public function testABackslashBeforeANewlineCarriesARegexOntoTheNextLine(): void
    {
        $crosses = $this->scratchTape("Set WaitPattern /a\\\n#b/ Set Shell \"sh\"\n");

        self::assertSame(
            ['sh'],
            self::directiveValues($crosses, 'Set Shell'),
            'the backslash makes upstream consume the newline into the pattern, so the `#` on '
            . 'the next line is pattern text and the `Set Shell` behind it is a live directive '
            . 'that aborts the render',
        );

        $control = $this->scratchTape("Set WaitPattern /a\n#b/ Set Shell \"sh\"\n");

        self::assertSame(
            [],
            self::directiveValues($control, 'Set Shell'),
            'without the backslash the newline does close the regex, so the next line really '
            . 'is a comment and reporting a `Set Shell` would be a false alarm',
        );
    }

    /**
     * The JSON token, `{` … `}` — the fifth thing that can hide a `#`, and the
     * only asymmetric delimiter pair in the grammar.
     *
     * It is the one this file missed for ten rounds, for two structural reasons
     * the earlier delimiter model could not express: the closer is a DIFFERENT
     * byte from the opener, and no newline bounds it. Both are exercised below,
     * along with the three rows where hiding is CORRECT — an unterminated `{`
     * really does swallow the rest of the file upstream too, and a JSON token
     * really is never a directive head.
     *
     * And it is not an exotic construct that only a probe would write. `Set
     * Theme` is upstream's own documented use for it — a JSON object of base-16
     * colours, which every entry of is a `#`-prefixed hex string — and `Set
     * Theme` is the one directive all five of these tapes pin. So the realistic
     * spelling of this defect is a tape that swapped `Set Theme "TokyoNight"`
     * for the equivalent theme object: `{ "name": …, "background": "#1a1b26" }`
     * puts a `#` on the line and everything after it out of this suite's reach.
     * That row is below, measured on both oracles including a real render that
     * aborts with `invalid shell sh`.
     *
     * The `Source` row is the one that matters most, though — it defeats
     * {@see testNoTapeSourcesAnotherFile()}, i.e. it hides the escape hatch out
     * of every other assertion in this file.
     *
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('jsonTokenProvider')]
    public function testTheJsonTokenIsModelledIncludingItsAsymmetry(
        string $source,
        string $directive,
        array $expected,
        string $why,
    ): void {
        $tape = $this->scratchTape($source);

        self::assertSame($expected, self::directiveValues($tape, $directive), $why);
    }

    /** @return array<string, array{string, string, list<string>, string}> */
    public static function jsonTokenProvider(): array
    {
        return [
            'hides Set Shell' => [
                "Set WaitPattern {#} Set Shell \"sh\"\n",
                'Set Shell',
                ['sh'],
                '`{#}` is one JSON token to upstream, so the `Set Shell` behind it is live and '
                . 'aborts the render — the false green this whole test class exists to prevent',
            ],
            'hides Set Shell under Env' => [
                "Env {#} x Set Shell \"sh\"\n",
                'Set Shell',
                ['sh'],
                'the JSON token is not tied to one head; `Env` takes it too, so binding this to '
                . '`Set WaitPattern` would leave the other heads open',
            ],
            'realistic theme object' => [
                "Set Theme { \"name\": \"TokyoNight\", \"background\": \"#1a1b26\" } Set Shell \"sh\"\n",
                'Set Shell',
                ['sh'],
                'the spelling this defect would actually arrive as: `Set Theme` takes a JSON '
                . 'object of base-16 colours upstream, every value of which is a `#` hex string, '
                . 'and `Set Theme` is the one directive all five tapes pin. Both oracles agree '
                . 'and the real render aborts with `invalid shell sh`',
            ],
            'hides Source' => [
                "Set WaitPattern {#} Source shared.tape\n",
                'Source',
                ['shared.tape'],
                'this is the worst spelling of the defect: `Source` is the escape hatch out of '
                . 'every assertion in this suite, so a `{#}` in front of it puts the whole file '
                . 'to sleep at once',
            ],
            'crosses lines' => [
                "Set WaitPattern {a\n#b} Set Shell \"sh\"\n",
                'Set Shell',
                ['sh'],
                'no newline bounds a JSON token — upstream reads to the `}` however many lines '
                . 'away it is, so the `#` on the second line is inside the token',
            ],
            'breaks a bare token' => [
                "Set WaitPattern a{#}b Set Shell \"sh\"\n",
                'Set Shell',
                ['sh'],
                'unlike `/`, a `{` is NOT position-dependent: it ends a bare word run and opens '
                . 'a JSON token mid-word, so `a{#}b` is three tokens and the `Set Shell` behind '
                . 'them is lexed. Upstream then rejects the tape on the two stray tokens, which '
                . 'is a CI failure this suite would otherwise report nothing about',
            ],
            'unterminated swallows the file' => [
                "Set WaitPattern {a Set Shell \"sh\"\n",
                'Set Shell',
                [],
                'an unterminated `{` runs to EOF upstream, parses clean and renders, so the '
                . '`Set Shell` genuinely never exists. Reporting it would be a false alarm',
            ],
            'is never a head' => [
                "{Source} nofile.tape\n",
                'Source',
                [],
                'a JSON token is value text; upstream answers `Invalid command: {Source}` for '
                . 'it rather than resolving a file, exactly as it does for `"Source"`',
            ],
            'keeps its braces in the value' => [
                "Set WaitPattern {\"a\":\"#\"}\n",
                'Set WaitPattern',
                ['{"a":"#"}'],
                'upstream stores the JSON literal WITH both braces, unlike a string whose '
                . 'quotes it strips, so the value of the directive includes them',
            ],
            'synthesizes the closer at EOF' => [
                'Set WaitPattern {#',
                'Set WaitPattern',
                ['{#}'],
                'upstream APPENDS a `}` the input never supplied — `readJSON` builds the '
                . 'literal rather than slicing it — so `{#` at EOF is the three bytes `{#}`, '
                . 'measured on upstream\'s own lexer and `vhs validate` exit 0. Nothing pinned '
                . 'the synthesized closer, so a model that emitted `{}` or `{#` here stayed '
                . 'green; the row above only covers the terminated spelling',
            ],
            'the swallowed span is the value, closer and all' => [
                "Set WaitPattern {abc\nSet Shell \"sh\"\n",
                'Set WaitPattern',
                ["{abc\nSet Shell \"sh\"\n}"],
                'the value side of `unterminated swallows the file`, and the reason that row is '
                . 'AGREEMENT rather than a miss: upstream\'s own literal contains the whole '
                . '`Set Shell` line plus a synthesized `}`, so reporting no `Set Shell` matches '
                . 'upstream exactly. It looks like the defect this file exists to prevent and is '
                . 'not one — pinned so nobody "fixes" it into a real divergence',
            ],
        ];
    }

    /**
     * A directive head GLUED to the previous value is still a head, because
     * upstream ended the previous token at the glue byte.
     *
     * This is the third form of the one defect this file keeps re-admitting,
     * and the first two forms cost eleven rounds between them: hide a `#` and
     * everything behind it is a comment; end a token one byte late and the
     * next directive's HEAD is inside the previous value, so nothing behind it
     * is ever matched. Both lose an occurrence, which is the only direction
     * that can ship green.
     *
     * The model that produced this took a bare token as "a run up to the next
     * of nine break bytes". 192 bytes were therefore glue — measured over the
     * whole domain, `Set Padding <B>Output x.gif` for every B in 1-255, and the
     * classification is in {@see tokenize()} — and every row below is a tape
     * upstream parses with ZERO errors, `vhs validate` exits 0 on, and a
     * break-set model reported OK for. The `}` row is the shortest: upstream reads
     * `SET Padding }` then `SET Shell sh` and aborts the render with
     * `invalid shell sh`, exit 1, taking every later tape in the workflow loop
     * with it.
     *
     * Each row names the glue byte's own token kind, because that is the reason
     * it is glue and not a break: `}` and `_` are ILLEGAL, `-` `%` `[` are
     * single-byte tokens, `0` and `50%`'s digits end an identifier by starting
     * a number. All five arms have to run BEFORE the identifier reader for
     * these to work, which is the order {@see tokenize()} takes them in.
     *
     * The `\x80` row is here because every row above it is a printable ASCII
     * byte — that much is true of the ROWS — while the recorded glue set they
     * stood in for was the SEVEN-BIT one, which is a different thing and is what
     * this sentence used to call printable as well. Of its 64 bytes only 35 are
     * printable; the other 29 are the control bytes `\x01`-`\x08` `\x0b` `\x0c`
     * `\x0e`-`\x1f` `\x7f`, so a reader who took the label at its word would hunt
     * the defect among the punctuation and never reach `\x1b`. (This is the
     * fourth of the four sites the class docblock records; `2bd2263f` fixed three
     * and said there were three.) Beyond the seven-bit slice, 128 of the
     * 192 glue bytes are `\x80`-`\xff`, each an ILLEGAL one-byte token
     * lexically identical to the `}` of the `ILLEGAL } glues Source` row — named
     * rather than counted, because this sentence said "two rows up" and that row
     * is THREE up from the `\x80` one in {@see gluedHeadProvider()}'s order
     * (positions 6 and 9 of ten), which is one more off-by-one in a docblock
     * about off-by-ones. Measured the same way as the
     * rest (upstream's own parser, zero errors, `vhs validate` exit 0), and note
     * what the row asserts: the two OUTPUT OCCURRENCES, not the glue byte's own
     * value. Upstream's literal for a high byte is the UTF-8 re-encoding
     * `\xc2\x80` because the Go lexer widens the byte to a rune, so the value
     * upstream reports for `Set Padding` is two bytes where this model reports
     * one. That residual is boundary-neutral — it changes no token's start or
     * end, and this model never consumes an upstream literal — which is why the
     * assertion is written on the occurrence count and not on the byte.
     *
     * The spaced control is not decoration. It is the only row a break-set
     * model also got right, so a suite that ran only the control would have
     * passed throughout — exactly as the ten-round `#` defect did.
     *
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('gluedHeadProvider')]
    public function testAGluedDirectiveHeadIsStillAHead(
        string $source,
        string $directive,
        array $expected,
        string $why,
    ): void {
        $tape = $this->scratchTape($source);

        self::assertSame($expected, self::directiveValues($tape, $directive), $why);
    }

    /** @return array<string, array{string, string, list<string>, string}> */
    public static function gluedHeadProvider(): array
    {
        return [
            'ILLEGAL } glues Set' => [
                "Set Padding }Set Shell \"sh\"\n",
                'Set Shell',
                ['sh'],
                '`}` outside a JSON token is ILLEGAL — one byte, one token — so upstream reads '
                . '`SET Padding }` and then `SET Shell sh`, and the render aborts on `sh`. A '
                . 'model that took `}Set` as one bare word never saw the second directive at all',
            ],
            'digit glues Set' => [
                "Set Padding 0Set Shell \"sh\"\n",
                'Set Shell',
                ['sh'],
                'a digit at a token start enters `readNumber`, whose run is `[0-9.]` and stops '
                . 'dead at `S`. `0Set` is not a token upstream can produce',
            ],
            'single-byte - glues Output' => [
                "Output .vhs/glued.gif\nSet Padding -Output evil.gif\n",
                'Output',
                ['.vhs/glued.gif', 'evil.gif'],
                '`-` is one of the nine single-byte tokens and its arm runs BEFORE the '
                . 'identifier reader, so it cannot start an identifier however much it belongs '
                . 'to one mid-run. The second Output is live, and lands outside the artifact glob',
            ],
            'single-byte % glues Output' => [
                "Output .vhs/glued.gif\nSet Padding %Output etc/x.gif\n",
                'Output',
                ['.vhs/glued.gif', 'etc/x.gif'],
                'same for `%`. Note the path has no leading slash: `/etc/x.gif` would put a `/` '
                . 'at a token start, which opens a REGEX and makes upstream reject the tape — so '
                . 'that spelling is not a false green and does not belong in this table',
            ],
            'percent after a number glues Set' => [
                "Set LoopOffset 50%Set Shell \"sh\"\n",
                'Set Shell',
                ['sh'],
                '`50%` is TWO tokens upstream, NUMBER `50` and `%`, because `%` is not in '
                . '`readNumber`\'s run class. `parseSet` then re-joins them for LoopOffset, which '
                . 'is why the value looks like one token in the command list and is not one',
            ],
            'ILLEGAL } glues Source' => [
                "Set Padding }Source real.tape\n",
                'Source',
                ['real.tape'],
                'the worst spelling again: `Source` is the escape hatch out of every assertion '
                . 'here, so gluing it to the previous value puts the whole file to sleep. This '
                . 'is the one row whose zero-error status depends on its surroundings: '
                . '`parseSource` stats the path, so upstream reports `File real.tape not found` '
                . 'unless a real.tape sits beside the tape. Measured both ways — with one there, '
                . '`vhs validate` exits 0 and upstream emits a SOURCE command',
            ],
            'ILLEGAL _ glues Set' => [
                "Set Theme \"TokyoNight\"\nSet Padding _Set Theme \"Nord\"\n",
                'Set Theme',
                ['TokyoNight', 'Nord'],
                '`_` is in `readIdentifier`\'s run class but is NOT in its entry test '
                . '(`isLetter || isDot`), so at a token start it is ILLEGAL. That asymmetry is '
                . 'invisible to any model that only knows which bytes break a run',
            ],
            'single-byte [ glues Set' => [
                "Set Height 380\nSet Padding [Set Height 999\n",
                'Set Height',
                ['380', '999'],
                '`[` is a single-byte token, so `Set Height 999` is live and the geometry this '
                . 'suite pins is no longer the geometry the tape asks for',
            ],
            'ILLEGAL high byte glues Output' => [
                "Output .vhs/glued.gif\nSet Padding \x80Output etc/x.gif\n",
                'Output',
                ['.vhs/glued.gif', 'etc/x.gif'],
                '`\x80` is ILLEGAL — one byte, one token — exactly as `}` is, and it stands in '
                . 'for the 128 bytes `\x80`-`\xff` that the recorded glue set left out while '
                . 'quoting a figure of 64. Measured on upstream\'s own parser: zero errors, two '
                . 'OUTPUT commands, `vhs validate` exit 0',
            ],
            'CONTROL: spaced, and always seen' => [
                "Set Padding } Set Shell \"sh\"\n",
                'Set Shell',
                ['sh'],
                'the one row a break-set model also got right, because whitespace breaks a run '
                . 'in every model. It is here so the table cannot pass by accident',
            ],
        ];
    }

    /**
     * The token kinds, one row per arm of upstream's `NextToken` switch.
     *
     * This is what makes each kind assignment in {@see tokenize()} load-bearing
     * rather than incidental. Three of them cannot be reached through
     * {@see directiveValues()} at all and so have to be pinned here directly:
     *
     *   * `json` — the literal upstream stores always carries both braces, so a
     *     JSON token's TEXT can never equal a keyword and marking it `ident`
     *     would change no directive lookup today. It is still wrong, and it is
     *     one edit away from mattering;
     *   * `number` and `single` — their texts are digits, dots and the nine
     *     punctuation bytes, none of which is in {@see KEYWORDS} either.
     *
     * `string` and `regex` ARE reachable — `"Source" x` and `/Source/ x` both
     * answer `Invalid command: Source` — and are pinned behaviourally as well,
     * by {@see testARegexIsNeverADirectiveHead()} and the quoted-head note in
     * {@see directiveValues()}.
     *
     * One deliberate coarseness shows up in the expectations: upstream's STRING
     * type covers BOTH a delimited string and a non-keyword identifier, because
     * `LookupIdentifier` returns STRING on a miss. This model keeps the two
     * apart by which READER produced the token — `ident` for `readIdentifier`,
     * `string` for `readString` — and does the keyword lookup one step later in
     * {@see startsDirective()}. That is why `nofile.tape` is `ident` here and
     * STRING upstream: same information, different place.
     *
     * Every row was read off upstream's own lexer, run directly.
     *
     * The call goes through {@see tokensWithNoPhpDiagnostic()} rather than
     * straight into {@see tokenize()} for a reason that has nothing to do with
     * token kinds: this test's `a trailing dot at EOF is an ident` row is the ONLY
     * row in this file that walks onto {@see tokenize()}'s `$i + 1 < $length` peek
     * bound, so it is the only place that guard can be killed — and unrouted it
     * was killed as one PHP warning in an otherwise-green run. See the SCOPE
     * section of {@see valuesWithNoPhpDiagnostic()} for the full per-guard
     * routing.
     *
     * @param list<array{string, string}> $expected [kind, text] per token
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tokenKindProvider')]
    public function testTheTokenizerAssignsUpstreamsTokenKinds(string $source, array $expected): void
    {
        $tokens = self::tokensWithNoPhpDiagnostic($source);

        $actual = array_map(
            static fn (array $t): array => [$t['kind'], $t['text']],
            $tokens,
        );

        self::assertSame($expected, $actual, 'token kinds/texts for ' . var_export($source, true));
    }

    /** @return array<string, array{string, list<array{string, string}>}> */
    public static function tokenKindProvider(): array
    {
        return [
            'ILLEGAL between identifiers' => ["Set Padding }Set Shell \"sh\"\n", [
                ['ident', 'Set'], ['ident', 'Padding'], ['illegal', '}'],
                ['ident', 'Set'], ['ident', 'Shell'], ['string', 'sh'],
            ]],
            'number then percent' => ["Set LoopOffset 50%\n", [
                ['ident', 'Set'], ['ident', 'LoopOffset'], ['number', '50'], ['single', '%'],
            ]],
            'speed suffix' => ["Type@100ms \"hi\"\n", [
                ['ident', 'Type'], ['single', '@'], ['number', '100'],
                ['ident', 'ms'], ['string', 'hi'],
            ]],
            'a path is one identifier' => ["Output .vhs/cli.gif\n", [
                ['ident', 'Output'], ['ident', '.vhs/cli.gif'],
            ]],
            'regex keeps no delimiters' => ["Set WaitPattern /a#b/ x\n", [
                ['ident', 'Set'], ['ident', 'WaitPattern'], ['regex', 'a#b'], ['ident', 'x'],
            ]],
            'json keeps both braces' => ["Set Theme {\"a\":\"#\"}\n", [
                ['ident', 'Set'], ['ident', 'Theme'], ['json', '{"a":"#"}'],
            ]],
            'json is never an ident' => ["{Source} nofile.tape\n", [
                ['json', '{Source}'], ['ident', 'nofile.tape'],
            ]],
            'a dot decides the reader' => [".5 .a\n", [
                ['number', '.5'], ['ident', '.a'],
            ]],
            // Both rows above give the `.` a next byte to look at. This one does
            // not: upstream's `peekChar()` answers 0 at EOF and `isDigit(0)` is
            // false, so a trailing `.` goes to `readIdentifier` and upstream
            // emits STRING `.` (measured). The model's entry test needs its
            // `$peek !== ''` conjunct to agree, because PHP's
            // `str_contains($haystack, '')` is TRUE — without it the `.` enters
            // the number reader instead. No tape ends in a bare `.` today, which
            // is exactly why the kind is pinned here rather than left to a
            // behavioural test that cannot reach it.
            'a trailing dot at EOF is an ident' => ['Set Padding .', [
                ['ident', 'Set'], ['ident', 'Padding'], ['ident', '.'],
            ]],
            'dots do not end a number' => ["Set Padding 1.2.3\n", [
                ['ident', 'Set'], ['ident', 'Padding'], ['number', '1.2.3'],
            ]],
            'a unit is its own token' => ["Sleep 5em\n", [
                ['ident', 'Sleep'], ['number', '5'], ['ident', 'em'],
            ]],
            'a modifier is its own token' => ["Ctrl+O\n", [
                ['ident', 'Ctrl'], ['single', '+'], ['ident', 'O'],
            ]],
            'the run class mid-word' => ["Type a_b-c%d/e\n", [
                ['ident', 'Type'], ['ident', 'a_b-c%d/e'],
            ]],
            'all nine single-byte tokens' => ["Set Padding [ ] ^ = \\ @ + - %\n", [
                ['ident', 'Set'], ['ident', 'Padding'],
                ['single', '['], ['single', ']'], ['single', '^'], ['single', '='],
                ['single', '\\'], ['single', '@'], ['single', '+'], ['single', '-'],
                ['single', '%'],
            ]],
            'backtick strings are strings' => ["Type `x`\n", [
                ['ident', 'Type'], ['string', 'x'],
            ]],
            'DEL is ILLEGAL, one token' => ["Type ab\x7fcd\n", [
                ['ident', 'Type'], ['ident', 'ab'], ['illegal', "\x7f"], ['ident', 'cd'],
            ]],
            'a comment produces no token' => ["Set Padding 1 # why\nSet Height 380\n", [
                ['ident', 'Set'], ['ident', 'Padding'], ['number', '1'],
                ['ident', 'Set'], ['ident', 'Height'], ['number', '380'],
            ]],
        ];
    }

    /**
     * The two byte classes, transcribed rather than sampled.
     *
     * They are the whole of upstream's answer to "where does a token end", so
     * their content is a fact about `lexer.go` and not a tuning knob. Pinned
     * for three reasons:
     *
     *   * a byte wrongly IN a run class glues the next head onto the previous
     *     value and loses the occurrence — the silent direction, and the one
     *     Finding 1 was;
     *   * a byte wrongly OUT of one splits `.vhs/cli.gif` or `./bin/sugarcrush`
     *     and every path assertion in this suite stops describing the tapes;
     *   * the ENTRY tests have to stay inside the run classes. `readNumber`
     *     entered on a byte outside `[0-9.]`, or `readIdentifier` on a byte
     *     outside its own class, would return an empty literal and leave
     *     `NextToken` looping on the same position forever — in the Go exactly
     *     as here. {@see tokenize()} composes the run classes out of the entry
     *     classes so that cannot be arranged by mistake, and the containment is
     *     asserted below so it cannot be arranged on purpose either.
     *
     * WHAT THIS TEST IS, EXACTLY, because overstating it would be the same
     * defect class as the glue-byte figure: it is a DRIFT DETECTOR, not a
     * derivation. Every equality below compares a constant against a hardcoded
     * copy of itself; nothing here reads `lexer.go` at test time, and nothing
     * can — upstream's Go source is not vendored, is not in this repo, and is
     * not present on a CI runner. So this test catches a CHANGED transcription
     * and can never catch a WRONG one. It still earns its place: it is what
     * makes the classes fail loudly when someone tunes a byte to make an
     * unrelated assertion pass, which is how the nine-byte break set survived
     * twelve rounds.
     *
     * WHAT DOES DERIVE THEM is the oracle procedure written out in
     * {@see directiveValues()} — upstream's `lexer.go`, `token.go` and
     * `parser.go` copied into a scratch module with only their three import
     * lines rewritten, `diff`-proven, driven over a generated corpus. The
     * classes below were transcribed from these predicates and then re-derived
     * that way:
     *
     *   `isDigit`      `'0' <= ch && ch <= '9'`                  `lexer.go:266`
     *   `isLetter`     ASCII, case-sensitive, no locale           `lexer.go:261`
     *   `readNumber`   `isDigit || isDot`                         `lexer.go:204`
     *   `readIdentifier` `isLetter || isDot || isDash ||
     *                   isUnderscore || isSlash || isPercent ||
     *                   isDigit`                                  `lexer.go:214`
     *   the nine `case` arms above `default`                  `lexer.go:40-67`
     *
     * Anyone re-checking these should run that procedure, not re-read this test.
     */
    public function testTheByteClassesAreUpstreamsOwn(): void
    {
        self::assertSame(
            '@=][-%^\\+',
            self::SINGLE_BYTE_TOKENS,
            'the nine `case` arms above `default` in upstream\'s NextToken switch, no more and '
            . 'no fewer. Each is ONE token; a byte dropped from here becomes part of a run and '
            . 'can glue the next directive\'s head to the previous value',
        );
        self::assertSame(9, \strlen(self::SINGLE_BYTE_TOKENS));

        self::assertSame('0123456789.', self::NUMBER_BYTES, '`isDigit(ch) || isDot(ch)`');
        self::assertSame(11, \strlen(self::NUMBER_BYTES));

        self::assertSame(
            'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.-_/%',
            self::IDENT_BYTES,
            '`isLetter || isDot || isDash || isUnderscore || isSlash || isPercent || isDigit`',
        );
        self::assertSame(67, \strlen(self::IDENT_BYTES));

        // `/` and `%` are the two nobody expects, and both are load-bearing:
        // `/` is what keeps `.vhs/cli.gif` one token, `%` what makes `a50%b`
        // one. Neither may leave, and neither may start a run.
        foreach (['/', '%', '-', '_', '.'] as $byte) {
            self::assertStringContainsString(
                $byte,
                self::IDENT_BYTES,
                "`{$byte}` is in readIdentifier's run class; dropping it splits paths and unit "
                . 'values this suite asserts on',
            );
        }

        // Entry ⊆ run, for both readers. The composition above makes this true
        // by construction; these are the assertions that say so, because
        // "by construction" is a claim and not a guarantee.
        $numberEntry = self::DIGIT_BYTES . '.';
        self::assertSame(
            \strlen($numberEntry),
            strspn($numberEntry, self::NUMBER_BYTES),
            '`readNumber` is entered on a digit or a `.`, so both must be in NUMBER_BYTES — '
            . 'otherwise it returns an empty literal and the token walk never advances past that '
            . 'byte, in the Go exactly as here',
        );

        $identEntry = self::LETTER_BYTES . '.';
        self::assertSame(
            \strlen($identEntry),
            strspn($identEntry, self::IDENT_BYTES),
            '`readIdentifier` is entered on a letter or a `.`, so every letter and the `.` must '
            . 'be in IDENT_BYTES, for the same reason',
        );
    }

    /**
     * KEYWORDS is upstream's whole keyword map, so its size is a fact and not a
     * matter of taste.
     *
     * Pinned because the list has been wrong twice in the direction of being
     * SHORT — 53 entries with a docblock asserting there was no 54th — and a
     * short list is only loud when a value runs on far enough to fail some other
     * assertion. The count comes from `token/token.go`; a future edit that drops
     * one fails here instead of somewhere unrelated three rounds later.
     */
    public function testTheKeywordSetIsUpstreamsWholeMap(): void
    {
        self::assertCount(
            60,
            self::KEYWORDS,
            'upstream `token.Keywords` has exactly 60 keys at v0.11.0. If this changed, it '
            . 'changed because the vhs version changed — re-extract the map, do not adjust the '
            . 'number',
        );

        self::assertSame(
            [],
            array_keys(array_filter(array_count_values(self::KEYWORDS), static fn (int $n): bool => $n > 1)),
            'a duplicate would make the count agree while the set was short',
        );

        // The seven that a CamelCase-only sweep can never reach, spelled
        // exactly as upstream's case-sensitive lookup wants them.
        foreach (['em', 'px', 'ms', 's', 'm', 'true', 'false'] as $lower) {
            self::assertContains(
                $lower,
                self::KEYWORDS,
                "`{$lower}` is a keyword upstream; a sweep over CamelCase candidates cannot "
                . 'produce it, which is how all seven went missing',
            );
        }

        // SUBSTITUTION is what the three assertions above cannot see: `End` for
        // `Home` and `Screenshot` for `Screenshots` both keep the count at 60,
        // add no duplicate and touch none of the seven lowercase names, so both
        // were GREEN. Adding a spurious keyword is the direction this constant's
        // own docblock calls the silent one — a name upstream does not treat as a
        // keyword cuts a value short and hides a defect — and `Home` is the exact
        // name that docblock warns about, because `token.IsCommand()` answers
        // true for it while `token.Keywords` has no such key.
        //
        // The expectation is not a copy of the constant. It is upstream's map,
        // extracted with `awk '/^var Keywords = map/,/^}/' token/token.go |
        // grep -oE '"[^"]+":' | tr -d '":' | LC_ALL=C sort` and compared
        // set-identical against the constant, 60 names against 60.
        $sorted = self::KEYWORDS;
        sort($sorted);

        self::assertSame(
            [
                'Alt', 'Backspace', 'BorderRadius', 'Copy', 'Ctrl', 'CursorBlink',
                'Delete', 'Down', 'End', 'Enter', 'Env', 'Escape', 'FontFamily',
                'FontSize', 'Framerate', 'Height', 'Hide', 'Insert', 'Left',
                'LetterSpacing', 'LineHeight', 'LoopOffset', 'Margin',
                'MarginFill', 'Output', 'Padding', 'PageDown', 'PageUp', 'Paste',
                'PlaybackSpeed', 'Require', 'Right', 'Screenshot', 'ScrollDown',
                'ScrollUp', 'Set', 'Shell', 'Shift', 'Show', 'Sleep', 'Source',
                'Space', 'Tab', 'Theme', 'Type', 'TypingSpeed', 'Up', 'Wait',
                'WaitPattern', 'WaitTimeout', 'Width', 'WindowBar',
                'WindowBarSize', 'em', 'false', 'm', 'ms', 'px', 's', 'true',
            ],
            $sorted,
            'the whole of upstream `token.Keywords` at v0.11.0, byte-sorted. A name SWAPPED '
            . 'rather than added or dropped keeps the count, the no-duplicates check and the '
            . 'seven lowercase names all green, so this is the only assertion here that can see '
            . 'it. If this changed, it changed because the vhs version changed — re-extract the '
            . 'map from `token/token.go`, do not edit this list to match the constant',
        );
    }

    /**
     * The nine shell names upstream accepts, transcribed from its own source.
     *
     * {@see VALID_SHELLS} was the one constant in this file with a live false-green
     * consequence and no drift pin: adding `'sh'` to it left the whole suite GREEN,
     * and `Set Shell "sh"` is both the abort the class docblock opens with and the
     * sentinel the lexer regressions throughout this file are built on. A tape
     * carrying it would then pass {@see testShellIsOneUpstreamVhsAccepts()} and take
     * the render job down.
     *
     * MEASURED against upstream v0.11.0's `shell.go`: nine `const` names at
     * `shell.go:5-13` and nine keys in `var Shells = map[string]Shell`
     * (`shell.go:23`), the same nine names. The ORDER pinned below is the MAP's,
     * not the const block's — the block is sorted by Go identifier, so `cmdexe`
     * and `nushell` sit in different places there than `cmd` and `nu` do here.
     *
     * `sh` is in neither, and the failure it causes is a RENDER failure and NOT a
     * validation one: on both binaries under THE THREE ORACLES in
     * {@see directiveValues()}, `vhs validate` on a tape carrying `Set Shell "sh"`
     * exits 0, while rendering the same tape answers `failed to execute command:
     * invalid shell sh`, exit 1, no GIF. That split is why the name must be pinned
     * in this file: the only cheap oracle anyone reaches for says nothing about it.
     */
    public function testTheValidShellSetIsUpstreamsOwn(): void
    {
        self::assertSame(
            ['bash', 'zsh', 'fish', 'powershell', 'pwsh', 'cmd', 'nu', 'osh', 'xonsh'],
            self::VALID_SHELLS,
            'the nine keys of `var Shells` (`shell.go:23`), in the order that map declares '
            . 'them — which is NOT the order of the `const` block at `shell.go:5-13`, since '
            . 'that one is sorted by Go identifier. A name ADDED here is the false green: the '
            . 'tape passes this suite and the render aborts',
        );

        self::assertNotContains(
            'sh',
            self::VALID_SHELLS,
            '`sh` above all, because it is the sentinel every lexer regression in this file '
            . 'uses. Upstream has no `sh` key, `vhs validate` exits 0 on it anyway, and the '
            . 'render dies with `invalid shell sh` — so this suite is the only thing that can '
            . 'catch it before CI does',
        );
    }

    /**
     * `Set Shell` really is the most-queried two-word head in this file, and the
     * WHOLE head tally is ASSERTED here rather than narrated anywhere.
     *
     * This test exists because the sentence that used to carry the figure —
     * in {@see testATwoWordHeadNeedsBothWordsAndBothAsIdents()} — quoted a tally
     * measured on the PARENT commit and shipped inside the commit that changed it:
     * that round's own new rows moved all three of its numbers. A narrated count
     * of this file's own call sites cannot survive an edit to this file, so it is
     * a count no comment should hold.
     *
     * DOMAIN: every LITERAL head argument at every call site of the two model
     * entry points ({@see directiveValues()} and
     * {@see valuesWithNoPhpDiagnostic()}) in this file, found by walking PHP's
     * OWN token stream over `__FILE__` — see {@see literalHeadArguments()}.
     *
     * WHY A TOKEN WALK AND NOT A `grep`. The retired body used a single regex,
     *
     *   self::(directiveValues|valuesWithNoPhpDiagnostic)\([^,]+, '([A-Za-z]+ [A-Za-z]+)'\)
     *
     * and stated its domain as "the literal two-word head arguments at this
     * file's model call sites, both entry points". It could not see either half
     * of that. `[^,]+` cannot cross a newline, so a call WRAPPED over several
     * lines was invisible: a genuine fifth `Set Padding` query written with its
     * head argument on a line of its own left the whole file GREEN, while the
     * identical call on ONE line turned it red — measured both ways. And `[A-Za-z]+ [A-Za-z]+` restricted
     * the tally to TWO-WORD heads, so `Output`, `Type`, `Source`, `Hide` and
     * `Enter` were outside the domain the sentence claimed. The scan below has
     * neither blind spot, which is what lets {@see directiveValues()}'s
     * divergence classes 4 and 6 CITE this tally instead of re-enumerating the
     * call sites in prose — the enumeration they used to carry went stale in the
     * same commit that widened it.
     *
     * NOT in the domain: heads a provider passes as DATA (the `$directive`
     * parameter of {@see testTheJsonTokenIsModelledIncludingItsAsymmetry()} and
     * {@see testAGluedDirectiveHeadIsStillAHead()}, and the inner call inside
     * {@see valuesWithNoPhpDiagnostic()} itself). Those are counted separately by
     * {@see literalHeadArguments()} and asserted below as an EXACT figure —
     * THREE — rather than as the non-zero floor they used to be. The floor was
     * the hole: a spelling the literal gate did not recognise fell through to the
     * dynamic counter, and a counter only asserted `> 0` swallows it in silence.
     * A NAMED ARGUMENT was exactly such a spelling and shipped green; it is
     * tallied properly now, and the exact figure is what makes any FUTURE
     * unrecognised spelling loud rather than merely unhandled. The number of
     * queries this suite actually RUNS is therefore higher than the tally; what
     * is pinned is which heads are asked for literally, how often, and the
     * RANKING — which is all the retired sentence used its figure for.
     */
    public function testSetShellIsTheMostQueriedTwoWordHead(): void
    {
        $source = file_get_contents(__FILE__);
        self::assertIsString($source, 'could not read this file');

        $scan = self::literalHeadArguments($source);

        self::assertSame(
            [
                'Enter' => 1,
                'Hide' => 1,
                'Output' => 8,
                'Set FontSize' => 1,
                'Set Height' => 2,
                'Set Padding' => 4,
                'Set Shell' => 20,
                'Set Theme' => 5,
                'Set WaitPattern' => 2,
                'Set Width' => 1,
                'Source' => 4,
                'Type' => 15,
            ],
            $scan['tally'],
            'every literal head argument at this file\'s model call sites, both entry points '
            . '(`directiveValues()` and `valuesWithNoPhpDiagnostic()`), one-word heads included. '
            . 'DOMAIN, STATED WITH ITS RESTRICTION rather than beside it: a call site is an '
            . 'occurrence of either name preceded by `::`, `->` or `?->` and followed by `(`. '
            . 'That gate is what keeps the two method DECLARATIONS out — they are preceded by '
            . '`function` — and it is ALSO a restriction: a call reached any other way (through '
            . 'a `Closure` handle, a callable string, a variable method name) is in neither '
            . 'this figure nor the dynamic count below, and this file has no such call site '
            . 'today. The `::`-only version of the same gate was the third silent shrinkage of '
            . 'this domain in three rounds; see testTheHeadScanSeesACallWrappedAcrossLines(). '
            . 'This assertion is MEANT to fail when a call is added or removed — that is the '
            . 'whole point, because the figure it replaces was written into a commit that '
            . 'changed it. Update the numbers here and nowhere else: no comment in this file '
            . 'quotes them, and the two divergence classes in directiveValues() that used to '
            . 'enumerate these call sites now point here instead',
        );

        $twoWord = array_filter(
            $scan['tally'],
            static fn (string $head): bool => str_contains($head, ' '),
            \ARRAY_FILTER_USE_KEY,
        );
        $others = $twoWord;
        unset($others['Set Shell']);

        self::assertGreaterThan(
            max($others),
            $twoWord['Set Shell'],
            'and the CLAIM rather than the tally: `Set Shell` is queried more often than any '
            . 'other two-word head, which is why its head-matching run is worth a test of its '
            . 'own even where the mutant is loud. This half stays true across edits that move '
            . 'the numbers above',
        );

        self::assertSame(
            3,
            $scan['dynamic'],
            'and the OTHER half of the domain, asserted EXACTLY rather than as a floor. '
            . 'DOMAIN: call sites of the two entry points in this file whose head argument is '
            . 'not a single string literal — the `$directive` parameter of the two '
            . 'provider-driven tests (testTheJsonTokenIsModelledIncludingItsAsymmetry() and '
            . 'testAGluedDirectiveHeadIsStillAHead()) and the inner call inside '
            . 'valuesWithNoPhpDiagnostic() itself, three in total. A FLOOR was what this used '
            . 'to be, and a floor is where a genuine literal head can hide: any spelling the '
            . 'literal gate does not recognise falls through to this counter, and '
            . '`assertGreaterThan(0, …)` accepts every one of them. Measured escapes that were '
            . 'silent under the floor and are red under this — each still NOT tallied, each '
            . 'moving THIS number: a concatenation (`\'Set \' . \'Padding\'`), a class '
            . 'constant, a spread (`...$args`), an enum case, a heredoc head, and the '
            . 'deprecated `"${x}"` interpolation. Two shapes that also used to be silent are '
            . 'red for the OTHER reason, by moving the TALLY rather than this number, because '
            . 'they are handled now: a named argument (`directive: \'Set Padding\'`) and an '
            . 'argument carrying a `#[Attribute]`, which scans to `Set Theme => 1` with the '
            . 'dynamic count unmoved. The same gate that keeps the tally honest is what bounds '
            . 'this figure: only calls reached through `::`, `->` or `?->` are counted at all',
        );
    }

    /**
     * The token-walk scanner behind {@see testSetShellIsTheMostQueriedTwoWordHead()},
     * against the one shape the `grep` it replaced could not see.
     *
     * THIS is what makes the tally's stated domain honest rather than aspirational.
     * A wrapped call is not hypothetical — this file's own house style wraps any
     * `assertSame()` whose arguments do not fit a line, and the assertion messages
     * here are long enough that most of them are wrapped already. The fifth
     * `Set Padding` query below is the exact shape that shipped GREEN past the
     * one-line regex, reduced to a fixture so the blind spot cannot reopen
     * silently: reverting the scanner to any line-oriented match fails this test
     * while leaving the tally above green, because no call site in this file is
     * wrapped TODAY.
     *
     * The second fixture is the other half — a head passed as a VARIABLE is a
     * call site the scan must find and must NOT tally, which is how the
     * provider-driven tests stay out of the tally without being invisible to it.
     *
     * TEN FIXTURES NOW, and each one is a shape the scanner GOT WRONG at some
     * point rather than a shape someone thought of: the wrapped call and the
     * variable head (the retired regex), the interpolated string, the nested call
     * and the array literal (depth counting), the declaration (the accessor
     * gate), the NAMED argument in both orders and the misnamed one (the
     * positional-index-1 assumption), the `#[Attribute]` (the second token-stream
     * asymmetry), and `$this->`/`?->` (the `::`-only accessor gate).
     * EIGHT of the ten occur nowhere in this file's real call sites, which is
     * precisely why each needs a fixture rather than a reader — a shape no call
     * site uses is a shape a reader has no reason to check. The two that DO occur
     * are the variable head (three sites, counted as dynamic) and the two method
     * DECLARATIONS, and both are in the fixture set anyway because the tally is
     * only right if the scan treats them as it does.
     *
     * THE `$this->` ROW IS THE THIRD ROUND OF ONE DEFECT, and it is worth naming
     * as such: the domain of the tally has now silently shrunk three times — from
     * "call sites the regex could see", to "call sites the single-token gate can
     * see", to "call sites reached through `::`". Each time the shrinkage lived
     * in a GATE that the prose presented as a benefit rather than as a
     * restriction. Whatever gate survives the next round, its restriction belongs
     * in the sentence that states the domain, not two paragraphs away.
     */
    public function testTheHeadScanSeesACallWrappedAcrossLines(): void
    {
        $wrapped = <<<'PHP'
            <?php
            self::assertSame(
                [''],
                self::valuesWithNoPhpDiagnostic(
                    $atAtEof,
                    'Set Padding',
                ),
                'why',
            );
            self::assertSame([], self::directiveValues($t, 'Output'), 'why');
            PHP;

        self::assertSame(
            ['Output' => 1, 'Set Padding' => 1],
            self::literalHeadArguments($wrapped)['tally'],
            'a call wrapped over several lines and a call on one line are the SAME call site. '
            . 'The regex this scanner replaced matched only the second, which is how a genuine '
            . 'fifth `Set Padding` query shipped green past a tally whose stated domain was '
            . '"this file\'s model call sites"',
        );

        $dynamic = <<<'PHP'
            <?php
            self::assertSame($expected, self::directiveValues($tape, $directive), $why);
            PHP;

        $scanned = self::literalHeadArguments($dynamic);

        self::assertSame(
            [],
            $scanned['tally'],
            'a head passed as a variable is not a literal head, so it contributes nothing to the '
            . 'tally — which is why the provider-driven tests are outside its domain',
        );
        self::assertSame(
            1,
            $scanned['dynamic'],
            'but it is still a call site, and it is COUNTED as one. Without this the scan could '
            . 'not tell "no dynamic sites" from "found nothing at all"',
        );

        $interpolated = <<<'PHP'
            <?php
            self::assertSame([], self::directiveValues("{$dir}/x.tape", 'Set Shell'), 'why');
            PHP;

        self::assertSame(
            ['Set Shell' => 1],
            self::literalHeadArguments($interpolated)['tally'],
            'and the FIRST of the token stream\'s three asymmetries: the `{` opening an '
            . 'interpolation is an ARRAY token while the `}` closing it is a bare string, so a '
            . 'depth count that matched only bare braces would go one closer over and truncate '
            . 'the argument list before reaching the head. No call site in this file passes an '
            . 'interpolated string today, which is precisely why the blind spot needs a fixture '
            . 'rather than a reader',
        );

        // The 8.2-DEPRECATED SPELLING of the row above, and the reason it is a
        // NOWDOC: `${dir}` inside a nowdoc is data, so this file never compiles
        // it and cannot emit the deprecation on any PHP. `token_get_all()`
        // lexes rather than compiles, so the scanner under test still sees the
        // opener it has to handle for as long as PHP emits one.
        $deprecatedInterpolation = <<<'PHP'
            <?php
            self::assertSame([], self::directiveValues("${dir}/x.tape", 'Set Font Size'), 'why');
            PHP;

        self::assertSame(
            ['Set Font Size' => 1],
            self::literalHeadArguments($deprecatedInterpolation)['tally'],
            'and the SAME asymmetry in its other spelling, which this file did not handle for '
            . 'four rounds while every other brace walker in the suite was given it one at a '
            . 'time. `"${dir}"` opens with T_DOLLAR_OPEN_CURLY_BRACES - a THIRD array token, '
            . 'whose text is `${` and not `{`, measured on PHP 8.3.6 - and closes with the same '
            . 'bare `}`, so a walker naming only T_CURLY_OPEN loses a level here exactly as one '
            . 'naming neither loses it above. Latent rather than live: the syntax occurs in no '
            . 'model call site, which is why it needs a fixture and not a reader',
        );

        $nested = <<<'PHP'
            <?php
            self::assertSame([], self::directiveValues(implode(',', $parts), 'Set Theme'), 'why');
            PHP;

        self::assertSame(
            ['Set Theme' => 1],
            self::literalHeadArguments($nested)['tally'],
            'and the last way a textual split goes wrong: a comma INSIDE an earlier argument. '
            . 'Every real call site here nests `$this->scratchTape(...)` in argument zero, so the '
            . 'nesting is the norm and only the comma is missing — which is why the depth guard '
            . 'on the split needs a row of its own rather than a note saying it looks necessary',
        );

        $bracketed = <<<'PHP'
            <?php
            self::assertSame([], self::directiveValues([$a, $b][0], 'Set Height'), 'why');
            PHP;

        self::assertSame(
            ['Set Height' => 1],
            self::literalHeadArguments($bracketed)['tally'],
            'the same depth argument for SQUARE brackets, which the round-brackets row above '
            . 'cannot reach: an array literal in argument zero puts a comma at depth two, and a '
            . 'scan that counted only parentheses would split on it',
        );

        $named = <<<'PHP'
            <?php
            self::valuesWithNoPhpDiagnostic(tape: $atAtEof, directive: 'Set Padding');
            self::valuesWithNoPhpDiagnostic(directive: 'Set Shell', tape: $t);
            PHP;

        self::assertSame(
            ['Set Padding' => 1, 'Set Shell' => 1],
            self::literalHeadArguments($named)['tally'],
            'a NAMED argument is a literal head argument, in either order. This is the shape '
            . 'that reopened the blind spot after the wrapped call closed it: the head walk '
            . 'returned `directive` `:` `\'Set Padding\'` — three tokens, not one — so the '
            . 'literal gate fell through to the dynamic counter, which was only asserted as a '
            . 'floor. Measured before the fix: a genuine fifth `Set Padding` query written this '
            . 'way left the whole file green while the positional spelling of the same call '
            . 'failed the tally',
        );

        $misnamed = <<<'PHP'
            <?php
            self::directiveValues(tape: $t, wrong: 'Set Height');
            PHP;

        $scannedMisnamed = self::literalHeadArguments($misnamed);

        self::assertSame(
            ['tally' => [], 'dynamic' => 1],
            $scannedMisnamed,
            'and the other side of the same gate: a name the scan does not know is not the head '
            . 'it is looking for. Without this the walk takes whatever sits at index one, so a '
            . 'renamed parameter would quietly start tallying the WRONG argument — and the '
            . 'dynamic count above is what keeps that from being silent',
        );

        $attributed = <<<'PHP'
            <?php
            self::directiveValues(array_map(static fn (#[\Foo] $s) => $s, $a)[0], 'Set Theme');
            PHP;

        self::assertSame(
            ['Set Theme' => 1],
            self::literalHeadArguments($attributed)['tally'],
            'and the token stream\'s THIRD asymmetry, the mirror of the two interpolation rows '
            . 'above: `#[` comes back as one `T_ATTRIBUTE` token while its `]` comes back bare, '
            . 'so a depth count matching only bare brackets decrements once more than it '
            . 'increments and truncates the argument list before the head. Measured before the '
            . 'fix, `php -l` clean: `tally=[] dynamic=1`, the head silently lost',
        );

        $instance = <<<'PHP'
            <?php
            self::assertSame(['1'], $this->valuesWithNoPhpDiagnostic($glue, 'Set Padding'), 'why');
            self::assertSame([], $that?->directiveValues($t, 'Set Width'), 'why');
            PHP;

        self::assertSame(
            ['Set Padding' => 1, 'Set Width' => 1],
            self::literalHeadArguments($instance)['tally'],
            'and the THIRD spelling of a call to a `private static` method: PHP accepts '
            . '`$this->` and `$x?->` for one as readily as `self::`, and this file already '
            . 'writes `$this->` for its own helpers thirty times over. Measured at the PARENT '
            . 'commit — a total safe to quote because the state it describes is gone — with the '
            . 'gate testing for `::` alone: a genuine fifth `Set Padding` query written '
            . '`$this->valuesWithNoPhpDiagnostic($glue, \'Set Padding\')` left the whole file '
            . 'GREEN at 114 tests / 552 assertions — neither tallied nor counted as dynamic, so '
            . 'invisible to BOTH exact figures — while the identical call written `self::` '
            . 'failed the tally with `Set Padding => 4` against 5. Same defect class as the '
            . 'wrapped call and the named argument before it, one spelling along',
        );

        $declaration = <<<'PHP'
            <?php
            private static function directiveValues(string $tape, string $directive): array
            {
            }
            PHP;

        self::assertSame(
            ['tally' => [], 'dynamic' => 0],
            self::literalHeadArguments($declaration),
            'and a DECLARATION is not a call site. This file declares both entry points, so '
            . 'without the accessor gate — the name must be preceded by `::`, `->` or `?->`, '
            . 'and a declaration is preceded by `function` — each declaration would be scanned '
            . 'as a call whose second parameter is not a literal, inflating the dynamic count '
            . 'with the very methods being counted',
        );
    }

    /**
     * The two structural figures this file quotes about its own model are
     * MEASURED from its own source rather than narrated.
     *
     * Both are here for one reason: for NINE consecutive rounds this file's
     * dominant defect has been a number that travelled without its domain, and
     * both of these numbers were being carried in prose — one of them inside the
     * very docblock whose subject is that defect.
     *
     *   BOUNDS COMPARISONS PER MODEL METHOD. Cited by the SCOPE section of
     *     {@see valuesWithNoPhpDiagnostic()}, which classifies every one of them
     *     as routed through a diagnostic promotion, loud without help, or not a
     *     bound at all. Its retired version said "every bounds guard" and "these
     *     four guards" over twelve of them.
     *     DOMAIN: comparison operators with `$count` or `$length` immediately on
     *     one side, in the method's own TOKEN stream — so neither a comment nor a
     *     string literal can contribute one. The exclusion matters: {@see tokenize()}
     *     discusses three of its own guards in an inline comment, and a count that
     *     read comments says ten where the code has four.
     *   CONJUNCTS PER MODEL METHOD. Cited by {@see skipSpeedSuffix()}'s pin list,
     *     whose retired heading said FOUR over eight.
     *     DOMAIN: the LEAVES of the boolean conditions in the method's own token
     *     stream — {@see CONJUNCT_ANCHORS} for each condition's base leaf,
     *     {@see CONJUNCT_OPERATORS} for each leaf joined onto it, and
     *     {@see unanchoredConditions()} for the base of a condition no anchor
     *     introduces. That is the number of leaves a conjunct-drop mutation
     *     sweep has to visit, which is what the pin list is an inventory of.
     *     NAMED EXCLUSIONS, so that "silent here" never has to be inferred:
     *     ternaries and `match` arms are a different mutation operator with its
     *     own entry in {@see tokenize()}'s register — and
     *     {@see sweepLeafCensus()} is where the ONE figure in this file that
     *     sweeps both operators together adds them back, by a subtraction that
     *     is asserted rather than described; `foreach` has no condition to drop;
     *     `do` is skipped because the `while` of a `do … while` already counts
     *     that loop's one condition.
     *
     *     THE DOMAIN HAS BEEN WIDENED TWICE AND THE FIGURES MOVED BOTH TIMES —
     *     the only figures in this file's history that have, and both times
     *     because the domain had been stated to be a leaf count while the
     *     instrument counted something narrower.
     *
     *       * LOOP CONDITIONS AND `??=`. `directiveValues` 5 → 8, `scanRegex`
     *         8 → 10, `tokenize` 17 → 18. Measured under the old domain, adding
     *         `while ($arity === -99) { break; }` or `$values ??= [];` to
     *         {@see directiveValues()} left the whole file GREEN at 114 tests /
     *         549 assertions while the `if` and `??` spellings of the same leaf
     *         each failed this test.
     *       * UNANCHORED CONDITIONS. `tokenize` 18 → 19; the other three have
     *         none and do not move. Measured under the old domain, adding
     *         `$unanchored = static fn (): bool => $arity > 0;` to
     *         {@see directiveValues()} left the whole file GREEN at 114 tests /
     *         549 assertions, and the two-leaf spelling of it moved this figure
     *         by ONE where a leaf count moves by two. This one was ALREADY IN
     *         THE FILE rather than only reachable: {@see tokenize()}'s
     *         `$terminated = $close !== false && $close < $lineEnd;` is the
     *         shape, and both of its leaves are kills — dropping
     *         `$close !== false` runs the tokenizer out of memory, dropping
     *         `$close < $lineEnd` fails three tests — so what was wrong was the
     *         FIGURE, not the sweep it feeds.
     *
     *     Neither is a re-baseline. Both are the same correction: the previous
     *     domain contradicted the register on {@see literalHeadArguments()},
     *     which counts each loop bound and each leaf of every condition under
     *     the same drop operator. `skipSpeedSuffix` has no loop, no `??=` and no
     *     unanchored condition, and stays at 8 through both, so the pin list it
     *     is cited by is untouched by either.
     *
     * WHY TOKENS AND NOT A REGEX, which is what both counters used to be. A
     * regex over the stripped source could be fooled in BOTH directions, and
     * both were measured on this file:
     *
     *   * SILENTLY, the direction that matters. `/\bif \(|&&|\|\|/` sees neither
     *     `and` nor `elseif (` (no word boundary between `else` and `if`) nor
     *     `??`. Adding a real extra leaf spelled `... and $tokens[$i]['text'] !==
     *     "\x00zz"` to {@see directiveValues()}'s head test left the whole file
     *     GREEN — a genuine conjunct a drop-sweep must visit, invisible to the
     *     instrument that claims to count them.
     *   * NOISILY. Comments were stripped but STRINGS were not, so `&&` inside an
     *     assertion message counted as a conjunct: changing
     *     {@see directiveValues()}'s `"could not read {$tape}"` to
     *     `"could not read && {$tape}"` failed this test with no conjunct added.
     *
     * The token walk has neither behaviour, and the figures did not move when it
     * replaced the regex — 5/8/8/17 and 2/3/3/4 both ways UNDER THE DOMAIN OF
     * THAT ROUND, which excluded loop conditions, `??=` and unanchored
     * conditions; that is what says the INSTRUMENT swap was a fix and not a
     * re-baseline, and it is a claim about that swap only. The conjunct figures
     * are 8/10/8/19 today because the DOMAIN widened twice afterwards, as the
     * paragraph above records. It is still not a SEMANTIC count: it sees syntax,
     * so it moves for a conjunct that is added or removed however harmless, and
     * that is the next paragraph's whole subject.
     *
     * THIS TEST IS EXCLUDED FROM A MUTATION SWEEP, and the exclusion is executable
     * rather than prose. Because it counts `if` sites and boolean operators, EVERY
     * conjunct-drop mutation reds it regardless of semantics — so with it in the
     * run, {@see tokenize()}'s EQUIVALENT-MUTANT REGISTER cannot be exercised at
     * all: its rule "a conjunct-drop survivor in the parsing model outside this
     * list IS a gap" would read "killed" for every mutant and find nothing ever
     * again. Sweep with
     *
     *   vendor/bin/phpunit tests/VhsTapeContractTest.php --exclude-group syntax-census
     *
     * and the group name in that command is {@see SWEEP_EXCLUDE_GROUP}, asserted
     * below BOTH to be the group this method actually carries AND to be the name
     * every COMMENT of this file that spells the command out uses. The second
     * half is the one that bites: measured, asserting the attribute against the
     * constant alone left renaming the constant GREEN, because both sides of
     * that comparison move together while the command lines in prose do not.
     *
     * THE CARRIER LIST IS DERIVED FROM THE TOKEN STREAM, not written down. It
     * was written down — "this one and {@see tokenize()}'s register" — and the
     * file has FOUR carriers, the other two being {@see SWEEP_EXCLUDE_GROUP}'s
     * own docblock and {@see skipSpeedSuffix()}'s pin list. Measured under the
     * hardcoded pair: misspelling the group in either unchecked carrier left the
     * whole file GREEN, and renaming the constant while updating only the two
     * checked carriers was green as well — leaving two live command lines that
     * name a group nothing carries. A hardcoded list of the places a claim holds
     * is the same defect as a figure without its domain, one noun along.
     *
     * Neither figure is a claim about correctness. Each is a claim that the prose
     * elsewhere in this file is describing THIS code, and each turns red the
     * moment a guard or a conjunct is added or removed — which is exactly when
     * that prose needs re-reading. The numbers below are therefore MEANT to be
     * edited, in this one place, by whoever moves the code.
     */
    #[\PHPUnit\Framework\Attributes\Group(self::SWEEP_EXCLUDE_GROUP)]
    public function testTheModelsBoundsAndConjunctsAreCountedNotNarrated(): void
    {
        $grouped = [];

        foreach ((new \ReflectionClass(self::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(\PHPUnit\Framework\Attributes\Group::class) as $attribute) {
                $grouped[$method->getName()][] = $attribute->newInstance()->name();
            }
        }

        ksort($grouped);

        self::assertSame(
            [
                'testTheHeadScanSweepRegisterIsMeasuredNotNarrated' => [self::SWEEP_EXCLUDE_GROUP],
                'testTheModelsBoundsAndConjunctsAreCountedNotNarrated' => [self::SWEEP_EXCLUDE_GROUP],
            ],
            $grouped,
            'first half of making the sweep instruction executable: the methods that really do '
            . 'carry the group the instruction excludes, DERIVED from the class rather than '
            . 'from __FUNCTION__. There are TWO census tests now, and the retired version '
            . 'checked whichever one it was written inside — so the second could have shipped '
            . 'with no attribute at all and the instruction would have silently stopped '
            . 'excluding it, which is a sweep reading "killed" for every mutant. Both sides '
            . 'bite: an attribute deleted here, and a test that acquires the group without '
            . 'being a census',
        );

        $source = file_get_contents(__FILE__);
        self::assertIsString($source, 'could not read this file');

        $spelled = [];
        $carriers = 0;

        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)
                || !\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)
                || !str_contains($token[1], '--exclude-group')) {
                continue;
            }

            ++$carriers;
            // The capture group is what makes a BARE mention of the flag (the
            // register refers to it once without a name) not count as a
            // misspelling: with no name after it there is nothing to capture.
            preg_match_all('/--exclude-group\s+([A-Za-z0-9_-]+)/', $token[1], $matches);
            $spelled = [...$spelled, ...$matches[1]];
        }

        self::assertSame(
            array_fill(0, 5, self::SWEEP_EXCLUDE_GROUP),
            $spelled,
            'second half, and the half that actually bites: EVERY comment in this file that '
            . 'spells the sweep command out must spell THIS group. DOMAIN: every `//`, `/* */` '
            . 'and `/** */` token of this file — derived from the token stream, NOT a hardcoded '
            . 'carrier list. The retired version named two carriers (this method and tokenize()) '
            . 'while the file had four, and measured, misspelling the group in either unchecked '
            . 'one — SWEEP_EXCLUDE_GROUP\'s own docblock or skipSpeedSuffix()\'s — left the whole '
            . 'file GREEN at 114 tests / 549 assertions; renaming the constant and updating only '
            . 'the two checked carriers was green too, with two live-but-stale command lines '
            . 'left in the file. That is verbatim the failure mode this half exists to prevent, '
            . 'reproduced inside the fix for it — for the second time. Strings are outside the '
            . 'domain on purpose: this very message would otherwise be a carrier',
        );

        self::assertSame(
            4,
            $carriers,
            'and the count of carriers, so that DELETING a command line is as loud as '
            . 'misspelling one. DOMAIN: comments of this file containing the substring '
            . '`--exclude-group` at all, name or no name — SWEEP_EXCLUDE_GROUP\'s own docblock, '
            . 'this one, tokenize()\'s register (which spells the command twice and the bare '
            . 'flag once) and skipSpeedSuffix()\'s pin list. Both figures here are MEANT to be '
            . 'edited by whoever adds or removes a sweep instruction, and nowhere else',
        );

        $measured = [];

        foreach (['directiveValues', 'scanRegex', 'skipSpeedSuffix', 'tokenize'] as $method) {
            $measured[$method] = self::guardCensus(self::modelMethodTokens($method));
        }

        self::assertSame(
            [
                'directiveValues' => ['bounds' => 2, 'conjuncts' => 8],
                'scanRegex' => ['bounds' => 3, 'conjuncts' => 10],
                'skipSpeedSuffix' => ['bounds' => 3, 'conjuncts' => 8],
                'tokenize' => ['bounds' => 4, 'conjuncts' => 19],
            ],
            $measured,
            'the model\'s bounds comparisons and conjuncts, per method, from this file\'s own '
            . 'token stream, under the domains stated on this method and on guardCensus(). '
            . 'TWELVE bounds comparisons in total, which is the domain the SCOPE section of '
            . 'valuesWithNoPhpDiagnostic() classifies one by one, and EIGHT conjuncts in '
            . 'skipSpeedSuffix(), which is what its pin list called four. '
            . 'A figure that moves here is a docblock that has gone stale two screens away',
        );
    }

    /**
     * The mutation-sweep register recorded on {@see literalHeadArguments()} is
     * MEASURED against the code it describes, not narrated beside it.
     *
     * That register is the one place in this file where a figure had drifted in
     * every direction at once: round 19 stated THIRTY-EIGHT leaves over a domain
     * sentence enumerating thirty-seven, a killed count that was one out with it,
     * one warning-only kill where there were two, and a survivor that appeared in
     * no class at all while the rule above it read "a survivor in none of the
     * three classes is a gap". Round 20 corrected each by rewriting the sentence.
     * Rewriting the sentence is what produced it.
     *
     * SO NOTHING HERE IS TYPED TWICE. The leaf total per method comes from
     * {@see sweepLeafCensus()} over the real methods; the survivors come from
     * {@see SWEEP_SURVIVORS}; the KILLED total is the subtraction of the two and
     * exists nowhere as a literal. A leaf added to any of the four methods moves
     * the census and reds this test; a survivor listed for a method that has
     * fewer leaves than survivors reds it; a fourth equivalence class reds it.
     *
     * AND THE RECONCILIATION THE CENSUSES OWE EACH OTHER, which is the half round
     * 20 left standing. {@see guardCensus()} counts conjunct-drop leaves and
     * excludes ternaries BY NAME, calling them "a different mutation operator";
     * {@see literalHeadArguments()}'s register sweeps ternary collapse and
     * conjunct drop under ONE heading and one figure. Both statements were true
     * and they contradicted each other, so the file had two definitions of a leaf
     * and no way to notice. They are one subtraction now — sweep leaves =
     * conjuncts + ternary conditions — asserted below for ALL EIGHT methods the
     * two censuses read between them, so widening either domain without the other
     * is red rather than silent.
     *
     * WHY THIS CARRIES THE SWEEP-EXCLUDE GROUP. It counts leaves, so every
     * conjunct-drop mutation in the four scan methods reds it whatever the
     * mutation MEANS — which is exactly the property that made the model census
     * uninterpretable inside a sweep. THAT IS A METHODOLOGY CHANGE for the
     * head-scan register: before this test existed, the scan methods were outside
     * every census and their sweep could be read from a whole-file run. It cannot
     * be any more, and the register says so where it records the sweep.
     */
    #[\PHPUnit\Framework\Attributes\Group(self::SWEEP_EXCLUDE_GROUP)]
    public function testTheHeadScanSweepRegisterIsMeasuredNotNarrated(): void
    {
        $scan = ['literalHeadArguments', 'callArgument', 'headArgument', 'splitNamedArgument'];
        $leaves = [];

        foreach ($scan as $method) {
            $leaves[$method] = self::sweepLeafCensus(self::modelMethodTokens($method));
        }

        self::assertSame(
            [
                'literalHeadArguments' => 14,
                'callArgument' => 17,
                'headArgument' => 4,
                'splitNamedArgument' => 4,
            ],
            $leaves,
            'the SIZE OF THE SWEPT DOMAIN, per method, measured from this file\'s own token '
            . 'stream instead of being enumerated in a docblock that adds up to something else. '
            . 'DOMAIN, STATED WITH ITS RESTRICTIONS rather than beside them: every leaf of '
            . 'every boolean condition (CONJUNCT_ANCHORS for each condition\'s base, '
            . 'CONJUNCT_OPERATORS for each leaf joined on, unanchoredConditions() for a base no '
            . 'anchor introduces) PLUS every ternary condition. What ESCAPES it, so that '
            . '"silent" never has to be inferred: `match` arms, `foreach`, the second '
            . 'unanchored condition of a single statement, and a boolean-bodied closure nested '
            . 'inside an anchored statement — all four under-count, and none occurs in these '
            . 'four methods today',
        );

        $total = array_sum($leaves);

        self::assertSame(
            39,
            $total,
            'and the total the register quotes, which is the sum of the four above and not a '
            . 'figure of its own. The retired version was a literal beside a sentence '
            . 'enumerating one fewer',
        );

        $byMethod = array_fill_keys($scan, 0);
        $byClass = [];

        foreach (self::SWEEP_SURVIVORS as $survivor) {
            self::assertContains(
                $survivor['method'],
                $scan,
                'every registered survivor names one of the four methods actually swept — a '
                . 'survivor recorded against a method outside the domain is a register '
                . 'describing something else',
            );
            self::assertStringContainsString(
                $survivor['fragment'],
                self::methodSourceWithoutComments($survivor['method']),
                "the registered survivor `{$survivor['leaf']}` is not in "
                . "{$survivor['method']}() any more. This is the half that makes the register "
                . 'ENFORCED rather than merely PRESENT: the counts above reconcile just as well '
                . 'when a row describes a leaf that was deleted rounds ago, because counting '
                . 'rows is not checking them. Comments are stripped from the haystack on '
                . 'purpose — the leaves here are discussed in prose a few lines from the code, '
                . 'and a register satisfied by its own explanation would be worse than none',
            );
            ++$byMethod[$survivor['method']];
            $byClass[$survivor['class']] = ($byClass[$survivor['class']] ?? 0) + 1;
        }

        ksort($byClass);

        self::assertSame(
            [
                'redundant against a companion conjunct' => 4,
                'type guard PHP makes redundant' => 6,
                'unreachable from this file\'s content' => 7,
            ],
            $byClass,
            'the survivors by equivalence class, counted from SWEEP_SURVIVORS rather than '
            . 'stated three times in prose. THREE classes exactly: the register\'s rule is '
            . '"a survivor in none of the three classes is a gap", and a fourth class added '
            . 'without a reader is that rule relaxing in silence',
        );

        foreach ($byMethod as $method => $survivors) {
            self::assertLessThanOrEqual(
                $leaves[$method],
                $survivors,
                "more survivors registered for {$method} than it has leaves to mutate. This is "
                . 'the check that catches a register left behind by a method that SHRANK — the '
                . 'leaf count is measured and the survivor list is written down, so only this '
                . 'comparison notices when the second outlives the first. IT TAKES TWO EDITS TO '
                . 'REACH, and saying so is the point: removing a leaf alone fails the census '
                . 'above first, so this fires only once someone has done what the docblocks '
                . 'invite and updated the numbers there without touching SWEEP_SURVIVORS. '
                . 'Measured that way — dropping splitNamedArgument()\'s `\is_array($argument[0])` '
                . 'and correcting 4 → 3, 38 → 37 and 21 → 20 — this is the one assertion that '
                . 'fails. splitNamedArgument() is where it bites soonest: all four of its '
                . 'leaves are survivors, so it has no slack at all',
            );
        }

        self::assertSame(
            22,
            $total - \count(self::SWEEP_SURVIVORS),
            'and the KILLED count, DERIVED. It is the one figure in the register a sweeper '
            . 'cannot measure from the source — it takes 39 runs — so it is the one figure '
            . 'that must not be typed independently of the two that can be. Round 19 typed it '
            . 'independently and it was one out',
        );

        $reconciled = [];

        foreach ([...$scan, 'directiveValues', 'scanRegex', 'skipSpeedSuffix', 'tokenize'] as $method) {
            $tokens = self::modelMethodTokens($method);
            $ternaries = 0;

            foreach (array_keys($tokens) as $k) {
                if (self::isTernaryCondition($tokens, $k)) {
                    ++$ternaries;
                }
            }

            $reconciled[$method] = self::sweepLeafCensus($tokens)
                - self::guardCensus($tokens)['conjuncts']
                - $ternaries;
        }

        self::assertSame(
            array_fill_keys(
                [...$scan, 'directiveValues', 'scanRegex', 'skipSpeedSuffix', 'tokenize'],
                0,
            ),
            $reconciled,
            'and the two censuses reconciled, for ALL EIGHT methods they read between them: '
            . 'the head-scan sweep\'s domain is the model census\'s domain plus ternary '
            . 'conditions and nothing else. Round 19 found the two disagreeing about loop '
            . 'bounds and `??=`; round 20 fixed those by editing one list and left the TERNARY '
            . 'half of the same disagreement in place, stated as a deliberate exclusion in one '
            . 'docblock and as an included operator in the other. Neither could see the other. '
            . 'This subtraction is what makes a third round of it red instead of quiet',
        );
    }

    /**
     * One model method's own tokens, with comments and whitespace dropped.
     *
     * Located by reflection rather than by line numbers so it cannot drift, and
     * read through PHP's tokenizer rather than by regex because the thing being
     * counted — a guard, a conjunct — is spelled the same in code, in the comment
     * that discusses it and in the assertion message that quotes it.
     * {@see tokenize()} discusses three of {@see scanRegex()}'s guards in an
     * inline comment, which is enough to more than double its own count.
     *
     * @return list<array{int, string, int}|string>
     */
    private static function modelMethodTokens(string $method, bool $keepWhitespace = false): array
    {
        $reflection = new \ReflectionMethod(self::class, $method);
        $lines = file(__FILE__);
        self::assertIsArray($lines, 'could not read this file');

        $body = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        $dropped = [\T_COMMENT, \T_DOC_COMMENT];

        if (!$keepWhitespace) {
            $dropped[] = \T_WHITESPACE;
        }

        return array_values(array_filter(
            token_get_all('<?php ' . $body),
            static fn (array|string $token): bool => !\is_array($token)
                || !\in_array($token[0], $dropped, true),
        ));
    }

    /**
     * One method's own source with every comment removed and each whitespace run
     * collapsed to a single space.
     *
     * The haystack {@see SWEEP_SURVIVORS}'s `fragment` half is checked against.
     * Comments go because this file discusses its own guards a few lines from
     * where they live — {@see tokenize()} quotes three of {@see scanRegex()}'s
     * verbatim — so a register checked against the raw text would be satisfied
     * by the paragraph explaining the leaf rather than by the leaf. Whitespace
     * collapses because a condition that gets rewrapped across lines is the same
     * condition, and a register that reds on reflow trains people to edit it
     * without reading it.
     */
    private static function methodSourceWithoutComments(string $method): string
    {
        $text = '';

        foreach (self::modelMethodTokens($method, true) as $token) {
            $text .= \is_array($token) ? $token[1] : $token;
        }

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * The bounds comparisons and the conjuncts in one method's token stream.
     *
     * A BOUNDS COMPARISON is a comparison operator with `$count` or `$length`
     * immediately on one side of it — the same rule the retired regex used,
     * except that a token walk cannot read one out of a string literal.
     *
     * A CONJUNCT is one LEAF of a boolean condition: an anchor from
     * {@see CONJUNCT_ANCHORS} carries its condition's base leaf, an operator from
     * {@see CONJUNCT_OPERATORS} joins one more onto it. `if (A || B)` is
     * therefore two, which is what a drop-sweep of it visits.
     *
     * AN ANCHOR + OPERATOR COUNT IS NOT A LEAF COUNT ON ITS OWN, and the gap is
     * where round 20 put the same defect back that round 20 was fixing. A
     * boolean condition needs no anchor at all: `$flag = $a === $b;` is a leaf a
     * drop-sweep must visit — the mutation makes the assignment unconditional —
     * and it carries none of the eleven tokens. Measured, adding
     * `$unanchored = static fn (): bool => $arity > 0;` to
     * {@see directiveValues()} left the whole file GREEN at 114 tests / 549
     * assertions, and the two-leaf spelling of the same thing moved this figure
     * by ONE where a leaf count moves by two. It is not hypothetical either:
     * {@see tokenize()}'s `$terminated = $close !== false && $close < $lineEnd;`
     * is exactly that shape and was under-counted by one until this round —
     * which is why `tokenize` reads 19 here and read 18 last round.
     * {@see unanchoredConditions()} supplies the missing bases, and states its
     * own two restrictions rather than leaving them to be discovered.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return array{bounds: int, conjuncts: int}
     */
    private static function guardCensus(array $tokens): array
    {
        $comparisons = [
            '<', '>',
            \T_IS_SMALLER_OR_EQUAL, \T_IS_GREATER_OR_EQUAL,
            \T_IS_IDENTICAL, \T_IS_NOT_IDENTICAL,
            \T_IS_EQUAL, \T_IS_NOT_EQUAL, \T_SPACESHIP,
        ];
        $conjunctions = [...self::CONJUNCT_ANCHORS, ...self::CONJUNCT_OPERATORS];

        $bound = static fn (array|string|null $token): bool => \is_array($token)
            && $token[0] === \T_VARIABLE
            && \in_array($token[1], ['$count', '$length'], true);

        $bounds = 0;
        $conjuncts = \count(self::unanchoredConditions($tokens));

        foreach ($tokens as $k => $token) {
            if (\is_array($token) && \in_array($token[0], $conjunctions, true)) {
                ++$conjuncts;

                continue;
            }

            if (!\in_array(\is_array($token) ? $token[0] : $token, $comparisons, true)) {
                continue;
            }

            if ($bound($tokens[$k - 1] ?? null) || $bound($tokens[$k + 1] ?? null)) {
                ++$bounds;
            }
        }

        return ['bounds' => $bounds, 'conjuncts' => $conjuncts];
    }

    /**
     * {@see statements()} does not split inside EITHER interpolation spelling.
     *
     * WHY THIS IS A TEST AND NOT A NOTE. The splitter is one of the two brace
     * walkers in this file, and unlike {@see callArgument()} it is reached only
     * through {@see unanchoredConditions()}, which reads this file's OWN method
     * bodies. Nothing in those bodies is an interpolated string, so both
     * openers are unreachable from the census that consumes it and a leaf
     * dropped here is silent in every other test the file has. Measured:
     * removing `\T_DOLLAR_OPEN_CURLY_BRACES` from the depth condition leaves
     * the whole file green without this method.
     *
     * The two spellings are supplied as SOURCE STRINGS in a nowdoc rather than
     * written as code, so this file never compiles the 8.2-deprecated one and
     * cannot emit its deprecation on any PHP; `token_get_all()` lexes rather
     * than compiles, so the splitter still sees the opener.
     */
    public function testTheStatementSplitterSurvivesBothInterpolationSpellings(): void
    {
        $plain = <<<'PHP'
            <?php $x = 'ac'; $y = 1;
            PHP;
        $modern = <<<'PHP'
            <?php $x = "a{$b}c"; $y = 1;
            PHP;
        $deprecated = <<<'PHP'
            <?php $x = "a${b}c"; $y = 1;
            PHP;

        $count = static fn (string $source): int
            => \count(self::statements(\token_get_all($source)));

        // The control: two statements, no interpolation, no opener involved.
        // Without it a splitter that returned one statement for everything
        // would satisfy both rows below.
        self::assertSame(2, $count($plain), 'the splitter cannot even split two plain statements');

        self::assertSame(
            2,
            $count($modern),
            'the `{` of `"{$b}"` is an ARRAY token and its `}` is a bare one, so a splitter '
            . 'that does not increment on T_CURLY_OPEN treats that `}` as a statement boundary '
            . 'and cuts one statement into two',
        );

        self::assertSame(
            2,
            $count($deprecated),
            'and the same in the 8.2-deprecated spelling, whose opener is a THIRD array token: '
            . 'T_DOLLAR_OPEN_CURLY_BRACES, text `${` rather than `{`, measured on PHP 8.3.6. '
            . 'This walker had it in neither depth condition for four rounds while every other '
            . 'brace walker in the suite was given it one at a time, because the file sits at '
            . 'the root of tests/ and was in no lane\'s ownership list',
        );
    }

    /**
     * The statements of one method that carry a boolean condition NO anchor
     * introduces — one entry per such statement, each the missing BASE leaf that
     * {@see guardCensus()}'s token count cannot see.
     *
     * A statement is anchored when it opens with `if`, `elseif`, `else`, `for`,
     * `while` or `do`, or when it contains a ternary condition or a `??`/`??=`.
     * Anything else that carries a comparison or a boolean operator is a
     * condition standing on its own: an assignment, a `return`, an arrow
     * function's body.
     *
     * TWO RESTRICTIONS, stated here because both are ways this can UNDER-count
     * and neither is visible from the figure it feeds:
     *
     *   * ONE PER STATEMENT. Two independent unanchored conditions written in a
     *     single statement contribute one base, not two.
     *   * ANCHORED WINS FOR THE WHOLE STATEMENT. A closure with a boolean body
     *     nested inside an `if (…)` header is inside an anchored statement, so
     *     its own base leaf is not counted.
     *
     * Neither shape occurs in the eight methods the two censuses read today.
     * Both would under-count rather than over-count, which is the direction that
     * hides a leaf, so they are named rather than merely true.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<string> the statement text of each, so a failure names it
     */
    private static function unanchoredConditions(array $tokens): array
    {
        $comparisons = [
            '<', '>',
            \T_IS_SMALLER_OR_EQUAL, \T_IS_GREATER_OR_EQUAL,
            \T_IS_IDENTICAL, \T_IS_NOT_IDENTICAL,
            \T_IS_EQUAL, \T_IS_NOT_EQUAL, \T_SPACESHIP,
        ];
        $openers = [\T_IF, \T_ELSEIF, \T_ELSE, \T_FOR, \T_WHILE, \T_DO];
        $found = [];

        foreach (self::statements($tokens) as $statement) {
            $first = $statement[0];
            $anchored = \is_array($first) && \in_array($first[0], $openers, true);
            $bears = false;

            foreach ($statement as $k => $token) {
                $id = \is_array($token) ? $token[0] : $token;

                if ($id === \T_COALESCE || $id === \T_COALESCE_EQUAL
                    || self::isTernaryCondition($statement, $k)) {
                    $anchored = true;
                }

                if (\in_array($id, self::CONJUNCT_OPERATORS, true)
                    || \in_array($id, $comparisons, true)) {
                    $bears = true;
                }
            }

            if ($bears && !$anchored) {
                $text = implode('', array_map(
                    static fn (array|string $token): string => \is_array($token) ? $token[1] : $token,
                    $statement,
                ));
                $found[] = (string) preg_replace('/\s+/', ' ', $text);
            }
        }

        return $found;
    }

    /**
     * Split a method's token stream into statements at top-level `;`, `{` and `}`.
     *
     * Depth-counted over `(` and `[` so a `for` header's own semicolons do not
     * split it, and over the SAME token-stream asymmetries
     * {@see callArgument()} handles — `T_CURLY_OPEN`,
     * `T_DOLLAR_OPEN_CURLY_BRACES` and `T_ATTRIBUTE` all open with an array
     * token and close with a bare one, so a splitter matching only bare braces
     * would treat the `}` of `"{$tape}"` as a statement boundary.
     *
     * WHAT THIS SAID: "the SAME two token-stream asymmetries", naming
     * `T_CURLY_OPEN` and `T_ATTRIBUTE`. WHAT IS TRUE NOW: there are three, and
     * the third — the opener of the 8.2-deprecated `${x}` spelling — was
     * missing from both counters in this file while every other brace walker
     * in the suite had been given it, because this file sat at the root of
     * `tests/` and was in no lane's ownership list for the rounds that fixed
     * the others. WHY THE PARAGRAPH STILL EARNS ITS PLACE: the asymmetry it
     * describes is the whole reason a bare-brace depth count is wrong here,
     * and that reasoning is what a reader needs before touching either
     * counter. Only the roster was stale, not the argument.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<list<array{int, string, int}|string>>
     */
    private static function statements(array $tokens): array
    {
        $statements = [];
        $current = [];
        $depth = 0;

        foreach ($tokens as $token) {
            $id = \is_array($token) ? $token[0] : $token;

            if ($token === '(' || $token === '['
                || $id === \T_CURLY_OPEN || $id === \T_DOLLAR_OPEN_CURLY_BRACES
                || $id === \T_ATTRIBUTE) {
                ++$depth;
            } elseif ($token === ')' || $token === ']') {
                --$depth;
            } elseif ($token === '}' && $depth > 0) {
                --$depth;
            } elseif ($depth === 0
                && ($token === ';' || $token === '{' || $token === '}')) {
                if ($current !== []) {
                    $statements[] = $current;
                }

                $current = [];

                continue;
            }

            $current[] = $token;
        }

        if ($current !== []) {
            $statements[] = $current;
        }

        return $statements;
    }

    /**
     * Whether the `?` at $k opens a TERNARY rather than a nullable type.
     *
     * PHP lexes both as a bare `?`, so position is the only thing that separates
     * them: a ternary's `?` follows an EXPRESSION — a `)`, a `]`, a variable, a
     * name, a string or a number — while a nullable type's follows a `(`, a `,`
     * or a `:`. `?->` is a token of its own and is never seen here. Without this
     * a single `?int $x` parameter added to any of the eight methods would read
     * as a ternary condition and move a figure with no leaf added.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function isTernaryCondition(array $tokens, int $k): bool
    {
        if (($tokens[$k] ?? null) !== '?') {
            return false;
        }

        $previous = $tokens[$k - 1] ?? null;

        if ($previous === ')' || $previous === ']') {
            return true;
        }

        return \is_array($previous) && \in_array($previous[0], [
            \T_VARIABLE, \T_STRING, \T_CONSTANT_ENCAPSED_STRING,
            \T_LNUMBER, \T_DNUMBER,
        ], true);
    }

    /**
     * The mutation LEAVES of one method under the operator
     * {@see literalHeadArguments()}'s register sweeps.
     *
     * That register sweeps a WIDER operator than {@see guardCensus()} counts: it
     * folds ternary-condition collapse in beside conjunct drop, under one figure
     * and one heading. This is where the two instruments are reconciled, and the
     * reconciliation is arithmetic rather than prose — one term, named:
     *
     *   sweep leaves = guardCensus conjuncts + ternary conditions
     *
     * {@see testTheHeadScanSweepRegisterIsMeasuredNotNarrated()} asserts that
     * identity for all eight methods both censuses read, so neither side can be
     * widened or narrowed without the other's figure moving. Round 19's finding
     * was that the two disagreed about loop bounds and `??=`; round 20 fixed
     * those two and left the ternary half of the same disagreement standing,
     * described in two docblocks that contradicted each other. It is one
     * subtraction now.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function sweepLeafCensus(array $tokens): int
    {
        $ternaries = 0;

        foreach (array_keys($tokens) as $k) {
            if (self::isTernaryCondition($tokens, $k)) {
                ++$ternaries;
            }
        }

        return self::guardCensus($tokens)['conjuncts'] + $ternaries;
    }

    /**
     * A tape that cannot be READ is reported as a tape that cannot be read, on
     * both routes into the diagnostic promotion.
     *
     * {@see panicsUpstreamsLexer()}'s docblock states this as a design principle —
     * "the read itself stays outside the promotion — turning an I/O warning into
     * this method's exception would misattribute it". It held on that one route
     * and was VIOLATED on the other, which is the busier of the two: the read
     * inside {@see directiveValues()} sat inside
     * {@see valuesWithNoPhpDiagnostic()}'s promotion, so a missing tape came out
     * of it as `ErrorException: file_get_contents(): Failed to open stream`
     * instead of as this file's own `could not read` assertion — measured, both
     * routes side by side, before the pre-read went in.
     *
     * That is a false lead rather than a false green: the run is red either way.
     * It is pinned because the principle is written down as though it were
     * enforced, and an unenforced principle in this file has been the shape of
     * nine consecutive findings.
     *
     * The probes' own `file_get_contents` warning is swallowed by a local handler
     * rather than left to PHPUnit, because `failOnWarning="true"` would otherwise
     * fail this test for the very diagnostic it exists to attribute. What is under
     * test is WHICH exception comes out, not whether PHP warned.
     */
    public function testAMissingTapeIsReportedByTheReadNotByThePromotion(): void
    {
        $missing = self::libRoot() . '/.vhs/no-such-tape.tape';
        $reported = [];

        set_error_handler(static fn (): bool => true);

        try {
            $probes = [
                'valuesWithNoPhpDiagnostic' => static function () use ($missing): void {
                    self::valuesWithNoPhpDiagnostic($missing, 'Set Shell');
                },
                'panicsUpstreamsLexer' => static function () use ($missing): void {
                    self::panicsUpstreamsLexer($missing);
                },
            ];

            foreach ($probes as $route => $probe) {
                try {
                    $probe();
                    $reported[$route] = 'returned without throwing';
                } catch (\Throwable $thrown) {
                    $reported[$route] = $thrown::class . ': ' . $thrown->getMessage();
                }
            }
        } finally {
            restore_error_handler();
        }

        foreach ($reported as $route => $report) {
            self::assertStringStartsWith(
                \PHPUnit\Framework\ExpectationFailedException::class
                    . ': could not read ' . $missing,
                $report,
                $route . ' must report an unreadable tape as an unreadable tape. An '
                . '`ErrorException` naming `file_get_contents` here means the read has moved '
                . 'back inside the promotion, and the promotion exists to attribute a '
                . 'diagnostic to a BOUNDS GUARD in the token walk',
            );
        }
    }

    /**
     * No tape may end in the one input shape that CRASHES upstream's lexer.
     *
     * UPSTREAM-ONLY, and the only rule here that is about a defect in the
     * renderer rather than about the tape language. A regex whose content ends
     * in a backslash run that is the last thing in the file makes `readRegex`
     * take one `readChar()` too many and slice past the end of its input:
     * `panic: runtime error: slice bounds out of range`, exit 2, no GIF, and in
     * the workflow's `set -euo pipefail` loop that is every later tape as well.
     * Nothing about it is visible as a tape defect — it is a two-character
     * accident at the end of a file.
     *
     * Measured both ways rather than reasoned about: upstream's own `lexer.go`,
     * built and run directly, and the `vhs` binary agree exactly, panicking on
     * `/a\`, `/a\\` and `/a\\\` and not on `/a`, `/a/`, `/a\ ` or `/a\` +
     * newline. A pure-PHP lexer cannot reproduce a Go slice panic, so this is
     * checked as a forbidden shape instead of modelled as a token — and it is
     * the assertion to delete on the day the renderer is no longer upstream vhs.
     *
     * The detector is only as good as the byte classes, because the whole
     * question is which `/` is at a token start. Swept over 1,944 tapes — nine
     * heads x nine middles x twelve tails x a leading `/` present or absent —
     * upstream panics on 352 and this model now flags exactly those 352: no
     * false negative, no false positive. The break-set model it replaced flagged
     * 294 of the 352, missing every panic whose `/` was glued to a number, a
     * `%` or a `\` rather than preceded by whitespace. The rows that pin it are
     * in {@see testTheRegexPanicDetectorMatchesTheMeasuredShapes()}.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tapeProvider')]
    public function testNoTapeEndsInUpstreamsRegexPanic(string $tape): void
    {
        self::assertFalse(
            self::panicsUpstreamsLexer($tape),
            basename($tape) . ': this tape leaves a regex open with a trailing backslash run at '
            . 'EOF, which panics upstream vhs (exit 2) instead of failing a directive. Close the '
            . 'regex, or end the file with anything other than a backslash',
        );
    }

    /**
     * The detector above, against the shapes both oracles were measured on.
     *
     * Without this the assertion could be vacuously true — a detector that
     * always returned false would pass on all five tapes, which is how a
     * "nothing to report" check quietly stops checking.
     *
     * ITS DOMAIN IS THE OTHER HALF OF THE CHECK, and for two revisions this
     * table had only one shape in it: `Set WaitPattern /…`, i.e. a `/` already
     * at a token start because whitespace put it there. Whether a `/` is at a
     * token start is the ONLY thing the detector depends on, so a table of
     * whitespace-preceded slashes cannot tell a correct detector from one that
     * never opens a regex mid-line. It stayed green while `Set Padding 50/a\`
     * and `a\/b\` — both exit 2, `slice bounds out of range [:18] with length
     * 17` and `[:15] with length 14` — went unreported. The GLUED rows below
     * are the ones that make the table load-bearing: in each, the `/` follows
     * another token with no space, so it is a token start only if the byte
     * classes end the previous run in the right place.
     *
     * Every row measured on all three oracles under THE THREE ORACLES in
     * {@see directiveValues()}: upstream's `lexer.go` run directly (recovered
     * panic) and `vhs validate` on both v0.11.0 binaries, whose exit status and
     * byte offsets are quoted per row, identical on both binaries. The offset is
     * worth quoting because it is `len(input) + 1` and nothing else — 17-byte tape,
     * `[:18] with length 17` — so it pins the exact byte length of the tape in the
     * comment, and a row whose source string drifted by one byte stops matching
     * its own citation.
     */
    public function testTheRegexPanicDetectorMatchesTheMeasuredShapes(): void
    {
        $panics = [
            'one backslash at EOF' => 'Set WaitPattern /a\\',
            'two backslashes at EOF' => 'Set WaitPattern /a\\\\',
            'three backslashes at EOF' => 'Set WaitPattern /a\\\\\\',
            // `readNumber` stops at `/`, so the `/` is a token start and opens
            // a regex: exit 2, `slice bounds out of range [:18] with length 17`.
            'glued to a number' => 'Set Padding 50/a\\',
            // `%` is a single-byte token, same conclusion:
            // `[:22] with length 21`.
            'glued to a percent' => 'Set LoopOffset 50%/a\\',
            // `\` is a single-byte token and never starts an identifier, so the
            // `/` after it is a token start too, on line 2 of the file:
            // `[:15] with length 14`.
            'glued to a backslash, mid-file' => "Type \"x\"\na\\/b\\",
        ];

        foreach ($panics as $why => $source) {
            self::assertTrue(
                self::panicsUpstreamsLexer($this->scratchTape($source)),
                "{$why}: measured as a panic on both oracles",
            );
        }

        $safe = [
            'no backslash' => 'Set WaitPattern /a',
            'closed regex' => 'Set WaitPattern /a/',
            'backslash then space' => 'Set WaitPattern /a\\ ',
            'backslash then newline' => "Set WaitPattern /a\\\n",
            'backslash outside a regex' => 'Set WaitPattern a\\',
            'backslash inside a string' => 'Type "a\\',
            // The controls for the glued rows, and the reason `/` may not be
            // treated as a regex opener everywhere: mid-identifier it is an
            // ordinary run byte, so no regex opens and nothing panics. Both
            // exit 1 (`Invalid command`), not 2.
            'slash inside an identifier' => 'Output x.gif/a\\',
            'glued number, closed regex' => 'Set Padding 50/a/',
            'glued number, no backslash' => 'Set Padding 50/a',
            // The row that makes `$end === $length` load-bearing: the regex is
            // closed by a NEWLINE, so it never runs off the end, and the
            // backslash at EOF is an ordinary `\` token THREE tokens after the
            // REGEX. Measured — the stream is SET/WAIT_PATTERN/REGEX/TYPE/
            // STRING/`\`, six tokens, and upstream does not panic; it reports
            // ONE parser error on the trailing
            // `\`, as the other rows here do, which is a rejected tape and not a
            // dead binary. A detector that dropped the `$end === $length`
            // conjunct calls this a panic and fails
            // testNoTapeEndsInUpstreamsRegexPanic() on a tape vhs lexes fine.
            'regex closed by a newline, backslash at EOF' => "Set WaitPattern /a\nType b\\",
        ];

        foreach ($safe as $why => $source) {
            self::assertFalse(
                self::panicsUpstreamsLexer($this->scratchTape($source)),
                "{$why}: measured as NOT a panic — reporting one would fail a tape vhs renders",
            );
        }
    }

    /**
     * {@see directiveValues()} with PHP's own diagnostics promoted to a THROWN
     * error rather than left as warnings.
     *
     * A bounds guard whose mutant changes no ANSWER — see
     * {@see testTheSpeedSuffixWalkStopsAtTheEndOfTheStream()} for why — can only
     * be tested by asserting that PHP said nothing. That is already a non-zero
     * exit, because `phpunit.xml` sets `failOnWarning="true"`, and it was
     * verified to be one. The problem is what a reader SEES: PHPUnit prints
     * `OK, but there were issues!` and lists the warning in a block most eyes
     * skip, so such a guard reads as a passing test with a lint nit attached —
     * and these are exactly the lines a reader then tidies up, because each looks
     * redundant against the loop header two lines above it.
     *
     * Promoting the diagnostic to an `\ErrorException` puts the row in the ERRORS
     * block with the guard's own message on it, which is a thing that gets acted
     * on. It also makes the kill independent of `phpunit.xml`, which is not this
     * file's to guarantee.
     *
     * SCOPE, ONE ENTRY PER GUARD, because the retired version of this paragraph
     * opened on "Every bounds guard in this model is invisible in the ANSWER" and
     * closed on "these four guards", and NEITHER half held.
     *
     * DOMAIN: the TWELVE comparisons against a stream bound (`$count` or
     * `$length`) in the four model methods — three in {@see skipSpeedSuffix()},
     * two in {@see directiveValues()}, four in {@see tokenize()}, three in
     * {@see scanRegex()}. Those four per-method figures are ASSERTED from this
     * file's own source by
     * {@see testTheModelsBoundsAndConjunctsAreCountedNotNarrated()} rather than
     * narrated here, so a thirteenth cannot appear unremarked. Of the twelve,
     * dropping one is answer-invisible at SEVEN, loud at THREE, and two are not
     * bounds at all. All seven of the invisible ones are routed; the retired
     * "four" was the number routed at the time, written as though it were the
     * number that exists, and the retired "eight" put {@see directiveValues()}'s
     * `$i + $arity <= $count` in this class when a whole-file run makes it 82
     * ERRORS starting in an UNROUTED test — measured below.
     *
     * One mutation per comparison, whole-file run, judged on this test file
     * alone. Each entry names the KILLING TEST and the KIND of kill — error,
     * failure, warning-only, hang — and deliberately not an assertion total,
     * because a count of this file's own assertions cannot survive an edit to
     * this file (the same trap {@see testSetShellIsTheMostQueriedTwoWordHead()}
     * exists to close):
     *
     *   {@see skipSpeedSuffix()}'s `$i >= $count`, and both its `$i < $count` —
     *     THIS helper, from
     *     {@see testTheSpeedSuffixWalkStopsAtTheEndOfTheStream()}. ERROR.
     *   {@see scanRegex()}'s `$j < $length` before the `/` test, and the one in
     *     its backslash run — {@see panicsUpstreamsLexer()}, from
     *     {@see testTheRegexPanicDetectorMatchesTheMeasuredShapes()}. ERROR.
     *     BEFORE that route existed each was WARNING-ONLY: measured, `Warnings:
     *     5` with zero failures and zero errors on a run PHPUnit summarises as
     *     `OK, but there were issues!`. That is the presentation defect this
     *     helper was written for, and it was sitting three lines from the helper
     *     while a round declared warning-only kills gone.
     *   {@see scanRegex()}'s `$j < $length` loop header — needed TWO routes and
     *     now has both. {@see panicsUpstreamsLexer()} kills it, but the tape that
     *     reaches an unbounded {@see scanRegex()} FIRST in declaration order is
     *     `Set WaitPattern /a\` + CR in
     *     {@see testACarriageReturnBoundsEveryTokenAnLfBounds()} — the only tape
     *     in this file where a regex runs to EOF with neither a delimiter nor a
     *     newline to close it — so while that row still went through
     *     {@see directiveValues()} the whole-file run HUNG there rather than
     *     reaching the error further down. That row is routed too; the mutant is
     *     now an ERROR in both tests. Unrouted it did not finish at all: hard
     *     killed at 90 s in a sandbox with no time limit.
     *   {@see tokenize()}'s `$i + 1 < $length` peek bound —
     *     {@see tokensWithNoPhpDiagnostic()}, from the `a trailing dot at EOF is
     *     an ident` row of {@see testTheTokenizerAssignsUpstreamsTokenKinds()}.
     *     ERROR. Unrouted it was ONE warning in the whole file, from that one
     *     row, which asserts token kinds and so calls the tokenizer directly —
     *     reaching neither of the routes above.
     *
     * AND THE FIVE THAT ARE NOT ROUTED, with the reason each is not. Every figure
     * below is a WHOLE-FILE run of this test file at the commit that carries this
     * sentence — re-taken after the round's own new tests existed, because three
     * of the figures this paragraph used to hold were measured on the parent
     * commit and shipped inside the one that moved them:
     *
     *   LOUD WITHOUT ANY HELP — three, not two.
     *
     *     {@see directiveValues()}'s `$i + $arity <= $count`. This was filed among
     *     the ROUTED eight, "from
     *     {@see testATwoWordHeadNeedsBothWordsAndBothAsIdents()}, ERROR", and both
     *     halves were wrong. MEASURED: `Errors: 82, Failures: 1, Warnings: 13`
     *     (no test or assertion total quoted, for the reason
     *     {@see testSetShellIsTheMostQueriedTwoWordHead()} gives). The first
     *     killer is
     *     {@see testOutputStaysInsideTheArtifactDirectory()} with data set
     *     `agents.tape`, which calls plain {@see directiveValues()} — UNROUTED —
     *     and dies on `TypeError: headMatches(): Argument #1 ($token) must be of
     *     type array, null given`. A `TypeError` needs no promotion to be an
     *     ERROR, and it is one in 82 places across 25 test methods. Being
     *     reachable through the promotion as well is not what makes this guard
     *     loud, and filing it there implied a route it does not need.
     *
     *     {@see directiveValues()}'s `while ($i < $count && …)`. MEASURED: `Errors:
     *     48, Failures: 1, Warnings: 8` — and the STATED MECHANISM was wrong. It
     *     said "the value walk then runs off the end and reports values no tape
     *     wrote"; there is not one value-mismatch failure in the run. The 48 are
     *     `TypeError: startsDirective(): Argument #1 ($token) must be of type
     *     array, null given` — the walk never gets far enough to report a bogus
     *     value, because the loop condition itself indexes past the end first. The
     *     one failure is the syntax census, which every conjunct drop moves.
     *
     *     {@see tokenize()}'s own `for` header. It said "dropping it hangs, and a
     *     hang is red on its own now (`failOnRisky` plus `defaultTimeLimit="60"`)".
     *     That was false as written, and its DOMAIN is the correction: the time
     *     limit bounds a loop that COMPUTES, and this mutant is one that ALLOCATES
     *     — every iteration reads past the end of the string and appends another
     *     token. MEASURED under the config as it was, with `memory_limit` left at
     *     the CLI default `-1`: the whole-file run was still going at 100 s with
     *     two progress characters, no summary and no 60 s abort, exit 124 from an
     *     external `timeout`. `phpunit.xml` now pins `memory_limit=1G`, and
     *     re-measured under it the same mutant dies after 10.7 s with `PHP Fatal
     *     error: Allowed memory size of 1073741824 bytes exhausted`, exit 255.
     *     That is fast and it is red, but it is a PROCESS-LEVEL fatal: no test
     *     name, no summary, and it depends on a `memory_limit` this file does not
     *     own. It is filed as loud on that basis and not on the time limit's.
     *
     *   NOT A BOUND AT ALL — {@see tokenize()}'s `$length > 0` is
     *     unreachable-dead and its `$end === $length` is a conjunct of the PANIC
     *     rule. Both are argued at the condition itself and both belong to the
     *     EQUIVALENT-MUTANT REGISTER's conjunct-drop section, not here.
     *
     * WHAT THE WARNING COUNTS IN THOSE TWO RUNS MEAN, because "no warning-only
     * kills" is a thesis this file states and neither figure is covered by it.
     * `Warnings: 13` and `Warnings: 8` are not thirteen and eight warning-only
     * KILLS: each run is already an ERROR run, and those are the tests where the
     * mutant raised a PHP diagnostic without also reaching a `TypeError` or an
     * assertion. The thesis is about a mutant whose ONLY evidence is a warning —
     * a run PHPUnit summarises as `OK, but there were issues!` — and neither of
     * these is that. Warning-only kills are not extinct in this file either: the
     * conjunct sweep on {@see literalHeadArguments()} records one, and records it
     * as one.
     *
     * The handler is installed for the duration of ONE probe and restored in a
     * `finally`, so nothing else in the suite runs under it.
     *
     * @return list<string>
     */
    private static function valuesWithNoPhpDiagnostic(string $tape, string $directive): array
    {
        // The read is done HERE, ahead of the promotion, purely so a missing tape
        // is reported as the missing tape. {@see directiveValues()} reads the file
        // again inside the probe below; without this pre-read that inner
        // `file_get_contents` was inside the promotion, and an unreadable path came
        // out of this method as `ErrorException: file_get_contents(): Failed to open
        // stream` — the misattribution {@see panicsUpstreamsLexer()}'s docblock
        // claims the design avoids, live on the majority of routed call sites.
        // {@see testAMissingTapeIsReportedByTheReadNotByThePromotion()} pins both
        // routes reporting it the same way.
        $source = file_get_contents($tape);
        self::assertIsString($source, "could not read {$tape}");

        return self::withPhpDiagnosticsPromoted(
            static fn (): array => self::directiveValues($tape, $directive),
        );
    }

    /**
     * {@see tokenize()} under the same promotion as
     * {@see valuesWithNoPhpDiagnostic()}, for the one bounds guard that only a
     * DIRECT tokenizer call reaches.
     *
     * {@see tokenize()}'s `$i + 1 < $length` peek bound is read at every bare
     * token start, so a tape whose last token is bare walks onto it — and the
     * only such row in this file goes through
     * {@see testTheTokenizerAssignsUpstreamsTokenKinds()}, which asserts token
     * kinds and therefore calls the tokenizer rather than
     * {@see directiveValues()}. Without this route that guard's mutant was a
     * single warning in an otherwise-green run.
     *
     * @return list<array{text: string, kind: string}>
     */
    private static function tokensWithNoPhpDiagnostic(string $source): array
    {
        return self::withPhpDiagnosticsPromoted(
            static fn (): array => self::tokenize($source),
        );
    }

    /**
     * Run $probe with every PHP diagnostic promoted to a thrown
     * `\ErrorException`, restoring the previous handler whatever happens.
     *
     * Factored out because there are now THREE routes into the promotion
     * ({@see valuesWithNoPhpDiagnostic()}, {@see tokensWithNoPhpDiagnostic()},
     * {@see panicsUpstreamsLexer()}) and a second copy of a `set_error_handler`
     * / `finally` pair is a second copy to drift out of step with. The scope
     * claim that says which of the model's bounds guards each route covers is on
     * {@see valuesWithNoPhpDiagnostic()}.
     */
    private static function withPhpDiagnosticsPromoted(callable $probe): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $probe();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Every head argument passed to a model entry point in $php: the literal ones
     * tallied, the ones passed as an expression counted.
     *
     * WHY PHP'S OWN TOKENIZER rather than a regex, in three parts, because each is
     * a way the `grep` this replaced got its own stated domain wrong:
     *
     *   * A CALL MAY BE WRAPPED. `[^,]+` between the `(` and the head cannot cross
     *     a newline, so a call split over lines was outside the match while being
     *     inside the domain the docblock claimed. Measured: a wrapped fifth
     *     `Set Padding` query left the file green; the same call on one line turned
     *     it red. {@see testTheHeadScanSeesACallWrappedAcrossLines()} pins it.
     *   * A HEAD MAY BE ONE WORD. `[A-Za-z]+ [A-Za-z]+` silently restricted the
     *     tally to `Set X` heads, so `Output`, `Type`, `Source`, `Hide` and `Enter`
     *     were never in it. They are now, which is what lets
     *     {@see directiveValues()}'s divergence classes 4 and 6 cite the tally
     *     instead of listing call sites in prose.
     *   * TEXT THAT LOOKS LIKE A CALL IS NOT A CALL. The fixtures in
     *     {@see testTheHeadScanSeesACallWrappedAcrossLines()} spell whole call
     *     sites out inside a nowdoc; a `grep` counts those and inflates its own
     *     tally, whereas the tokenizer hands the whole nowdoc back as one string
     *     token. Same for a `{@see directiveValues()}` in a docblock, of which this
     *     file has dozens.
     *
     * AND A FOURTH PART, which is NOT about the retired regex — the token walk got
     * its OWN stated domain wrong the same way. The docblock on
     * {@see testSetShellIsTheMostQueriedTwoWordHead()} said "every LITERAL head
     * argument at every call site", and what the walk actually saw was every
     * literal head argument the SINGLE-TOKEN GATE below could recognise at
     * positional index one. A NAMED ARGUMENT is neither: measured at the PARENT
     * commit — a total that cannot drift because the state it describes is gone —
     * inserting
     * `self::valuesWithNoPhpDiagnostic(tape: $atAtEof, directive: 'Set Padding')`
     * as a genuine fifth `Set Padding` query left the whole file GREEN at 113
     * tests / 532 assertions, while the same call written positionally failed the
     * tally with `'Set Padding' => 4` against 5. {@see headArgument()} resolves the
     * name now, and the DYNAMIC count below is asserted exactly rather than as a
     * floor — which is what makes the NEXT unrecognised spelling loud instead of
     * merely unhandled. Measured escapes that were silent under the floor and are
     * red under the exact figure BY MOVING IT: `'Set ' . 'Padding'`, a class
     * constant, `...$args`, an enum case, a heredoc head, a first-class-callable
     * creation and `"${x}"` — each scans to `dynamic=1` with an empty tally. An
     * argument carrying a `#[Attribute]` is red for the OTHER reason and belongs
     * in the other list: it is HANDLED, so it moves the TALLY
     * (`tally={"Set Theme":1} dynamic=0`) and not this figure. The two are
     * distinct claims and the retired sentence filed the attribute under the
     * wrong one.
     *
     * AND A FIFTH PART, the same defect a third time. The gate below WAS `::`
     * alone, and that is a RESTRICTION on the domain, not only a filter on
     * noise — which is how it was written up, as "what keeps the two METHOD
     * DECLARATIONS out". Both entry points are `private static`, so PHP accepts
     * `$this->directiveValues(…)` for them, and this file writes `$this->` for
     * its own helpers at thirty-odd sites. Measured at the PARENT commit — a
     * total safe to quote because the state it describes is gone — with the
     * `::`-only gate in place: a genuine fifth `Set Padding` query written
     * `$this->valuesWithNoPhpDiagnostic($glue, 'Set Padding')` left the whole
     * file GREEN at 114 tests / 552 assertions, tallied nowhere and counted as
     * dynamic nowhere — silent to BOTH exact figures at once, which no earlier
     * escape managed — while the identical call written `self::` failed the
     * tally with `'Set Padding' => 4` against 5.
     *
     * THE GATE NOW: the name must be preceded by `::`, `->` or `?->` and
     * followed by `(`. That still keeps the two METHOD DECLARATIONS out, since a
     * declaration is preceded by `function`. It is STILL a restriction, and the
     * restriction is stated where the domain is claimed rather than only here:
     * a call reached some other way — a `Closure` handle over
     * `[self::class, 'directiveValues']`, a callable string, a variable method
     * name — is in NEITHER figure. Measured, and the one remaining silent shape:
     * `$f = \Closure::fromCallable([self::class, 'directiveValues']); $f($t,
     * 'Set Padding');` scans to `tally=[] dynamic=0`. First-class-callable
     * creation written `self::directiveValues(...)` is NOT silent — it counts as
     * dynamic. No call site in this file uses either today; the difference is
     * that this sentence now says so.
     *
     * ITS OWN CONJUNCT SWEEP, recorded here rather than folded into
     * {@see tokenize()}'s EQUIVALENT-MUTANT REGISTER, because that register's
     * subject is the parsing model and a survivor here costs a MEASUREMENT of this
     * file rather than a missed defect in a tape.
     *
     * OPERATOR: drop one conjunct LEAF, OR collapse one ternary condition.
     * TWO operators under one figure, said plainly because saying "conjunct
     * drop" alone is what set this register against
     * {@see testTheModelsBoundsAndConjunctsAreCountedNotNarrated()}, which
     * excludes ternaries by name as a different operator. The two are
     * reconciled by subtraction now, in
     * {@see testTheHeadScanSweepRegisterIsMeasuredNotNarrated()}.
     *
     * DOMAIN, MEASURED RATHER THAN ENUMERATED. The size of it, per method and in
     * total, is asserted by that same test from {@see sweepLeafCensus()} over
     * these four methods, and appears as a number NOWHERE in this docblock —
     * because the retired version of this paragraph said THIRTY-EIGHT over a
     * sentence that adds up to thirty-seven, with a kill count one out to match,
     * and the round that fixed it fixed it by rewriting the sentence. What is
     * swept, in each of {@see literalHeadArguments()}, {@see callArgument()},
     * {@see headArgument()} and {@see splitNamedArgument()}:
     *
     *   * every LEAF of every `&&`/`||` condition, where a leaf is one atomic
     *     test and NOT a parenthesised subgroup. `A || (B && (C || D))` is four
     *     mutations, not five: dropping the group whole is dropping three leaves
     *     at once and is a different operator. A condition that no `if` or loop
     *     introduces is still a condition and its leaves are still in the
     *     domain — the token filter's `static fn (…): bool => A || B` is two of
     *     them, and {@see unanchoredConditions()} is what stops the census from
     *     reporting one.
     *   * every single-condition site, mutated to an unconditional one — an `if`
     *     whose condition is one leaf, and equally an unanchored one.
     *   * every ternary CONDITION, mutated by collapsing to the true arm.
     *   * every loop bound, RELAXED BY ONE — `$k + 1 < $count` to `$k < $count`,
     *     `$k < \count($tokens)` to `<=`, `$index <= 1` to `$index <= 2`. The
     *     DIRECTION is part of the domain and the retired version did not state
     *     it: relaxing {@see headArgument()}'s bound SURVIVES while TIGHTENING
     *     the same bound to `$index < 1` is a kill (`Failures: 2`,
     *     {@see testSetShellIsTheMostQueriedTwoWordHead()} and
     *     {@see testTheHeadScanSeesACallWrappedAcrossLines()}), so "the loop
     *     bound survives" means nothing without it.
     *   * every `??`, which is in the domain because
     *     {@see testTheModelsBoundsAndConjunctsAreCountedNotNarrated()} counts
     *     `??` as a conjunct and the two instruments may not disagree about what
     *     a leaf is. There is exactly one here, the `?? 0` in the tally
     *     increment.
     *
     * Swept in FULL, one mutation at a time, judged on this test file alone.
     * THE SWEEP NOW NEEDS THE EXCLUSION — see {@see SWEEP_EXCLUDE_GROUP} — which
     * it did not before: until
     * {@see testTheHeadScanSweepRegisterIsMeasuredNotNarrated()} existed these
     * four methods were outside every census, so a plain whole-file run read
     * their kills correctly. It now reports "killed" for all thirty-nine
     * without the flag, and a survivor is green either way.
     *
     * THE THREE FIGURES — leaves, survivors, killed — ARE NOT WRITTEN HERE. They
     * are asserted by that test from {@see sweepLeafCensus()} and
     * {@see SWEEP_SURVIVORS}, with the killed count derived by subtracting the
     * second from the first, because it is the only one of the three that cannot
     * be measured from the source and so is the one that must not be typed on
     * its own. The survivors fall in three classes with no fourth inside that
     * domain, and each entry below is one row of {@see SWEEP_SURVIVORS}:
     *
     *   * TYPE GUARDS PHP MAKES REDUNDANT — the `!\is_array($x) ||` half of
     *     each of the three such pairs here (the token filter, the entry-point
     *     gate and the accessor test),
     *     the `\is_array($head[0])` in the literal gate, the `\is_array($token) &&`
     *     in {@see callArgument()}'s array-token arm, and the
     *     `\is_array($argument[0]) &&` in {@see splitNamedArgument()}. For a
     *     one-character string token `$x[0]` is that character, which is never
     *     `===` an integer token id, so the array test decides nothing. They all
     *     stay: comparing a string's first byte against a token id is nonsense a
     *     reader should not have to work out is harmless.
     *   * REDUNDANT AGAINST A COMPANION CONJUNCT — the `$token[0] !==
     *     \T_STRING` and `$tokens[$k + 1] !== '('` tests here, both implied by the
     *     accessor test plus the entry-point name test on this file's content;
     *     {@see callArgument()}'s `$depth === 1` early-continue, which only keeps
     *     the outermost `(` out of a collection no head argument reaches; and
     *     {@see splitNamedArgument()}'s `\count($argument) >= 3`, whose companion
     *     `T_STRING` test returns false first on every argument this file passes
     *     that is shorter than three tokens.
     *   * UNREACHABLE FROM THIS FILE'S CONTENT — the `$k + 1 < $count`
     *     bound and the `$k > 0` guard here; {@see callArgument()}'s bare `{`
     *     opener and its own loop bound; {@see headArgument()}'s `$index <= 1`
     *     bound; and {@see splitNamedArgument()}'s `T_STRING` and `:` tests. No
     *     call site here puts an entry-point name at the very start or the very
     *     end of the token stream, passes a brace-delimited expression, leaves
     *     its parentheses unbalanced, passes a THIRD argument that could carry a
     *     `directive:` name, or opens an argument with a `name :` pair that is
     *     not a named argument — so nothing in this file can reach any of them.
     *     Same standing as {@see tokenize()}'s `$length > 0`: kept so the
     *     indexing beside them is obviously in range.
     *     THE `$index <= 1` BOUND IS THE ONE THAT WAS MISSING, and it was
     *     missing rather than argued away: under the relaxation direction stated
     *     above it survives like the other two loop bounds and belonged in a
     *     class from the round it was written. A survivor in none of the three
     *     classes is a gap by this register's own rule, which is what makes an
     *     unlisted one worth naming as an omission and not a discovery.
     *
     * The ones that ARE killed are pinned between the tally and the exact
     * dynamic count in {@see testSetShellIsTheMostQueriedTwoWordHead()} and the
     * TEN fixtures of {@see testTheHeadScanSeesACallWrappedAcrossLines()}. All
     * but two fail an assertion naming a test; those TWO are WARNING-ONLY
     * kills, red because `phpunit.xml` sets `failOnWarning="true"` and for no
     * other reason — the `\count($head) === 1` gate (`Warnings: 1`,
     * `Undefined array key 0` from the `misnamed` fixture) and the `?? 0` in the
     * tally increment (`Warnings: 12`, `Undefined array key`, with the tally
     * itself UNCHANGED because `null + 1 === 1`). Both are recorded as such
     * rather than counted in with the rest, because a warning-only kill is exactly
     * the presentation defect {@see valuesWithNoPhpDiagnostic()} exists to fix and
     * this file has three times now declared that class of kill gone while one
     * sat in it — the `?? 0` kill sat inside the very method this register
     * documents, unrecorded, while the register said ONE.
     * SEVEN of the kills — the accessor gate, both square-bracket depth arms,
     * the `T_ATTRIBUTE` opener, the `T_DOLLAR_OPEN_CURLY_BRACES` opener,
     * {@see headArgument()}'s `$name === null` guard and the
     * `\count($head) === 1` gate just named — were survivors until the fixture
     * beside each went in, which is the whole reason those fixtures exist.
     * THE DEPRECATED OPENER IS THE ONE THAT WAS MISSING FOR FOUR ROUNDS, and it
     * was missing rather than argued away: {@see callArgument()}'s doc-block
     * called it "a stated gap, not an unnoticed one" while
     * `tests/Support/InterpolationOpenerTokenTest.php` was recording this file
     * as the one brace walker in `tests/` and `src/` that still lacked it. Both
     * halves are settled in the same change-set — the counters name the token
     * and that row is gone — because a deferral recorded only inside another
     * lane's test constant is a deferral nobody outside that lane looks for.
     *
     * ONE OTHER OPERATOR HAS BEEN SWEPT HERE, named because a survivor under an
     * unswept operator is not a gap: NARROW A SET LITERAL. `$callOperators`
     * reduced to `[\T_DOUBLE_COLON]` — the gate as it shipped last round — is a
     * KILL, `Failures: 1`, {@see testTheHeadScanSeesACallWrappedAcrossLines()}'s
     * `$instance` row, which is the whole reason that fixture exists: no REAL
     * call site in this file is written `$this->` today, so the tally alone
     * cannot see the difference. Adding one does move the tally — measured, a
     * genuine fifth `Set Padding` query written
     * `$this->valuesWithNoPhpDiagnostic($glue, 'Set Padding')` now fails
     * {@see testSetShellIsTheMostQueriedTwoWordHead()} with `'Set Padding' => 5`
     * against 4, where under the `::`-only gate it was silent in both figures.
     *
     * @return array{tally: array<string, int>, dynamic: int}
     */
    private static function literalHeadArguments(string $php): array
    {
        $entryPoints = ['directiveValues', 'valuesWithNoPhpDiagnostic'];
        // Both entry points are `private static`, so PHP accepts `self::`, `$this->`
        // and `$x?->` for all of them — and this file already writes `$this->` for
        // its own helpers thirty times over. Anything narrower is a gate that
        // silently shrinks the tally's domain rather than a gate that filters it.
        $callOperators = [\T_DOUBLE_COLON, \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR];
        $tokens = array_values(array_filter(
            token_get_all($php),
            static fn (array|string $t): bool => !\is_array($t)
                || !\in_array($t[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
        ));

        $tally = [];
        $dynamic = 0;
        $count = \count($tokens);

        for ($k = 0; $k + 1 < $count; ++$k) {
            $token = $tokens[$k];

            if (!\is_array($token) || $token[0] !== \T_STRING
                || !\in_array($token[1], $entryPoints, true)) {
                continue;
            }

            $previous = $k > 0 ? $tokens[$k - 1] : '';

            if (!\is_array($previous) || !\in_array($previous[0], $callOperators, true)
                || $tokens[$k + 1] !== '(') {
                continue;
            }

            $head = self::headArgument($tokens, $k + 1);

            if (\count($head) === 1 && \is_array($head[0])
                && $head[0][0] === \T_CONSTANT_ENCAPSED_STRING) {
                $literal = substr($head[0][1], 1, -1);
                $tally[$literal] = ($tally[$literal] ?? 0) + 1;

                continue;
            }

            ++$dynamic;
        }

        ksort($tally);

        return ['tally' => $tally, 'dynamic' => $dynamic];
    }

    /**
     * The tokens of argument $index of the call whose opening `(` is at $open.
     *
     * Depth-counted over all three bracket pairs, so a nested call, array literal
     * or short closure in an earlier argument cannot be mistaken for the end of
     * the argument list — which is the whole reason
     * {@see literalHeadArguments()} cannot split on `,` textually. An argument
     * list with a trailing comma yields an empty final argument; asking for an
     * index past the end yields an empty list, which
     * {@see literalHeadArguments()} treats as "not a literal".
     *
     * TWO ASYMMETRIES IN PHP'S OWN TOKEN STREAM have to be handled here rather
     * than discovered later. In both an opening bracket comes back as an ARRAY
     * token while its closer comes back as a bare string, so a depth count that
     * matched only bare brackets would see one more closer than opener, drop the
     * depth to zero early and truncate the argument list:
     *
     *   * `T_CURLY_OPEN` — the `{` that opens an interpolation in `"...{$x}..."`,
     *     closed by a bare `}`.
     *   * `T_DOLLAR_OPEN_CURLY_BRACES` — the `${` that opens the spelling PHP 8.2
     *     deprecated, also closed by a bare `}`. Its token text is `${` rather
     *     than `{`, measured on PHP 8.3.6, so a walker keying on the text alone
     *     misses it as surely as one keying on the bare string does.
     *   * `T_ATTRIBUTE` — the `#[` that opens an attribute, closed by a bare `]`.
     *     This was the SECOND asymmetry while the paragraph above it said "THE ONE
     *     ASYMMETRY", and it is not exotic here: this file's own data-provider
     *     attributes are spelled `#[\PHPUnit\Framework\Attributes\DataProvider(…)]`.
     *     Measured before it was handled, both of these were `php -l` clean and
     *     both scanned to `tally=[] dynamic=1` — the head silently lost:
     *     `self::directiveValues(array_map(static fn (#[\SensitiveParameter] string
     *     $s): string => $s, $a)[0], 'Set Padding')` and
     *     `self::directiveValues((new #[Foo] class { public $t = 'x'; })->t, 'Set
     *     Padding')`.
     *
     * No call site in this file passes an interpolated string or an attribute
     * inside a model call today, which is exactly why both are worth handling now
     * and pinning in {@see testTheHeadScanSeesACallWrappedAcrossLines()}.
     *
     * WHAT THIS SAID about the third opener: "the deprecated `${x}` spelling is
     * genuinely NOT handled and is not used — that one is a stated gap, not an
     * unnoticed one." WHAT IS TRUE NOW: it is handled, in both counters. The
     * sentence was accurate when written and became the wrong half of a
     * trade-off: `tests/Support/InterpolationOpenerTokenTest.php` derives the
     * opener roster from the running interpreter and requires every brace
     * walker under `tests/` and `src/` to name all of it, so "stated gap"
     * stopped being a position this file could hold on its own. WHY THE
     * REASONING STILL EARNS ITS PLACE: the distinction it draws — between a gap
     * somebody argued for and a gap nobody noticed — is the one that decides
     * whether an exemption row or a fix is the right answer, and the next
     * asymmetry PHP adds will need it again. The syntax still occurs zero times
     * in `src/` and `tests/`, so handling it costs one token per counter and
     * buys the walk back if it ever appears.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    private static function callArgument(array $tokens, int $open, int $index): array
    {
        $depth = 0;
        $at = 0;
        $argument = [];

        for ($k = $open; $k < \count($tokens); ++$k) {
            $token = $tokens[$k];

            if ($token === '(' || $token === '[' || $token === '{'
                || (\is_array($token)
                    && ($token[0] === \T_CURLY_OPEN
                        || $token[0] === \T_DOLLAR_OPEN_CURLY_BRACES
                        || $token[0] === \T_ATTRIBUTE))) {
                ++$depth;

                if ($depth === 1) {
                    continue;
                }
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                --$depth;

                if ($depth === 0) {
                    break;
                }
            } elseif ($token === ',' && $depth === 1) {
                ++$at;

                if ($at > $index) {
                    break;
                }

                continue;
            }

            if ($at === $index) {
                $argument[] = $token;
            }
        }

        return $argument;
    }

    /**
     * The tokens of the DIRECTIVE argument of the call whose `(` is at $open,
     * whichever way it was written.
     *
     * A NAMED ARGUMENT is why this is not just `callArgument($tokens, $open, 1)`.
     * `self::valuesWithNoPhpDiagnostic(tape: $t, directive: 'Set Padding')` is a
     * literal head argument by any reading, and the positional-index-1 walk saw
     * `directive` `:` `'Set Padding'` — three tokens, so the single-token literal
     * gate in {@see literalHeadArguments()} fell through to the DYNAMIC count.
     * Measured at the PARENT commit — a total safe to quote because the state it
     * describes is gone — inserting exactly that call as a genuine fifth
     * `Set Padding` query left the whole file GREEN at 113 tests / 532 assertions,
     * while the identical call written positionally failed the tally with
     * `'Set Padding' => 4` against 5. That is the same defect class as the wrapped
     * call the regex could not see, one spelling along — the domain had shrunk
     * from "every literal head argument" to "every literal head argument the
     * single-token gate can see".
     *
     * PHP requires positional arguments before named ones, so index 1 is still
     * the head until someone writes `directive:`; a named `directive:` may sit at
     * either index and wins wherever it is. An argument named anything else at
     * index 1, or a missing second argument, yields the empty list — which
     * {@see literalHeadArguments()} counts as dynamic rather than guessing.
     *
     * AND THE RESTRICTION, stated here because both entry points take exactly two
     * parameters and that is an assumption rather than a law: only indices 0 and
     * 1 are examined, so a `directive:` at index 2 or beyond is outside this
     * method's domain — the call would be counted as DYNAMIC, not tallied and
     * not silent. Relaxing the bound to `$index <= 2` is a registered survivor in
     * {@see literalHeadArguments()}'s sweep for exactly that reason: no call site
     * in this file has a third argument at all.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<array{int, string, int}|string>
     */
    private static function headArgument(array $tokens, int $open): array
    {
        for ($index = 0; $index <= 1; ++$index) {
            [$name, $value] = self::splitNamedArgument(self::callArgument($tokens, $open, $index));

            if ($name === 'directive') {
                return $value;
            }

            if ($index === 1 && $name === null) {
                return $value;
            }
        }

        return [];
    }

    /**
     * Split `name: <expression>` into its two halves, or report the argument as
     * positional.
     *
     * Three tokens is the shortest a named argument can be, and the leading pair
     * is unambiguous inside an argument list: a bare `:` after a `T_STRING` at
     * argument depth is the named-argument colon and nothing else. A short
     * ternary (`$x ?: 'y'`) and a call (`foo() ?: 'y'`) both start with a token
     * this cannot mistake for a name.
     *
     * @param list<array{int, string, int}|string> $argument
     *
     * @return array{0: string|null, 1: list<array{int, string, int}|string>}
     */
    private static function splitNamedArgument(array $argument): array
    {
        if (\count($argument) >= 3 && \is_array($argument[0])
            && $argument[0][0] === \T_STRING && $argument[1] === ':') {
            return [$argument[0][1], \array_slice($argument, 2)];
        }

        return [null, $argument];
    }

    /**
     * Collect the argument of every occurrence of a directive.
     *
     * vhs lexes a tape into one flat stream of TOKENS. There is no newline
     * token in it, so a line is not a unit of anything: a directive is not
     * anchored to column 0, several may share a line, and a directive and its
     * value need not be on the same line at all. This walks the whole file as
     * one stream for that reason, and gives a directive every following token
     * up to the next KEYWORD, joined with a single space — which is what vhs
     * does. Every claim below was reproduced against the `vhs` binary this
     * suite is written for, v0.11.0, by rendering a probe tape and reading
     * its exit status, the command list it echoes, or the file it wrote:
     *
     *   * A head and its value need not share a line. `Set Shell` / `"sh"` on
     *     two lines still aborts with `invalid shell sh`, exit 1, and so does
     *     `Set` / `Shell` / `sh` on three. `Output` / `n3.txt` wrote `n3.txt`,
     *     and `Source` / `srcd.tape` resolved and honoured a `srcd.tape`
     *     carrying `Set Shell "sh"` — aborting the PARENT render. A parser
     *     that reads a value only from the head's own line misses all four
     *     silently, which is how the defect this file exists to catch came
     *     back a fourth time.
     *   * A value runs to the next keyword, quoted or bare alike, and the
     *     pieces are joined with a space. `Type php examples/echo-chat.php`
     *     types the whole path, and `Type "php" "examples/echo-chat.php"`
     *     types `php examples/echo-chat.php` — a space between them, put
     *     there by vhs. `Type abc` / `def` on two lines types `abc def`.
     *   * A keyword ends it, and only a keyword. `Type abc Enter def` types
     *     `abc`, presses Enter and then answers `Invalid command: def`, while
     *     `Type abc Bogus def` types all three words as one string.
     *   * A quoted token is never a head. `"Type" "x"` answers
     *     `Invalid command: Type`, so a directive word inside a string is
     *     value text and nothing else.
     *   * Several directives share a line, in either order.
     *     `Sleep 500ms Set Shell "sh"` and `Type "x" Set Shell "sh"` both
     *     abort with `failed to execute command: invalid shell sh`, and
     *     `Sleep 200ms Output evil1.txt` / `Output evil2.txt Set Height 380`
     *     both wrote the named file. This repo's own tape convention uses that
     *     form (`Down  Sleep 400ms` in sugar-stash/.vhs/play.tape and a dozen
     *     more tapes besides), so it is not a hypothetical.
     *   * `vhs validate` is no backstop: it exits 0 on both shared-line
     *     `Set Shell "sh"` tapes above, which then fail at render time.
     *   * A value may be quoted with `"` or `'`, or carry no quotes at all —
     *     `Set Shell 'sh'` and `Set Shell sh` are both read as `sh` and
     *     rejected as one. A backtick delimits a string too (`` Type `hello` ``
     *     types `hello`), so it is stripped here for the same reason.
     *   * `\` does not escape inside a STRING: `Type "say \"hi\""` is a parse
     *     error, so a quote always ends its string. That does not generalise
     *     to every delimited token, and an earlier revision of this line said
     *     it did — a REGEX is escape-aware, and the two rounds it took to
     *     notice are F2 and F3 in {@see tokenize()}.
     *   * `#` opens a comment ANYWHERE outside a string, adjacent or not —
     *     `Set Shell "sh" # why` still aborts on `sh` (so the value ends at
     *     the `#`), and `Sleep 200ms#c` renders clean. Inside a string it is
     *     literal: `Type "# hash inside"` types it.
     *   * The `@` speed suffix rides on the head token: `Type@100ms "hello"`
     *     types `hello`, so matching bare `Type` alone would miss it.
     *   * A string ends at the newline, exactly as it does here — vhs has no
     *     multi-line string. `Type "echo abc` / `def"` types `echo abc def `,
     *     which is THREE strings joined: `echo abc`, the bare word `def`, and
     *     the empty unterminated string the trailing `"` opens (hence the
     *     trailing space). It is not one string carrying a newline. A
     *     continuation line that is directive-shaped is a hard parse error,
     *     but because its words lex as commands rather than because a string
     *     is unclosed: `Type "hello` / `Set Shell sh"` is `parser: 1 error(s)`
     *     and `Type "abc` / `Output evil.gif` / `def"` is `parser: 2 error(s)`
     *     with no `evil.gif` written.
     *   * A `/` at a TOKEN START opens a regex, `{` opens a JSON token
     *     anywhere, and `#` plus all three quotes are literal inside either.
     *     `Set WaitPattern /a#b/ Source nofile.tape` resolves the `Source`, and
     *     so do the `/a"b/`, `/a'b/`, `` /a`b/ `` and `{#}` spellings.
     *     Mid-token a `/` is inert, a `{` is not. A regex also honours an odd
     *     run of backslashes as escaping its delimiter, and a backslash before
     *     a newline carries it onto the next line. The full grammar, its
     *     asymmetries and where each rule comes from are in {@see tokenize()}.
     *
     * WHERE THIS MODEL AND UPSTREAM DIVERGE, and why the divergence is
     * bounded. No EXHAUSTIVE LIST is given here, deliberately. This paragraph
     * has been rewritten three times, each time as such a list — two places,
     * then three — and each list was falsified by the next round: the JSON
     * token, the nine punctuation tokens `@ = + - % ^ \ [ ]` and seven of the
     * keywords were all missing from the "three". A claim that cannot be kept
     * true is worse than a narrower one that can, so what is recorded is the
     * DERIVATION and the oracle, both of which are re-checkable in
     * `parser/parser.go` in a way a hand-count is not. Counts DO appear further
     * down, each one with the domain it was measured over — a figure without its
     * domain is the specific mistake that let round twelve's defect ship.
     *
     * The derivation: upstream ends a value at the first token that is not a
     * `token.STRING`. `parseType` and `parseCopy` loop `for p.peek.Type ==
     * token.STRING`; `parseSet` takes exactly `p.peek.Literal`, one token,
     * whatever kind it is. So NUMBER, JSON, REGEX, BOOLEAN, every keyword and
     * every punctuation token terminate a value upstream. This model instead
     * ends a value at the next KEYWORD and hands everything else to the value,
     * so on each of those it gives a value MORE tokens than upstream would:
     * `Type abc 123 def` reads `abc 123 def` where upstream types `abc` and
     * answers `Invalid command: 123`. Every tape in that class is one upstream
     * already refuses — the terminator it stopped at becomes the next
     * `Parse()` step's command and is not one — so the class as a whole can
     * only ever raise a false alarm on a tape that already fails CI.
     *
     * WHAT THAT ARGUMENT DOES NOT BOUND, and the reason it was read as if it
     * did. It is an argument about which TOKEN ends a value, and it holds. It
     * says nothing about where each token itself ends, and until the byte
     * classes went in that was the actual defect: the model handed a value more
     * tokens' worth of TEXT and, at the same time, FEWER occurrences, because
     * the next directive's head had been glued into the last token of the
     * previous value. Step 5 below names fewer occurrences as "a false green of
     * exactly the kind this file exists to prevent", and that is what was
     * happening while this paragraph reassured the reader in the other
     * direction. Both halves have to be checked, and both now are: the
     * occurrence direction by the differential in step 5, the token boundaries
     * by the 255-byte class differential in {@see tokenize()}.
     *
     * THE MIRROR IMAGE, which the derivation above does NOT cover, so each
     * class is named with the domain it was measured over rather than argued
     * away. Every one of them is a case where upstream gives a value MORE than
     * this model does, or a different one; none of them loses an occurrence,
     * because the loop below resumes head-matching ON the terminator rather
     * than past it. What class 1 DOES do, and what the phrase "none of them
     * loses an occurrence" reads as denying, is GAIN one: resuming on the
     * terminator means a `Set` whose value is a keyword naming a queried
     * directive yields that directive twice over. Measured — `Set Theme Output`
     * then `Output a.gif` parses with zero errors, upstream emits ONE `OUTPUT`
     * command, and this model answers `['', 'a.gif']` for `Output`. That is the
     * loud direction (an empty value fails every path assertion here, and an
     * extra occurrence fails the by-count ones), so no false green follows from
     * it; the class is real and complete, and only its description was short.
     *
     * The previous revision listed two of these and called the second "the ONLY
     * divergence class the corpus differential below still reports". The
     * differential reports FIVE, and the sentence was written while three of
     * them were already in it — which is the same failure mode as the glue-byte
     * figure in {@see tokenize()}: a completeness claim whose domain was never
     * stated. So no TOTAL is claimed for the list either: it is a list of
     * MEASUREMENTS, each carrying what measured it, and a sixth entry was added
     * to it from a probe the differential's corpus cannot produce (that corpus
     * queries `Set Padding` and `Output` and no keypress head at all).
     *
     *   1. `Set` whose value is a KEYWORD — empty here, the token upstream.
     *      `Set CursorBlink false` is `false` upstream (clean parse), `` here.
     *   2. `Set` whose value is a COMMENT — empty here, the comment TEXT
     *      upstream. `Set Theme #Dracula` really does set the theme to
     *      `Dracula`, and `Set Shell #sh` really does abort the render. DOMAIN:
     *      the 73,920-tape delimiter corpus, 2,682 of its 24,846 clean-parse
     *      tapes, all of them a `Set` followed by a `#`.
     *   3. `Set` whose value is the SINGLE-BYTE token `@` — empty here, `@`
     *      upstream, on a tape upstream accepts. `Set Padding @Output x.gif` is
     *      zero errors and `vhs validate` exit 0 with SET Padding `@`.
     *      {@see skipSpeedSuffix()} is why, and it has the full measurement.
     *      DOMAIN: the 255-tape byte sweep in {@see tokenize()}, where `0x40`
     *      is the only value divergence that is neither a `#` nor the UTF-8
     *      re-encoding residual; pinned by
     *      {@see testTheSetPlusAtDivergenceLosesTheValueNotTheHead()}.
     *   4. UNIT RE-JOINING in `parseSet`/`parseTime`. Upstream appends the unit
     *      to the value, sometimes one the tape never wrote: `LoopOffset` gets a
     *      `%` (`parser.go:469`), `TypingSpeed` and `parseTime` an `s` when no
     *      unit token followed (`parser.go:483`, `parser.go:282`), and a unit
     *      that DID follow is concatenated with no space (`parser.go:480`,
     *      `parser.go:279`). This model gives the tokens joined by a space, or
     *      the number alone once the unit keyword terminates the value:
     *      `Set LoopOffset 50%` → `50%` upstream, `50 %` here;
     *      `Set TypingSpeed 60ms` → `60ms` upstream, `60` here;
     *      `Sleep 1s` → `1s` upstream, `1` here.
     *      DOMAIN, and it is CITED rather than restated, because restating it is
     *      how it went stale: the heads this file queries LITERALLY are exactly
     *      the keys of the tally asserted by
     *      {@see testSetShellIsTheMostQueriedTwoWordHead()}, plus the two heads
     *      the providers pass as data. The prose enumeration this bullet used to
     *      carry named NINE heads. It omitted `Set Padding`, which was already a
     *      literal call site — twice — at the commit the enumeration was written
     *      on; and then `Hide` and `Enter`, both added by the very commit the
     *      enumeration shipped in. Nine named, twelve today, and the fix is to
     *      point at the assertion rather than to renumber the prose.
     *      THE INERTNESS CLAIM ITSELF STANDS, and now with a reason per head that
     *      could carry a unit at all. `Set Padding` reaches `parseSet`'s ungated
     *      `default:` arm (`parser.go:527-528`), which appends nothing, and no
     *      unit token can follow it cleanly: `Set Padding 5px` is ONE error,
     *      `Invalid command: px`, `vhs validate` exit 1 on BOTH binaries — so
     *      Padding never re-joins a unit. `Enter` takes a speed, but
     *      `parseKeypress` is `cmd.Options = p.parseSpeed(); cmd.Args =
     *      p.parseRepeat()` (`parser.go:406-411`), so `parseTime`'s appended `s`
     *      lands in OPTIONS, outside the value this model reads — its divergence
     *      at that call site is class 6 below, not this one. Every other queried
     *      head takes no unit. So the class is inert TODAY.
     *      It is not hypothetical: all five tapes carry exactly one
     *      `Set TypingSpeed 60ms` (chat:29, permission:33, diff:39, agents:57,
     *      cli:85) and 22 `Sleep <n>s|ms` between them, so the moment
     *      anyone writes `testTypingSpeedIsPinned()` it fails for a reason that
     *      has nothing to do with the tape. Fix the model then, not the tape.
     *   5. `Env`'s Options/Args SPLIT. `parseEnv` puts the first token in
     *      Options and the second in Args (`parser.go:652-666`), so `Env FOO bar`
     *      is Args `bar` upstream and `FOO bar` here. This one is the ordinary
     *      over-approximation direction, listed with the rest because the same
     *      two-token shape is what makes `Set X` a two-word head here.
     *   6. A KEYPRESS head's synthesized REPEAT COUNT. `parseRepeat` returns the
     *      string `1` when the token after the head is not a NUMBER
     *      (`parser.go:253-261`), and `parseKeypress` puts it in Args
     *      (`parser.go:406-411`), so upstream reports a count the tape never
     *      wrote. Same shape as class 4 at a third site. MEASURED: `Output a.gif`
     *      + newline + `Enter` parses with zero errors on all three oracles and is
     *      ENTER Args `1` upstream against `` here. DOMAIN: a probe, not the
     *      corpus differential — its generator emits no keypress head, which is
     *      why the class went unlisted while the differential reported five.
     *      LIVE, NOT INERT, and it went live in the same commit that wrote "inert
     *      today (nothing here queries a keypress head)" about it. The call site
     *      is the `Enter` query in
     *      {@see testTheSpeedSuffixWalkStopsAtTheEndOfTheStream()} — a keypress
     *      head, and one of the keys of
     *      {@see testSetShellIsTheMostQueriedTwoWordHead()}'s tally — where
     *      upstream answers ENTER Options `100s` / Args `1` on a tape it parses
     *      with zero errors (`vhs validate` exit 0 on both binaries) and this
     *      model answers ``. That assertion's own message already names the class,
     *      which is what made the inertness claim checkable and false in the same
     *      commit.
     *      IT STAYS IN THE LOUD DIRECTION: the `1` is a value UPSTREAM invents,
     *      so an empty answer here can only under-report — it cannot approve a
     *      tape. And the `Hide` row beside it is the control that keeps the class
     *      visible: `parseHide` builds a Command and synthesizes nothing
     *      (`parser.go:548-551`), so that is the one row in this file where the
     *      model and upstream agree on the VALUE as well as on the occurrence.
     *
     * All six stay in the loud direction — an empty or over-long value fails
     * every assertion in this file rather than hiding one — but they are
     * divergences and pretending otherwise is how this paragraph got falsified
     * three times.
     *
     * And one divergence is in neither direction, which is why it is modelled
     * explicitly instead of being bounded by argument: `{` … `}` both over-
     * and under-approximates, because it can hide a `#`, and hiding a `#`
     * hides every directive behind it. See {@see tokenize()}.
     *
     * THE THREE ORACLES, named by VERSION and not only by path. Every binary
     * citation in this file was re-derived on all three, and all three agree on
     * every tape cited, byte for byte including the three panic offsets:
     *
     *   * THE GO, and the authority. `$(go env GOMODCACHE)/github.com/
     *     charmbracelet/vhs@v0.11.0` — see the procedure below.
     *   * `vhs version v0.11.0 (c6af91a)`, 23,171,234 bytes, found at
     *     `/usr/local/bin/vhs`.
     *   * `vhs version v0.11.0`, 30,797,703 bytes, found at `/tmp/vhsbin/vhs`.
     *     A DIFFERENT BUILD of the same tag — different size, and its version
     *     string carries no commit — which is why the two are worth running
     *     separately rather than treating either path as "the binary".
     *
     * Where a claim anywhere in this file says "both binaries" it means those two;
     * where it says "all three oracles" it means those two and the Go.
     *
     * WHY BY VERSION. Five citations used to name `/tmp/vhsbin/vhs validate` and
     * nothing else, and the round after them wrote "there is no vhs binary on this
     * machine" into its commit message and hedged every binary claim to the Go
     * alone. Both halves of that are explained by the mtimes: `/tmp/vhsbin/vhs` is
     * `2026-08-17 08:33`, four hours and fifty-nine minutes BEFORE `2bd2263f`
     * (`2026-08-17 13:32`, `git log -1 --format=%cI`), and it is not on `PATH`;
     * `/usr/local/bin/vhs` is `13:36`, three minutes AFTER it. So a `command -v
     * vhs` at that moment answered nothing while a usable binary the file already
     * cited by absolute path sat on disk. A version string survives that; a path
     * in `/tmp` does not.
     *
     * HOW TO RE-CHECK ALL OF THIS, since a comment nobody measured is itself a
     * defect and this file has shipped three of those. The strongest available
     * oracle is not `vhs validate` (which exits 0 on every false green this
     * file has ever had) and not a render, but upstream's own lexer and parser
     * run directly:
     *
     *   1. `go env GOMODCACHE`/github.com/charmbracelet/vhs@v0.11.0 holds the
     *      real source. Copy `lexer/lexer.go`, `token/token.go` and
     *      `parser/parser.go` into a scratch module and do NOT touch their
     *      imports: name the scratch module `github.com/charmbracelet/vhs` in
     *      its own `go.mod` and all three `github.com/charmbracelet/vhs/...`
     *      import lines resolve unchanged, so `diff` against the originals is
     *      empty on all three files rather than three lines short of it. (An
     *      earlier revision of this step rewrote the imports and diffed to prove
     *      nothing ELSE moved, which works and leaves three edits to argue
     *      about; renaming the module leaves none.)
     *   2. Add a `main` that prints, for a tape: the token stream
     *      (`Type` + `Literal` per token), `parser.Parse()`'s command list
     *      (`Type`/`Options`/`Args`) and `p.Errors()`. Wrap both in a
     *      `recover()` — upstream's lexer can panic, see {@see scanRegex()}.
     *   3. Generate a corpus of tapes from fragments that exercise the five
     *      `#`-hiding bytes, backslash runs, newlines inside tokens and the
     *      keyword set, each with a `Set Shell "sh"` / `Source nofile.tape`
     *      sentinel behind it. Keep only the ones upstream parses with ZERO
     *      errors — a tape upstream rejects cannot be a false green.
     *   4. For each survivor compare this method's answer against the command
     *      list: `Set X` → the `Args` of every `SET` command whose `Options`
     *      is `X`; `Output`/`Type` → the `Args` of those commands; `Source` →
     *      the token after each `SOURCE` token, because `parseSource` inlines
     *      the sourced file and keeps no record of the path.
     *   5. Any survivor where this method reports FEWER occurrences than the
     *      command list is a false green of exactly the kind this file exists
     *      to prevent. More occurrences, or a longer value, is the bounded
     *      over-approximation above.
     *
     * The numbers each run produced, WITH THE DOMAIN EACH WAS MEASURED OVER,
     * because a figure without its domain is what turned this paragraph into a
     * clean bill of health for a model that was losing directives.
     *
     * The delimiter corpus. Six heads x eight openers x twenty-two middles x
     * seven closers x five sentinels, each sentinel placed both behind the
     * closer and on the following line — 73,920 tapes, of which upstream parses
     * 24,846 with zero errors. Against the model as it then stood: 0
     * miss-direction divergences, 0 extra-occurrence divergences, 2,682 value
     * divergences, every one of them the `Set` + comment case above. Against
     * the model THAT round replaced: 156 miss-direction divergences, spanning
     * `{a#b}`, `/a\/#b/`, `/https:\/\/x#y/` and `/a\` + newline + `#b/`. A
     * rebuild that reports 0 against BOTH models is broken, not clean.
     *
     * What that corpus cannot reach, and did not: its product ALWAYS separates
     * the sentinel from the value by whitespace or by a closer, and a closer is
     * either a delimiter the tokenizer knows or something `scanRegex()` handles.
     * So no tape in it ever glues a directive head directly onto a bare value,
     * and the whole 192-byte glue class was outside the domain of the
     * "0 miss-direction divergences" it reported — 192 measured over the 255
     * tapes `Set Padding <B>Output x.gif`, one per byte value B in 1-255, the
     * enumeration in {@see tokenize()}. Correctly reported, and wholly
     * reassuring about the wrong thing.
     *
     * The domain sweep that found it. Nine families, 998 zero-error
     * tapes-with-sentinel: adjacency (glue, no space), nested constructs, two
     * constructs on one line, CRLF, non-ASCII spaced and glued, three-line
     * constructs, multiple sentinels on a line, empty middles, and a closer with
     * the sentinel glued to it. Against the break-set model: 627 misses, 0
     * extras, and they fall in exactly two families — adjacency (604) and
     * multiple sentinels (23). Against the model in this file now: 0 and 0
     * across all nine. The lesson is the family list, not the totals: any
     * rebuild of the harness should start by asking which of the nine its
     * generator can produce.
     *
     * The five shipped tapes, which is the domain that actually ships. Upstream
     * parses all five with zero errors and emits 74 command occurrences across
     * 13 distinct directive kinds and 52 (tape, kind) pairs — 25 of them `Set`,
     * which is 9 kinds if bare `Set` is counted once, over 16 distinct KEYWORD
     * token types. This method agrees with the command list on every directive
     * any assertion here queries: 0 miss-direction divergences.
     *
     * That harness is what found the JSON token and both regex escape rules
     * after ten rounds of black-box probing had found neither, and the tests
     * below are its findings pinned as fixed cases so the harness does not
     * have to be rebuilt to notice a regression.
     *
     * @return list<string>
     */
    private static function directiveValues(string $tape, string $directive): array
    {
        $source = file_get_contents($tape);
        self::assertIsString($source, "could not read {$tape}");

        $words = preg_split('/\s+/', trim($directive)) ?: [];
        $arity = \count($words);
        $tokens = self::tokenize($source);
        $count = \count($tokens);
        $values = [];

        for ($i = 0; $i + $arity <= $count;) {
            if (!self::headMatches($tokens[$i], $words[0])) {
                ++$i;
                continue;
            }

            // `Set Shell` is two tokens; the trailing ones are plain settings
            // names, carrying neither a speed nor a modifier suffix.
            $matched = true;
            for ($w = 1; $w < $arity; ++$w) {
                if ($tokens[$i + $w]['kind'] !== 'ident' || $tokens[$i + $w]['text'] !== $words[$w]) {
                    $matched = false;
                    break;
                }
            }

            if (!$matched) {
                ++$i;
                continue;
            }

            $i = self::skipSpeedSuffix($tokens, $i + $arity, $count);
            $parts = [];

            // Stops ON the terminator rather than past it: the token that ends
            // this value is the next directive's head, and stepping over it
            // would lose that occurrence.
            while ($i < $count && !self::startsDirective($tokens[$i])) {
                $parts[] = $tokens[$i]['text'];
                ++$i;
            }

            $values[] = implode(' ', $parts);
        }

        return $values;
    }

    /**
     * Split the WHOLE tape the way upstream vhs's lexer does.
     *
     * =====================================================================
     * THE UPSTREAM vhs v0.11.0 LEXICAL GRAMMAR
     * =====================================================================
     *
     * Written out in full rather than as commentary on the individual cases
     * below, because it is not local knowledge and it is about to be needed
     * somewhere else. `candy-vcr` is meant to replace this renderer outright
     * (see the class docblock), and `candy-vcr/src/Tape/Lexer.php` is today a
     * line-oriented regex matcher with NO JSON token and NO regex token at
     * all — so two of the five constructs under "WHAT CAN HIDE A `#`" below
     * do not exist there yet. Everything in this section is derived from
     * upstream's `lexer/lexer.go` and `token/token.go` at v0.11.0 (c6af91a)
     * and then confirmed against the `vhs` binary; whoever writes the
     * replacement lexer should be able to work from here without reading the
     * Go.
     *
     * SHAPE. One flat token stream. There is no newline token and no
     * statement separator, so a line is not a unit of anything: a directive
     * is not anchored to column 0, several may share a line (`Down Sleep
     * 400ms` is this repo's own house style), and a head and its value need
     * not share a line. Whitespace between tokens is insignificant — a `\n`
     * only bumps the line counter for error messages, and bounds some of the
     * token kinds below.
     *
     * WHICH BYTES ARE A NEWLINE, spelled out because three token kinds end
     * where one says so and `\r` is easy to leave out of a PHP port: upstream's
     * `isWhitespace` is `' '`, `\t`, `\n`, `\r` (`lexer.go:271`) and its
     * `isNewLine` is `\n` or `\r` (`lexer.go:279`, whose own doc comment says
     * the split exists for Windows CRLF). So a BARE `\r` is whitespace between
     * tokens AND the bound of a COMMENT (`lexer.go:118`), a STRING
     * (`lexer.go:133`) and a REGEX (`lexer.go:156`) alike — one CR ends all
     * three, exactly as one LF does. Pinned by
     * {@see testACarriageReturnBoundsEveryTokenAnLfBounds()}, which exists
     * because this file went twelve rounds with no `\r` byte anywhere in its
     * test data while `\r` decided where three of its token kinds ended.
     *
     * TOKEN KINDS, in the order upstream's `NextToken` switch tries them:
     *
     *   EOF                             end of input
     *   `@` `=` `+` `-` `%` `^` `\` `[` `]`
     *                                   nine single-byte tokens, one byte each
     *   COMMENT   `#` … newline | EOF                    newline-bounded
     *             — and a REAL token, not a skipped one. `Parse()` skips it at
     *             the top level, but a parse function that reads
     *             `p.peek.Literal` without gating on the token type takes it:
     *             `parseSet` does so silently (`Set Theme #Dracula` sets the
     *             theme to `Dracula`) and `parseRequire` does so while also
     *             raising an error (`Require #x` gets `x`). The ones that gate
     *             — `parseType`, `parseOutput`, `parseCopy` — reject it. All
     *             measured; see {@see directiveValues()}.
     *   JSON      `{` … `}`   | EOF                  NOT newline-bounded
     *   STRING    `` ` `` … `` ` `` | newline | EOF      newline-bounded
     *   STRING    `'`  … `'`  | newline | EOF            newline-bounded
     *   STRING    `"`  … `"`  | newline | EOF            newline-bounded
     *   REGEX     `/`  … `/`  | newline | EOF      escape-aware, see ESCAPES
     *   NUMBER    entered on a digit, or on a `.` whose next byte is a digit;
     *             the run is `[0-9.]+` and NOTHING else, so `1.2.3` is one
     *             number token but `50%` is two (NUMBER `50`, then `%`) and
     *             `5em` is two (NUMBER `5`, then the keyword `em`) — measured
     *   keyword | STRING
     *             entered on a letter or a `.`; the run continues over
     *             letters, digits, `.`, `-`, `_`, `/` and `%` — note `/` and
     *             `%`, which is what holds `./bin/sugarcrush` and `a50%b`
     *             together as single tokens even though a leading digit would
     *             have split them. The run is then looked up in the 60-entry
     *             keyword map ({@see KEYWORDS}); a miss is a STRING, i.e. a
     *             bare word. Lookup is exact and case-sensitive.
     *   ILLEGAL   any other byte, one token each
     *
     * DELIMITER ASYMMETRY — the part that is easy to get wrong, and the part
     * this file has got wrong in three different ways:
     *
     *   * `"` `'` `` ` `` are symmetric (opener and closer are the same byte)
     *     and each also ENDS a bare word run, since none is in the identifier
     *     byte set.
     *   * `/` is symmetric but POSITION-DEPENDENT: it opens a REGEX only
     *     where a token starts. Mid-word it is an ordinary identifier byte,
     *     so `.vhs/cli.gif` is one token, and in `a/b#c/d` the `#` really
     *     does open a comment. This is the single most confusing rule in the
     *     grammar and it cost this file eight revisions.
     *   * `{` is ASYMMETRIC — it closes on `}`, never on a second `{` — and
     *     it is NOT newline-bounded, so an unterminated `{` swallows the rest
     *     of the FILE. It also ends a bare word run, so `a{#}b` is three
     *     tokens. And unlike a string, whose quotes are stripped, the JSON
     *     literal upstream stores KEEPS both braces (and appends the `}` even
     *     when the input never supplied one): `Set WaitPattern {#}` has the
     *     three-byte value `{#}`.
     *
     * ESCAPES. There is exactly ONE escape rule in the whole grammar and it
     * lives only in the regex reader. Nothing escapes inside a string:
     * `Type "say \"hi\""` is a parse error, because the second `"` simply
     * closes the string. Inside a regex, upstream counts a run of consecutive
     * backslashes, and an ODD count escapes the delimiter that follows it
     * (upstream's own doc comment: `/foo\/bar/`) while an EVEN count does
     * not, so `/foo\\/` ends at that `/`. Two further consequences follow
     * from the reader's control flow rather than from its intent, and both
     * are load-bearing:
     *
     *   * the byte after a backslash run is consumed WITHOUT being tested, so
     *     `\` followed by a newline makes the regex CROSS THE LINE — the one
     *     place in this grammar where a newline does not bound an otherwise
     *     newline-bounded token;
     *   * if that backslash run is the last thing in the file, upstream reads
     *     one byte past the end and PANICS — `slice bounds out of range`,
     *     exit 2, no GIF. Measured on both oracles for runs of one, two and
     *     three backslashes; `/a\ `, `/a\` + newline and `/a` do not panic.
     *     {@see testNoTapeEndsInUpstreamsRegexPanic()} keeps it out of the
     *     tapes.
     *
     * WHAT CAN HIDE A `#`. Five bytes: `"` `'` `` ` `` `/` `{`. That set is
     * complete BY CONSTRUCTION, which is a stronger claim than any of the
     * byte sweeps this docblock used to cite: a `#` is ordinary text only
     * inside a token whose reader consumes it, and upstream has exactly five
     * readers that consume one — `readComment` (the `#` itself),
     * `readString` (three openers), `readRegex`, `readJSON`.
     * `readIdentifier` and `readNumber` both stop at `#`, and every other
     * token is a single byte. So there is no sixth character, and reading
     * `lexer.go` is what establishes that; a sweep could only ever confirm
     * it, which is exactly what the 94-printable-ASCII sweep and the
     * 64,009-opener/closer-pair sweep did.
     *
     * Hiding a `#` is the LOUDEST way to lose a directive but not the only
     * one, and for eight rounds this docblock said it was — see WHERE A
     * TOKEN ENDS below. Whatever hides a `#` hides every directive behind
     * it, which is why the five bytes get a completeness argument of their
     * own; but a model that agrees on all five and still ends a token one
     * byte late loses the next directive's HEAD to the previous value, and
     * that is a miss of exactly the same kind. Both halves have to be right.
     *
     * Neither is {@see KEYWORDS}, and the asymmetry is worth keeping in view:
     * every head this suite queries (`Output`, `Type`, `Source`, `Set`) is
     * itself a keyword, so a MISSING keyword only lets a value run on and
     * fail loudly. The elaborate completeness argument in {@see KEYWORDS} was
     * spent on the half that can only ever raise a false alarm.
     *
     * WHERE A TOKEN ENDS. Upstream decides that with two POSITIVE byte
     * classes and nothing else:
     *
     *   `readNumber`   run = `isDigit || isDot`
     *                  ⇒ {@see NUMBER_BYTES}, eleven bytes
     *   `readIdentifier` run = `isLetter || isDot || isDash || isUnderscore
     *                  || isSlash || isPercent || isDigit`
     *                  ⇒ {@see IDENT_BYTES}, sixty-seven bytes
     *
     * Every byte outside the class the reader is in ends the run there, and
     * is then either one of the nine single-byte tokens
     * ({@see SINGLE_BYTE_TOKENS}), a delimiter opener, a `#`, whitespace, or
     * ILLEGAL — one token each way. Which of the two readers upstream enters
     * is decided by the FIRST byte alone: a digit, or a `.` whose next byte
     * is a digit, goes to `readNumber`; a letter, or any other `.`, goes to
     * `readIdentifier`.
     *
     * That is why this model transcribes the two classes rather than listing
     * the bytes that break a run. A negative break set can never be shown
     * complete — it was nine bytes wide for twelve rounds and over-greedy on
     * 179 of the 255 possible bytes for identifiers and 235 for numbers
     * (measured: `Type ab<B>cd` and `Set Padding 12<B>34` for every byte
     * 1-255, model token stream against upstream's own lexer, comments
     * dropped from upstream's side because this model skips them). A
     * positive class transcribed from `lexer.go` can, and the same
     * differential over the same 510 tapes now reports 0 over-greedy and 0
     * under-greedy.
     *
     * THE GLUE BYTES, and this time with the domain the figure was measured
     * over, because the previous revision of this paragraph is the third time
     * in this chain that a number travelled without one. DOMAIN: the 255 tapes
     * `Set Padding <B>Output x.gif` + newline, one per byte value B in 1-255,
     * each classified by running upstream's own lexer and parser over it:
     *
     *   GLUE — parses with ZERO errors AND still emits the second OUTPUT, i.e.
     *   enough to glue a directive head onto the previous value and lose the
     *   occurrence: 192 bytes.
     *     `\x01`-`\x08` `\x0b` `\x0c` `\x0e`-`\x1f` `!` `$`-`&` `(`-`-`
     *     `0`-`@` `[`-`_` `|`-`\xff`
     *   CLEAN, but the head is hidden or absorbed rather than glued: 6 —
     *   `"` `#` `'` `/` `` ` `` `{`, which is the `#` plus the five delimiters
     *   under WHAT CAN HIDE A `#` above.
     *   UPSTREAM REJECTS the tape: 57 — `\t` `\n` `\r` SPACE `.` `A`-`Z`
     *   `a`-`z`, i.e. whitespace, and the bytes that continue or start the
     *   previous run so that no second head exists to find.
     *
     * 64 of the 192 are below 0x80, and "sixty-four" is what this paragraph
     * said for three rounds — the set restricted to B < 0x80, presented as the
     * whole set. The 128 bytes `\x80`-`\xff` were omitted although each is an
     * ILLEGAL one-byte token, lexically identical to the `}` the paragraph did
     * list: `Set Padding \x80Output x.gif` parses with zero errors and a live
     * second OUTPUT, on all three oracles under THE THREE ORACLES in
     * {@see directiveValues()} — `validate` exit 0 on both binaries. An earlier
     * revision hedged this to the Go alone because the round writing it believed
     * there was no vhs binary on the machine; there were two, and every claim
     * that round hedged has since been re-derived on both.
     *
     * Those 64 are the SEVEN-BIT slice and not the printable one, which is what
     * `48e0690c` called them here and at two other sites. Of the 64, 35 are
     * printable and 29 are control bytes — `\x01`-`\x08` `\x0b` `\x0c`
     * `\x0e`-`\x1f` `\x7f` — so a reader who took the label at its word would
     * look for the defect among the punctuation and never reach `\x1b`.
     * (The "94-printable-ASCII sweep" under WHAT CAN HIDE A `#` above uses the
     * term CORRECTLY and is not the same mistake: 94 is the count of `!`-`~`,
     * whereas the seven-bit glue slice is 64 and its printable count is 35.)
     *
     * `Set Padding }Set Shell "sh"` is the shortest spelling: upstream reads
     * `SET Padding }` then `SET Shell sh` and the render aborts, while a
     * break-set model read one token `}Set` and reported OK.
     * {@see testAGluedDirectiveHeadIsStillAHead()} has TEN ROWS covering SEVEN
     * DISTINCT glue bytes — `}` `0` `-` `%` `_` `[` and `\x80`, one of the seven
     * from the high half — and the tenth row glues nothing at all, being the
     * SPACED control. Rows and bytes are not the same count and the sentence
     * that said "pins ten of them" conflated them: `}` carries three rows and
     * `%` two.
     *
     * WHAT THIS MODEL SCORES over that same domain — the 198 of the 255 that
     * parse with zero errors, comparing this method's `Set Padding` and
     * `Output` occurrences against upstream's command list: 0 MISSING, 0 EXTRA,
     * 130 VALUE DIVERGENCES. The previous revision of this paragraph said all
     * three were zero, in a sentence that opened "no behavioural claim changed
     * here"; the occurrence halves are the two that were true, and the same
     * file already described the third correctly in
     * {@see directiveValues()}'s class 3.
     *
     * The 130 are two of the classes {@see directiveValues()} lists and one
     * property of upstream itself:
     *   * `0x23` (`#`) — class 2, the dropped COMMENT token. Upstream sets
     *     `Padding` to `Output x.gif`; this model to ``.
     *   * `0x40` (`@`) — class 3, {@see skipSpeedSuffix()}. Upstream `@`, this
     *     model ``.
     *   * the 128 bytes `\x80`-`\xff` — upstream's `newToken` builds its
     *     literal as `string(ch)` with `ch` a `byte` (`lexer.go:107`), and Go
     *     converts an integer to the UTF-8 ENCODING of that code point, so the
     *     ILLEGAL token for `\x80` carries the two bytes `\xc2\x80` while this
     *     model carries the one byte the tape holds. Measured over exactly those
     *     128 tapes: 0 token-count mismatches, 0 kind mismatches, 128 token-TEXT
     *     mismatches — one per tape, always the ILLEGAL token — which is why the
     *     class cannot move an occurrence.
     *
     * That last one is upstream's own re-encoding and NOT an artefact of the
     * harness, but a harness can produce the same 128 for its own reason and the
     * two are indistinguishable if it does: PHP's `json_encode` refuses a lone
     * `\x80` outright (`Malformed UTF-8 characters`), so a differential that
     * ships literals as JSON or through a terminal loses the model's side of
     * every high byte. Base64 both sides end to end, or the 128 stop meaning
     * anything.
     *
     * =====================================================================
     *
     * Implementation notes for the model below.
     *
     * It models one token per upstream token, of the KIND upstream assigns —
     * `ident`, `number`, `string`, `json`, `regex`, `single`, and no token at
     * all for a comment. It is coarser than upstream in exactly one place:
     * an `ident` token is not looked up in {@see KEYWORDS} here, so this
     * model does not distinguish a keyword from a bare word at tokenize
     * time. {@see startsDirective()} does that lookup instead, which is the
     * same test one step later.
     *
     * Dropping the COMMENT token is the one deliberate omission, and it is
     * the divergence {@see directiveValues()} records as the `Set` + comment
     * class. Everything else is one-for-one, which is what
     * {@see testTheTokenizerAssignsUpstreamsTokenKinds()} pins.
     *
     * What used to be here instead was a note calling the model "deliberately
     * COARSER than upstream" for lumping NUMBER, keyword, STRING and
     * punctuation into one bare run, and closing with "it never affects which
     * bytes can hide a `#`, because `#` is a break byte". Every word of that
     * was true, and it read as a licence: `#` was indeed safe, so the
     * coarseness looked free. It was not free — it was Finding 1, and the
     * sentence's own framing is why twelve rounds of readers checked the `#`
     * set and never the run boundaries. Coarseness in a lexer model is not
     * automatically the loud direction; it is the loud direction only for the
     * bytes it cannot glue a head onto.
     *
     * A token's KIND is carried alongside its text rather than recovered from
     * it, because the two answer different questions and an unterminated
     * string answers them differently: `Type "echo abc` types `echo abc`, so
     * its text holds no quote, yet it is still a string and so still can never
     * be a directive head. The kind is what {@see startsDirective()} and
     * {@see headMatches()} gate on, and the gate is POSITIVE — only an `ident`
     * token can be a keyword, because `readIdentifier` is the only reader
     * whose result upstream passes to `LookupIdentifier` at all. Everything
     * else is value text: `/Source/ x` answers `Invalid command: Source`,
     * `{#} x` answers `Invalid command: {#}` and `"Source" x` answers
     * `Invalid command: Source`, all three exactly alike.
     *
     * EVERY ARM ADVANCES, which the break-set model could not promise. There
     * the bare-token arm was `strcspn($source, $breaks, $i)`, which returns 0
     * whenever `$source[$i]` is itself a break byte — so disabling any
     * delimiter arm while its opener stayed in the break set left the walk
     * looping on one position forever. Three such infinite loops turned up the
     * last time this file was mutation-swept. Here the fall-through is a
     * one-byte ILLEGAL token and both reader arms consume their entry byte
     * unconditionally, so DELETING AN ARM cannot produce a zero-width token:
     * re-measured, disabling the `{`, quote, comment, whitespace or identifier
     * arm fails tests in seconds instead of hanging.
     *
     * THAT IS THE WHOLE OF THE CLAIM, and a previous revision of this paragraph
     * generalised it to "no arm can produce a zero-width token and the failure
     * mode does not exist". IT CAN, AND IT DOES — the scope of the measurement
     * was arm DELETION, and a single-conjunct mutation is a different domain.
     * Dropping `$close !== false` from the string arm's `$terminated` below
     * leaves `false < $lineEnd`, which PHP 8 evaluates by casting the int to
     * bool: `false < true` is TRUE. So an UNTERMINATED string reports itself
     * terminated, `$end` becomes `false` i.e. 0, `$i` is set to 1 and the walk
     * goes BACKWARDS. Measured on `Type "echo abc` — a shape this suite already
     * contains, from {@see directiveValues()}'s own notes — it never terminates.
     *
     * So: run any mutation sweep of this method under a hard `timeout`, and do
     * NOT read a timeout as a harness bug. That inference is the reason this
     * warning is here rather than left to be rediscovered: a sweeper who trusts
     * the retired sentence concludes the harness hung, kills it and re-runs
     * without the mutation.
     *
     * THE EQUIVALENT-MUTANT REGISTER, so a sweep can tell a known no-op from a
     * finding. IT IS INDEXED BY MUTATION OPERATOR, because the retired version
     * of it listed five entries and then said "anything else that survives is a
     * gap" — an absolute over a register whose entries all came from ONE
     * operator, and two survivors from a second operator were sitting in the
     * file when it was written. A register is only complete for the operator
     * that filled it, and this one now says which.
     *
     * ITS SUBJECT IS THE PARSING MODEL, which is the other bound the absolute was
     * missing: {@see tokenize()}, {@see scanRegex()}, {@see skipSpeedSuffix()},
     * {@see directiveValues()}, {@see startsDirective()}, {@see headMatches()}.
     * The file's SELF-INSPECTION helpers ({@see literalHeadArguments()},
     * {@see callArgument()}, {@see headArgument()}, {@see splitNamedArgument()},
     * {@see modelMethodTokens()}, {@see guardCensus()}) read this file rather
     * than a tape, and the conjunct sweep over the first four is recorded on
     * {@see literalHeadArguments()} — separately, because a survivor there means
     * something different: it costs a measurement of this file, not a missed
     * defect in a tape.
     *
     *   HOW TO RUN THIS OPERATOR AT ALL, first, because the register was
     *   UNEXERCISABLE for a round without anyone noticing.
     *   {@see testTheModelsBoundsAndConjunctsAreCountedNotNarrated()} counts `if`
     *   sites, `for`/`while` conditions and boolean operators in the four model
     *   methods, so with it in the run EVERY conjunct drop and EVERY bound drop
     *   turns this file red
     *   regardless of what the mutation MEANS. A sweep run that way reports
     *   "killed" for all of them and the rule below can never fire again. Sweep
     *   with `--exclude-group syntax-census` — see {@see SWEEP_EXCLUDE_GROUP},
     *   which is the group that test actually carries rather than a name repeated
     *   in prose. The exclusion changes how a KILL reads, not whether a SURVIVOR
     *   survives: a survivor is green either way, since the census moves only when
     *   a conjunct is added or removed. Where a figure below depends on it, that
     *   is said at the figure.
     *
     *   OPERATOR: DROP ONE CONJUNCT of a boolean condition. FIVE survivors, each
     *   for a reason written down beside it: the panic condition's `!$terminated`
     *   and `$length > 0` (both in this method, both argued at the condition
     *   itself), {@see scanRegex()}'s newline branch returning `false` rather
     *   than `true`, and the strict flag on each of the two `in_array` calls
     *   ({@see startsDirective()}, {@see skipSpeedSuffix()}). A conjunct-drop
     *   survivor IN THE PARSING MODEL outside this list IS a gap, and the last
     *   three sweeps each found one after a round had declared the method closed.
     *
     *   The first two were registered as "unkillable" and were NOT, measured on
     *   the whole file: each gives `Failures: 1`, and the one failure is the
     *   syntax census — no behavioural test moves. Under
     *   `--exclude-group syntax-census` each is GREEN with every remaining test
     *   executed, which is the measurement the word "unkillable" was ever about.
     *   (No totals quoted here or below, for the reason
     *   {@see testSetShellIsTheMostQueriedTwoWordHead()} gives: a count of this
     *   file's own tests or assertions cannot survive an edit to this file.) Both
     *   entries stand — they are behavioural no-ops — but "unkillable" now means
     *   "unkillable BY BEHAVIOUR", and a syntactic instrument moving is not a kill
     *   any more than a line count changing is.
     *
     *   OPERATOR: WEAKEN ONE RELATIONAL OR OFF-BY-ONE. THREE survivors, named here
     *   because the sentence below licenses exactly such unswept survivors and
     *   then named none of them. Each measured whole-file, GREEN with every test
     *   executed:
     *     * the string arm's `$close < $lineEnd` widened to `<=`. Equal means the
     *       quote sits ON the newline byte, which cannot happen — `$lineEnd` is
     *       the index of a newline and `$close` the index of a quote.
     *     * the JSON arm's `strpos($source, '}', $i + 1)` searched from `$i`.
     *       `$source[$i]` is the `{` that opened the token, so the two searches
     *       cannot differ.
     *     * {@see directiveValues()}'s `trim($directive)` dropped. Every head this
     *       file passes is already trimmed, and `preg_split('/\s+/')` would only
     *       differ on one that was not.
     *   NOT a survivor, and recorded so the next sweeper does not re-derive it
     *   and does not mistake it for a broken harness: the comment arm's
     *   `strcspn($source, $newlines, $i)` moved to `$i + 1` is a KILL, and an
     *   EXPENSIVE one. A bare `#` immediately before a newline makes the skip
     *   return 0 and the walk spins in place. It is the pure-COMPUTE shape rather
     *   than the allocating one, so `defaultTimeLimit="60"` does bound it and
     *   `failOnRisky="true"` makes each abort red — but it bounds it PER TEST.
     *   MEASURED, whole-file, under an external `timeout 400`: the progress line
     *   reached `.RRRRRR` — six RISKY aborts at 60 s each — and the run had still
     *   not finished when the timeout cut it, exit 124. Six is a floor, not a
     *   count: the run never reached its summary. So budget minutes for this one
     *   mutation, and read a long silence here as the guard working rather than as
     *   the hang {@see tokenize()}'s `for` header produces.
     *
     *   OPERATOR: COLLAPSE A TERNARY to one arm. TWO survivors, both the SAME
     *   line in two arms of this method — `$i = $terminated ? $end + 1 : $end`
     *   rewritten to `$i = $end + 1`, once in the regex arm and once in the
     *   string arm. Measured: whole file GREEN with every test executed, on each
     *   (no total quoted, for the reason
     *   {@see testSetShellIsTheMostQueriedTwoWordHead()} gives). They are genuine
     *   no-ops for the reason {@see scanRegex()}'s own
     *   docblock already gives about the flag: on the `false` side `$end` is the
     *   index of a `\r`, of a `\n`, or `$length` itself, so consuming one more
     *   byte either skips whitespace the very next iteration would have skipped
     *   or steps past the end of a loop already finished. NOT reachable by the
     *   conjunct-drop operator above — a ternary arm is not a conjunct — which
     *   is exactly why the register's old absolute did not cover them and why the
     *   operator is named here instead of assumed.
     *
     * SO: a survivor from ANY OTHER operator is neither registered nor a gap
     * until someone sweeps that operator and writes down what it found — and the
     * three operators above are the three that HAVE been swept, each with its
     * survivors named rather than counted. The `--exclude-group` above matters
     * only for reading a KILL: a survivor is green with or without the census,
     * since that test moves only when a conjunct is added or REMOVED.
     *
     * $regexPanic is set when the walk finds the one input shape that crashes
     * upstream's lexer outright rather than mis-lexing it — see
     * {@see scanRegex()}. It rides along on this walk instead of getting a
     * second one of its own, because it depends on which `/` is a token start
     * and a duplicate walk is a duplicate to drift out of step with.
     *
     * @param bool $regexPanic out-param; true when this tape panics upstream
     *
     * @return list<array{text: string, kind: string}>
     */
    private static function tokenize(string $source, bool &$regexPanic = false): array
    {
        $regexPanic = false;
        $newlines = "\r\n";

        $tokens = [];
        $length = \strlen($source);

        for ($i = 0; $i < $length;) {
            $char = $source[$i];

            if (str_contains(" \t" . $newlines, $char)) {
                ++$i;
                continue;
            }

            if ($char === '#') {
                // Comments end at the newline, not at the end of the file.
                // Upstream emits a COMMENT token here; this model emits none,
                // which is the `Set` + comment divergence recorded in
                // {@see directiveValues()}.
                $i += strcspn($source, $newlines, $i);
                continue;
            }

            // A JSON token closes on `}` — a DIFFERENT byte from the one that
            // opened it, the only asymmetric pair in the grammar — and no
            // newline bounds it, so an unterminated `{` runs to EOF. Upstream
            // keeps both braces in the literal and appends the closer even
            // when the input never supplied one, so the text does too: the
            // value of `Set WaitPattern {#}` is the three bytes `{#}`.
            if ($char === '{') {
                $close = strpos($source, '}', $i + 1);
                $end = $close === false ? $length : $close;
                $tokens[] = [
                    'text' => '{' . substr($source, $i + 1, $end - $i - 1) . '}',
                    'kind' => 'json',
                ];
                $i = $close === false ? $length : $close + 1;
                continue;
            }

            // Reached only at a token boundary — after whitespace, a comment, a
            // closing delimiter, a single-byte token or a bare run — which is
            // exactly the position in which `/` opens a regex.
            if ($char === '/') {
                [$end, $terminated] = self::scanRegex($source, $i, $length);

                // The scan ran to EOF with a backslash run still open — the one
                // shape that panics upstream instead of mis-lexing. Recorded
                // rather than modelled, because there is no token to model: the
                // binary dies.
                //
                // `!$terminated` is REDUNDANT and deliberate. {@see scanRegex()}
                // returns `[$j, true]` only from a branch where `$j < $length`,
                // so `$end === $length` already implies it, and a mutation
                // sweep duly finds the conjunct unkillable. It stays because
                // this condition is the whole of the panic rule and reads as
                // "an UNTERMINATED regex that ran off the end": deleting it
                // would make the rule depend on a postcondition of a different
                // method, which is the kind of coupling this file has been bitten
                // by.
                //
                // The retired version of this comment then said of the other
                // three that "each of those is load-bearing and killable". One
                // is neither and one was not killed. Re-swept, one mutation per
                // conjunct, judged on this file alone:
                //   `$length > 0`   UNKILLABLE, and unreachable-dead: this arm
                //                   runs only from inside `$i < $length`, so
                //                   $length >= 1 here by construction. Kept for
                //                   the same reason as `!$terminated` — the next
                //                   conjunct indexes `$length - 1`, and a reader
                //                   should not have to walk out to the loop
                //                   header to see that it is in range.
                //   `$end === $length`  KILLABLE, and it was not killed until
                //                   `regex closed by a newline, backslash at
                //                   EOF` went into
                //                   {@see testTheRegexPanicDetectorMatchesTheMeasuredShapes()}.
                //                   Without it a regex closed by a NEWLINE plus
                //                   a backslash anywhere at the end of the file
                //                   reads as a panic: measured, `Set WaitPattern
                //                   /a` + newline + `Type b\` does not panic
                //                   upstream, and the mutant says it does.
                //   `$source[$length - 1] === '\\'`  KILLABLE, and killed by the
                //                   `no backslash` row that was already there.
                if (!$terminated && $end === $length && $length > 0 && $source[$length - 1] === '\\') {
                    $regexPanic = true;
                }

                $tokens[] = ['text' => substr($source, $i + 1, $end - $i - 1), 'kind' => 'regex'];
                $i = $terminated ? $end + 1 : $end;
                continue;
            }

            if (str_contains("\"'`", $char)) {
                $lineEnd = $i + 1 + strcspn($source, $newlines, $i + 1);
                $close = strpos($source, $char, $i + 1);
                // vhs closes an unterminated string at the newline and carries
                // on rendering: a tape whose only content is `Type "echo abc`
                // exits 0 and types `echo abc`. The newline wins whenever the
                // closing quote is on a later line, or absent.
                $terminated = $close !== false && $close < $lineEnd;
                $end = $terminated ? $close : $lineEnd;

                $tokens[] = ['text' => substr($source, $i + 1, $end - $i - 1), 'kind' => 'string'];
                $i = $terminated ? $end + 1 : $end;
                continue;
            }

            // Tried BEFORE the two readers, exactly as upstream's switch does,
            // which is why `-` and `%` are tokens of their own at a token start
            // even though both are identifier bytes mid-run: `Set LoopOffset
            // 50%` is NUMBER `50` then `%`, and `Set Padding -` is one `-`.
            if (str_contains(self::SINGLE_BYTE_TOKENS, $char)) {
                $tokens[] = ['text' => $char, 'kind' => 'single'];
                ++$i;
                continue;
            }

            // Which reader upstream enters is decided by the FIRST byte alone,
            // and `.` is the byte that decides it two ways: `.5` is a number,
            // `.vhs/cli.gif` an identifier.
            $peek = $i + 1 < $length ? $source[$i + 1] : '';
            $digit = str_contains(self::DIGIT_BYTES, $char);

            if ($digit || ($char === '.' && $peek !== '' && str_contains(self::DIGIT_BYTES, $peek))) {
                // The entry byte is consumed unconditionally because it is in
                // the run class by construction — NUMBER_BYTES is DIGIT_BYTES
                // plus `.`, and the entry test is a digit or a `.`. Upstream
                // relies on the same containment: an entry byte outside the run
                // class would leave `readNumber` returning an empty literal and
                // `NextToken` looping forever, in the Go as much as here.
                $end = $i + 1 + strspn($source, self::NUMBER_BYTES, $i + 1);
                $tokens[] = ['text' => substr($source, $i, $end - $i), 'kind' => 'number'];
                $i = $end;
                continue;
            }

            if (str_contains(self::LETTER_BYTES, $char) || $char === '.') {
                // Same containment argument: IDENT_BYTES is LETTER_BYTES plus
                // DIGIT_BYTES plus `.-_/%`, and the entry test is a letter or a
                // `.`, so the first byte always belongs to the run.
                $end = $i + 1 + strspn($source, self::IDENT_BYTES, $i + 1);
                $tokens[] = ['text' => substr($source, $i, $end - $i), 'kind' => 'ident'];
                $i = $end;
                continue;
            }

            // ILLEGAL: every byte upstream's switch and both readers reject,
            // one byte and one token each. `}` outside a JSON token is the one
            // that matters — it is what `Set Padding }Set Shell "sh"` turns on.
            $tokens[] = ['text' => $char, 'kind' => 'illegal'];
            ++$i;
        }

        return $tokens;
    }

    /**
     * Upstream's `readRegex`, ported: where does the regex opened at $open end,
     * and did a `/` end it?
     *
     * Kept as its own method because it is the only part of the grammar with an
     * escape rule, and because two of its three exits are consequences of
     * upstream's control flow rather than of its stated intent — see ESCAPES in
     * {@see tokenize()}. The odd/even backslash count is upstream's own
     * (`/foo\/bar/` escapes, `/foo\\/` does not), and the `continue` that
     * consumes the byte after a backslash run untested is what lets `\` +
     * newline carry the regex onto the next line.
     *
     * ONE EQUIVALENT MUTANT lives here and is recorded so the next sweep does not
     * report it as a survivor: making the newline branch return `[$j, true]`
     * instead of `[$j, false]` changes nothing observable. The flag has exactly
     * two readers in {@see tokenize()}, and a newline close satisfies neither.
     * `$i = $terminated ? $end + 1 : $end` differs only in whether the NEWLINE
     * itself is consumed, and the newline is whitespace, so the very next
     * iteration skips it either way. And the panic condition's `!$terminated`
     * cannot be reached by a newline close at all: this branch returns from inside
     * `$j < $length`, so `$end < $length` and the `$end === $length` conjunct is
     * already false. The flag stays honest — a newline is NOT a delimiter, and
     * that is what the name says — for the same reason `!$terminated` stays in the
     * panic condition it cannot influence.
     *
     * @return array{int, bool} the index the regex text ends at, and whether a
     *                          delimiter (rather than a newline or EOF) closed it
     */
    private static function scanRegex(string $source, int $open, int $length): array
    {
        for ($j = $open + 1; $j < $length;) {
            $char = $source[$j];

            if ($char === "\n" || $char === "\r") {
                return [$j, false];
            }

            if ($char === '\\') {
                $run = 0;
                while ($j < $length && $source[$j] === '\\') {
                    ++$run;
                    ++$j;
                }

                // An EVEN run leaves the delimiter unescaped, so it closes the
                // regex; an ODD run escapes it and scanning continues past it.
                if ($j < $length && $source[$j] === '/') {
                    if ($run % 2 === 0) {
                        return [$j, true];
                    }

                    ++$j;
                    continue;
                }

                // Any other byte — INCLUDING a newline, and including none at
                // all because the run hit EOF — is consumed without being
                // tested. That untested newline is F3; the untested EOF is
                // where upstream reads past the end and panics, which this
                // model reports as an ordinary EOF close and
                // {@see testNoTapeEndsInUpstreamsRegexPanic()} forbids outright.
                ++$j;
                continue;
            }

            if ($char === '/') {
                return [$j, true];
            }

            ++$j;
        }

        return [$length, false];
    }

    /**
     * Whether this tape would drive upstream's `readRegex` off the end of the
     * input, which crashes the binary rather than failing one directive.
     *
     * The condition is exact: a regex is open and its content ends in a run of
     * one or more backslashes that is the last thing in the file, so upstream
     * takes one `readChar()` too many and slices past `len(input)`. Measured
     * both ways — the Go built from `lexer.go` and the `vhs` binary itself
     * agree on `/a\`, `/a\\` and `/a\\\` (panic, exit 2) and on `/a`, `/a\ `,
     * `/a\` + newline and `/a/` (no panic).
     *
     * Only the token walk can tell, because it depends on which `/` is a token
     * start; the last byte of the file is not enough to decide it.
     *
     * THE WALK RUNS UNDER {@see withPhpDiagnosticsPromoted()} because this is the
     * only route by which any test reaches {@see scanRegex()} at EOF, and all
     * three of that method's bounds guards are answer-invisible: unrouted, each
     * of the two inside the backslash logic measured five PHP warnings and ZERO
     * failures, and the loop-header one hung. The promotion is what makes them
     * die in the ERRORS block instead. The read itself stays outside the
     * promotion — a failed `file_get_contents` is already asserted on, and
     * turning an I/O warning into this method's exception would misattribute it.
     *
     * THAT LAST SENTENCE WAS A PRINCIPLE STATED AS THOUGH IT WERE ENFORCED, and it
     * held only here. {@see valuesWithNoPhpDiagnostic()} wraps
     * {@see directiveValues()}, whose own `file_get_contents` was INSIDE the
     * promotion — so on the busier of the two routes an unreadable tape came back
     * as `ErrorException: file_get_contents(): Failed to open stream` while this
     * one returned `ExpectationFailedException: could not read <path>`, measured
     * side by side. That route now takes the same pre-read this one does, and
     * {@see testAMissingTapeIsReportedByTheReadNotByThePromotion()} asserts the
     * two agree, so the principle is enforced rather than asserted in prose.
     */
    private static function panicsUpstreamsLexer(string $tape): bool
    {
        $source = file_get_contents($tape);
        self::assertIsString($source, "could not read {$tape}");

        $panics = false;
        self::withPhpDiagnosticsPromoted(static function () use ($source, &$panics): void {
            self::tokenize($source, $panics);
        });

        return $panics;
    }

    /**
     * True for `Type`, and for the `Type@100ms` speed-suffixed spelling alike.
     *
     * The suffix needs nothing done to the text any more. `@` and `+` are two
     * of the nine single-byte tokens and neither is in {@see IDENT_BYTES}, so
     * upstream already ends the identifier at them and this model now does the
     * same: `Type@100ms` is `Type` `@` `100` `ms`, `Ctrl+O` is `Ctrl` `+` `O`.
     * What used to be a suffix strip on the token text is now
     * {@see skipSpeedSuffix()} on the token walk, which is where upstream keeps
     * it too (`parseSpeed`, called from `parseType`, `parseKeypress` and
     * `parseWait`).
     *
     * @param array{text: string, kind: string} $token
     */
    private static function headMatches(array $token, string $word): bool
    {
        return $token['kind'] === 'ident' && $token['text'] === $word;
    }

    /**
     * Whether this token opens a new directive, and so ends the previous one's
     * value.
     *
     * Gated POSITIVELY on `ident`, because `readIdentifier` is the only reader
     * whose result upstream hands to `LookupIdentifier`. A `number`, a
     * `single`, an `illegal`, a `string`, a `json` or a `regex` token can never
     * be a keyword however its text is spelled — `/Source/` and `"Source"` both
     * answer `Invalid command: Source` — so this is upstream's own rule rather
     * than a list of exceptions to keep up to date.
     *
     * The `true` on the `in_array` below is an EQUIVALENT MUTANT and is recorded
     * as one so the next sweep does not report it: `$token['text']` is always a
     * string and no entry of {@see KEYWORDS} is a numeric string, so PHP 8
     * compares the two as bytes either way. Same for {@see skipSpeedSuffix()}'s
     * `['ms', 's', 'm']`. Both flags stay because the file's convention is
     * strict comparison everywhere, not because a test can tell. Both are in the
     * EQUIVALENT-MUTANT REGISTER in {@see tokenize()}, which is the list a sweep
     * should check a survivor against before calling it a finding.
     *
     * @param array{text: string, kind: string} $token
     */
    private static function startsDirective(array $token): bool
    {
        return $token['kind'] === 'ident' && \in_array($token['text'], self::KEYWORDS, true);
    }

    /**
     * Step over an `@<time>` speed suffix sitting between a head and its value.
     *
     * Upstream's `parseSpeed` runs BEFORE the value is read — `if p.peek.Type
     * == token.AT { p.nextToken(); return p.parseTime() }` — and `parseTime`
     * takes a NUMBER and then an OPTIONAL bare unit, `ms`, `s` or `m`. So
     * `Type@100ms "hello"` types `hello`: the `@ 100 ms` belongs to the head.
     * Without this the value would start at the `@`, run to the unit keyword
     * and come out as `@ 100` — the same lost-value shape a break set produced,
     * one construct along.
     *
     * Modelled for every head rather than only the three upstream calls it
     * from (`parseType`, `parseKeypress`, `parseWait`), and WHAT THAT COSTS is
     * measured rather than argued. The previous revision said a tape suffixing
     * any other head "is one upstream rejects outright — `Output@1s x.gif`
     * answers `Invalid command: @` — so the extra tolerance can only ever
     * over-approximate on a tape that already fails CI". The example is real
     * (five errors, `Output` among them) and unrepresentative: it is true of
     * every COMMAND head and false of every `Set <setting>` head.
     *
     * `parseSet`'s `default:` arm is `cmd.Args = p.peek.Literal` with no type
     * gate (`parser.go:527-528`), so an `@` after a setting name is taken as
     * the value. MEASURED, on upstream's own parser and on the binary:
     *
     *   `Set Padding @Output x.gif`  ZERO errors, `vhs validate` exit 0.
     *                                upstream: SET Padding `@`, OUTPUT `x.gif`
     *                                model:    SET Padding ``,  OUTPUT `x.gif`
     *   `Set Theme @Set Theme "TokyoNight"`
     *                                ZERO errors. upstream `['@','TokyoNight']`,
     *                                model `['','TokyoNight']`
     *
     * So on a `Set` head this method UNDER-approximates the value on a tape
     * upstream ACCEPTS, which is the opposite of what the retired sentence
     * claimed. It is still not the direction that can ship a false green: the
     * tokens skipped here can never be a directive head — `@` is a single-byte
     * token and the two after it are a NUMBER and one of three unit keywords —
     * so no occurrence is lost, and an empty value fails loudly wherever any
     * assertion reads one. {@see testTheSetPlusAtDivergenceLosesTheValueNotTheHead()}
     * pins both halves: the empty value AND the live `Output` behind it.
     *
     * The divergence is recorded in {@see directiveValues()} beside the other
     * three rather than fixed here, because narrowing this method to upstream's
     * three heads would trade a bounded value divergence for a new occurrence
     * risk on every keypress head (`Ctrl`, `Down`, `Wait+Screen`, …) that
     * `parseKeypress` reaches, and nothing in this suite queries a `Set` value
     * that could be an `@`. If it is ever narrowed, that test's expectation
     * becomes `['@']` and the change is deliberate.
     *
     * WHAT PINS THE CLAUSES BELOW, because this method has now supplied a
     * survivor to four consecutive mutation sweeps and the reason is always the
     * same: its guards are invisible in the answer, or they only bite on a token
     * kind or text no row happened to use.
     *
     * THREE COUNTS, EACH WITH ITS DOMAIN, because the retired heading was "WHAT
     * PINS THE FOUR CONJUNCTS BELOW" over a list whose four bullets enumerated
     * ELEVEN separately-killed clauses across the method's EIGHT syntactic
     * conjuncts — one number standing in for three, none of them labelled, in a
     * docblock whose whole subject is numbers without domains.
     *
     *   EIGHT SYNTACTIC CONJUNCTS. Domain: this method's own source, counted as
     *       `if (` sites plus `&&`/`||` operators (3 + 5). ASSERTED, not
     *       narrated, by
     *       {@see testTheModelsBoundsAndConjunctsAreCountedNotNarrated()}, whose
     *       own domain is WIDER — it also counts `for`/`while` conditions and
     *       `??`/`??=`. This method has none of those, which is the only reason
     *       the two domains agree at eight; the wider domain moved three of the
     *       four figures in that census and left this one alone.
     *   ELEVEN CLAUSES. Domain: the bullet text below, where a clause is a unit
     *       of PROSE and not of syntax — the `in_array` list is one clause as a
     *       whole and one more per entry, which is four clauses over one
     *       conjunct. Deliberately NOT asserted: it counts sentences, and a
     *       count of sentences is what a comment may not hold.
     *   FIVE BULLETS. Domain: the list immediately below. It is five and not
     *       four because `kind === 'number'` is pinned somewhere else entirely,
     *       which is what the retired heading's fourth bullet got wrong.
     *
     *   `$i >= $count`, and both `$i < $count`
     *       {@see testTheSpeedSuffixWalkStopsAtTheEndOfTheStream()}. Bounds, so
     *       no answer changes — the kill is a PHP diagnostic promoted to a thrown
     *       error by {@see valuesWithNoPhpDiagnostic()}.
     *   `kind !== 'single'`
     *       the `Type "@" "abc"` row of
     *       {@see testASpeedSuffixBelongsToTheHeadNotTheValue()}.
     *   `text !== '@'`
     *       the `Set Padding -` row of the same test, which is the TEXT twin of
     *       the row above it and stayed unpinned for a round after it went in.
     *   `kind === 'ident'`, each of `ms`/`s`/`m`, and the `in_array` AS A WHOLE
     *       FIVE rows of the same test, one per clause; each verified to fail
     *       that test ALONE, under `--filter`, one mutation at a time. The last
     *       of the five is `Type@100 abc`: with the list gone but the kind gate
     *       kept, any bare word after the number is eaten. Why the other eight
     *       rows do NOT notice that is a three-way split, not the one-liner it
     *       used to be — the breakdown is in that test's own docblock.
     *   `kind === 'number'`
     *       NOT that test, and the provenance is CORRECTED here rather than
     *       backdated by adding a row. Dropping this conjunct alone leaves
     *       {@see testASpeedSuffixBelongsToTheHeadNotTheValue()} GREEN under
     *       `--filter`, with all nine of its rows executed, because in every one
     *       of them the token after an `@` that the walk actually reaches is a
     *       NUMBER — or the walk never starts. Run over the whole file the same
     *       mutant gives TWO killers, `Failures: 2`:
     *       {@see testTheSetPlusAtDivergenceLosesTheValueNotTheHead()},
     *       whose `Set Padding @Output x.gif` is the only tape in this file with
     *       a non-NUMBER token after an `@`, and
     *       {@see testTheModelsBoundsAndConjunctsAreCountedNotNarrated()}, which
     *       counts conjuncts and so moves for EVERY conjunct drop whatever it
     *       means. The second one is not evidence about this guard, which is why
     *       a sweep runs `--exclude-group syntax-census`: under that exclusion the
     *       figure is `Failures: 1` and the one killer is the divergence test.
     *       "Exactly ONE killer" was the whole-file
     *       figure taken BEFORE the census test existed and shipped in the commit
     *       that added it — the same defect, one docblock along, as the tally this
     *       file replaced with an assertion.
     *       The pin itself is PRE-EXISTING, not one of that round's: replayed
     *       against `2bd2263f` (green there at 108 tests / 492 assertions) the
     *       same mutant fails the same single test. The retired bullet claimed
     *       "six rows of the same test, one per conjunct" — five rows pin five
     *       clauses, and the sixth clause was pinned a commit earlier by a
     *       different test.
     *
     * @param list<array{text: string, kind: string}> $tokens
     */
    private static function skipSpeedSuffix(array $tokens, int $i, int $count): int
    {
        if ($i >= $count || $tokens[$i]['kind'] !== 'single' || $tokens[$i]['text'] !== '@') {
            return $i;
        }

        ++$i;

        if ($i < $count && $tokens[$i]['kind'] === 'number') {
            ++$i;

            if ($i < $count && $tokens[$i]['kind'] === 'ident'
                && \in_array($tokens[$i]['text'], ['ms', 's', 'm'], true)) {
                ++$i;
            }
        }

        return $i;
    }
}
