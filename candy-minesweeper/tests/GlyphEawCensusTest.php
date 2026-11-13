<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\I18n\T;
use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Cell;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Lang;
use SugarCraft\Minesweeper\Renderer;

/**
 * msw-16 (candy-minesweeper-021/-086): the executable glyph census.
 *
 * The law: every glyph the board paints must have East Asian Width NOT
 * Ambiguous, because on ambw=2 terminals (the standard CJK configuration —
 * and ja/ko/zh-cn are first-class locales here) an ambiguous cell glyph
 * doubles and the grid shatters. U+00B7 MIDDLE DOT — the shipped hidden
 * mark until this row — fails that census; the regression net never
 * releases it. The post-mortem dingbat '✗' and the rounded border stay on
 * the colour-capable bin (they are the G6(b) profile-keyed set's FULL-bin
 * members) and shed to 'x'/ASCII alongside the colour strip; the narrow bin
 * must therefore be pure ASCII.
 *
 * The census walks the renderer's own glyph table through cellStyleData
 * (every state the board can emit: hidden, flagged, revealed blank, every
 * adjacency number, detonated/missed/revealed mine, wrong flag, cursor)
 * under both bins, plus a forced-ambw=2 simulation proving a 10-wide row
 * paints exactly ten cells' worth of interior.
 */
final class GlyphEawCensusTest extends TestCase
{
    /** Standalone ambiguous codepoints plausible for this renderer's palette. */
    private const AMBIGUOUS_CHARS = [0x00B7, 0x2717, 0x2713, 0x2715, 0x2716, 0x2718];

    /** Box-drawing and block elements, incl. U+2591 LIGHT SHADE. */
    private const AMBIGUOUS_RANGES = [[0x2500, 0x259F]];

    private ?string $priorLocale = null;

    protected function setUp(): void
    {
        $this->priorLocale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        Renderer::setBoardColourOverride(true); // suite default (tests/bootstrap.php)
        T::setLocale($this->priorLocale);
    }

    /**
     * The codepoint class the census rejects.
     */
    private static function isAmbiguous(string $glyph): bool
    {
        $cp = mb_ord($glyph, 'UTF-8');
        if ($cp === false) {
            return true; // unparsable: guilty until proven narrow
        }
        if (in_array($cp, self::AMBIGUOUS_CHARS, true)) {
            return true;
        }
        foreach (self::AMBIGUOUS_RANGES as [$lo, $hi]) {
            if ($cp >= $lo && $cp <= $hi) {
                return true;
            }
        }
        return mb_strwidth($glyph, 'UTF-8') > 1; // wide/fullwidth never ship either
    }

    /** @return list<Board> live, exploded, won, and a plain 10-wide live board */
    private static function stateBoards(): array
    {
        $rows = static fn (callable $cell): array => array_map(
            static fn (int $y): array => array_map(static fn (int $x): Cell => $cell($x, $y), range(0, 3)),
            range(0, 3),
        );

        // Mid-play 4x4: hidden safe, hidden mine, flagged safe, flagged mine,
        // revealed blank, revealed 1..8 (adjacency hand-set — the census
        // walks the renderer's table, not the generator's).
        $live = Board::fromRows(4, 4, 4, $rows(static fn (int $x, int $y): Cell => match ([$x, $y]) {
            [0, 0] => new Cell(mine: true),
            [1, 1] => new Cell(mine: true),
            [2, 0] => new Cell(mine: false, flagged: true),
            [3, 1] => new Cell(mine: true, flagged: true),
            // msw-28 third-marker states the census must clear too:
            // questioned safe (paints '?') and questioned mine mid-play
            // (still hidden ':'-family? no — '?' until the boom).
            [1, 0] => new Cell(mine: false, questioned: true),
            [2, 1] => new Cell(mine: true, questioned: true),
            [0, 2] => new Cell(mine: false, revealed: true, adjacent: 0),
            [1, 2] => new Cell(mine: false, revealed: true, adjacent: 1),
            [2, 2] => new Cell(mine: false, revealed: true, adjacent: 2),
            [3, 2] => new Cell(mine: false, revealed: true, adjacent: 3),
            [0, 3] => new Cell(mine: false, revealed: true, adjacent: 4),
            [1, 3] => new Cell(mine: false, revealed: true, adjacent: 5),
            [2, 3] => new Cell(mine: false, revealed: true, adjacent: 6),
            [3, 3] => new Cell(mine: false, revealed: true, adjacent: 8),
            default => new Cell(mine: false),
        }), minesPlaced: true, exploded: false, revealedCount: 8, flaggedCount: 2);

        // Lost 4x4: detonated mine, missed mine, wrong flag, correct flag,
        // hidden safe, numbers.
        $lost = Board::fromRows(4, 4, 4, $rows(static fn (int $x, int $y): Cell => match ([$x, $y]) {
            [0, 0] => new Cell(mine: true, revealed: true),
            [1, 1] => new Cell(mine: true),
            [2, 0] => new Cell(mine: false, flagged: true),
            [3, 1] => new Cell(mine: true, flagged: true),
            // msw-28 on a lost board: a questioned safe cell keeps its '?',
            // a questioned mine is DISCLOSED as '*' (the memo never outranks
            // the post-mortem), so both arms of the match are walked.
            [1, 0] => new Cell(mine: false, questioned: true),
            [2, 1] => new Cell(mine: true, questioned: true),
            [0, 2] => new Cell(mine: false, revealed: true, adjacent: 0),
            [1, 2] => new Cell(mine: false, revealed: true, adjacent: 7),
            [2, 2] => new Cell(mine: false, revealed: true, adjacent: 3),
            [3, 2] => new Cell(mine: false, revealed: true, adjacent: 1),
            [0, 3] => new Cell(mine: false, revealed: true, adjacent: 2),
            [1, 3] => new Cell(mine: false, revealed: true, adjacent: 4),
            [2, 3] => new Cell(mine: false, revealed: true, adjacent: 5),
            [3, 3] => new Cell(mine: false, revealed: true, adjacent: 6),
            default => new Cell(mine: false),
        }), minesPlaced: true, exploded: true, revealedCount: 9, flaggedCount: 2);

        // Won 4x4: mines hidden-but-flagged except two the overlay reveals dim.
        $won = Board::fromRows(4, 4, 4, $rows(static fn (int $x, int $y): Cell => match ([$x, $y]) {
            [0, 0] => new Cell(mine: true),
            [1, 1] => new Cell(mine: true),
            [2, 0] => new Cell(mine: true, flagged: true),
            [3, 1] => new Cell(mine: true, flagged: true),
            default => new Cell(mine: false, revealed: true, adjacent: 0),
        }), minesPlaced: true, exploded: false, revealedCount: 12, flaggedCount: 2);

        return [$live, $lost, $won];
    }

    /**
     * Walk every glyph the renderer can emit for a board under one bin.
     *
     * @return list<string>
     */
    private static function emittedGlyphs(Board $b, bool $colourBin): array
    {
        Renderer::setBoardColourOverride($colourBin);
        $method = new ReflectionMethod(Renderer::class, 'cellStyleData');
        $method->setAccessible(true);

        $glyphs = [];
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                [$glyph] = $method->invoke(null, $b->cell($x, $y), $b, $x, $y, 0, 0);
                $glyphs[] = $glyph;
            }
        }
        return $glyphs;
    }

    public function testEveryLiveBoardGlyphIsEastAsianNotAmbiguous(): void
    {
        $live = self::stateBoards()[0];
        foreach ([true, false] as $colourBin) {
            foreach (self::emittedGlyphs($live, $colourBin) as $glyph) {
                self::assertFalse(self::isAmbiguous($glyph), 'bin ' . var_export($colourBin, true) . " live glyph {$glyph} is width-ambiguous");
            }
        }
    }

    public function testTheNarrowBinEmitsOnlyAsciiBoardGlyphs(): void
    {
        foreach (self::stateBoards() as $board) {
            foreach (self::emittedGlyphs($board, false) as $glyph) {
                $cp = mb_ord($glyph, 'UTF-8');
                self::assertIsInt($cp);
                self::assertGreaterThanOrEqual(0x20, $cp, 'narrow glyph below printable ASCII');
                self::assertLessThanOrEqual(0x7E, $cp, "narrow bin glyph {$glyph} is not ASCII");
            }
        }
    }

    public function testTheCapableBinKeepsOnlyTheDocumentedDingbat(): void
    {
        // The full bin may still paint '✗' (README's ambiguous-width
        // disclosure names it); nothing else in the board table may leave ASCII.
        foreach (self::stateBoards() as $board) {
            foreach (self::emittedGlyphs($board, true) as $glyph) {
                $cp = mb_ord($glyph, 'UTF-8');
                self::assertIsInt($cp);
                self::assertTrue(
                    ($cp >= 0x20 && $cp <= 0x7E) || $cp === 0x2717,
                    "unexpected non-ASCII board glyph {$glyph} (U+" . dechex($cp) . ')',
                );
            }
        }
    }

    public function testTheHiddenMarkIsNeverTheMiddleDotAgain(): void
    {
        // The regression net from the finding: U+00B7 and the shade blocks
        // must never appear in a board interior again.
        foreach ([true, false] as $colourBin) {
            foreach (self::stateBoards() as $board) {
                foreach (self::emittedGlyphs($board, $colourBin) as $glyph) {
                    self::assertNotSame('·', $glyph, 'the middle-dot hidden mark is back');
                    self::assertNotSame('░', $glyph);
                }
            }
        }
        // The frame's status/footer text legitimately carries ' · '
        // separators; the census scopes to the interior rows only.
        foreach ([true, false] as $colourBin) {
            Renderer::setBoardColourOverride($colourBin);
            $clean = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render(new Game(board: self::stateBoards()[0]))) ?? '';
            $lines = explode("\n", $clean);
            $board = self::stateBoards()[0];
            for ($y = 0; $y < $board->height; $y++) {
                $interior = mb_substr($lines[Renderer::INTERIOR_ROW_OFFSET + $y], Renderer::INTERIOR_COL_OFFSET, $board->width);
                self::assertStringNotContainsString('·', $interior, 'the middle-dot hidden mark is back in the grid');
            }
        }
    }

    public function testAmbw2SimulationPaintsExactlyTenCellsPerTenWideRow(): void
    {
        $rows = array_map(
            static fn (int $y): array => array_map(static fn (int $x): Cell => new Cell(mine: false), range(0, 9)),
            range(0, 1),
        );
        $wide = Board::fromRows(10, 2, 2, $rows, minesPlaced: true, exploded: false, revealedCount: 0, flaggedCount: 0);

        foreach ([true, false] as $colourBin) {
            Renderer::setBoardColourOverride($colourBin);
            $clean = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render(new Game(board: $wide, cursorX: 0, cursorY: 0))) ?? '';
            $lines = explode("\n", $clean);
            for ($y = 0; $y < 2; $y++) {
                $interior = mb_substr($lines[Renderer::INTERIOR_ROW_OFFSET + $y], Renderer::INTERIOR_COL_OFFSET, 10);
                $forced = 0;
                foreach (mb_str_split($interior) as $ch) {
                    $forced += self::isAmbiguous($ch) ? 2 : 1;
                }
                self::assertSame(10, $forced, 'ambw=2 simulation: a 10-wide row painted ' . $forced . " cells' worth (bin " . var_export($colourBin, true) . ')');
            }
        }
    }

    public function testTheNarrowBinBordersInAscii(): void
    {
        Renderer::setBoardColourOverride(false);
        $frame = Renderer::render(new Game(board: self::stateBoards()[0]));
        self::assertStringNotContainsString('╭', $frame, 'the rounded border must not survive the downgrade bin');
        $clean = preg_replace('/\x1b\[[0-9;]*m/', '', $frame) ?? '';
        foreach (mb_str_split($clean) as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if (is_int($cp) && $cp >= 0x2500 && $cp <= 0x259F) {
                self::fail("box-drawing/block glyph {$ch} leaked into the narrow frame");
            }
        }
        self::assertStringContainsString('+', $clean, 'sanity: the ASCII border is actually drawn');
    }

    public function testTheCapableBinKeepsTheRoundedBorder(): void
    {
        Renderer::setBoardColourOverride(true);
        $frame = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render(new Game(board: self::stateBoards()[0]))) ?? '';
        self::assertStringContainsString('╭', $frame, 'the shipped look keeps its rounded corners');
    }

    public function testTheLostBoardTeachesThePostMortemMarks(): void
    {
        // msw-16/-086: the board-legend line swaps for the loss legend, and
        // names the glyph the active bin actually paints.
        $lost = new Game(board: self::stateBoards()[1]);

        Renderer::setBoardColourOverride(true);
        $full = Renderer::render($lost);
        self::assertStringContainsString(Lang::t('ui.help_loss', ['mark' => '✗']), $full);
        self::assertStringNotContainsString(Lang::t('ui.help_board'), $full, 'the mid-play legend yields to the lesson');
        self::assertLessThanOrEqual(34, \SugarCraft\Core\Util\Width::of(Lang::t('ui.help_loss', ['mark' => '✗'])),
            'the en loss legend must stay within the 34-col budget');

        Renderer::setBoardColourOverride(false);
        $narrow = Renderer::render($lost);
        self::assertStringContainsString(Lang::t('ui.help_loss', ['mark' => 'x']), $narrow);
        self::assertStringNotContainsString('✗ wrong flag', $narrow, 'the legend must not teach a glyph the bin never shows');
    }

    public function testTheLossLegendAppearsOnNoOtherBoard(): void
    {
        [$live, , $won] = self::stateBoards();
        foreach ([$live, $won] as $board) {
            $frame = Renderer::render(new Game(board: $board));
            self::assertStringContainsString(Lang::t('ui.help_board'), $frame);
            self::assertStringNotContainsString('wrong flag', $frame, 'the post-mortem lesson is for the post-mortem');
        }
    }

    public function testTheLossLegendReachesEveryLocale(): void
    {
        foreach (glob(__DIR__ . '/../lang/*.php') ?: [] as $file) {
            $locale = basename($file, '.php');
            T::setLocale($locale);
            $line = Lang::t('ui.help_loss', ['mark' => '✗']);
            self::assertNotSame('ui.help_loss', $line, "locale {$locale} fell through to the raw key");
            self::assertStringNotContainsString('{mark}', $line, "locale {$locale} leaked the placeholder");
        }
    }
}
