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
    ];

    /**
     * Libraries scanned, relative to the monorepo root.
     *
     * Deliberately NOT every library in the tree. A census run from
     * candy-core\'s suite that reddened on an unrelated library\'s edit would
     * be a guard nobody can see coming; these three are where this defect
     * family lives and they move together. To widen it, add a directory here
     * and add the rows the scanner then reports.
     *
     * A library that is absent -- which is every sibling in a split-repo
     * clone of candy-core -- has its rows skipped rather than reported
     * missing. candy-core itself is always present, and
     * {@see testTheCensusActuallyScannedSomething()} refuses a run in which
     * it somehow was not.
     */
    private const LIBRARIES = ['candy-core', 'candy-flip', 'candy-pty'];

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

    /** @return list<string> the libraries in self::LIBRARIES that exist here */
    private function presentLibraries(): array
    {
        $present = [];
        foreach (self::LIBRARIES as $lib) {
            if (is_dir($this->monorepoRoot() . '/' . $lib . '/src')) {
                $present[] = $lib;
            }
        }

        return $present;
    }

    /** @return array<string, array{sink:string, kind:string, argument:string}> */
    private function scanLibraries(): array
    {
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

        return $found;
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
