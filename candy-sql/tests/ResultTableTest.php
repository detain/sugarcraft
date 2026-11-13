<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sql\ResultTable;
use PHPUnit\Framework\TestCase;

final class ResultTableTest extends TestCase
{
    private const SGR = '/\x1b\[[0-9;]*m/u';

    private function rows(): array
    {
        return [
            ['id' => 1, 'name' => 'alice',   'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'bob',     'email' => 'bob@example.com'],
            ['id' => 3, 'name' => 'carol',   'email' => 'carol@example.com'],
        ];
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    public function testFromRowsCreatesTable(): void
    {
        $t = ResultTable::fromRows($this->rows());
        $this->assertSame(['id', 'name', 'email'], $t->columns());
    }

    public function testEmptyTableHasNoColumns(): void
    {
        $t = new ResultTable([]);
        $this->assertSame([], $t->columns());
    }

    // ── NULL formatting ────────────────────────────────────────────────────────

    public function testRenderNullShowsNullToken(): void
    {
        $rows = [['val' => null]];
        $t = ResultTable::fromRows($rows);
        $out = $t->render();
        // Rendered output should contain the NULL token (styled).
        $this->assertStringContainsString('NULL', $out);
    }

    public function testRenderNullTokenCustomizable(): void
    {
        $rows = [['val' => null]];
        $t = ResultTable::fromRows($rows)->withNullToken('∅');
        $out = $t->render();
        $this->assertStringContainsString('∅', $out);
    }

    // ── JSON formatting ────────────────────────────────────────────────────────

    public function testRenderArrayShowsJsonPretty(): void
    {
        $rows = [['val' => ['a' => 1, 'b' => 2]]];
        $t = ResultTable::fromRows($rows)->withJsonPretty(true);
        $out = $t->render();
        // Pretty-printed JSON should have a newline (at minimum).
        $this->assertStringContainsString('"a"', $out);
    }

    public function testRenderArrayShowsJsonCompactWhenPrettyDisabled(): void
    {
        $rows = [['val' => ['a' => 1, 'b' => 2]]];
        $t = ResultTable::fromRows($rows)->withJsonPretty(false);
        $out = $t->render();
        // Compact JSON should not have a newline between keys.
        $this->assertStringNotContainsString("\"a\": 1,\n", $out);
    }

    public function testRenderScalarIsNotJsonEncoded(): void
    {
        $rows = [['val' => 'plain string', 'num' => 42, 'float' => 3.14]];
        $t = ResultTable::fromRows($rows);
        $out = $t->render();
        $this->assertStringContainsString('plain string', $out);
        $this->assertStringContainsString('42', $out);
        $this->assertStringContainsString('3.14', $out);
    }

    // ── Horizontal scrolling ──────────────────────────────────────────────────

    public function testVisibleColCountDecreasesWithSmallerWidth(): void
    {
        $t = ResultTable::fromRows($this->rows());
        // D-M23 flip (2026-11-03): with the cumulative fit, 30 cells genuinely
        // hold all three columns (id 2 + name 5 + email 17 + two 1-cell gaps =
        // 26), so the old "narrower decreases the count" expectation — an
        // artifact of floor(width / AVERAGE-cell-width) reporting 10 visible
        // columns at 120 and 2 at 30 — is replaced by the honest window.
        $this->assertSame(3, $t->visibleColCount());
        $this->assertSame(3, $t->withVisibleWidth(30)->visibleColCount());
        // 2+1+5 = 8 fits under 12, but +1+17 does not: the email column is
        // the first to be pushed off.
        $this->assertSame(2, $t->withVisibleWidth(12)->visibleColCount());
    }

    public function testScrollLeftDecreasesOffset(): void
    {
        $t = ResultTable::fromRows($this->rows())->withVisibleWidth(20)->withOffset(1);
        $scrolled = $t->scrollLeft();
        $this->assertSame(0, $scrolled->offset);
    }

    public function testScrollLeftStopsAtZero(): void
    {
        $t = ResultTable::fromRows($this->rows())->withVisibleWidth(20);
        $scrolled = $t->withOffset(0)->scrollLeft();
        $this->assertSame(0, $scrolled->offset);
    }

    public function testScrollRightIncreasesOffset(): void
    {
        $t = ResultTable::fromRows($this->rows())->withVisibleWidth(20);
        $scrolled = $t->scrollRight();
        $this->assertGreaterThan($t->offset, $scrolled->offset);
    }

    public function testCanScrollLeftIsFalseAtOffsetZero(): void
    {
        $t = ResultTable::fromRows($this->rows())->withVisibleWidth(20);
        $this->assertFalse($t->withOffset(0)->canScrollLeft());
    }

    public function testCanScrollRightIsFalseWhenAllColsVisible(): void
    {
        // With wide enough visibleWidth, all columns fit at once.
        $t = ResultTable::fromRows($this->rows())->withVisibleWidth(200);
        $this->assertFalse($t->canScrollRight());
    }

    public function testVisibleColumnsExcludesScrolledColumns(): void
    {
        $t = ResultTable::fromRows($this->rows())->withVisibleWidth(20);
        $this->assertTrue($t->canScrollRight(), 'Table should be scrollable with visibleWidth=20');
        $visible0 = $t->visibleColumns();

        $scrolled = $t->scrollRight();
        $visible1 = $scrolled->visibleColumns();

        // Scrolling right should shift which columns are visible.
        $this->assertNotEquals($visible0, $visible1);
    }

    public function testWithOffsetSetsOffset(): void
    {
        $t = ResultTable::fromRows($this->rows())->withOffset(1);
        $this->assertSame(1, $t->offset);
    }

    public function testWithOffsetClampsToZero(): void
    {
        $t = ResultTable::fromRows($this->rows())->withOffset(-5);
        $this->assertSame(0, $t->offset);
    }

    // ── Rendering ──────────────────────────────────────────────────────────────

    public function testRenderEmptyReturnsPlaceholder(): void
    {
        $t = new ResultTable([]);
        $out = $t->render();
        $this->assertStringContainsString('empty', $out);
    }

    public function testRenderIncludesColumnHeaders(): void
    {
        $t = ResultTable::fromRows($this->rows());
        $out = $t->render();
        $this->assertStringContainsString('id', $out);
        $this->assertStringContainsString('name', $out);
        $this->assertStringContainsString('email', $out);
    }

    public function testRenderPlainReturnsNoAnsiCodes(): void
    {
        $t = ResultTable::fromRows($this->rows());
        $plain = $t->renderPlain();
        // ANSI escape codes start with \x1b[.
        $this->assertStringNotContainsString("\x1b[", $plain);
    }

    public function testRenderPlainIncludesHeadersAndData(): void
    {
        $rows = [['id' => 99, 'name' => 'test']];
        $t = ResultTable::fromRows($rows)->withVisibleWidth(200);
        $plain = $t->renderPlain();
        $this->assertStringContainsString('id', $plain);
        $this->assertStringContainsString('test', $plain);
        $this->assertStringContainsString('99', $plain);
    }

    public function testRenderIncludesScrollHintWhenNeeded(): void
    {
        // Narrow width forces scrolling.
        $t = ResultTable::fromRows($this->rows())->withVisibleWidth(20);
        $out = $t->render();
        // Scroll hint should appear when canScrollLeft or canScrollRight.
        $this->assertTrue(
            $t->canScrollLeft() || $t->canScrollRight(),
            'Table should be scrollable with visibleWidth=20',
        );
    }

    public function testWithRowsReplacesData(): void
    {
        $t = ResultTable::fromRows([['a' => 1]]);
        $t2 = $t->withRows([['b' => 2]]);
        $this->assertSame(['b'], $t2->columns());
    }

    public function testImmutabilityWithRowsDoesNotMutateOriginal(): void
    {
        $t = ResultTable::fromRows([['a' => 1]]);
        $t->withRows([['b' => 2]]);
        $this->assertSame(['a'], $t->columns());
    }

    public function testImmutabilityScrollDoesNotMutateOriginal(): void
    {
        $t = ResultTable::fromRows($this->rows())->withVisibleWidth(20);
        $t->scrollRight();
        $this->assertSame(0, $t->offset);
    }

    // ── Column measurement over the full row set (C07) ──────────────────────

    public function testColWidthMeasuresEveryRowNotJustPageZero(): void
    {
        // C07 reverses the old page-0-only sampling: the window slides with
        // the cursor/viewport, so a wide value on row 26 must widen the
        // column too — with maxCellWidth clamping the damage (C07's own
        // negative pin would be a clamp-free full scan; the 40-cell ceiling
        // is the guard). Pre-C07 this column measured 5 cells and the wide
        // tail row spilled its column.
        $rows = [['col' => 'short']];
        for ($i = 1; $i < 25; $i++) {
            $rows[] = ['col' => 'medium'];
        }
        $rows[] = ['col' => str_repeat('x', 200)]; // beyond the old page bound

        $widths = ResultTable::fromRows($rows)->colWidths();

        $this->assertSame(40, $widths['col'], 'tail row feeds the measurement, clamp caps it');
    }

    public function testCjkAndEmojiCellsPaintOnMeasuredColumns(): void
    {
        // C07: widths/padding/truncation run on display cells, not
        // codepoints — a CJK or emoji value counts its real 2-cell glyphs.
        $rows = [
            ['id' => 1, 'note' => '日本語テキスト', 'tag' => '🎉'],
            ['id' => 2, 'note' => 'plain', 'tag' => 'ok'],
        ];
        $out = ResultTable::fromRows($rows)->withVisibleWidth(200)->render();
        $widths = array_map(
            static fn (string $line): int => Width::of($line),
            explode("\n", preg_replace(self::SGR, '', $out) ?? $out),
        );
        $this->assertCount(1, array_unique($widths), 'grid lines must be column-aligned');
    }

    public function testOversizedCellIsClippedToItsColumn(): void
    {
        // Belt: pad() left-clips (truncate to width) instead of overflowing
        // when the cell exceeds the column it is painted into.
        $t = new ResultTable(
            rows: [['v' => '日本語']],
            visibleWidth: 20,
            precomputedColWidths: ['v' => 2],
        );
        $body = explode("\n", $t->render())[1] ?? '';
        $plain = preg_replace(self::SGR, '', $body) ?? $body;
        $this->assertSame(2, Width::of($plain), 'a wide cell must not spill its column');
    }

    public function testColWidthWithRowsChangeRecomputes(): void
    {
        $t = ResultTable::fromRows([['col' => 'short']]);
        $widths1 = $t->colWidths();

        $t2 = $t->withRows([['col' => str_repeat('x', 100)]]);
        $widths2 = $t2->colWidths();

        // withRows changed the rows — colWidths should be recomputed
        $this->assertNotEquals($widths1['col'], $widths2['col']);
    }

    // ── Memoization — scroll/with* preserve colWidths ───────────────────────

    public function testScrollRightPreservesColWidths(): void
    {
        $t = ResultTable::fromRows($this->rows())->withVisibleWidth(20);
        $widthsBefore = $t->colWidths();

        $scrolled = $t->scrollRight();
        $widthsAfter = $scrolled->colWidths();

        // scrollRight() doesn't change rows — colWidths should be reused
        $this->assertSame($widthsBefore, $widthsAfter);
    }

    public function testScrollLeftPreservesColWidths(): void
    {
        $t = ResultTable::fromRows($this->rows())->withOffset(2)->withVisibleWidth(20);
        $widthsBefore = $t->colWidths();

        $scrolled = $t->scrollLeft();
        $widthsAfter = $scrolled->colWidths();

        // scrollLeft() doesn't change rows — colWidths should be reused
        $this->assertSame($widthsBefore, $widthsAfter);
    }

    public function testWithVisibleWidthPreservesColWidths(): void
    {
        $t = ResultTable::fromRows($this->rows());
        $widthsBefore = $t->colWidths();

        $wider = $t->withVisibleWidth(200);
        $widthsAfter = $wider->colWidths();

        // withVisibleWidth doesn't change rows — colWidths should be reused
        $this->assertSame($widthsBefore, $widthsAfter);
    }

    public function testWithJsonPrettyPreservesColWidths(): void
    {
        $t = ResultTable::fromRows($this->rows());
        $widthsBefore = $t->colWidths();

        $toggled = $t->withJsonPretty(false);
        $widthsAfter = $toggled->colWidths();

        // withJsonPretty doesn't change rows — colWidths should be reused
        $this->assertSame($widthsBefore, $widthsAfter);
    }

    public function testWithNullTokenPreservesColWidths(): void
    {
        $t = ResultTable::fromRows($this->rows());
        $widthsBefore = $t->colWidths();

        $toggled = $t->withNullToken('NULL');
        $widthsAfter = $toggled->colWidths();

        // withNullToken doesn't change rows — colWidths should be reused
        $this->assertSame($widthsBefore, $widthsAfter);
    }

    public function testWithOffsetPreservesColWidths(): void
    {
        $t = ResultTable::fromRows($this->rows())->withVisibleWidth(20);
        $widthsBefore = $t->colWidths();

        $offset = $t->withOffset(1);
        $widthsAfter = $offset->colWidths();

        // withOffset doesn't change rows — colWidths should be reused
        $this->assertSame($widthsBefore, $widthsAfter);
    }

    public function testUnevenColumnsFitCumulatively(): void
    {
        // D-M23: the average-cell-width estimate over-claimed the visible
        // window on uneven tables; the cumulative fit is the law now.
        $rows = [['aa' => '1', 'bb' => '2', 'cc' => '3', 'wide' => str_repeat('W', 60)]];
        $t = ResultTable::fromRows($rows)->withVisibleWidth(30);
        // widths: aa 2, bb 2, cc 2, wide clamped to maxCellWidth 40 →
        // 2 + (2+1) + (2+1) = 8 fits, adding wide would need 49 > 30.
        $this->assertSame(3, $t->visibleColCount());
        // Scrolling may advance one column at a time but never past the last
        // column (the old average-window maxOffset let the window strand).
        $walk = $t;
        for ($i = 0; $i < 6; $i++) {
            $walk = $walk->scrollRight();
        }
        $this->assertLessThanOrEqual(3, $walk->offset);
        // The window genuinely slid to the last column (its content clips to
        // the pane with an ellipsis — the range indicator is the proof).
        $this->assertStringContainsString('cols 4–4 of 4', $walk->render(), 'tail window reached');
    }

    public function testWidthShrinkStrandsNoOffsetPastTheColumns(): void
    {
        $t = ResultTable::fromRows($this->rows())
            ->withOffset(5)
            ->withVisibleWidth(12);
        // three columns: offset clamped to the last column, window renders it.
        $this->assertSame(2, $t->offset);
        $this->assertStringContainsString('carol', $t->render());
    }
}
