<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Color\Color;
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
 * renderer model has bits for. Sequences whose semantics differ by
 * representation (truecolor, blink/dim/hidden, tab stops, combining marks)
 * are catalogued in {@see DIVERGENCE_NOTE} rather than silently normalised
 * away; sequences where the engines genuinely DISAGREE today are pinned as
 * tripwires by the catalogue tests at the bottom — they fail the moment
 * parity is reached, so the sequence graduates into the curated corpus.
 */
final class VtParityTest extends TestCase
{
    private const COLS = 20;
    private const ROWS = 6;

    /**
     * Representation limits — not tested for equality on either side:
     *  - truecolor SGR 38;2 / 48;2 — the renderer pen has no RGB slot
     *    (asserted as a divergence below, since candy-core emits it).
     *  - blink / dim / hidden SGR — the renderer cell has no such bits.
     *  - SGR 58/59 underline colour — neither pen stores it yet; the
     *    candy-ansi PARSER round-trip is guarded by
     *    candy-ansi/tests/SgrSubparameterTest.php, and parity of the
     *    downstream corruption is asserted here as grid invariance.
     *  - HT / CHT / CBT tab stops — renderer moves by $count, emulator
     *    advances to stops.
     *  - combining marks — attached into the char by the renderer, a
     *    dedicated field by the emulator.
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
            'mouse modes multi set reset' => ["\x1b[?1000;1002;1006h\x1b[?1006lreport-on\x1b[0m!"],
            'mixed output' => ["\x1b[2J\x1b[H\x1b[1;1HHeader\r\n\x1b[36mvalue:\x1b[39m 42\x1b[K\r\nfooter\x1b[s\x1b[99;99H\x1b[u!"],
            'wide sgr runs in one dispatch' => ["\x1b[1;31;42mx\x1b[m\x1b[0my"],
        ];
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
    // Divergence catalogue — each case pins a place where the engines
    // currently DISAGREE. They are tripwires, not endorsements: when the
    // candy-vt owner lands the parity fix, the pinned value changes and the
    // matching test fails — move that sequence into {@see curatedStreams}
    // and delete the catalogue case.
    // ------------------------------------------------------------------

    public function testCataloguedDivergenceDectcemIsInvertedOnRendererPath(): void
    {
        // DECTCEM: `CSI ? 25 h` shows the cursor, `l` hides it.
        $emulator = self::feedEmulator("\x1b[?25l");
        self::assertFalse($emulator->mode()->cursorVisible, 'emulator tracks DECTCEM correctly');

        $renderer = self::feedRenderer("\x1b[?25h");
        self::assertFalse(
            $renderer->cursor()->visible,
            'KNOWN DIVERGENCE (candy-vt Parser\CsiHandlerImpl::decset inverts DECTCEM — ?25h hides instead of '
            . 'showing). When fixed this assertion fails: delete this case; the grid-invariant behaviour is '
            . 'already in the curated corpus.',
        );
    }

    public function testCataloguedDivergenceCupClampsToScrollRegionOnRendererPath(): void
    {
        // With DECSTBM 2..4 active, `CSI 1;1H` addresses screen line 1.
        // The emulator honours that; the renderer path clamps into the
        // scroll region, printing at line 2 instead — and DECSTBM itself
        // yanks the renderer cursor into the region.
        $bytes = "\x1b[2;4r\x1b[1;1HA";
        $renderer = self::feedRenderer($bytes);
        $emulator = self::feedEmulator($bytes);

        self::assertSame('A', self::emulatorCharAt($emulator, 0, 0), 'emulator prints at absolute line 1');
        self::assertSame(' ', self::charAt($renderer, 0, 0), 'renderer never puts it on line 1');
        self::assertSame('A', self::charAt($renderer, 1, 0), 'renderer clamped into the 2..4 region');
    }

    public function testCataloguedDivergenceSemicolonFourThreeMisreadAsSubparamByEmulator(): void
    {
        // `CSI 4 ; 3 m` is underline + italic (two independent SGRs);
        // `CSI 4 : 3 m` is one parameter with a curly sub-parameter.
        // candy-ansi flattens both to [4, 3]; the emulator's heuristic
        // (peek the next slot) reads the SEMICOLON form as the colon form,
        // while the renderer applies both as independent SGRs. The fix is
        // to plumb Parser::subparams() into the emulator — a candy-vt job.
        $bytes = "\x1b[4;3mX\x1b[0m";
        $rendererAttrs = self::rendererAttrs(self::feedRenderer($bytes), 0, 0);
        $emulatorAttrs = self::emulatorAttrs(self::feedEmulator($bytes), 0, 0);

        self::assertSame(4 | 2, $rendererAttrs, 'renderer: underline + italic');
        self::assertSame(4, $emulatorAttrs, 'emulator folded 4;3 into 4:3 — italic bit lost');
    }

    public function testCataloguedDivergenceTruecolorDroppedByRendererPath(): void
    {
        // candy-core emits `38;2;R;G;B` at truecolor profiles
        // (Util/Color::toSgr); the renderer pen only knows 58;5/38;5/16-colour,
        // so the two grids diverge on fg. Representation limit, documented.
        $bytes = "\x1b[38;2;255;0;0mX\x1b[0mY";
        $renderer = self::feedRenderer($bytes);
        $emulator = self::feedEmulator($bytes);

        self::assertSame('I7', self::rendererFg($renderer, 0, 0), 'renderer keeps the default pen');
        self::assertSame('TC', self::emulatorFg($emulator, 0, 0), 'emulator stores truecolor');
    }

    public function testCataloguedDivergenceExtremeCupClampsDifferently(): void
    {
        // `CSI 999;999H` then a print, WITHOUT enabling DECAWM: the emulator
        // clamps the cursor at the last cell (autoWrap defaults to FALSE in
        // candy-vt Mode — see the DECAWM catalogue case below) while the
        // renderer path always wraps, advancing past the bottom-right corner
        // and scrolling one line early — so the character lands one row
        // above the emulator's copy. With `CSI ? 7 h` the two agree (proven
        // in the curated corpus), pinning this to the mode default, not the
        // corner maths.
        $bytes = "\x1b[999;999HZ";

        self::assertSame('Z', self::emulatorCharAt(self::feedEmulator($bytes), self::ROWS - 1, self::COLS - 1));
        self::assertSame('Z', self::charAt(self::feedRenderer($bytes), self::ROWS - 2, self::COLS - 1));
    }

    public function testCataloguedDivergenceEmulatorDefaultsDecawmOff(): void
    {
        // DECAWM (`CSI ? 7 h`) controls auto-wrap on the emulator, and
        // candy-vt defaults `Mode::$autoWrap` to FALSE — while the renderer
        // path always wraps and ignores DECAWM entirely. Any print run that
        // crosses the last column therefore diverges until the program
        // enables DECAWM: the emulator clamps the cursor at the last cell
        // and DROPS the remaining glyphs (xterm, VT and ANSI.SYS all default
        // DECAWM ON; a candy-vt job). With `?7h` emitted, long runs,
        // motion, save/restore and fills at the line end all agree — pinned
        // by the curated corpus cases, so the parity guard below can simply
        // start its streams with `?7h`.
        $bytes = str_repeat('x', 21);
        $emulator = self::feedEmulator($bytes);
        $renderer = self::feedRenderer($bytes);

        self::assertSame('x', self::emulatorCharAt($emulator, 0, self::COLS - 1), 'emulator printed 20 and clamped');
        self::assertSame(' ', self::emulatorCharAt($emulator, 1, 0), '21st glyph dropped by the emulator');
        self::assertSame('x', self::charAt($renderer, 1, 0), 'renderer wrapped it to the next line');
    }

    public function testCataloguedDivergenceStrikeOffMissingOnRendererPath(): void
    {
        // SGR 29 (strikethrough off) — also 25 (blink off) and 28 (hidden
        // off), same gap — resets the pen in the emulator but falls through
        // to the renderer's default arm, leaving the attribute lit.
        $bytes = "\x1b[9mS\x1b[29mN";

        self::assertSame(16, self::rendererAttrs(self::feedRenderer($bytes), 0, 1), 'renderer: strike stays on');
        self::assertSame(0, self::emulatorAttrs(self::feedEmulator($bytes), 0, 1), 'emulator: strike off');
    }

    public function testCataloguedDivergenceInsertDeleteLinesRendererOnly(): void
    {
        // CSI L / CSI M shift lines on the renderer path; the emulator's
        // ScreenHandler does not dispatch them at all.
        $bytes = "AAAA\r\nBBBB\x1b[2;1H\x1b[1L";

        self::assertSame(' ', self::charAt(self::feedRenderer($bytes), 1, 0), 'renderer: blank line inserted');
        self::assertSame('B', self::emulatorCharAt(self::feedEmulator($bytes), 1, 0), 'emulator: IL ignored');
    }

    public function testCataloguedDivergenceRepeatLastCharRendererOnly(): void
    {
        // CSI b replays the last printable on the renderer path; the
        // emulator does not dispatch it.
        $bytes = "ab\x1b[3bc";

        self::assertSame('b', self::charAt(self::feedRenderer($bytes), 0, 2), 'renderer: b repeated');
        self::assertSame('c', self::emulatorCharAt(self::feedEmulator($bytes), 0, 2), 'emulator: REP ignored');
    }

    public function testCataloguedSharedDefectUnderlineColorIsMisparsedByBothEngines(): void
    {
        // candy-core EMITS `58;5;N` (Util/Color::toUnderline). Neither SGR
        // consumer has a 58 arm, so `5` and `33` are read as independent SGRs
        // and the underline colour corrupts the pen — both engines turn the
        // fg green (33 - 30). The candy-ansi PARSER is correct (see
        // candy-ansi SgrSubparameterTest); the handlers must consume the
        // 58/48/38 triplets — a candy-vt job. When either engine gains a 58
        // arm this test fails: then move these sequences into the parity
        // corpus and delete this case.
        $bytes = "\x1b[58;5;33mU";

        self::assertSame('I3', self::rendererFg(self::feedRenderer($bytes), 0, 0), 'renderer: 33 ate the fg');
        self::assertSame('I3', self::emulatorFg(self::feedEmulator($bytes), 0, 0), 'emulator: 33 ate the fg');
    }

    public function testCataloguedDivergenceEmulatorErasesWithActiveBackground(): void
    {
        // xterm semantics: erase cells inherit the ACTIVE pen, so `CSI 31m`
        // + `CSI 100m` before an ED/EL paints the erased region. The
        // emulator implements that (and is the correct one); the renderer
        // path blanks with default cells — its erase writes bare empty cells,
        // so colour-wiped regions diverge on every ED/EL under a set
        // background (the quirk: with an fg-only pen the emulator optimises
        // the blank back to default, hiding the divergence there). (candy-vt
        // Parser\CsiHandlerImpl erase — renderer side is the outlier.)
        $bytes = "\x1b[31m\x1b[100m\x1b[1;3H\x1b[2K";
        $renderer = self::feedRenderer($bytes);
        $emulator = self::feedEmulator($bytes);

        self::assertSame('I7', self::rendererFg($renderer, 0, 5), 'renderer: default-pen blank');
        self::assertSame('I1', self::emulatorFg($emulator, 0, 5), 'emulator: active-pen blank');
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
     * Normalised renderer grid: rows of `char|fg|bg|attrs` tuples.
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
                    '%s|I%d|I%d|%d',
                    $cell->char === '' ? ' ' : $cell->char,
                    $cell->fg,
                    $cell->bg,
                    $cell->attrs & 0x1F,
                );
            }
            $rows[] = $line;
        }
        return $rows;
    }

    /**
     * Normalised emulator grid, reduced to the renderer's expressive range:
     * default colour -> theme default slot (7/0), indexed colour -> palette
     * index, truecolor -> its own token (and excluded from parity streams).
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
                    '%s|%s|%s|%d',
                    $char,
                    self::normaliseColour($sgr->foreground, 7),
                    self::normaliseColour($sgr->background, 0),
                    self::normaliseAttrs($sgr),
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
            return 'TC';
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
     * `CSI ? 7 h` (DECAWM) because the emulator defaults auto-wrap to OFF
     * while the renderer always wraps (catalogued below) — with DECAWM
     * enabled the two engines' line-end, corner and motion semantics agree,
     * which is what the random mix relies on. DECSTBM, tabs, truecolor and
     * the engines' one-sided primitives (IL/DL/REP on the renderer only)
     * stay excluded — they live in the divergence catalogue.
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
            "\x1b[58;5;178m", "\x1b[59m", "\x1b[21m", "\x1b[0m",
            "\x1b[s", "\x1b[u", "\x1b[?25h", "\x1b[?25l", "\x1b[?1000;1006h", "\x1b[?1006l",
        ];
        $moves = ['A', 'B', 'C', 'D'];
        $fills = ['J', 'K', '@', 'P', 'S', 'T'];
        $penDirtying = array_flip([
            "\x1b[31m", "\x1b[100m", "\x1b[38;5;200m", "\x1b[48;5;17m", "\x1b[1m", "\x1b[4m", "\x1b[7m",
        ]);

        // DECAWM on for the whole stream — see the method docblock.
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
                    // to the common ground first. ICH/DCH/SU/SD insert default
                    // cells on both and stay unguarded.
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
        return 'I' . $terminal->grid()->get($row, $col)->fg;
    }

    private static function emulatorFg(EmulatorTerminal $terminal, int $row, int $col): string
    {
        return self::normaliseColour($terminal->screen()->cell($row, $col)->sgr()->foreground, 7);
    }
}
