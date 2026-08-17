<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The contract between `.vhs/*.tape` and the renderer that actually runs them.
 *
 * `.github/workflows/vhs.yml`'s `render` job carries sugar-crush in its matrix,
 * runs `working-directory: ${{ matrix.lib }}`, drives the upstream
 * charmbracelet/vhs binary, and uploads `${{ matrix.lib }}/.vhs/*.gif`. Three
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
 * is exactly what shipped for twelve rounds. This docblock said "sixty-four"
 * for three of those rounds, which is that same set restricted to B < 0x80: a
 * printable-ASCII figure presented as the whole one. See the byte-class section
 * in {@see tokenize()} and {@see testAGluedDirectiveHeadIsStillAHead()}.
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
     */
    private const VALID_SHELLS = [
        'bash', 'zsh', 'fish', 'powershell', 'pwsh', 'cmd', 'nu', 'osh', 'xonsh',
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
     * directive live in here — 192 over the whole byte domain, see
     * {@see tokenize()}; this line said "sixty-four" while quoting the
     * printable-ASCII slice of that set.
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
     * Both rows measured on both oracles — upstream's own `lexer.go`/`parser.go`
     * run directly, and `/tmp/vhsbin/vhs validate`, exit 0 on both:
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
     * Every row measured on both oracles — upstream's own lexer and parser run
     * directly, and `/tmp/vhsbin/vhs`, whose exit status is quoted per row. The
     * three CRLF rows are the control: a CRLF tape must keep working, since
     * anything committed from Windows is one.
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
            self::directiveValues($escaped, 'Set WaitPattern'),
            'and the one place a CR does NOT bound a regex is the same place a LF does not: '
            . 'the byte after a backslash run is consumed untested, so upstream keeps the CR '
            . 'INSIDE the pattern (measured literal `a\\` + CR) and does not panic',
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
     * ALL THREE UNITS are rowed, not just the two anyone writes. `parseTime`
     * accepts MILLISECONDS, SECONDS or MINUTES (`parser.go:278`) and
     * `token.Keywords` maps `ms`, `s` and `m` respectively (`token.go:114-116`),
     * so `m` is a real unit and `Type@1m "abc"` is Options `1m` / Args `abc`
     * with zero errors. Dropping `m` from {@see skipSpeedSuffix()}'s unit list
     * left the suite green: the `m` then began the value and the answer became
     * `m abc`. Two of three units covered is the same shape as a figure quoted
     * without its domain.
     *
     * The QUOTED `@` row is F3's defect one construct along, and it is the
     * reason {@see skipSpeedSuffix()} tests the token KIND and not only its
     * text. `Type "@" "abc"` lexes STRING `@` then STRING `abc`, so upstream
     * types `@ abc` — the `@` is content. A model that matched on text alone
     * skipped it as a speed suffix and answered `abc`, silently dropping a
     * character the tape types, and nothing pinned the `kind === 'single'`
     * conjunct that prevents it.
     *
     * Every row measured on both oracles: upstream's own `lexer.go`/`parser.go`
     * run directly, and `/tmp/vhsbin/vhs validate`, exit 0 on all five.
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
            . 'only `ms` and `s` lets the `m` start the value and answers `m abc`',
        );

        $quotedAt = $this->scratchTape("Type \"@\" \"abc\"\n");

        self::assertSame(
            ['@ abc'],
            self::directiveValues($quotedAt, 'Type'),
            'a QUOTED `@` is a STRING token, so it is content and not a speed suffix — upstream '
            . 'types `@ abc`. This is the F3 defect one construct along: matching the suffix on '
            . 'the token text alone rather than on its KIND drops a character the tape types',
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
     * Measured on both oracles — upstream's own `lexer.go`/`parser.go` run
     * directly, and `/tmp/vhsbin/vhs validate`:
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
     * measured on both oracles: upstream's own `lexer.go`/`parser.go` run
     * directly (zero errors, no panic on each), and `/tmp/vhsbin/vhs validate`,
     * exit 0 on each. A tape upstream rejects could not be a false green, so
     * all three had to clear that bar before their expectations meant anything.
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
     * byte, and for three rounds so was the whole recorded glue set — 128 of the
     * 192 glue bytes are `\x80`-`\xff`, each an ILLEGAL one-byte token
     * lexically identical to the `}` two rows up. Measured the same way as the
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
     * @param list<array{string, string}> $expected [kind, text] per token
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tokenKindProvider')]
    public function testTheTokenizerAssignsUpstreamsTokenKinds(string $source, array $expected): void
    {
        $tokens = self::tokenize($source);

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
     * Every row measured twice: upstream's `lexer.go` run directly (recovered
     * panic) and `/tmp/vhsbin/vhs validate`, whose exit status and byte offsets
     * are quoted per row.
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
        ];

        foreach ($safe as $why => $source) {
            self::assertFalse(
                self::panicsUpstreamsLexer($this->scratchTape($source)),
                "{$why}: measured as NOT a panic — reporting one would fail a tape vhs renders",
            );
        }
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
     * than past it.
     *
     * The previous revision listed two of these and called the second "the ONLY
     * divergence class the corpus differential below still reports". The
     * differential reports FIVE, and the sentence was written while three of
     * them were already in it — which is the same failure mode as the glue-byte
     * figure in {@see tokenize()}: a completeness claim whose domain was never
     * stated. The list is therefore kept as a list of MEASUREMENTS:
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
     *      DOMAIN, and this is the one that ships: no assertion in this file
     *      queries a unit-bearing directive — the call sites are `Output`,
     *      `Type`, `Source`, `Set Shell/Theme/Height/Width/FontSize` and, from
     *      the two providers, `Set WaitPattern` — so the class is inert TODAY.
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
     *
     * All five stay in the loud direction — an empty or over-long value fails
     * every assertion in this file rather than hiding one — but they are
     * divergences and pretending otherwise is how this paragraph got falsified
     * three times.
     *
     * And one divergence is in neither direction, which is why it is modelled
     * explicitly instead of being bounded by argument: `{` … `}` both over-
     * and under-approximates, because it can hide a `#`, and hiding a `#`
     * hides every directive behind it. See {@see tokenize()}.
     *
     * HOW TO RE-CHECK ALL OF THIS, since a comment nobody measured is itself a
     * defect and this file has shipped three of those. The strongest available
     * oracle is not `vhs validate` (which exits 0 on every false green this
     * file has ever had) and not a render, but upstream's own lexer and parser
     * run directly:
     *
     *   1. `go env GOMODCACHE`/github.com/charmbracelet/vhs@v0.11.0 holds the
     *      real source. Copy `lexer/lexer.go`, `token/token.go` and
     *      `parser/parser.go` into a scratch module, rewriting ONLY the three
     *      `github.com/charmbracelet/vhs/...` import lines (`diff` against the
     *      originals to prove nothing else moved).
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
     * said for three rounds — the printable-ASCII slice of the set, presented
     * as the set. The 128 bytes `\x80`-`\xff` were omitted although each is an
     * ILLEGAL one-byte token, lexically identical to the `}` the paragraph did
     * list: `Set Padding \x80Output x.gif` is `vhs validate` exit 0 with a live
     * second OUTPUT, confirmed against the binary as well as the Go.
     *
     * `Set Padding }Set Shell "sh"` is the shortest spelling: upstream reads
     * `SET Padding }` then `SET Shell sh` and the render aborts, while a
     * break-set model read one token `}Set` and reported OK.
     * {@see testAGluedDirectiveHeadIsStillAHead()} pins ten of them end to end,
     * one from the high half. No behavioural claim changed here: over those
     * same 255 tapes this model reports the same occurrences as upstream on all
     * 198 that parse clean — 0 missing, 0 extra, 0 value divergences.
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
     * unconditionally, so no arm can produce a zero-width token and the failure
     * mode does not exist. Re-measured: disabling the `{`, quote, comment,
     * whitespace or identifier arm now fails tests in seconds instead of
     * hanging.
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
                // by. Do not weaken the other three conjuncts to match — each of
                // those is load-bearing and killable.
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
     */
    private static function panicsUpstreamsLexer(string $tape): bool
    {
        $source = file_get_contents($tape);
        self::assertIsString($source, "could not read {$tape}");

        $panics = false;
        self::tokenize($source, $panics);

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
