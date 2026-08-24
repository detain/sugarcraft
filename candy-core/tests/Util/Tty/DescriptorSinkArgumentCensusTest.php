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
 * ## The THIRD spelling, added after the first two had been trusted for a
 * ## round
 *
 * WHAT THIS DOC-BLOCK USED TO IMPLY, by talking only about `posix_isatty(...)`
 * and `SizeIoctl::query(...)`: that the census saw every descriptor sink in
 * the tree.
 *
 * WHAT IS TRUE NOW: it saw them only where they are spelled as a plain
 * function or as `Class::method`. Every call into candy-pty's FFI `Libc`
 * binding -- `Libc::lib()->close($fd)`, `$libc->fcntl($masterFd, ...)`,
 * `self::libc()->dup($this->fd)` -- is a libc symbol taking a descriptor, and
 * not one of them was visible. The previous round's own fix added one of them
 * (`closeDeviceDescriptor()`) INSIDE that blind spot while documenting the
 * blind spot in the same commit series.
 *
 * WHY THE ORIGINAL REASONING STILL EARNS ITS PLACE: "search on the sink, not
 * on the operand" is right, and it is the reason the method arm was a day's
 * work rather than a rewrite. What was wrong was believing the SINK had been
 * enumerated when only two of its three spellings had.
 *
 * The method roster is DERIVED from {@see \SugarCraft\Pty\Libc::cdef()}
 * rather than listed -- see {@see DescriptorSinkScanner::methodSinks()} and
 * {@see testTheMethodSinkRosterIsDerivedFromTheCdef()} -- and the arm matches
 * on the METHOD NAME with no opinion at all about the receiver, because a
 * list of receiver spellings is the same trap one level along.
 *
 * ## What this test asserts
 *
 * Set equality between the sites found in the tree and {@see self::ROSTER}.
 * Not a count — a count taken in one worktree is wrong the moment a sibling
 * lands. A NEW site fails because it is unjudged; a REMOVED site fails because
 * its row is stale. Both failures name the resolution in their message.
 *
 * "A NEW site fails" includes one whose spelling is ALREADY in the roster for
 * that same file: {@see self::indexByKey()} gives the second occurrence its
 * own key rather than letting it overwrite the first. It did not always, and
 * the paragraph above was measured false for that shape before it did.
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
     * A spelling that occurs TWICE in one file gets ` #2`, ` #3` on the second
     * and later occurrences -- see {@see self::indexByKey()} for what happened
     * before it did.
     *
     * WHAT THIS USED TO SAY: "no row needs one today (13 sites, 13 distinct
     * spellings)". WHAT IS TRUE NOW: both halves are false. The census grew
     * the METHOD spelling of its own sinks and the population roughly tripled;
     * ordinals are ordinary here, and several rows below carry one. WHY THE
     * PARAGRAPH STILL EARNS ITS PLACE: the mechanism it describes is the point
     * -- a row that GROWS an ordinal is telling you a same-spelled sibling
     * appeared beside it, and that is the signal to read, whether or not any
     * row happens to carry one on the day you are reading.
     *
     * No count is written here on purpose. This roster is the count, it moves
     * whenever a sibling lane lands a descriptor sink, and a number in prose
     * is stale the moment it is true.
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
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. size()\'s first arm. WHAT THIS ROW USED TO SAY: "OPEN, KNOWN, LATENT ... '
            . 'two lines up it does `$fd = (int) $this->stream` ... closing it needs the '
            . 'descriptor carried alongside the stream, which the constructor does not do". '
            . 'WHAT IS TRUE NOW: the assignment is `$fd = self::descriptorForStream($this->'
            . 'stream)`, which resolves a GENUINE descriptor -- by identity for the three '
            . 'standard streams, otherwise by matching st_dev+st_ino against the process\'s own '
            . 'descriptor table -- and no constructor signature changed. WHY THE OLD ROW STILL '
            . 'EARNS ITS PLACE HERE: it recorded that a constructor change was the only way, '
            . 'and that is the sentence a future reader would otherwise re-derive. It was '
            . 'incomplete, not wrong: a descriptor can also be recovered from the process '
            . 'itself. Pinned end to end by PosixBackendStreamDescriptorTest.',
        ],
        'candy-core/src/Util/Tty/PosixBackend.php::SizeIoctl::query($ttyFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. size()\'s /dev/tty arm. $ttyFd comes from openDeviceDescriptor(), which '
            . 'asks libc to open the device and hands back a genuine descriptor. This arm used '
            . 'to read `(int) $tty[0]` on a freshly fopen\'d handle, and was wrong on every run '
            . 'rather than latent -- see PosixBackendTerminalDescriptorTest.',
        ],
        'candy-core/src/Util/Tty/PosixBackend.php::TermiosFactory::open($fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. enableRawMode(). Was `$fd = (int) $this->stream` two lines up; same '
            . 'mechanism and same fix as the size() first-arm row above, and the same '
            . 'correction to the reasoning that deferred it. A null answer now skips raw mode '
            . 'rather than applying it to a number that names nothing, which is what a '
            . '`php://memory` stream produced before.',
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

        // ---- the METHOD spelling ------------------------------------------
        //
        // Every row below is a call on candy-pty's FFI `Libc` handle, i.e. a
        // real libc symbol declared in {@see \SugarCraft\Pty\Libc::cdef()}
        // with a file descriptor as its first parameter. The census could not
        // see any of them until this round: it reported a sink only in its
        // FUNCTION spelling, and every one of these is reached as a method.
        //
        // THE POPULATION, RE-DERIVED WITH THE CORRECTED INSTRUMENT. The
        // backlog entry commissioning this arm put the count at 20, from a
        // grep whose symbol alternation was `open|close|ioctl|fcntl|read|
        // write|dup|dup2`. Measured through the cdef instead: `read`, `write`
        // and `dup2` are not declared there at all, while `grantpt`,
        // `unlockpt`, `ptsname_r`, `tcgetattr` and `tcsetattr` are fd-first
        // and were missing from it -- 8 further sites, which is exactly the
        // 20-to-28 gap. Do NOT read 28 as a fact either: it is a property of
        // this worktree, the rows below are the judgement, and set equality
        // is what enforces them.
        //
        // ALL OF THEM ARE CORRECT, and that is the expected result: an FFI
        // binding is reached with numbers that came from other libc calls, so
        // this family's defect -- deriving a descriptor from a PHP stream --
        // has nowhere to enter. The value of the rows is that a NEW method
        // sink now has to be judged instead of being invisible.

        // candy-core. The descriptor comes from openDeviceDescriptor(), which
        // is `Libc::lib()->open($device, O_RDONLY)`; the pair exists so this
        // close cannot drift away from that open.
        'candy-core/src/Util/Tty/PosixBackend.php::->close($fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. closeDeviceDescriptor()\'s `int $fd` parameter. This is the site the '
            . 'backlog named as the cheapest known-positive seed for this arm, having been added '
            . 'by the previous round INSIDE the blind spot that same round documented.',
        ],

        // candy-pty/ControllingTerminal. NOTE for anyone re-deriving: this
        // file ALSO carries a doc-comment mention of the same symbol, and the
        // backlog counted the doc-comment while missing the live call one
        // screen below it. Token-based scanning does not have that problem --
        // T_DOC_COMMENT is never tokenised into a call.
        'candy-pty/src/ControllingTerminal.php::->ioctl($fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. claim()\'s `int $fd` parameter, handed straight to TIOCSCTTY.',
        ],

        // candy-pty/PosixMasterPty. `$this->fd` is the promoted `int $fd`
        // constructor parameter; `$this->anchorSlaveFd` is set only by
        // attachAnchorSlaveFd(int $fd) and initialised to the -1 sentinel.
        'candy-pty/src/Posix/PosixMasterPty.php::->close($this->anchorSlaveFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. attachAnchorSlaveFd() closing the anchor it is replacing.',
        ],
        'candy-pty/src/Posix/PosixMasterPty.php::->close($this->anchorSlaveFd) #2' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. The same field released on close(). A SECOND site spelled exactly like '
            . 'the one above -- before the ordinal existed these two collapsed into one row and '
            . 'only the later survived.',
        ],
        'candy-pty/src/Posix/PosixMasterPty.php::->dup($this->fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. The promoted constructor parameter. `dup` is one of the five symbols the '
            . 'backlog\'s hand-written alternation could not express.',
        ],
        'candy-pty/src/Posix/PosixMasterPty.php::->close($this->fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Same field, released on close().',
        ],

        // candy-pty/PosixPtySystem. `$masterFd` comes from openPtyMaster(),
        // i.e. posix_openpt(); `$slaveFd` from `$libc->open($slavePath, ...)`;
        // `$slaveFdPtr[0]` is openpty()'s out-parameter. All three are libc
        // return values, never a cast of a PHP stream.
        'candy-pty/src/Posix/PosixPtySystem.php::->fcntl($masterFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. FD_CLOEXEC on the freshly opened master.',
        ],
        'candy-pty/src/Posix/PosixPtySystem.php::->close($masterFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Error-path cleanup after the FD_CLOEXEC call above failed.',
        ],
        'candy-pty/src/Posix/PosixPtySystem.php::->grantpt($masterFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Same descriptor.',
        ],
        'candy-pty/src/Posix/PosixPtySystem.php::->close($masterFd) #2' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Error-path cleanup after grantpt() failed.',
        ],
        'candy-pty/src/Posix/PosixPtySystem.php::->unlockpt($masterFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Same descriptor.',
        ],
        'candy-pty/src/Posix/PosixPtySystem.php::->close($masterFd) #3' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Error-path cleanup after unlockpt() failed.',
        ],
        'candy-pty/src/Posix/PosixPtySystem.php::->fcntl($slaveFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. FD_CLOEXEC on the slave descriptor `$libc->open($slavePath, ...)` returned.',
        ],
        'candy-pty/src/Posix/PosixPtySystem.php::->close($slaveFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Error-path cleanup for that slave descriptor.',
        ],
        'candy-pty/src/Posix/PosixPtySystem.php::->close($slaveFdPtr[0])' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. openpty()\'s slave out-parameter, an `int[1]` allocated through FFI. An '
            . 'ARRAY ELEMENT operand -- the shape the FIRST census of this family could not '
            . 'express and dropped in silence.',
        ],
        'candy-pty/src/Posix/PosixPtySystem.php::->ptsname_r($masterFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Same master descriptor.',
        ],
        'candy-pty/src/Posix/PosixPtySystem.php::->close($masterFd) #4' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Error-path cleanup after ptsname_r() failed.',
        ],

        // candy-pty/PosixTermios. `$this->fd` is the `int $fd` constructor
        // parameter -- the same field the posix_isatty() row above judges.
        'candy-pty/src/Posix/PosixTermios.php::->tcgetattr($this->fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. The constructor\'s descriptor. `tcgetattr` is another symbol absent from '
            . 'the backlog\'s alternation.',
        ],
        'candy-pty/src/Posix/PosixTermios.php::->tcsetattr($this->fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Same field, applying a termios snapshot.',
        ],

        // candy-pty/Pty. `$masterFd` is `$libc->posix_openpt(...)`.
        'candy-pty/src/Pty.php::->grantpt($masterFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. The posix_openpt() return value.',
        ],
        'candy-pty/src/Pty.php::->close($masterFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Error-path cleanup after grantpt() failed.',
        ],
        'candy-pty/src/Pty.php::->unlockpt($masterFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Same descriptor.',
        ],
        'candy-pty/src/Pty.php::->close($masterFd) #2' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Error-path cleanup after unlockpt() failed.',
        ],
        'candy-pty/src/Pty.php::->ptsname_r($masterFd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Same descriptor.',
        ],
        'candy-pty/src/Pty.php::->close($masterFd) #3' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. Error-path cleanup after ptsname_r() failed.',
        ],

        // candy-pty/SizeIoctl. Both are the `int $fd` parameter of a static
        // helper that takes the FFI handle alongside it.
        'candy-pty/src/SizeIoctl.php::->ioctl($fd)' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. setSizeViaLibc()\'s `int $fd` parameter (TIOCSWINSZ).',
        ],
        'candy-pty/src/SizeIoctl.php::->ioctl($fd) #2' => [
            DescriptorSinkScanner::VARIABLE,
            'CORRECT. getSizeViaLibc()\'s `int $fd` parameter (TIOCGWINSZ).',
        ],

        // candy-wish -- a library no scoping of this census had ever looked
        // at, reached because presentLibraries() globs `*/src` instead of
        // listing what someone suspected.
        'candy-wish/src/Transport/InProcessTransport.php::->ioctl(0)' => [
            DescriptorSinkScanner::LITERAL_INT,
            'CORRECT. TIOCGWINSZ on descriptor 0, spelled as the literal it is. The arm refuses '
            . 'to run at all unless the stream it was handed IS the STDIN constant, checked by '
            . 'identity two lines above, so the literal and the stream cannot disagree.',
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
                . "A key ending in ' #2' is a SECOND call in that file spelled exactly like\n"
                . "one already rostered -- it needs its own judgement, not a shrug.\n"
                . "\nIF YOU ARE RESOLVING A MERGE AND DID NOT TOUCH candy-core: that is\n"
                . "expected and it is not a mistake. This census walks EVERY library's src/,\n"
                . "so a descriptor sink added anywhere in the monorepo reds candy-core.\n"
                . "\nA key beginning `->` is the METHOD spelling: a call SPELLED as a method\n"
                . "whose name matches a libc symbol from Libc::cdef(). The scanner forms no\n"
                . "opinion about the receiver -- deliberately, because enumerating receiver\n"
                . "spellings is how the previous attempt missed four live sites. So FIRST\n"
                . "DECIDE WHETHER THIS IS A LIBC CALL AT ALL. A one-argument ->close(\$x) on a\n"
                . "stream wrapper, a socket object or your own handle class is reported here\n"
                . "too, and it is NOT a descriptor sink. If that is your case, the row still\n"
                . "belongs -- write the judgement as 'not a libc call, <what it really is>'.\n"
                . "Do not write a row that calls it an FFI descriptor because this message\n"
                . "mentioned FFI. A wrong judgement in the roster is worse than the red.\n"
                . "\nLibc::cdef() is also the symbol roster's source, so a new fd-first\n"
                . "declaration THERE lands here too. Add the row; do not delete the site.\n"
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
                    . $expected[$key][0] . ".\nRESOLUTION: usually, rewrite that row -- both its "
                    . "kind and the judgement under it, which was written about the old shape.\n"
                    . "BUT CHECK ONE FUNCTION UP FIRST when the move is to or from "
                    . DescriptorSinkScanner::INT_CAST_VIA_VARIABLE . ".\nThat kind is decided by "
                    . "lastAssignmentTo(), which walks the WHOLE FILE backwards and is not \n"
                    . "scope-aware -- deliberately, see its doc-block. So an unrelated \n"
                    . '`$fd = (int) $x;` added anywhere above this call moves THIS row without '
                    . "touching\nit. Observed live in round 53. The right fix is then at the new "
                    . 'assignment, not here.',
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
        // Taken from the derived roster rather than typed, so that a symbol
        // leaving candy-pty's cdef cannot leave this fixture quietly
        // exercising a name the scanner no longer looks for.
        $method = DescriptorSinkScanner::methodSinks()[0];
        self::assertNotSame('', $method, 'the cdef yielded no fd-first symbol at all');

        $fixture = "<?php\n"
            . $fn . "(0);\n"
            . $fn . "(STDIN);\n"
            . $fn . "(SOME_OTHER_CONST);\n"
            . $fn . "(\$stream);\n"
            . $fn . '((int) $stream)' . ";\n"
            . $fn . '(intval($stream))' . ";\n"
            . '$fd = (int) $this->stream;' . "\n"
            . $static . "(\$fd);\n"
            // ---- the four ways classify() answers UNCLASSIFIED ----------
            //
            // A classifier needs a case per RETURN, not a case per
            // CLASSIFICATION. classify() reaches UNCLASSIFIED through FOUR
            // separate returns, and each is its own branch to mutate:
            //
            //  1. the empty-argument guard at the top -- a call with no
            //     first argument at all;
            //  2. the single-token fallthrough, after the ladder that names
            //     a lone number, a lone variable and a lone constant;
            //  3. the return inside the accessor-chain walk, for a
            //     multi-token operand ROOTED in a variable or a name that
            //     contains something the chain has no word for;
            //  4. the terminal fallthrough at the end of the method, for a
            //     multi-token operand rooted in anything else.
            //
            // WHAT AN EARLIER REVISION OF THIS COMMENT SAID: "Those are two
            // separate returns", naming 3 and 4.
            //
            // WHAT IS TRUE NOW: there are four, and 1 and 2 were unpinned.
            // MEASURED (round-53 mutations R2 and R9), each rewritten to
            // answer VARIABLE, whole candy-core suite: 818 tests / 7384
            // assertions / rc 0 both times. Both are reachable -- a bare
            // `<sink>()` takes return 1, and `<sink>("x")` takes return 2.
            //
            // WHY THIS STILL EARNS ITS PLACE: the reasoning was right, and it
            // is the reason these four lines exist rather than one. An
            // operand the classifier has no word for, silently absorbed as a
            // benign one, is the exact failure this scanner replaced -- one
            // level down, inside the instrument built to fix it.
            . $fn . '($a ? 1 : 2)' . ";\n"
            . $fn . '(0 + 1)' . ";\n"
            . $fn . '()' . ";\n"
            . $fn . '("x")' . ";\n"
            // ---- three shapes that must NOT be reported at all -----------
            // A method of the same name, the nullsafe spelling of that same
            // method, and a declaration of the function.
            //
            // The first two are the sharp ones now that method-shaped sinks
            // ARE reported: a method merely SHARING a name with a POSIX
            // function is still not one, and the two arms have to be able to
            // tell those apart. See the method block below for the other
            // polarity.
            . '$obj->' . $fn . "(0);\n"
            . '$obj?->' . $fn . "(0);\n"
            . 'function ' . $fn . "(\$x) {}\n"
            // ---- the METHOD spelling, in BOTH polarities -----------------
            //
            // Reported: any receiver at all, because a census that
            // enumerates receiver spellings is a transcript of the receivers
            // its author had. The four written here are the four the tree
            // actually uses, and the point of the arm is that it would match
            // a fifth nobody has thought of.
            //
            // NOT reported: a nullary call, because every symbol in
            // methodSinks() is declared with at least one parameter, so
            // `->close()` is a different method that shares a word; and a
            // property read, which is not a call at all.
            . '$libc->' . $method . '($other)' . ";\n"
            . '$libc?->' . $method . '($other)' . ";\n"
            . 'Libc::lib()->' . $method . '(3)' . ";\n"
            . 'self::libc()->' . $method . '(3)' . ";\n"
            . 'Foo::' . $method . '(3)' . ";\n"
            . '$x->' . $method . '()' . ";\n"
            . '$x->' . $method . ";\n";

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
                // `$a ? 1 : 2` -- the accessor-chain walk's return (3).
                DescriptorSinkScanner::UNCLASSIFIED,
                // `0 + 1` -- the terminal fallthrough (4).
                DescriptorSinkScanner::UNCLASSIFIED,
                // `()` -- the empty-argument guard (1).
                DescriptorSinkScanner::UNCLASSIFIED,
                // `"x"` -- the single-token fallthrough (2).
                DescriptorSinkScanner::UNCLASSIFIED,
                // ---- the method spelling, four receivers -----------------
                DescriptorSinkScanner::VARIABLE,
                DescriptorSinkScanner::VARIABLE,
                DescriptorSinkScanner::LITERAL_INT,
                DescriptorSinkScanner::LITERAL_INT,
                DescriptorSinkScanner::LITERAL_INT,
                // `->close()` and `->close` produce nothing at all, which is
                // why there are five entries here and seven lines above.
            ],
            $kinds,
            'the scanner did not classify the control fixture as expected; every assertion of '
                . 'absence in this file is void until it does',
        );

        // AND THE ARM FOR A CALL IT CANNOT BRACKET AT ALL. This is the one
        // the class doc-blocks on both sides name as the scanner's reason for
        // existing -- "a guard that quietly ignores what it cannot parse has
        // a hole shaped exactly like the next defect" -- and it was the one
        // branch with no case. MEASURED (round-53 mutation R3): with the row
        // it records deleted, leaving a bare `continue;`, the whole
        // candy-core suite stayed green at 818 / 7384 / rc 0. The scanner
        // could be made to swallow exactly what it cannot read, silently, and
        // nothing in the tree noticed.
        //
        // It needs its own scanSource() call rather than a line in the
        // fixture above: an unterminated call swallows everything after it.
        self::assertSame(
            [[
                'sink'     => $fn,
                'kind'     => DescriptorSinkScanner::UNCLASSIFIED,
                'argument' => '<could not bracket the argument list>',
                'line'     => 2,
            ]],
            DescriptorSinkScanner::scanSource("<?php\n" . $fn . '('),
            'a sink whose argument list does not close is no longer REPORTED as unreadable. '
                . 'Dropping it in silence is the failure mode this scanner exists to replace.',
        );
    }

    /**
     * The method-sink roster is READ OUT OF candy-pty's cdef, not typed here.
     *
     * This family's recurring failure is a hand-written list of the cases its
     * author already had. The list this arm replaces was written into the
     * backlog with `read`, `write` and `dup2` in it -- three symbols candy-pty
     * does not declare -- and without `grantpt`, `unlockpt`, `ptsname_r`,
     * `tcgetattr` or `tcsetattr`, which it does and which are fd-first. So the
     * roster is derived, and this test is the derivation's control.
     *
     * The synthetic cdef carries the NEAR-MISSES on purpose: a path-first
     * `open`, a `pid`-first `waitpid`, a pointer-first `openpty`, a nullary
     * `setsid`, a `void *`-first `cfmakeraw`, and an fd-first-looking
     * declaration inside a block comment. A parser that answered "everything"
     * would pass a test that only listed what must come back.
     */
    public function testTheMethodSinkRosterIsDerivedFromTheCdef(): void
    {
        $synthetic = "int   setsid(void);\n"
            . "int   posix_openpt(int flags);\n"
            . "int   grantpt(int fd);\n"
            . "int   waitpid(int pid, int *status, int options);\n"
            . "int   close(int fd);\n"
            . "int   open(const char *path, int flags);\n"
            . "int   ioctl(int fd, unsigned long request, void *arg);\n"
            . "/* prose that happens to contain int notadecl(int fd) inside it */\n"
            . "int   openpty(int *amaster, int *aslave, char *name, void *t, void *w);\n"
            . "void  cfmakeraw(void *termios_p);\n";

        self::assertSame(
            ['close', 'grantpt', 'ioctl'],
            DescriptorSinkScanner::sinksFromCdef($synthetic),
            'the cdef parser did not answer with exactly the fd-first declarations',
        );

        // THE PARSER'S OWN ALPHABET. Every near-miss above is a GENUINE
        // non-fd-first declaration, so all of them would still be rejected by
        // a parser that recognised the single literal name `fd` and nothing
        // else -- which is what this one did. MEASURED through the shipped
        // parser before this block existed, PHP 8.3.6: given `fsync(int
        // fildes)`, `close(int fd)`, `dup3(int oldfd, int newfd)` and
        // `fchdir(int)`, it answered `['close']` and dropped the other three
        // without a sound. `fildes` is POSIX's own spelling for `fsync`,
        // `ftruncate` and `fstat`; `oldfd`/`newfd` are the `dup` family's;
        // and candy-pty already declares `dup`. A descriptor sink missing
        // from this roster is every call site of it missing from the census.
        self::assertSame(
            ['dup3', 'fsync'],
            DescriptorSinkScanner::sinksFromCdef(
                "int fsync(int fildes);\nint dup3(int oldfd, int newfd);\n",
            ),
            'the cdef parser reads only the literal parameter name `fd`, so a descriptor '
                . 'declared with any of POSIX\'s other spellings for one is silently not a sink',
        );

        // And the REAL cdef, through the same parser. Asserted by membership
        // rather than by set equality: candy-pty owns that file, and a census
        // in candy-core that reds because candy-pty declared a new symbol
        // would be reporting the wrong thing. What must NOT drift is the
        // discrimination -- fd-first in, everything else out.
        $real = DescriptorSinkScanner::methodSinks();
        self::assertNotSame([], $real, 'the real cdef yielded no fd-first symbol at all');

        foreach (['close', 'ioctl', 'fcntl', 'dup', 'tcgetattr', 'tcsetattr'] as $expected) {
            self::assertContains($expected, $real, $expected . ' is fd-first in the cdef but is not in the roster');
        }
        foreach (['open', 'waitpid', 'setsid', 'posix_openpt', 'cfmakeraw'] as $rejected) {
            self::assertNotContains($rejected, $real, $rejected . ' is not fd-first but is in the roster');
        }
        // The three the hand-written alternation invented. If candy-pty ever
        // declares one of them fd-first this assertion is the right place to
        // find out, because a new sink means new roster rows.
        foreach (['read', 'write', 'dup2'] as $imaginary) {
            self::assertNotContains($imaginary, $real, $imaginary . ' is now declared; every call site of it needs a roster row');
        }
    }

    /**
     * A CAST HIDDEN IN A PROPERTY OR AN ARRAY ELEMENT is traced back too, not
     * only one hidden in a local.
     *
     * The trace-back exists because three of the six sites in the original
     * defect family parked the cast a line or two above the sink -- "the same
     * defect with a line break in it", in the scanner's own words. It then
     * only spoke one spelling. MEASURED through the shipped scanner, PHP
     * 8.3.6, on three sources differing in nothing but where the cast was
     * parked: `$fd` traced back and classified INT_CAST_VIA_VARIABLE, while
     * `$this->fd` and `$tty[0]` both came back as the benign VARIABLE, in
     * silence.
     *
     * That is not an abstract gap. Rows in {@see self::ROSTER} are spelled
     * `$this->fd` and `$this->anchorSlaveFd`, and this census leans on the
     * KIND to catch a site whose spelling is unchanged but whose meaning
     * moved. For a property spelling the kind could not move at all. The
     * ARRAY spelling is the one the FIRST census of this family died of.
     *
     * @dataProvider hiddenCastSpellings
     */
    public function testACastHiddenBehindAnyAssignableSpellingIsTracedBack(
        string $spelling,
        string $assignment,
    ): void {
        $fn = DescriptorSinkScanner::FUNCTION_SINKS[0];

        self::assertSame(
            [DescriptorSinkScanner::INT_CAST_VIA_VARIABLE],
            array_column(
                DescriptorSinkScanner::scanSource(
                    "<?php\n" . $assignment . "\n" . $fn . '(' . $spelling . ");\n",
                ),
                'kind',
            ),
            'a cast parked in ' . $spelling . ' was not traced back, so it classifies as the '
                . 'benign shape and no judgement is ever demanded for it',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function hiddenCastSpellings(): iterable
    {
        yield 'a local'          => ['$fd', '$fd = (int) $stream;'];
        yield 'a property'       => ['$this->fd', '$this->fd = (int) $stream;'];
        yield 'an array element' => ['$tty[0]', '$tty[0] = (int) $stream;'];
        yield 'a static property' => ['self::$fd', 'self::$fd = (int) $stream;'];
        yield 'a nested property' => ['$this->pty->fd', '$this->pty->fd = (int) $stream;'];
        yield 'intval in a property' => ['$this->fd', '$this->fd = intval($stream);'];
    }

    /**
     * THE NEGATIVE POLARITY of the test above: the widened trace-back did not
     * buy its reach by calling everything a hidden cast.
     *
     * A trace-back that answered INT_CAST_VIA_VARIABLE for every accessor
     * chain would pass every case in the provider above and would be useless
     * -- it would classify all 28 method rows in the roster as defective and
     * force the kind column to a constant.
     */
    public function testAnAssignmentThatIsNotACastIsStillTheBenignShape(): void
    {
        $fn = DescriptorSinkScanner::FUNCTION_SINKS[0];

        foreach ([
            'a property assigned a genuine descriptor' => ['$this->fd', '$this->fd = $libc->posix_openpt($flags);'],
            'a property assigned an int literal'       => ['$this->fd', '$this->fd = 0;'],
            'a property never assigned at all'         => ['$this->fd', '$unrelated = (int) $stream;'],
            'a DIFFERENT property carrying the cast'   => ['$this->fd', '$this->other = (int) $stream;'],
            'an array element with another index'      => ['$tty[0]', '$tty[1] = (int) $stream;'],
            'a DIFFERENT static property'              => ['self::$fd', 'self::$other = (int) $stream;'],
            'the same name on another class'           => ['self::$fd', 'Other::$fd = (int) $stream;'],
        ] as $because => [$spelling, $assignment]) {
            self::assertSame(
                [DescriptorSinkScanner::VARIABLE],
                array_column(
                    DescriptorSinkScanner::scanSource(
                        "<?php\n" . $assignment . "\n" . $fn . '(' . $spelling . ");\n",
                    ),
                    'kind',
                ),
                $because . ' was reported as a hidden cast, so the trace-back is matching the '
                    . 'wrong assignment and the kind column says nothing',
            );
        }
    }

    /**
     * THE DORMANT BRANCH of the trace-back's shape test, pinned so a future
     * widening cannot make it live in silence.
     *
     * {@see DescriptorSinkScanner::renderedLeftHandSideEndingAt()} refuses a
     * chain that {@see DescriptorSinkScanner::classify()} does not call
     * VARIABLE, and a bare `FOO` is CONSTANT. That refusal is unreachable
     * today for one reason only: scanSource() asks for a trace-back solely
     * when the ARGUMENT classified VARIABLE, and a lone name never does.
     *
     * SCOPE OF WHAT THIS PINS, stated plainly because it is narrower than it
     * looks, and because the first version of this very comment got it wrong
     * and a mutation run said so. The refusal and the gate are REDUNDANT, not
     * layered: either one alone stops a constant being traced. MEASURED by
     * mutation, PHP 8.3.6 --
     *
     *   widen the gate to admit CONSTANT, alone      -> this test SURVIVES
     *   drop the shape test's refusal, alone         -> this test SURVIVES
     *   both at once                                 -> this test is KILLED
     *
     * So it does not guard either defence individually; it guards the
     * PROPERTY those two defences jointly provide, against the day someone
     * simplifies away whichever one they happen to be reading. That is worth
     * having and it is not worth overselling, which is why the table is here
     * instead of a sentence claiming more.
     */
    public function testABareConstantIsNeverTracedBack(): void
    {
        $fn = DescriptorSinkScanner::FUNCTION_SINKS[0];

        self::assertSame(
            [DescriptorSinkScanner::CONSTANT],
            array_column(
                DescriptorSinkScanner::scanSource(
                    "<?php\nFOO = (int) \$stream;\n" . $fn . "(FOO);\n",
                ),
                'kind',
            ),
            'a bare constant argument was traced back to an assignment. The trace-back is '
                . 'reached only for a VARIABLE argument, so either that gate widened or the '
                . 'shape test in renderedLeftHandSideEndingAt() no longer refuses a name -- '
                . 'read its doc-block before changing either.',
        );
    }

    /**
     * A spelling the widened trace-back still cannot reach answers LOUDLY.
     *
     * `static::$fd` breaks the accessor chain on `T_STATIC`, which is not an
     * accessor token. The scanner does NOT quietly call that the benign
     * VARIABLE shape -- it reports UNCLASSIFIED, so the census demands a
     * judgement rather than passing over it. MEASURED, PHP 8.3.6: `self` is a
     * `T_STRING` and rides the chain, `static` is a `T_STATIC` and does not.
     *
     * This is the guard-must-go-red-on-what-it-cannot-parse rule applied to
     * the one gap the widening left, so the gap is a recorded answer instead
     * of a silent hole.
     */
    public function testASpellingTheChainCannotWalkIsUnclassifiedNotBenign(): void
    {
        $fn = DescriptorSinkScanner::FUNCTION_SINKS[0];

        self::assertSame(
            [DescriptorSinkScanner::UNCLASSIFIED],
            array_column(
                DescriptorSinkScanner::scanSource(
                    "<?php\nstatic::\$fd = (int) \$stream;\n" . $fn . "(static::\$fd);\n",
                ),
                'kind',
            ),
            'a spelling the accessor walk cannot express was reported as something other than '
                . 'UNCLASSIFIED. A shape this scanner cannot parse must be reported, never '
                . 'absorbed into the benign VARIABLE bucket.',
        );
    }

    /**
     * A cdef declaration the parser CANNOT classify is a failure, not a skip.
     *
     * The counterpart to the derivation control above, and the half that
     * makes its rejections trustworthy. That test asserts which declarations
     * come back; this one asserts that everything else was actually READ and
     * understood to be a non-descriptor, rather than merely not matching a
     * pattern. Without it, the correct answer and the answer of a parser that
     * had quietly stopped recognising anything are the same list.
     *
     * Each case names its resolution in the exception, because the person who
     * hits one is holding a red census in a package they may never have
     * opened, caused by a one-line change in another.
     *
     * @dataProvider unclassifiableDeclarations
     */
    public function testACdefDeclarationThatCannotBeClassifiedIsAFailure(
        string $declaration,
        string $because,
        string $expectedInMessage,
    ): void {
        try {
            $answer = DescriptorSinkScanner::sinksFromCdef($declaration);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(
                $expectedInMessage,
                $e->getMessage(),
                'the parser refused this declaration without saying what would resolve it',
            );
            self::assertStringContainsString(
                trim($declaration),
                $e->getMessage(),
                'the parser refused a declaration without quoting it, so the reader cannot find it',
            );

            return;
        }

        self::fail(
            'the cdef parser answered [' . implode(', ', $answer) . '] for a declaration it '
            . 'cannot actually classify (' . $because . '): ' . trim($declaration) . ' -- a '
            . 'descriptor sink dropped here is absent from the roster and every call site of it '
            . 'is absent from the census, with nothing going red anywhere.',
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function unclassifiableDeclarations(): iterable
    {
        yield 'an unnamed int parameter' => [
            "int fchdir(int);\n",
            'the type is right and the meaning is unreadable',
            'UNNAMED int',
        ];

        yield 'an int parameter whose name is in neither list' => [
            "int weird(int gadget);\n",
            'the name says nothing either way',
            'DESCRIPTOR_PARAMETER_NAMES',
        ];

        yield 'a scalar of a type the parser does not know' => [
            "int fchdir2(pid_t p);\n",
            'a descriptor hidden behind a typedef is exactly what a rule keyed on `int` misses',
            'firstParameterIsADescriptor',
        ];

        yield 'a parameter list that does not close' => [
            "int broken(int fd;\n",
            'nothing about it can be read at all',
            'could not be parsed',
        ];
    }

    /**
     * THE POSITIVE CONTROL for the test above: the same parser, on the same
     * shapes, with a classifiable name -- so its refusals are a property of
     * the declaration and not of a parser that has started refusing
     * everything.
     *
     * Rule of this file, learned the hard way one level down: a test whose
     * expectation is "it threw" passes just as well against an instrument
     * that always throws.
     */
    public function testTheParserStillClassifiesTheShapesItCanRead(): void
    {
        self::assertSame(
            ['fchdir'],
            DescriptorSinkScanner::sinksFromCdef("int fchdir(int fd);\n"),
            'the parser refuses a NAMED int-first declaration too, so its refusals above say '
                . 'nothing about the declarations that caused them',
        );
        self::assertSame(
            [],
            DescriptorSinkScanner::sinksFromCdef("int weird(int flags);\n"),
            'a known non-descriptor int name no longer resolves, so the two lists are not both live',
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

        // And more than one kind, so neither a scanner stuck on "everything
        // is a cast" nor one stuck on "everything is fine" passes.
        //
        // WHAT THIS USED TO ASSERT: that the TREE contained at least one
        // LITERAL_INT and at least one INT_CAST_VIA_VARIABLE.
        //
        // WHAT IS TRUE NOW: the two INT_CAST_VIA_VARIABLE sites were the last
        // ones in the monorepo and this round closed them, so that assertion
        // became unsatisfiable -- it asserted the continued existence of the
        // very defect the census exists to drive out. A guard whose green
        // depends on a defect surviving is a guard that punishes the fix.
        //
        // WHY THE REASONING STILL EARNS ITS PLACE: "one site of each polarity"
        // is right, and it is why this is not just `assertNotSame([], $found)`
        // one line up. What was wrong was sourcing BOTH polarities from the
        // tree. The negative polarity is now sourced from a fixture, where it
        // is a property of the classifier and cannot be legislated away by
        // someone fixing a call site; the tree still has to show at least two
        // distinct kinds, which is the part only the tree can say.
        $kinds = array_column($found, 'kind');
        self::assertContains(DescriptorSinkScanner::LITERAL_INT, $kinds);
        self::assertGreaterThan(
            1,
            \count(array_unique($kinds)),
            'every descriptor site in the tree classified the same way; a classifier stuck on '
                . 'one answer would look exactly like this',
        );

        // The negative polarity, from a fixture rather than from the tree.
        // Built from the scanner's own constants so the literal call text
        // never appears in this file -- see the control test's doc-block.
        $fn = DescriptorSinkScanner::FUNCTION_SINKS[0];
        self::assertSame(
            [DescriptorSinkScanner::INT_CAST_VIA_VARIABLE],
            array_column(
                DescriptorSinkScanner::scanSource(
                    "<?php\n" . '$fd = (int) $this->stream;' . "\n" . $fn . "(\$fd);\n",
                ),
                'kind',
            ),
            'the scanner can no longer see a cast hidden in the assignment above a sink, so '
                . 'its silence about the tree is a property of the instrument',
        );
    }

    /**
     * A spelling that occurs twice in ONE file is two rows, and the FIRST
     * keeps its own kind.
     *
     * A pin for {@see self::indexByKey()}, driven with hand-built hits rather
     * than with the tree.
     *
     * WHAT THIS USED TO SAY: "the tree has 13 sites and 13 distinct spellings,
     * so it cannot exercise this at all". WHAT IS TRUE NOW: the opposite. The
     * tree does exercise it -- rows in {@see self::ROSTER} carry ordinals --
     * and the sentence was already false when the METHOD spelling landed.
     *
     * WHY THE FIXTURE STILL EARNS ITS PLACE, which is the part the old reason
     * got backwards: being exercised BY THE TREE is not the same as being
     * pinned. Tree coverage here is incidental and revocable -- it lasts
     * exactly as long as some library happens to spell two sinks the same way
     * in one file, and it vanishes on a refactor nobody connects to this test.
     * A hand-built pair holds the behaviour still regardless, and it fails
     * pointing at indexByKey() rather than at whichever library moved.
     *
     * The second row asserts the ORDINAL, and the first asserts that the
     * earlier hit was not overwritten by the later one -- which is the half
     * that mattered, because the collapse reported a defective site placed
     * above a correct one AS correct.
     */
    public function testTwoSitesSpelledIdenticallyInOneFileEachGetTheirOwnRow(): void
    {
        $indexed = self::indexByKey(
            [
                [
                    'sink' => 'posix_isatty', 'kind' => DescriptorSinkScanner::INT_CAST_VIA_VARIABLE,
                    'argument' => '$fd', 'file' => '/root/lib/src/A.php', 'line' => 10,
                ],
                [
                    'sink' => 'posix_isatty', 'kind' => DescriptorSinkScanner::VARIABLE,
                    'argument' => '$fd', 'file' => '/root/lib/src/A.php', 'line' => 20,
                ],
                [
                    'sink' => 'posix_isatty', 'kind' => DescriptorSinkScanner::LITERAL_INT,
                    'argument' => '$fd', 'file' => '/root/lib/src/A.php', 'line' => 30,
                ],
                // Same spelling, DIFFERENT file: no ordinal, the file is
                // already in the key.
                [
                    'sink' => 'posix_isatty', 'kind' => DescriptorSinkScanner::VARIABLE,
                    'argument' => '$fd', 'file' => '/root/lib/src/B.php', 'line' => 10,
                ],
            ],
            '/root',
        );

        self::assertSame(
            [
                'lib/src/A.php::posix_isatty($fd)'    => DescriptorSinkScanner::INT_CAST_VIA_VARIABLE,
                'lib/src/A.php::posix_isatty($fd) #2' => DescriptorSinkScanner::VARIABLE,
                'lib/src/A.php::posix_isatty($fd) #3' => DescriptorSinkScanner::LITERAL_INT,
                'lib/src/B.php::posix_isatty($fd)'    => DescriptorSinkScanner::VARIABLE,
            ],
            array_map(static fn (array $row): string => $row['kind'], $indexed),
            'same-spelled sinks in one file collapsed into one row, so an unjudged site beside '
                . 'a judged one is invisible and the later of two kinds wins',
        );
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

        $root = $this->monorepoRoot();
        $hits = [];

        foreach ($this->presentLibraries() as $lib) {
            foreach (DescriptorSinkScanner::scanTree($root . '/' . $lib . '/src') as $hit) {
                $hits[] = $hit;
            }
        }

        return self::$scanned = self::indexByKey($hits, $root);
    }

    /**
     * Index the scanner's hits by `<lib>/<path>::<sink>(<argument>)`, and give
     * a REPEATED spelling in one file its own key rather than letting the
     * later hit overwrite the earlier one.
     *
     * WHAT THIS USED TO BE: a plain `$found[$key] = ...`, on the reasoning
     * that the spelling is what the census is about.
     *
     * WHAT IS TRUE NOW: two sites in one file CAN share a spelling, and the
     * plain assignment collapsed them. MEASURED (round-53 mutation R4b): a
     * second, entirely unjudged `posix_isatty(STDOUT)` added to
     * candy-shine/src/Theme.php, whose one rostered site is spelled
     * identically, left this census green -- 4 tests, 22 assertions, rc 0.
     * The class doc-block's "a NEW site fails because it is unjudged" was
     * false for that shape. Worse, because the later hit won, two same-spelled
     * sites of DIFFERENT kinds reported whichever came last: a defective site
     * placed above a correct one read as correct.
     *
     * WHY THE ORIGINAL REASONING STILL EARNS ITS PLACE: keying on the spelling
     * rather than on a line number is right, and for the stated reason --
     * line numbers rot within a round and would make every roster row a
     * maintenance chore that teaches nothing. The conclusion drawn from it was
     * what was wrong. So the key stays the spelling and gains an ORDINAL,
     * which is stable under every edit that does not add or remove a
     * same-spelled sibling.
     *
     * @param  list<array{sink:string, kind:string, argument:string, file:string, line:int}> $hits
     * @return array<string, array{sink:string, kind:string, argument:string}>
     */
    private static function indexByKey(array $hits, string $root): array
    {
        $found = [];

        foreach ($hits as $hit) {
            $relative = substr($hit['file'], \strlen($root) + 1);
            $key      = $relative . '::' . $hit['sink'] . '(' . $hit['argument'] . ')';

            $unique   = $key;
            $ordinal  = 1;
            while (isset($found[$unique])) {
                $unique = $key . ' #' . (++$ordinal);
            }

            $found[$unique] = [
                'sink'     => $hit['sink'],
                'kind'     => $hit['kind'],
                'argument' => $hit['argument'],
            ];
        }

        return $found;
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
