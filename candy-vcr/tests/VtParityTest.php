<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Screen\Scrollback;
use SugarCraft\Vt\Sgr\Sgr;
use SugarCraft\Vt\Sgr\UnderlineStyle;
use SugarCraft\Vt\Terminal as RendererTerminal;
use SugarCraft\Vt\Terminal\Terminal as EmulatorTerminal;

/**
 * Differential guard between the two cell-grid consumers of candy-ansi's
 * parser that candy-vcr depends on:
 *
 *  - {@see RendererTerminal} (`SugarCraft\Vt\Terminal`) — the vcr renderer
 *    path: candy-ansi Parser + HandlerAdapter + Parser\CsiHandlerImpl.
 *    Frame rendering (Render\Renderer, Encode\TapeToGif) feeds recorded
 *    bytes HERE.
 *  - {@see EmulatorTerminal} (`SugarCraft\Vt\Terminal\Terminal`) — the full
 *    emulator: candy-ansi Parser + Handler\ScreenHandler stack.
 *    ScreenAssertion and `vt`-style emulation HERE.
 *
 * Both paths consume the SAME recorded cassette bytes through the SAME
 * candy-ansi state machine, but through two independently written handler
 * implementations — the duplication the audit calls out. Until the unified
 * VT engine refactor lands (out of scope), these tests are the guard that
 * the two engines agree on the cell grid.
 *
 * Equivalence is asserted over a shared NORMALISED grid: char, palette
 * index (both models reduced to 0-255 indices), and the five attributes the
 * renderer model has bits for. #1417 corrected the EMULATOR (DECAWM
 * default, deferred/phantom wrap, RIS/DECSTR, IL/DL, SCS, reply channel);
 * this track brought the RENDERER path (Parser\CsiHandlerImpl) to the same
 * semantics — deferred wrap with DECAWM, IL/DL cursor homing, absolute CUP
 * clamping, DECTCEM, the 4/4:3 colon distinction via Parser::subparams(),
 * and consumption of SGR 29/58/59 and the 38;2/48;2 triplets. The former
 * divergence tripwires are graduated into {@see curatedStreams} plus the
 * per-feature parity pins below. What remains in the catalogue are genuine
 * REPRESENTATION limits (truecolor value, blink/dim/hidden, 58 colour
 * storage, tab stops, combining marks, BCE erases) and one ADAPTER limit
 * (the explicit `CSI 0 L/M` count no-op, clamped to 1 by candy-ansi's
 * HandlerAdapter before the renderer can see it).
 */
final class VtParityTest extends TestCase
{
    private const COLS = 20;
    private const ROWS = 6;

    /**
     * Representation limits — not tested for equality on either side:
     *  - blink / dim / hidden SGR — the renderer cell has no such bits.
     *  - SGR 58/59 underline colour — both engines consume the
     *    specification without corrupting the pen, but neither pen STORES
     *    the colour (no slot in Sgr or the renderer Cell).
     *  - HT / CHT / CBT tab stops — renderer moves by $count, emulator
     *    advances to stops. ESC H (HTS) sets a stop the emulator keeps and
     *    the renderer models no table for — see the HTS parity pin.
     *  - combining marks — attached into the char by the renderer, a
     *    dedicated field by the emulator.
     *
     * GRADUATED to agreement by the unified Cell + renderer ESC dispatch
     * ({@see \SugarCraft\Vt\Parser\RendererHandler}) — no longer a gap:
     *  - truecolor SGR 38;2 / 48;2 — the renderer now keeps an exact RGB slot
     *    (Cell::$fgTruecolor/$bgTruecolor), matching the emulator value-for-
     *    value ({@see testParityTruecolorAgreesOnBothPaths}).
     *  - the DEC `ESC # 3`/`# 4`/`# 5`/`# 6` line renditions (DECDHL/DECSWL/
     *    DECDWL) — both engines stamp the cursor line's cells with the same
     *    Rendition and apply the DECDWL extra-column rule identically.
     *  - ESC 7/8 (DECSC/DECRC), ESC D/E/M (IND/NEL/RI), ESC c (RIS) — once
     *    dropped by candy-ansi's empty escDispatch, now routed to the renderer.
     *  - REP (`CSI b`) — replays the last printable on the renderer path;
     *    the emulator does not dispatch it at all.
     *  - BCE erases — the emulator fills ED/EL/ECH with the pen BACKGROUND
     *    (xterm BCE, w4-vt); the renderer blanks with default cells (pinned below).
     *  - `CSI 0 L` / `CSI 0 M` — no-op on the emulator; candy-ansi's
     *    HandlerAdapter clamps the count to 1 before the renderer sees it
     *    (pinned below).
     *  - `CSI ? N L` / `CSI ? N M` — the emulator rejects prefixed IL/DL
     *    (`prefix === 0` guard); the renderer acts on them because the
     *    adapter strips the prefix before dispatch (pinned below).
     *  - DECOM (`? 6`) and the alt-screen buffers (`47/1047/1048/1049`) —
     *    genuine emulator features with no renderer implementation (pinned
     *    below). ESC 7/8 and CNL/CPL/CHA/VPA are not dispatched by the
     *    adapter on either path, so they stay parity no-ops on both sides.
     */
    private const DIVERGENCE_NOTE = 'see VtParityTest::DIVERGENCE_NOTE';

    /**
     * @return array<string, array{0: string}>
     */
    public static function curatedStreams(): array
    {
        return [
            'plain text' => ['Hello, world!'],
            'carriage return and line feed' => ["alpha\r\nbeta\r\ngamma"],
            'backspace overwrites' => ["abc\bX"],
            'cursor movements' => ["\x1b[3;5Hxyz\x1b[2A\x1b[1D\x1b[2B\x1b[4C!"],
            'hvp aliases cup' => ["\x1b[4;3fW"],
            'erase display below' => ["0123456789\x1b[H\x1b[0Jabc"],
            'erase display all' => ["abcdef\x1b[2J"],
            'erase display above' => ["abcdef\r\nghijkl\r\nmn\x1b[2;3H\x1b[1J"],
            'erase line right' => ["abcdef\x1b[1;3H\x1b[0K"],
            'erase line left' => ["abcdef\x1b[1;3H\x1b[1K"],
            'erase line all' => ["abcdef\x1b[1;2H\x1b[2K"],
            'sgr colors' => ["\x1b[31mred\x1b[32mgreen\x1b[34mblue\x1b[39mdefg\x1b[0mfin"],
            'sgr bright colors' => ["\x1b[91mbright-red\x1b[100mz\x1b[39;49mplain"],
            'sgr 256 indexed' => ["\x1b[38;5;178mX\x1b[0mY\x1b[48;5;21mZ\x1b[49m!"],
            'sgr 256 out-of-range index clamps' => ["\x1b[38;5;300mA\x1b[0m\x1b[48;5;999mB\x1b[0mC"],
            'sgr attrs on/off' => ["\x1b[1mB\x1b[22mN\x1b[3mI\x1b[23mN\x1b[4mU\x1b[24mN\x1b[7mR\x1b[27mN"],
            'sgr 58 does not corrupt grid' => ["\x1b[58;5;178mU\x1b[59mN"],
            'sgr 58 truecolour form does not corrupt grid' => ["\x1b[58;2;148;199;255mU\x1b[59mN"],
            'sgr 21 passthrough' => ["\x1b[21mD\x1b[0mE"],
            'sgr unknown params keep pen' => ["\x1b[53mO\x1b[73mS\x1b[31mR\x1b[0mZ"],
            'scrolling at bottom' => ["1\r\n2\r\n3\r\n4\r\n5\r\n6\r\n7\r\n8"],
            'scroll up down' => ["AAAAAAAAAA\r\nBBBBBBBBBB\r\nCCCCCC\r\nDDDDDD\r\n\x1b[5;1H\x1b[2SZZ"],
            'insert delete chars' => ["AAAABBBB\x1b[1;3H\x1b[2@\x1b[1;8H\x1b[2P"],
            'save restore cursor' => ["\x1b[3;4H\x1b[s\x1b[1;1Hxxxxx\x1b[uZZ"],
            'dectcem grid invariant' => ["\x1b[?25lX\x1b[?25hY\x1b[?25l\x1b[?25hZ"],
            'decawm long run wraps identically' => ["\x1b[?7h" . str_repeat('q', 45)],
            'decawm extreme cup agrees' => ["\x1b[?7h\x1b[999;999HZ"],
            'decawm motion at line end' => ["\x1b[?7h" . str_repeat('x', 20) . "\x1b[2C\x1b[1D\x1b[1A\x1b[3;4H\x1b[2B\x1b[1DAB"],
            'decawm backspace and fill at line end' => ["\x1b[?7h" . str_repeat('y', 20) . "\x1b[1D\x1b[0KY\x1b[1P\x1b[2@Z"],
            'long run wraps on default modes' => [str_repeat('x', 21)],
            'extreme cup without explicit decawm' => ["\x1b[999;999HZ"],
            'extreme cup print at the corner scrolls' => ["\x1b[999;999HZW"],
            'decawm off clips at right margin' => ["\x1b[?7l" . str_repeat('z', 25) . "\x1b[?7h" . 'www'],
            'phantom wrap survives erase then motion' => ["\x1b[?7l" . str_repeat('v', 20) . "\x1b[0K\x1b[C\x1b[?7hW"],
            'insert line then write' => ["AAAA\r\nBBBB\x1b[2;1H\x1b[1LCC"],
            'insert two lines then write' => ["AAAA\r\nBBBB\r\nCCCC\x1b[2;1H\x1b[2LXY"],
            'delete line then write' => ["AAAA\r\nBBBB\r\nCCCC\x1b[2;1H\x1b[1MDD"],
            'strike off' => ["\x1b[9mS\x1b[29mN"],
            'underline colon vs semicolon' => ["\x1b[4:3mC\x1b[0m\x1b[4;3mS\x1b[0mE"],
            'decstbm cup is absolute' => ["\x1b[2;4r\x1b[1;1HA"],
            'colon one underline consumes subparam' => ["\x1b[4:1mA"],
            'region scroll at phantom wrap' => [
                "\x1b[2;4r\x1b[4;20H" . str_repeat('a', 21) . 'BCDEFGHIJKLMNOPQRSTUVWX',
            ],
            'phantom straddles save and restore' => [str_repeat('x', 20) . "\x1b[s" . "A\x1b[u" . 'B'],
            'mouse modes multi set reset' => ["\x1b[?1000;1002;1006h\x1b[?1006lreport-on\x1b[0m!"],
            'mixed output' => ["\x1b[2J\x1b[H\x1b[1;1HHeader\r\n\x1b[36mvalue:\x1b[39m 42\x1b[K\r\nfooter\x1b[s\x1b[99;99H\x1b[u!"],
            'wide sgr runs in one dispatch' => ["\x1b[1;31;42mx\x1b[m\x1b[0my"],
            'truecolor foreground and background' => ["\x1b[38;2;10;20;30mR\x1b[48;2;200;100;50mG\x1b[39;49mB"],
            'truecolor then palette overrides' => ["\x1b[38;2;1;2;3mA\x1b[31mB\x1b[0mC"],
            'decdwl double-width line' => ["\x1b#6abcd"],
            'decdhl top half then print' => ["\x1b[3;1H\x1b#3AB"],
            'decdhl bottom then decswl clears' => ["\x1b#4\x1b#5XY"],
            'esc index and next line' => ["A\x1bDB\x1bEC"],
            'esc reverse index' => ["\x1b[2;1HA\x1bMB"],
            'esc save restore cursor' => ["\x1b[3;4H\x1b7\x1b[1;1Hwwww\x1b8Z"],
            'esc hard reset' => ["hello\x1bcW"],
        ];
    }

    // ------------------------------------------------------------------
    // Per-escape emulator↔renderer parity. Each DEC line rendition and
    // each two-byte ESC the renderer now handles (via
    // {@see \SugarCraft\Vt\Parser\RendererHandler}) is pinned individually
    // so a divergence names its own escape instead of hiding in a
    // full-grid diff. The sequences also appear in {@see curatedStreams}.
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function lineRenditionStreams(): array
    {
        return [
            'DECDHL top `ESC # 3`' => ["\x1b[2;1H\x1b#3AB", 1],
            'DECDHL bottom `ESC # 4`' => ["\x1b[3;1H\x1b#4Z", 2],
            'DECSWL single `ESC # 5`' => ["\x1b#3\x1b#5Q", 0],
            'DECDWL double `ESC # 6`' => ["\x1b#6ab", 3],
        ];
    }

    #[DataProvider('lineRenditionStreams')]
    public function testLineRenditionParity(string $bytes, int $expectedRendition): void
    {
        $emulator = self::normaliseEmulator(self::feedEmulator($bytes));
        $renderer = self::normaliseRenderer(self::feedRenderer($bytes));

        self::assertSame($emulator, $renderer, 'line rendition must agree for: ' . self::describe($bytes));

        // And the stamped row really carries the rendition (not silently lost).
        $row = $expectedRendition === 1 ? 1 : ($expectedRendition === 2 ? 2 : 0);
        $cell = self::feedEmulator($bytes)->screen()->cell($row, 0);
        self::assertSame($expectedRendition, $cell->rendition->value);
    }

    public function testDecdwlDoubleWidthOccupiesTwoColumns(): void
    {
        // DECDWL (`ESC # 6`) then 'a' must claim TWO columns on BOTH engines —
        // the observable consequence of the double-width line rendition.
        $emulator = self::feedEmulator("\x1b#6aZ");
        $renderer = self::feedRenderer("\x1b#6aZ");

        self::assertSame('a', self::emulatorCharAt($emulator, 0, 0));
        self::assertSame('Z', self::emulatorCharAt($emulator, 0, 2), 'DECDWL: glyph advanced two columns');
        self::assertSame('a', self::charAt($renderer, 0, 0));
        self::assertSame('Z', self::charAt($renderer, 0, 2), 'renderer: same two-column advance');
    }

    public function testEscRosterParityOnRendererAndEmulator(): void
    {
        // The two-byte ESC roster candy-ansi's HandlerAdapter used to swallow
        // on the renderer. IND/NEL/RI move the cursor; DECSC/DECRC park and
        // restore it; RIS clears the grid. All now reach the renderer, so a
        // printed marker lands identically on both engines.
        $cases = [
            "A\x1bDB" => 'IND',
            "A\x1bEB" => 'NEL',
            "\x1b[3;1HA\x1bMB" => 'RI',
            "\x1b[3;4H\x1b7Q\x1b8Z" => 'DECSC/DECRC',
            "junk\x1bcN" => 'RIS',
        ];
        foreach ($cases as $bytes => $label) {
            self::assertSame(
                self::normaliseEmulator(self::feedEmulator($bytes)),
                self::normaliseRenderer(self::feedRenderer($bytes)),
                "ESC roster divergence: {$label}",
            );
        }
    }

    #[DataProvider('curatedStreams')]
    public function testCuratedStreamsProduceIdenticalCellGrids(string $bytes): void
    {
        self::assertSame(
            self::normaliseEmulator(self::feedEmulator($bytes)),
            self::normaliseRenderer(self::feedRenderer($bytes)),
            'Renderer path and emulator path must agree on the cell grid for: ' . self::describe($bytes),
        );
    }

    public function testSeededRandomStreamsProduceIdenticalCellGrids(): void
    {
        foreach ([0xC0FFEE, 0xBEEF, 0xDEAD, 1, 42] as $seed) {
            $bytes = self::randomStream($seed);
            self::assertSame(
                self::normaliseEmulator(self::feedEmulator($bytes)),
                self::normaliseRenderer(self::feedRenderer($bytes)),
                'Seeded stream (seed=' . dechex($seed) . ') diverged: ' . self::describe($bytes),
            );
        }
    }

    public function testByteSplitFeedIsSelfConsistentOnBothEngines(): void
    {
        // The candy-ansi parser is shared, so whole-feed vs byte-split must be
        // identical on EACH engine; a state-carry regression in the parser
        // would break exactly one side and skew the parity baseline.
        $bytes = "\x1b[38;5;21mSplit\x1b[0m \x1b[1;31;42mstreaming\x1b[m test \x1b[?1000;1006h\x1b[4;3mends\x1b[24m.";

        self::assertSame(
            self::normaliseEmulator(self::feedEmulator($bytes)),
            self::normaliseEmulator(self::feedInChunks($bytes, 1)),
        );
        self::assertSame(
            self::normaliseRenderer(self::feedRenderer($bytes)),
            self::normaliseRenderer(self::feedInChunksRenderer($bytes, 1)),
        );
    }

    // ------------------------------------------------------------------
    // Graduated tripwires — each sequence below was pinned in this
    // catalogue as a place where the two engines DISAGREED. The emulator
    // fixes (#1417) and the renderer-path parity fix (this PR) brought them
    // together, so each now asserts AGREEMENT, per feature, with a specific
    // failure message; the sequences also live in {@see curatedStreams}.
    // What is left in the catalogue are representation/adapter limits that
    // a handler change cannot close.
    // ------------------------------------------------------------------

    public function testParityDectcemMatchesEmulator(): void
    {
        // DECTCEM: `CSI ? 25 h` shows the cursor, `l` hides it — the
        // renderer used to invert this.
        $emulator = self::feedEmulator("\x1b[?25l");
        self::assertFalse($emulator->mode()->cursorVisible, 'emulator tracks DECTCEM correctly');
        $renderer = self::feedRenderer("\x1b[?25l");
        self::assertFalse($renderer->cursor()->visible, 'renderer: ?25l hides the cursor');

        self::assertTrue(
            self::feedRenderer("\x1b[?25l\x1b[?25h")->cursor()->visible,
            'renderer: ?25h shows the cursor',
        );
        self::assertTrue(self::feedEmulator("\x1b[?25l\x1b[?25h")->mode()->cursorVisible);
    }

    public function testParityCupIsAbsoluteUnderDecstbmOnBothPaths(): void
    {
        // With DECSTBM 2..4 active, `CSI 1;1H` addresses screen line 1 on
        // BOTH engines: the renderer no longer clamps CUP into the scroll
        // region, and DECSTBM no longer yanks the renderer cursor either.
        $bytes = "\x1b[2;4r\x1b[1;1HA";

        self::assertSame('A', self::charAt(self::feedRenderer($bytes), 0, 0), 'renderer prints at absolute line 1');
        self::assertSame(
            'A',
            self::emulatorCharAt(self::feedEmulator($bytes), 0, 0),
            'emulator prints at absolute line 1',
        );
    }

    public function testParitySemicolonFourThreeIsIndependentSgrsOnBothPaths(): void
    {
        // `CSI 4 ; 3 m` is underline + italic (two independent SGRs);
        // `CSI 4 : 3 m` is one parameter with a curly sub-parameter.
        // candy-ansi flattens both to [4, 3]; both engines now consult
        // Parser::subparams() for the colon flags instead of peeking, so
        // the forms keep their distinct meanings on either path.
        $bytes = "\x1b[4;3mX\x1b[0m";
        self::assertSame(4 | 2, self::rendererAttrs(self::feedRenderer($bytes), 0, 0), 'renderer: underline + italic');
        self::assertSame(
            4 | 2,
            self::emulatorAttrs(self::feedEmulator($bytes), 0, 0),
            'emulator no longer folds the semicolon form into 4:3',
        );

        $bytes = "\x1b[4:3mX\x1b[0m";
        self::assertSame(
            4,
            self::rendererAttrs(self::feedRenderer($bytes), 0, 0),
            'renderer: colon form is underline only',
        );
        self::assertSame(4, self::emulatorAttrs(self::feedEmulator($bytes), 0, 0), 'emulator: same');
    }

    public function testParityExtremeCupClampsToBufferCornerOnBothPaths(): void
    {
        // `CSI 999;999H` then a print WITHOUT touching DECAWM: both engines
        // now default auto-wrap ON, clamp the cursor at the last cell and
        // drop Z there — the renderer no longer advances past the corner
        // and scrolls one line early. The follow-up print that consumes the
        // armed phantom (scroll at the corner) is pinned in the curated
        // corpus ('extreme cup print at the corner scrolls').
        $bytes = "\x1b[999;999HZ";

        self::assertSame('Z', self::charAt(self::feedRenderer($bytes), self::ROWS - 1, self::COLS - 1));
        self::assertSame('Z', self::emulatorCharAt(self::feedEmulator($bytes), self::ROWS - 1, self::COLS - 1));
    }

    public function testParityDefaultModesAutoWrapLongRuns(): void
    {
        // DECAWM defaults ON on both engines (#1417 fixed the emulator's
        // mode default; this PR made the renderer honour the mode at all).
        // A 20-glyph run arms the phantom cell on both, and the 21st wraps
        // onto the next line in both grids instead of being dropped.
        $emulator = self::feedEmulator(str_repeat('x', self::COLS));
        self::assertTrue($emulator->isWrapPending(), 'emulator arms the deferred wrap at the right margin');
        $renderer = self::feedRenderer(str_repeat('x', self::COLS));
        self::assertTrue($renderer->isWrapPending(), 'renderer mirrors the phantom flag');

        $bytes = str_repeat('x', 21);
        self::assertSame('x', self::emulatorCharAt(self::feedEmulator($bytes), 1, 0), '21st glyph wraps, not dropped');
        self::assertSame('x', self::charAt(self::feedRenderer($bytes), 1, 0), 'renderer: same');
    }

    public function testParityStrikeOffResetsPenOnBothPaths(): void
    {
        // SGR 29 (strikethrough off) resets the pen in both engines now the
        // renderer carries the arm. 21/25/28 are parity no-ops either way:
        // neither model stores double-underline/blink/hidden bits, so the
        // emulator's fold and the renderer's fall-through agree under the
        // normalisation mask.
        $bytes = "\x1b[9mS\x1b[29mN";

        self::assertSame(0, self::rendererAttrs(self::feedRenderer($bytes), 0, 1), 'renderer: strike off');
        self::assertSame(0, self::emulatorAttrs(self::feedEmulator($bytes), 0, 1), 'emulator: strike off');
    }

    public function testParityInsertDeleteLinesOnBothPaths(): void
    {
        // CSI L / CSI M shift lines on BOTH engines, home the cursor to
        // column 0 and drop the phantom flag (VT500 §IL/§DL, mirrored by
        // #1417's emulator implementation and this PR's renderer cursor
        // handling). Grid equality over the streams lives in the curated
        // corpus; this pin keeps the cursor-homing intent explicit.
        $bytes = "AAAA\r\nBBBB\x1b[2;1H\x1b[1L";
        $renderer = self::feedRenderer($bytes);
        self::assertSame(' ', self::charAt($renderer, 1, 0), 'renderer: blank line inserted at cursor row');
        self::assertSame('B', self::charAt($renderer, 2, 0), 'renderer: content shifted down');
        self::assertSame(' ', self::emulatorCharAt(self::feedEmulator($bytes), 1, 0), 'emulator: blank line inserted');

        $bytes = "AAAA\r\nBBBB\r\nCCCC\x1b[2;1H\x1b[1M";
        self::assertSame('C', self::charAt(self::feedRenderer($bytes), 1, 0), 'renderer: lines shifted up');
        self::assertSame('C', self::emulatorCharAt(self::feedEmulator($bytes), 1, 0), 'emulator: lines shifted up');
    }

    public function testParityUnderlineColourIsConsumedByBothEngines(): void
    {
        // candy-core EMITS `58;5;N` (Util/Color::toUnderline). Both SGR
        // consumers now carry a 58 arm (renderer CsiHandlerImpl, emulator
        // Handler\SgrHandler) that eats the whole colour specification
        // without touching the pen — the shared misparse this catalogue
        // pinned is gone. The underline COLOUR itself stays unrepresented
        // on both pens (no slot in Sgr or the renderer Cell), so grid
        // equality is asserted, not colour storage.
        $bytes = "\x1b[58;5;33mU";
        self::assertSame(
            'I7',
            self::rendererFg(self::feedRenderer($bytes), 0, 0),
            'renderer: 33 no longer eats the fg',
        );
        self::assertSame(
            'I7',
            self::emulatorFg(self::feedEmulator($bytes), 0, 0),
            'emulator: 33 no longer eats the fg',
        );

        $bytes = "\x1b[58;2;148;199;255mU\x1b[0m";
        self::assertSame('I7', self::rendererFg(self::feedRenderer($bytes), 0, 0), 'renderer: truecolor form consumed');
        self::assertSame('I7', self::emulatorFg(self::feedEmulator($bytes), 0, 0), 'emulator: truecolor form consumed');
    }

    // ------------------------------------------------------------------
    // Remaining catalogue — genuine representation/adapter limits. Each
    // stays a tripwire: when the model gains the missing slot (or the
    // adapter contract changes), the pinned value shifts and the test
    // fails; graduate the sequence then.
    // ------------------------------------------------------------------

    public function testParityTruecolorAgreesOnBothPaths(): void
    {
        // candy-core EMITS `38;2;R;G;B` / `48;2;R;G;B` at truecolor profiles
        // (Util/Color::toSgr). Both engines now STORE the exact value — the
        // emulator in its Sgr {@see Color}, the renderer in
        // {@see \SugarCraft\Vt\Cell::$fgTruecolor}/$bgTruecolor — so the grids
        // agree VALUE-FOR-VALUE, not merely on "some truecolour". The former
        // "renderer has no RGB slot" catalogue divergence is closed by the
        // unified Cell (it used to pin the renderer at the default pen 'I7').
        $bytes = "\x1b[38;2;255;0;0;48;2;0;128;255mX\x1b[0mY";
        $renderer = self::feedRenderer($bytes);
        $emulator = self::feedEmulator($bytes);

        self::assertSame('TC:' . 0xFF0000, self::rendererFg($renderer, 0, 0), 'renderer stores the exact fg RGB');
        self::assertSame(self::emulatorFg($emulator, 0, 0), self::rendererFg($renderer, 0, 0), 'fg RGB agrees');
        // The triplet must not leak into attributes or shift the grid:
        self::assertSame('X', self::charAt($renderer, 0, 0));
        self::assertSame('Y', self::charAt($renderer, 0, 1));
        self::assertSame(0, self::rendererAttrs($renderer, 0, 0), 'no stray attributes from 38;2/48;2');
        self::assertSame('Y', self::emulatorCharAt($emulator, 0, 1), 'chars agree on both engines');
        // Whole-grid parity now holds for a truecolour stream (incl. the bg RGB).
        self::assertSame(
            self::normaliseEmulator($emulator),
            self::normaliseRenderer($renderer),
            'a truecolour run renders an identical normalised grid on both engines',
        );
    }

    public function testCataloguedDivergenceExplicitZeroIlDlCountsDiffer(): void
    {
        // `CSI 0 L` / `CSI 0 M` are no-ops on the emulator (ECMA-48 default
        // parameter, pinned by #1417's charm parity guard). The renderer can
        // never see the zero: candy-ansi's Parser\HandlerAdapter clamps
        // every count with `max(1, p0)` before calling CsiHandlerImpl::il().
        // Fixing it means changing the adapter contract for ALL CsiHandler
        // implementors — deferred to a candy-ansi follow-up; no real-world
        // emitter sends `0 L`.
        $bytes = "AAAA\r\nBBBB\x1b[2;1H\x1b[0L";

        self::assertSame(
            'B',
            self::emulatorCharAt(self::feedEmulator($bytes), 1, 0),
            'emulator: explicit 0 L is a no-op',
        );
        self::assertSame(
            ' ',
            self::charAt(self::feedRenderer($bytes), 1, 0),
            'renderer: adapter clamped 0 to 1 — a line was inserted',
        );
    }

    public function testCataloguedDivergenceRepeatLastCharRendererOnly(): void
    {
        // CSI b replays the last printable on the renderer path; the
        // emulator does not dispatch it.
        $bytes = "ab\x1b[3bc";

        self::assertSame('b', self::charAt(self::feedRenderer($bytes), 0, 2), 'renderer: b repeated');
        self::assertSame('c', self::emulatorCharAt(self::feedEmulator($bytes), 0, 2), 'emulator: REP ignored');
    }

    public function testCataloguedDivergenceEmulatorErasesWithActiveBackground(): void
    {
        // xterm BCE: erase cells inherit ONLY the pen's BACKGROUND colour —
        // foreground and attributes reset to default. The w4-vt candy-vt fix
        // removed the pen-foreground bleed, so the emulator's erased-cell fg
        // now agrees with the renderer's default blank. What remains is the
        // background itself: the renderer blanks with a bare default cell,
        // the erases with the pen background (SGR 100 → bright black,
        // index 8) as the erase colour. (candy-vt Parser\CsiHandlerImpl
        // erase — renderer side is the outlier.)
        $bytes = "\x1b[31m\x1b[100m\x1b[1;3H\x1b[2K";
        $renderer = self::feedRenderer($bytes);
        $emulator = self::feedEmulator($bytes);

        // Published-mode caveat: while the w4 BCE fix is unmerged (or the
        // split-repo sync lags), candy-vcr's CI resolves candy-vt from
        // Packagist dev-master, whose erase still bleeds the pen fg into
        // erased cells ('I1'). Scrollback::clear() is the public marker that
        // shipped with the BCE fix, so it discriminates the two engines
        // honestly. Once published dev-master carries the fix the 'I1' arm is
        // dead — delete it and the ternary.
        $bceFixed = self::vtCarriesMarker(Scrollback::class, 'clear');
        self::assertSame('I7', self::rendererFg($renderer, 0, 5), 'renderer: default-pen blank');
        self::assertSame(
            $bceFixed ? 'I7' : 'I1',
            self::emulatorFg($emulator, 0, 5),
            $bceFixed
                ? 'emulator: BCE keeps only the background — fg is default'
                : 'emulator: pre-w4 published vt bleeds the pen fg (stale Packagist dev-master)',
        );
        self::assertSame('I0', 'I' . $renderer->grid()->get(0, 5)->bg, 'renderer: erase drops the pen background');
        self::assertSame('I8', self::emulatorBg($emulator, 0, 5), 'emulator: erase carries SGR 100 as the erase colour');
    }

    public function testCataloguedDivergencePrivatePrefixedIlActsOnRendererOnly(): void
    {
        // `CSI ? 1 L` is not a standard IL and the emulator rejects it via
        // its `prefix === 0` guard. The renderer cannot make that call:
        // candy-ansi's HandlerAdapter dispatches 'L'/'M' without forwarding
        // the private prefix. Same adapter follow-up as the explicit-zero
        // pin above; no known real-world emitter sends it.
        $bytes = "AAAA\r\nBBBB\x1b[2;1H\x1b[?1L";

        self::assertSame(
            'B',
            self::emulatorCharAt(self::feedEmulator($bytes), 1, 0),
            'emulator: prefixed L rejected',
        );
        self::assertSame(
            ' ',
            self::charAt(self::feedRenderer($bytes), 1, 0),
            'renderer: acted on the prefixed L the emulator rejected',
        );
    }

    public function testCataloguedDivergenceDecomOriginModeIsEmulatorOnly(): void
    {
        // `CSI ? 6 h` makes CUP region-relative on the emulator; the renderer
        // ignores mode 6 entirely (region-relative addressing is its own
        // feature, out of this parity pass' DECAWM/IL/DL/CUP scope).
        $bytes = "\x1b[2;4r\x1b[?6h\x1b[1;1HX";

        self::assertSame(
            'X',
            self::emulatorCharAt(self::feedEmulator($bytes), 1, 0),
            'emulator: origin mode — CUP 1;1 lands at the region top',
        );
        self::assertSame(
            'X',
            self::charAt(self::feedRenderer($bytes), 0, 0),
            'renderer: addressing stays absolute',
        );
    }

    public function testCataloguedDivergenceAltScreenIsEmulatorOnly(): void
    {
        // `CSI ? 1049 h` swaps the emulator to the alternate buffer and homes
        // the cursor; the renderer has no second buffer, so the mode is a
        // grid no-op there. This is the most consequential surviving gap for
        // full-screen tapes (candy-vcr itself renders scrollback regions
        // through the emulator path, where it is honoured).
        $bytes = "\x1b[2;2H\x1b[?1049hX";

        self::assertSame(
            'X',
            self::emulatorCharAt(self::feedEmulator($bytes), 0, 0),
            'emulator: alt screen begins fresh at home',
        );
        self::assertSame(
            'X',
            self::charAt(self::feedRenderer($bytes), 1, 1),
            'renderer: printed at the original cursor',
        );
    }

    public function testParityScorcRestoresPositionKeepingVisibilityOnBothPaths(): void
    {
        // DECSC/DECRC restore POSITION only on both engines — a hide between
        // save and restore stays hidden. The renderer initially clobbered
        // live visibility/shape by restoring the whole saved value object;
        // it now mirrors the emulator's field-selective Cursor::restore().
        $bytes = "\x1b[3;5H\x1b[s\x1b[1;1H\x1b[?25l\x1b[u";

        $renderer = self::feedRenderer($bytes);
        $emulator = self::feedEmulator($bytes);

        self::assertSame(2, $renderer->cursor()->row);
        self::assertSame(4, $renderer->cursor()->col);
        self::assertFalse($renderer->cursor()->visible, 'renderer keeps the cursor hidden');
        self::assertFalse($emulator->cursor()->visible, 'emulator keeps the cursor hidden');
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    private static function feedRenderer(string $bytes): RendererTerminal
    {
        return RendererTerminal::new(self::COLS, self::ROWS)->feed($bytes);
    }

    private static function feedInChunksRenderer(string $bytes, int $size): RendererTerminal
    {
        $terminal = RendererTerminal::new(self::COLS, self::ROWS);
        foreach (str_split($bytes, $size) as $chunk) {
            $terminal->feed($chunk);
        }
        return $terminal;
    }

    private static function feedEmulator(string $bytes): EmulatorTerminal
    {
        $terminal = EmulatorTerminal::new(self::COLS, self::ROWS);
        $terminal->feed($bytes);
        return $terminal;
    }

    private static function feedInChunks(string $bytes, int $size): EmulatorTerminal
    {
        $terminal = EmulatorTerminal::new(self::COLS, self::ROWS);
        foreach (str_split($bytes, $size) as $chunk) {
            $terminal->feed($chunk);
        }
        return $terminal;
    }

    /**
     * Normalised renderer grid: rows of `char|fg|bg|attrs|rendition` tokens.
     *
     * @return list<list<string>>
     */
    private static function normaliseRenderer(RendererTerminal $terminal): array
    {
        $grid = $terminal->grid();
        $rows = [];
        for ($r = 0; $r < $grid->rows; $r++) {
            $line = [];
            for ($c = 0; $c < $grid->cols; $c++) {
                $cell = $grid->get($r, $c);
                $line[] = sprintf(
                    '%s|%s|%s|%d|R%d',
                    $cell->char === '' ? ' ' : $cell->char,
                    $cell->fgTruecolor !== null ? 'TC:' . $cell->fgTruecolor : 'I' . $cell->fg,
                    $cell->bgTruecolor !== null ? 'TC:' . $cell->bgTruecolor : 'I' . $cell->bg,
                    $cell->attrs & 0x1F,
                    $cell->rendition->value,
                );
            }
            $rows[] = $line;
        }
        return $rows;
    }

    /**
     * Normalised emulator grid, reduced to the renderer's expressive range:
     * default colour -> theme default slot (7/0), indexed colour -> palette
     * index, truecolor -> `TC:<packed>` (the same 24-bit value the renderer
     * stores in its fg/bgTruecolor slot), plus the DEC line rendition.
     *
     * @return list<list<string>>
     */
    private static function normaliseEmulator(EmulatorTerminal $terminal): array
    {
        $screen = $terminal->screen();
        $rows = [];
        for ($r = 0; $r < self::ROWS; $r++) {
            $line = [];
            for ($c = 0; $c < self::COLS; $c++) {
                $cell = $screen->cell($r, $c);
                $sgr = $cell->sgr();
                $char = $cell->continuation || $cell->grapheme === '' ? ' ' : $cell->grapheme;
                $line[] = sprintf(
                    '%s|%s|%s|%d|R%d',
                    $char,
                    self::normaliseColour($sgr->foreground, 7),
                    self::normaliseColour($sgr->background, 0),
                    self::normaliseAttrs($sgr),
                    $cell->rendition->value,
                );
            }
            $rows[] = $line;
        }
        return $rows;
    }

    private static function normaliseColour(?Color $color, int $themeDefault): string
    {
        if ($color === null || $color->kind === 0) {
            return 'I' . $themeDefault;
        }
        if ($color->kind === 3) {
            return 'TC:' . $color->value;
        }
        return 'I' . $color->value;
    }

    private static function normaliseAttrs(Sgr $sgr): int
    {
        $mask = 0;
        if ($sgr->bold) {
            $mask |= 1;
        }
        if ($sgr->italic) {
            $mask |= 2;
        }
        if ($sgr->underline || $sgr->underlineStyle !== UnderlineStyle::None) {
            $mask |= 4;
        }
        if ($sgr->reverse) {
            $mask |= 8;
        }
        if ($sgr->strikethrough) {
            $mask |= 16;
        }
        return $mask;
    }

    /**
     * Deterministic Park-Miller generator over a parity-safe token alphabet,
     * so CI and local runs diverge identically. Every stream starts with
     * `CSI ? 7 h` (DECAWM) because both engines now default auto-wrap ON and
     * honour it; explicit `?7h`/`?7l` toggles are mixed in to exercise the
     * phantom-cell flag surviving a mid-stream DECAWM flip on both sides.
     * IL/DL graduated into this mix with the renderer parity fix (they fill
     * default cells and home the cursor identically). DECSTBM, tabs, the
     * 38;2/48;2 truecolor PEN VALUES (58;2 joins as a pen-inert consume
     * token) and the engines' one-sided primitives (REP on the renderer
     * only) stay excluded — they live in the divergence catalogue.
     */
    private static function randomStream(int $seed): string
    {
        // Park-Miller minstd: full-period on 2^31-1. The value is consumed
        // through its HIGH bits (>> 16) — the low bits of every multiplicative
        // generator cycle with a short period, which silently starved the
        // modulo-11 move table below of coverage.
        $state = max(1, $seed & 0x7FFFFFFE);
        $lcgNext = static function (int $mod) use (&$state): int {
            $state = (int) (($state * 16807) % 2147483647);
            return (($state >> 16) & 0x7FFF) % $mod;
        };

        $printables = ['x', 'Hello ', '0123 ', str_repeat('p', 23)];
        $penAndMode = [
            "\x1b[31m", "\x1b[100m", "\x1b[38;5;200m", "\x1b[48;5;17m",
            "\x1b[1m", "\x1b[4m", "\x1b[7m",
            "\x1b[58;5;178m", "\x1b[59m", "\x1b[58;2;1;2;3m", "\x1b[21m", "\x1b[0m",
            "\x1b[s", "\x1b[u", "\x1b[?25h", "\x1b[?25l", "\x1b[?1000;1006h", "\x1b[?1006l",
            "\x1b[?7l", "\x1b[?7h",
        ];
        $moves = ['A', 'B', 'C', 'D'];
        $fills = ['J', 'K', '@', 'P', 'S', 'T', 'L', 'M'];
        $penDirtying = array_flip([
            "\x1b[31m", "\x1b[100m", "\x1b[38;5;200m", "\x1b[48;5;17m", "\x1b[1m", "\x1b[4m", "\x1b[7m",
        ]);

        // DECAWM on up front — both engines default it on, but `?7l`/`?7h`
        // toggles come through $penAndMode later. See the method docblock.
        $bytes = "\x1b[?7h";
        $penDirty = false;
        for ($step = 0; $step < 150; $step++) {
            $kind = $lcgNext(5);
            if ($kind === 0) {
                $bytes .= $printables[$lcgNext(count($printables))];
                continue;
            }
            if ($kind === 1) {
                $token = $penAndMode[$lcgNext(count($penAndMode))];
                $bytes .= $token;
                $penDirty = $token === "\x1b[0m" ? false : ($penDirty || isset($penDirtying[$token]));
                continue;
            }
            if ($kind === 2) {
                $bytes .= "\x1b[" . (1 + $lcgNext(4)) . $moves[$lcgNext(count($moves))];
                continue;
            }
            if ($kind === 3) {
                $token = $fills[$lcgNext(count($fills))];
                if (($token === 'J' || $token === 'K') && $penDirty) {
                    // ED/EL blank with the active pen on the emulator but with
                    // default cells on the renderer path (catalogued) — reset
                    // to the common ground first. ICH/DCH/SU/SD/IL/DL insert
                    // default cells on both and stay unguarded.
                    $bytes .= "\x1b[0m";
                    $penDirty = false;
                }
                $bytes .= $token === 'J' || $token === 'K'
                    ? "\x1b[" . $lcgNext(3) . $token
                    : "\x1b[" . (1 + $lcgNext(4)) . $token;
                continue;
            }
            $bytes .= "\x1b[" . (1 + $lcgNext(self::ROWS)) . ';' . (1 + $lcgNext(self::COLS)) . ['H', 'f'][$lcgNext(2)];
        }
        // Reset the pen so the compared grids cannot differ by an attribute
        // still in flight at the last token.
        return $bytes . "\x1b[0m";
    }

    private static function describe(string $bytes): string
    {
        return strlen($bytes) > 120 ? substr(bin2hex($bytes), 0, 240) . '…' : bin2hex($bytes);
    }

    // Per-cell probes used by the divergence catalogue.

    private static function charAt(RendererTerminal $terminal, int $row, int $col): string
    {
        return $terminal->grid()->get($row, $col)->char;
    }

    private static function emulatorCharAt(EmulatorTerminal $terminal, int $row, int $col): string
    {
        $cell = $terminal->screen()->cell($row, $col);
        return $cell->continuation || $cell->grapheme === '' ? ' ' : $cell->grapheme;
    }

    private static function rendererAttrs(RendererTerminal $terminal, int $row, int $col): int
    {
        return $terminal->grid()->get($row, $col)->attrs;
    }

    private static function emulatorAttrs(EmulatorTerminal $terminal, int $row, int $col): int
    {
        return self::normaliseAttrs($terminal->screen()->cell($row, $col)->sgr());
    }

    private static function rendererFg(RendererTerminal $terminal, int $row, int $col): string
    {
        $cell = $terminal->grid()->get($row, $col);

        return $cell->fgTruecolor !== null ? 'TC:' . $cell->fgTruecolor : 'I' . $cell->fg;
    }

    private static function emulatorFg(EmulatorTerminal $terminal, int $row, int $col): string
    {
        return self::normaliseColour($terminal->screen()->cell($row, $col)->sgr()->foreground, 7);
    }

    private static function emulatorBg(EmulatorTerminal $terminal, int $row, int $col): string
    {
        return self::normaliseColour($terminal->screen()->cell($row, $col)->sgr()->background, 0);
    }

    /**
     * Does the candy-vt actually installed in vendor/ carry $method on $marker?
     * Routing through a parameter defeats PHPStan's constant folding: it
     * analyses the body once against `class-string`, so the probe is neither
     * "always true" (linked monorepo vt) nor "always false" (the stale
     * Packagist dev-master the CI matrix resolves) — both vintages are
     * legitimate subjects of this differential test.
     *
     * @param class-string $marker
     */
    private static function vtCarriesMarker(string $marker, string $method): bool
    {
        return \method_exists($marker, $method);
    }
}
