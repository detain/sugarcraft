<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;

/**
 * Every call that consumes a file descriptor carries a judgement about how
 * its descriptor was spelled.
 *
 * ## Why this census exists, and why it is the SECOND one
 *
 * The family it guards is the "resource id used as a file descriptor" defect:
 * an `(int)` cast of a PHP stream yields the stream's resource id, which is a
 * different number from its descriptor. MEASURED, PHP 8.3.6, three takes,
 * identical every time: `(int) STDIN` is 1, `(int) STDOUT` is 2, `(int) STDERR`
 * is 3 — over descriptors 0, 1 and 2. In an ordinary terminal all three name
 * one device, so the wrong number returns the right answer, which is what let
 * six sites live in this tree unnoticed.
 *
 * The FIRST census reported three of those six, and how it missed the rest is
 * worth more than the corrected number. It walked `T_INT_CAST` tokens — which
 * does see all of them — and then kept only the hits "whose operand is a
 * stream". That second step was written against the operand shapes already in
 * hand, `$this->stream` and `$stream`. An operand that was an ARRAY ELEMENT
 * (`$tty[0]`) or a BARE CONSTANT (`STDIN`, `STDOUT`) could not be said in its
 * vocabulary, so it was dropped in silence rather than surfaced as
 * unclassified. The classifier's alphabet was a transcript of the cases it
 * already knew, and it reported exactly those.
 *
 * {@see DescriptorSinkScanner} inverts the search — it enumerates the SINKS
 * and classifies whatever the first argument turns out to be — and, crucially,
 * reports anything it cannot name as `UNCLASSIFIED`, which fails this test.
 * A guard that quietly ignores what it cannot parse has a hole shaped exactly
 * like the next defect.
 *
 * ## Two further blind spots the first walk had, both now covered
 *
 *  - `intval()` produces the same wrong number and is not a `T_INT_CAST`
 *    token, so a cast-token walk cannot see it at all.
 *  - Three of the six sites were spelled as a plain `$fd` whose assignment one
 *    or two lines above held the cast. The scanner traces that back and calls
 *    it `INT_CAST_VIA_VARIABLE`; a cast with a line break in it is not a
 *    different defect.
 *
 * ## What this test asserts
 *
 * Set equality between the sites found in the tree and {@see self::ROSTER}.
 * Not a count — a count taken in one worktree is wrong the moment a sibling
 * lands. A NEW site fails because it is unjudged; a REMOVED site fails because
 * its row is stale. Both failures name the resolution in their message.
 *
 * ## And the instrument is proved alive in the same run
 *
 * {@see testTheScannerReportsEveryShapeIncludingTheOnesItCannotName()} pushes
 * a fixture through the SAME scanner and requires each classification to come
 * back, `UNCLASSIFIED` included. Without it, a scanner mutated to match
 * nothing would report an empty tree, the roster comparison would be the only
 * thing standing, and an empty roster would agree with it. That is not a
 * hypothetical: a census in this repo has already been observed passing,
 * entirely green, with its scanner mutated dead.
 *
 * There are TWO entry points to keep alive, not one.
 * {@see DescriptorSinkScanner::scanSource()} classifies a string;
 * {@see DescriptorSinkScanner::scanTree()} walks a directory and calls it.
 * Every absence in this file is computed through the second, so a control
 * that exercises only the first leaves the walk unwatched -- which is exactly
 * how a `scanTree()` gutted to see no files was measured passing this file's
 * absence test on its own. {@see self::assertTheInstrumentBehindTheAbsenceIsAlive()}
 * covers both, and is called from the absence test itself so that a
 * `--filter` on one method name cannot strand it.
 */
final class DescriptorSinkArgumentCensusTest extends TestCase
{
    /**
     * The judged roster: `<lib>/<path>::<sink>(<argument>)` => [kind, judgement].
     *
     * Keyed on the spelling rather than on a line number, because line numbers
     * rot within a round and the spelling is what this census is about. The KEY
     * already pins the argument text, so a site respelled from `open(0)` to
     * `open((int) STDIN)` fails on set equality alone.
     *
     * The KIND is held separately because one spelling can carry two shapes:
     * `query($fd)` is `INT_CAST_VIA_VARIABLE` while the cast sits in the
     * assignment above it, and plain `VARIABLE` once someone fixes that
     * assignment. Set equality cannot see that move; the kind can, and it
     * forces the judgement to be rewritten instead of left describing a defect
     * that is gone.
     */
    private const ROSTER = [
        // ---- candy-core -------------------------------------------------
        'candy-core/src/Util/Tty/PosixBackend.php::SizeIoctl::query($fd)' => [
            DescriptorSinkScanner::INT_CAST_VIA_VARIABLE,
            'OPEN, KNOWN, LATENT. size()\'s first arm; two lines up it does '
            . '`$fd = (int) $this->stream`. Usually-right by accident: $this->stream defaults '
            . 'to STDIN, whose resource id is 1 and whose descriptor is 0, and both name the '
            . 'same device in an ordinary terminal. Closing it needs the descriptor carried '
            . 'alongside the stream, which the constructor does not do -- a wider change than '
            . 'the /dev/tty arm needed, and deliberately not bundled with it.',
        ],
        'candy-core/src/Util/Tty/PosixBackend.php::SizeIoctl::query($ttyFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. size()\'s /dev/tty arm. $ttyFd comes from openDeviceDescriptor(), which '
            . 'asks libc to open the device and hands back a genuine descriptor. This arm used '
            . 'to read `(int) $tty[0]` on a freshly fopen\'d handle, and was wrong on every run '
            . 'rather than latent -- see PosixBackendTerminalDescriptorTest.',
        ],
        'candy-core/src/Util/Tty/PosixBackend.php::TermiosFactory::open($fd)' => [
            DescriptorSinkScanner::INT_CAST_VIA_VARIABLE,
            'OPEN, KNOWN, LATENT. enableRawMode(); `$fd = (int) $this->stream` two lines up. '
            . 'Same mechanism and same wider fix as the size() first-arm row above.',
        ],
        'candy-core/src/Util/Tty/PosixBackend.php::TermiosFactory::open(0)' => [
            DescriptorSinkScanner::LITERAL_INT,
            'CORRECT. restoreLast(). Was `(int) STDIN`, which is 1, i.e. STDOUT -- under a '
            . 'comment saying it saves STDIN\'s state. The literal 0 is STDIN\'s descriptor; '
            . 'see PosixBackendRestoreLastDescriptorTest.',
        ],

        // ---- candy-flip -------------------------------------------------
        'candy-flip/src/Renderer.php::SizeIoctl::query(1)' => [
            DescriptorSinkScanner::LITERAL_INT,
            'CORRECT. withAdaptiveSize(). Was `(int) STDOUT`, which is 2, i.e. STDERR -- the one '
            . 'member of this family that misbehaved with no unusual process state, only '
            . '`2>err.log`. See RendererAdaptiveSizeDescriptorTest in candy-flip.',
        ],

        // ---- candy-pty ---------------------------------------------------
        'candy-pty/src/Posix/PosixTermios.php::posix_isatty($this->fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. $this->fd is the `int $fd` constructor parameter -- a descriptor the '
            . 'caller supplied, never a cast of a stream.',
        ],
        'candy-pty/src/Posix/SttyTermios.php::posix_isatty($this->fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Same shape and same reason as PosixTermios.',
        ],
        'candy-pty/src/SizeIoctl.php::posix_isatty($fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. The `int $fd` parameter of query(). This is the guard every other member '
            . 'of the family ultimately trips over when handed a resource id, which is why the '
            . 'wrong number surfaces as "Cannot query size of non-tty fd" rather than as a '
            . 'wrong size.',
        ],

        // ---- the rest of the tree ----------------------------------------
        // Every one of these passes the STREAM ITSELF, uncast. `posix_isatty()`
        // and `posix_ttyname()` are declared `resource|int`, so this is not
        // merely tolerated -- it is the shape the whole family should be
        // moving toward, and the reason it cannot everywhere is that the
        // ioctl and termios sinks take `int` only.
        'candy-log/src/Log.php::posix_isatty(\STDERR)' => [
            DescriptorSinkScanner::STREAM_CONSTANT,
            'CORRECT. The resource, not a cast of it. Not named in E368\'s carve-out list -- '
            . 'found by re-deriving the population with the corrected instrument.',
        ],
        'candy-palette/src/Probe/TerminalProbe.php::posix_isatty(STDOUT)' => [
            DescriptorSinkScanner::STREAM_CONSTANT,
            'CORRECT, and an E368 carve-out: the resource, no cast.',
        ],
        'candy-shine/src/Theme.php::posix_isatty(STDOUT)' => [
            DescriptorSinkScanner::STREAM_CONSTANT,
            'CORRECT, and an E368 carve-out: the resource, no cast.',
        ],
        'candy-vcr/src/Cli/RecordCommand.php::posix_ttyname(\STDIN)' => [
            DescriptorSinkScanner::STREAM_CONSTANT,
            'CORRECT. The resource, not a cast. Not named in E368\'s carve-out list, which '
            . 'mentioned only this file\'s `TermiosFactory::open(0)`.',
        ],
        'candy-vcr/src/Cli/RecordCommand.php::TermiosFactory::open(0)' => [
            DescriptorSinkScanner::LITERAL_INT,
            'CORRECT, and an E368 carve-out: a real descriptor number spelled as a literal. '
            . 'This is the shape restoreLast() was moved to.',
        ],
    ];


    public function testEverySiteThatConsumesADescriptorIsJudgedInTheRoster(): void
    {
        $found = $this->scanLibraries();

        $expected = [];
        foreach (self::ROSTER as $key => $row) {
            if (\in_array(explode('/', $key, 2)[0], $this->presentLibraries(), true)) {
                $expected[$key] = $row;
            }
        }

        $unjudged = array_diff(array_keys($found), array_keys($expected));
        self::assertSame(
            [],
            array_values($unjudged),
            "A call consuming a file descriptor is not in this test's ROSTER.\n"
                . "RESOLUTION: add a row keyed exactly as printed below, whose value states\n"
                . "whether the descriptor is genuine and why. Do NOT relax the assertion.\n"
                . $this->describe($found, $unjudged),
        );

        $stale = array_diff(array_keys($expected), array_keys($found));
        self::assertSame(
            [],
            array_values($stale),
            "A ROSTER row describes a call site that is no longer in the tree.\n"
                . "RESOLUTION: if the site moved or was respelled, retire the old row and add\n"
                . "the new spelling the scanner now prints (run the census to see it). If the\n"
                . "site is genuinely gone, delete the row.",
        );

        // And the shape each site has must still be the shape its judgement was
        // written about. Set equality cannot see a site whose spelling is
        // unchanged but whose meaning moved: `query($fd)` stops being a hidden
        // cast the moment the assignment above it is fixed.
        foreach ($found as $key => $hit) {
            if (!isset($expected[$key])) {
                continue;
            }
            self::assertSame(
                $expected[$key][0],
                $hit['kind'],
                $key . ' is classified ' . $hit['kind'] . ' but its roster row expects '
                    . $expected[$key][0] . ".\nRESOLUTION: rewrite that row -- both its kind and "
                    . 'the judgement under it, which was written about the old shape.',
            );
        }
    }

    /**
     * Nothing in the tree is spelled in a way the scanner has no word for.
     *
     * This is the assertion the first census could not make, because it had
     * no way to say "I saw something and could not name it" -- it simply did
     * not report those.
     */
    public function testNoSiteIsSpelledInAWayTheScannerCannotClassify(): void
    {
        // THE IN-TEST KNOWN-POSITIVE, ON THE ENTRY POINT THE ABSENCE BELOW
        // ACTUALLY USES. An empty result is also what a dead instrument
        // returns, so an absence assertion is worth nothing on its own.
        //
        // WHAT AN EARLIER REVISION OF THIS COMMENT SAID: that a `scanSource()`
        // fixture here closed that hole, because MEASURED (round-53 mutation
        // M9a) this test on its own had passed against a `scanSource()` gutted
        // to walk no tokens.
        //
        // WHAT IS TRUE NOW: that control watched the wrong window. The absence
        // below is computed through {@see self::scanLibraries()}, which reaches
        // {@see DescriptorSinkScanner::scanTree()} -- a SECOND entry point with
        // its own directory walk, which a `scanSource()` fixture never touches.
        // MEASURED (round-53 mutation R1), with `scanTree()`'s `is_dir()` arm
        // rewritten to return an empty list unconditionally, this test on its
        // own passed again: one test, TWO green assertions, the `scanSource()`
        // control among them, against a tree walk that could no longer see a
        // single file.
        //
        // WHY THIS STILL EARNS ITS PLACE: the reasoning was right and only the
        // window was wrong. A positive still has to run inside this method,
        // because under `--filter` on this method's name it is the whole guard.
        // It now runs through `scanTree()`, and it keeps a `scanSource()`
        // component so that a classifier that stopped being able to say
        // UNCLASSIFIED is caught here too.
        $this->assertTheInstrumentBehindTheAbsenceIsAlive();

        $unclassified = [];
        foreach ($this->scanLibraries() as $key => $hit) {
            if ($hit['kind'] === DescriptorSinkScanner::UNCLASSIFIED) {
                $unclassified[$key] = $hit['argument'];
            }
        }

        self::assertSame(
            [],
            $unclassified,
            "A descriptor argument is spelled in a shape DescriptorSinkScanner cannot name.\n"
                . "RESOLUTION: teach the classifier that shape, or respell the call. Leaving it\n"
                . "unclassified is the failure this whole census exists to make impossible.\n"
                . var_export($unclassified, true),
        );
    }

    /**
     * THE KNOWN-POSITIVE CONTROL. The scanner is alive, and it can still say
     * "I do not know what this is".
     *
     * Every expectation above is an assertion of ABSENCE — no unjudged site,
     * no unclassified site — and an absence is also what a scanner that
     * matches nothing reports. So a fixture goes through the SAME entry point
     * in the SAME run, and each classification must come back.
     *
     * The sink names are taken from the scanner's own constants rather than
     * typed out. That keeps the literal call text from ever appearing in this
     * file (so widening the scan to `tests/` cannot turn the control into a
     * false positive), and it means a renamed sink cannot leave a fixture
     * quietly testing a name nothing uses any more.
     */
    public function testTheScannerReportsEveryShapeIncludingTheOnesItCannotName(): void
    {
        $fn     = DescriptorSinkScanner::FUNCTION_SINKS[0];
        $static = DescriptorSinkScanner::STATIC_SINKS[1];

        $fixture = "<?php\n"
            . $fn . "(0);\n"
            . $fn . "(STDIN);\n"
            . $fn . "(SOME_OTHER_CONST);\n"
            . $fn . "(\$stream);\n"
            . $fn . '((int) $stream)' . ";\n"
            . $fn . '(intval($stream))' . ";\n"
            . '$fd = (int) $this->stream;' . "\n"
            . $static . "(\$fd);\n"
            . $fn . '($a ? 1 : 2)' . ";\n"
            // An operand rooted in a LITERAL rather than in a variable. This
            // is not a duplicate of the line above it: `$a ? 1 : 2` leaves
            // classify() through the return inside the accessor-chain walk,
            // whereas an operand whose first token is neither a cast, nor
            // `intval`, nor a lone token, nor a variable-or-name reaches the
            // TERMINAL fallthrough at the end of that method. Those are two
            // separate returns, and until this line existed only the first of
            // them was pinned -- MEASURED: with the terminal return rewritten
            // to answer VARIABLE, this whole census stayed green (round-53
            // mutation M8), so an operand the classifier has no word for was
            // silently absorbed as a benign one. That is the exact failure
            // this scanner was written to replace, one level down.
            . $fn . '(0 + 1)' . ";\n"
            // Two shapes that must NOT be reported at all: a method of the
            // same name, and a declaration of it.
            . '$obj->' . $fn . "(0);\n"
            . 'function ' . $fn . "(\$x) {}\n";

        $kinds = array_column(DescriptorSinkScanner::scanSource($fixture), 'kind');

        self::assertSame(
            [
                DescriptorSinkScanner::LITERAL_INT,
                DescriptorSinkScanner::STREAM_CONSTANT,
                DescriptorSinkScanner::CONSTANT,
                DescriptorSinkScanner::VARIABLE,
                DescriptorSinkScanner::INT_CAST,
                DescriptorSinkScanner::INTVAL,
                DescriptorSinkScanner::INT_CAST_VIA_VARIABLE,
                // `$a ? 1 : 2` -- the accessor-chain walk's return.
                DescriptorSinkScanner::UNCLASSIFIED,
                // `0 + 1` -- the terminal fallthrough. Distinct branch; see
                // the fixture comment above.
                DescriptorSinkScanner::UNCLASSIFIED,
            ],
            $kinds,
            'the scanner did not classify the control fixture as expected; every assertion of '
                . 'absence in this file is void until it does',
        );
    }

    /**
     * The tree scan reached real files.
     *
     * The control above proves the scanner works on a string. This proves it
     * was pointed at something — a path typo would otherwise turn every
     * absence assertion green.
     */
    public function testTheCensusActuallyScannedSomething(): void
    {
        self::assertContains(
            'candy-core',
            $this->presentLibraries(),
            'candy-core/src was not found; the census scanned nothing it was written for',
        );

        $found = $this->scanLibraries();
        self::assertNotSame([], $found, 'the census found no descriptor sinks anywhere in the tree');

        // And at least one site of each polarity, so neither a scanner stuck
        // on "everything is a cast" nor one stuck on "everything is fine"
        // passes.
        $kinds = array_column($found, 'kind');
        self::assertContains(DescriptorSinkScanner::LITERAL_INT, $kinds);
        self::assertContains(DescriptorSinkScanner::INT_CAST_VIA_VARIABLE, $kinds);
    }

    /**
     * Every library beside candy-core that has a `src/`, sorted.
     *
     * Discovered rather than listed, which is the whole point: a census that
     * enumerates the libraries it looks at can only ever find the defect in
     * the libraries someone already suspected, and that is the exact failure
     * this census was written to replace one level up. A NEW library with a
     * descriptor sink in it must red this test on its first commit.
     *
     * In a split-repo clone of candy-core there are no siblings, so this
     * answers `['candy-core']` and the roster rows for absent libraries are
     * skipped. {@see testTheCensusActuallyScannedSomething()} refuses a run
     * in which even candy-core was not found.
     *
     * @return list<string>
     */
    private function presentLibraries(): array
    {
        $root    = $this->monorepoRoot();
        $present = [];
        foreach ((array) glob($root . '/*/src', \GLOB_ONLYDIR) as $dir) {
            $present[] = basename(\dirname((string) $dir));
        }
        sort($present);

        return $present;
    }

    /**
     * Memoised because the walk covers every library's `src/` and three tests
     * ask for it. Measured on this box, PHP 8.3.6: ~1.4s per walk, so caching
     * takes the file from ~2.0s to ~0.8s.
     *
     * @var array<string, array{sink:string, kind:string, argument:string}>|null
     */
    private static ?array $scanned = null;

    /** @return array<string, array{sink:string, kind:string, argument:string}> */
    private function scanLibraries(): array
    {
        if (self::$scanned !== null) {
            return self::$scanned;
        }

        $root  = $this->monorepoRoot();
        $found = [];

        foreach ($this->presentLibraries() as $lib) {
            foreach (DescriptorSinkScanner::scanTree($root . '/' . $lib . '/src') as $hit) {
                $relative = substr($hit['file'], \strlen($root) + 1);
                $key      = $relative . '::' . $hit['sink'] . '(' . $hit['argument'] . ')';
                $found[$key] = [
                    'sink'     => $hit['sink'],
                    'kind'     => $hit['kind'],
                    'argument' => $hit['argument'],
                ];
            }
        }

        return self::$scanned = $found;
    }

    /**
     * A fixture goes through BOTH entry points the absence assertion depends
     * on, and each must come back with the classifications it was built to
     * produce.
     *
     * `scanTree()` is exercised against a throwaway directory rather than
     * against the monorepo, so the control states a property of the scanner
     * and not of whatever happens to be committed today. The directory name
     * is process-unique: sibling lanes run their own suites against the same
     * /tmp, and a shared fixture path is a cross-lane failure waiting for a
     * coincidence.
     *
     * The sink text is built from the scanner's own constants rather than
     * typed out, for the reason given on
     * {@see testTheScannerReportsEveryShapeIncludingTheOnesItCannotName()}.
     */
    private function assertTheInstrumentBehindTheAbsenceIsAlive(): void
    {
        $fn = DescriptorSinkScanner::FUNCTION_SINKS[0];

        // (a) The classifier can still say "I have no word for this".
        self::assertSame(
            [DescriptorSinkScanner::UNCLASSIFIED],
            array_column(DescriptorSinkScanner::scanSource("<?php\n" . $fn . '(0 + 1)' . ";\n"), 'kind'),
            'the scanner can no longer report an unnameable operand, so the emptiness asserted '
                . 'in the calling test is a property of the instrument and not of the tree',
        );

        // (b) The TREE WALK -- the entry point the absence is computed
        // through -- still reads files and still reports what is in them.
        $dir = sys_get_temp_dir() . '/sc_r53a_sink_tree_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700, true), 'could not create the control fixture directory');

        try {
            file_put_contents(
                $dir . '/fixture.php',
                "<?php\n" . $fn . '(0)' . ";\n" . $fn . '(0 + 1)' . ";\n",
            );

            $hits = DescriptorSinkScanner::scanTree($dir);
            self::assertSame(
                [DescriptorSinkScanner::LITERAL_INT, DescriptorSinkScanner::UNCLASSIFIED],
                array_column($hits, 'kind'),
                'scanTree() no longer reports what is in a file it was pointed at, so the '
                    . 'emptiness asserted in the calling test says nothing about the tree',
            );
            self::assertSame(
                [$dir . '/fixture.php'],
                array_values(array_unique(array_column($hits, 'file'))),
                'scanTree() reported hits that did not come from the file it was given',
            );
        } finally {
            // Exact-path deletes only -- never a glob under /tmp, which is
            // shared with the other lanes' suites.
            @unlink($dir . '/fixture.php');
            @rmdir($dir);
        }

        // (c) And the walk over the REAL tree reached something. A path typo
        // in monorepoRoot() would otherwise turn the absence green.
        self::assertNotSame(
            [],
            $this->scanLibraries(),
            'the census found no descriptor sink anywhere in the tree, so the absence asserted '
                . 'in the calling test is about a walk that reached nothing',
        );
    }

    private function monorepoRoot(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * @param array<string, array{sink:string, kind:string, argument:string}> $found
     * @param array<int|string, string>                                       $keys
     */
    private function describe(array $found, array $keys): string
    {
        $out = '';
        foreach ($keys as $key) {
            $out .= "\n  " . $key . "   [" . $found[$key]['kind'] . ']';
        }

        return $out;
    }
}
