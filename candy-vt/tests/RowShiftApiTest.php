<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Parser\CsiHandlerImpl;
use SugarCraft\Vt\Terminal as Renderer;
use SugarCraft\Vt\Terminal\Terminal as Emulator;

/**
 * Buffer::insertRows()/deleteRows() — the row-shift public API extracted from
 * the duplicated IL/DL/SU/SD walks (E736/vt-6.4), plus the structural census
 * that keeps those walks from respreading at the call sites.
 *
 * Frame pins follow the house cell-grid style (lettered rows read back per
 * cell); the pre-existing InsertDeleteLinesTest / CsiHandlerImplTest suites
 * remain the behaviour regression net for the routed escape paths.
 *
 * @see ECMA-48 §8.4.15 (IL) / §8.4.10 (DL)
 * @see charmbracelet/x/vt Screen.InsertLine / Screen.DeleteLine
 */
final class RowShiftApiTest extends TestCase
{
    /**
     * 4×5 buffer whose rows read "aaaa".."eeee" (letter per row index).
     */
    private function lettered(): Buffer
    {
        $b = new Buffer(4, 5);
        foreach (['a', 'b', 'c', 'd', 'e'] as $r => $ch) {
            for ($c = 0; $c < 4; $c++) {
                $b->put($r, $c, new Cell($ch));
            }
        }
        return $b;
    }

    /** @return list<string> */
    private function rows(Buffer $b): array
    {
        $out = [];
        for ($r = 0; $r < $b->rows; $r++) {
            $line = '';
            for ($c = 0; $c < $b->cols; $c++) {
                $line .= $b->cell($r, $c)->char;
            }
            $out[] = $line;
        }
        return $out;
    }

    /** Feed the same lettered screen into either façade (both take feed(string)). */
    private function feedLetters(Renderer|Emulator $t): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $r => $ch) {
            $t->feed(sprintf("\x1b[%d;1H%s%s%s%s", $r + 1, $ch, $ch, $ch, $ch));
        }
    }

    // ─── Buffer direct: IL family ─────────────────────────────────────────

    public function testRowShiftInsertAtTopOfFullGridShiftsEverythingDown(): void
    {
        $b = $this->lettered();
        $b->insertRows(2); // bare-count shape: region [0, rows-1], from 0.
        $this->assertSame(['    ', '    ', 'aaaa', 'bbbb', 'cccc'], $this->rows($b));
        // Row 'eeee' overflowed the region bottom and was dropped.
    }

    public function testRowShiftInsertBlanksArePlainEmptyCells(): void
    {
        $b = $this->lettered();
        $b->insertRows(1, 1, 0, 4);
        for ($c = 0; $c < 4; $c++) {
            $this->assertTrue(Cell::empty()->equals($b->cell(1, $c)), 'inserted row must carry plain Cell::empty() (no pen)');
        }
        $this->assertSame(['aaaa', '    ', 'bbbb', 'cccc', 'dddd'], $this->rows($b));
    }

    public function testRowShiftInsertCoercesNonPositiveCountToOneRow(): void
    {
        // Pin of the extracted guard: the IL walk floors $count at 1 via
        // max(1, $count) — it is NOT a no-op (public CSI entry points reject
        // explicit 0 earlier, at the dispatch layer).
        $b = $this->lettered();
        $b->insertRows(0);
        $this->assertSame(['    ', 'aaaa', 'bbbb', 'cccc', 'dddd'], $this->rows($b));

        $b = $this->lettered();
        $b->insertRows(-7);
        $this->assertSame(['    ', 'aaaa', 'bbbb', 'cccc', 'dddd'], $this->rows($b));
    }

    public function testRowShiftInsertClampsOverrunToRegionBelowFrom(): void
    {
        $b = $this->lettered();
        $b->insertRows(99, 3, 0, 4); // only rows 3..4 lie at/below $from
        $this->assertSame(['aaaa', 'bbbb', 'cccc', '    ', '    '], $this->rows($b));
    }

    public function testRowShiftOverrunClampsToRegionNotGridBottom(): void
    {
        // Fixture law: bottom < rows-1 AND count > region — otherwise a
        // region-ignoring clamp is indistinguishable (M2 lesson).
        $b = $this->lettered();
        $b->insertRows(99, 2, 1, 3); // region rows 1..3, insertion from row 2 down
        $this->assertSame(['aaaa', 'bbbb', '    ', '    ', 'eeee'], $this->rows($b));

        $b = $this->lettered();
        $b->deleteRows(99, 2, 1, 3); // same span, deletion direction
        $this->assertSame(['aaaa', 'bbbb', '    ', '    ', 'eeee'], $this->rows($b));
        // Row 4 ('eeee') must survive in BOTH: the clamp is the region, not the grid.
    }

    public function testRowShiftInsertAtLastRegionRowBlanksOnlyThatRow(): void
    {
        $b = $this->lettered();
        $b->insertRows(1, 4, 1, 4);
        $this->assertSame(['aaaa', 'bbbb', 'cccc', 'dddd', '    '], $this->rows($b));
    }

    public function testRowShiftInsertIgnoresFromOutsideRegion(): void
    {
        foreach ([0, 4] as $from) { // above the top, below the bottom
            $b = $this->lettered();
            $b->insertRows(1, $from, 1, 3);
            $this->assertSame(['aaaa', 'bbbb', 'cccc', 'dddd', 'eeee'], $this->rows($b), "from=$from outside [1,3] is a no-op");
        }
    }

    // ─── Buffer direct: DL family ─────────────────────────────────────────

    public function testRowShiftDeletePullsRowsUpBlanksLandAtBottom(): void
    {
        $b = $this->lettered();
        $b->deleteRows(2, 1, 0, 4); // two rows deleted starting at row 1
        $this->assertSame(['aaaa', 'dddd', 'eeee', '    ', '    '], $this->rows($b));
        // b,c fell off the region bottom; the blanks land at the very bottom.
    }

    public function testRowShiftDeleteWholeRegionBlanksEntireRegion(): void
    {
        $b = $this->lettered();
        $b->deleteRows(5, 0, 0, 4);
        $this->assertSame(['    ', '    ', '    ', '    ', '    '], $this->rows($b));
    }

    public function testRowShiftDeleteSubRegionKeepsRowsOutsideAnchors(): void
    {
        $b = $this->lettered();
        $b->deleteRows(1, 1, 1, 3); // region [1..3] = b,c,d → c,d,blank
        $this->assertSame(['aaaa', 'cccc', 'dddd', '    ', 'eeee'], $this->rows($b));
    }

    public function testRowShiftDeleteCoercesNonPositiveCountToOneRow(): void
    {
        // Same one-row floor as the insert walk (guard symmetry pin).
        $b = $this->lettered();
        $b->deleteRows(0);
        $this->assertSame(['bbbb', 'cccc', 'dddd', 'eeee', '    '], $this->rows($b));
    }

    public function testRowShiftDeleteIgnoresFromOutsideRegion(): void
    {
        $b = $this->lettered();
        $b->deleteRows(1, 4, 1, 3);
        $this->assertSame(['aaaa', 'bbbb', 'cccc', 'dddd', 'eeee'], $this->rows($b));
    }

    public function testRowShiftInsertExtendsDirtyRegionLikeTheWalkDid(): void
    {
        // The extraction kept per-cell put() writes, so the dirty bounding box
        // still grows over every touched row/col — a row-splice shortcut would
        // desync incremental-render consumers.
        $b = new Buffer(4, 5); // pristine: sentinels until the walk writes via put()
        $b->insertRows(1, 3, 0, 4); // copy touches row 4, blank fill touches row 3
        $dirty = $b->dirtyRegion();
        $this->assertSame(3, $dirty['minRow']);
        $this->assertSame(4, $dirty['maxRow']);
        $this->assertSame(0, $dirty['minCol']);
        $this->assertSame(3, $dirty['maxCol']);
    }

    // ─── Renderer façade (CsiHandlerImpl path) ─────────────────────────────

    public function testRendererInsertLineFramePin(): void
    {
        $t = Renderer::new(4, 5);
        $this->feedLetters($t);
        $t->feed("\x1b[3;1H\x1b[L"); // IL at row index 2
        $this->assertSame(['aaaa', 'bbbb', '    ', 'cccc', 'dddd'], $this->rows($t->grid()));
    }

    public function testRendererDeleteLinePullsUpFramePin(): void
    {
        $t = Renderer::new(4, 5);
        $this->feedLetters($t);
        $t->feed("\x1b[2;1H\x1b[M"); // DL at row index 1
        $this->assertSame(['aaaa', 'cccc', 'dddd', 'eeee', '    '], $this->rows($t->grid()));
    }

    public function testRendererScrollUpKeepsCursorUnchanged(): void
    {
        $t = Renderer::new(4, 5);
        $this->feedLetters($t);
        $t->feed("\x1b[2;3H"); // cursor row 1, col 2
        $t->feed("\x1b[2S");
        $this->assertSame(1, $t->cursor()->row);
        $this->assertSame(2, $t->cursor()->col);
        $this->assertSame(['cccc', 'dddd', 'eeee', '    ', '    '], $this->rows($t->grid()));
    }

    public function testRendererScrollDownBlanksRegionTop(): void
    {
        $t = Renderer::new(4, 5);
        $this->feedLetters($t);
        $t->feed("\x1b[T");
        $this->assertSame(['    ', 'aaaa', 'bbbb', 'cccc', 'dddd'], $this->rows($t->grid()));
        // 'eeee' fell off the bottom — same direction as IL from the top.
    }

    // ─── Emulator façade (ScrollHandler path) ─────────────────────────────

    public function testEmulatorIlWithinDecstbmRegionKeepsRowsOutside(): void
    {
        $t = new Emulator(4, 5);
        $this->feedLetters($t);
        $t->feed("\x1b[2;4r"); // DECSTBM: region rows 1..3 (0-indexed)
        $t->feed("\x1b[3;1H\x1b[99L"); // IL overrun — clamp must stop at region bottom 3
        $this->assertSame(['aaaa', 'bbbb', '    ', '    ', 'eeee'], $this->screenRows($t));
        $this->assertSame(2, $t->cursor()->row);
        $this->assertSame(0, $t->cursor()->col); // column-home on success
    }

    public function testEmulatorSuSubRegionLeavesRowsOutsideAndCursorAlone(): void
    {
        $t = new Emulator(4, 5);
        $this->feedLetters($t);
        $t->feed("\x1b[2;4r");
        $t->feed("\x1b[3;3H\x1b[S"); // SU 1 at row 2, col 2
        $this->assertSame(['aaaa', 'cccc', 'dddd', '    ', 'eeee'], $this->screenRows($t));
        $this->assertSame(2, $t->cursor()->row);
        $this->assertSame(2, $t->cursor()->col);
    }

    /**
     * Read back the emulator screen as row strings (Screen is not a Buffer).
     *
     * @return list<string>
     */
    private function screenRows(Emulator $t): array
    {
        $screen = $t->screen();
        $out = [];
        for ($r = 0; $r < $screen->rows; $r++) {
            $line = '';
            for ($c = 0; $c < $screen->cols; $c++) {
                $line .= $screen->cell($r, $c)->char;
            }
            $out[] = $line;
        }
        return $out;
    }

    // ─── Structural census (E730 sole-site pattern) ────────────────────────

    public function testRelativeRowShiftWalkIsConfinedToBuffer(): void
    {
        $hits = [];
        foreach ($this->sourceFiles() as $file) {
            if (preg_match_all('/->cell\(\$r [-+]/', $file['text'], $m) > 0) {
                $hits[$file['path']] = count($m[0]);
            }
        }
        $this->assertSame(
            ['Buffer/Buffer.php' => 2], // one read per walk: insert ($r - $shift) + delete ($r + $shift)
            $hits,
            'relative-row shift reads must live ONLY inside Buffer::insertRows()/deleteRows() — respreading the walk at a call site reddens this pin',
        );
    }

    public function testRowShiftApiCallSitesMatchTheRoster(): void
    {
        $roster = ['Handler/ScrollHandler.php', 'Parser/CsiHandlerImpl.php'];
        foreach (['insertRows', 'deleteRows'] as $method) {
            $found = [];
            foreach ($this->sourceFiles() as $file) {
                $n = preg_match_all('/->' . $method . '\(/', $file['text']);
                if ($n > 0 && $file['path'] !== 'Buffer/Buffer.php') {
                    $found[$file['path']] = $n;
                }
            }
            $this->assertSame(
                array_fill_keys($roster, 2), // insertLines+scrollDown / deleteLines+scrollUp per file
                $found,
                "$method(): call sites drifted from the rostered four",
            );
        }
    }

    public function testRendererSingleStepLoopHelpersStayDeleted(): void
    {
        // The N×scrollXOne() loop form was THE duplication E736/vt-6.4 removed;
        // reintroducing it (or the Buffer walk beside it) must fail loud.
        $this->assertFalse(method_exists(CsiHandlerImpl::class, 'scrollUpOne'));
        $this->assertFalse(method_exists(CsiHandlerImpl::class, 'scrollDownOne'));
        foreach (['il', 'dl', 'su', 'sd'] as $public) {
            $this->assertTrue(method_exists(CsiHandlerImpl::class, $public));
        }
        foreach (['insertRows', 'deleteRows'] as $api) {
            $this->assertTrue(method_exists(Buffer::class, $api));
        }
    }

    /**
     * @return list<array{path:string, text:string>} every PHP file under src/
     *         paths relative to src/.
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__) . '/src';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => $f->isDir() || ($f->isFile() && $f->getExtension() === 'php'),
        ));
        /** @var \SplFileInfo $f */
        foreach ($it as $f) {
            $out[] = [
                'path' => substr($f->getPathname(), strlen($root) + 1),
                'text' => (string) file_get_contents($f->getPathname()),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $out;
    }
}
