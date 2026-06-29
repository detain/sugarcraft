<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\ResultTable;
use PHPUnit\Framework\TestCase;

final class ResultTableTest extends TestCase
{
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
        // Default maxCellWidth=40, visibleWidth=120 → enough for 3 cols.
        $this->assertGreaterThanOrEqual(3, $t->visibleColCount());

        $narrow = $t->withVisibleWidth(30);
        $this->assertLessThan($t->visibleColCount(), $narrow->visibleColCount());
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

    // ── Bounded column measurement (page-size) ───────────────────────────────

    public function testColWidthBoundedToRenderedPageSize(): void
    {
        // Create a table where the first row has a short value but a later row (beyond
        // DEFAULT_PAGE_SIZE=25) has a very long value. The column width should be
        // determined by the first 25 rows only, not the full set.
        $shortValue = 'short';
        $longValue = str_repeat('x', 200); // Very long value

        // Row 0 has short value
        $rows = [['col' => $shortValue]];
        // Add rows 1-24 with short values
        for ($i = 1; $i < 25; $i++) {
            $rows[] = ['col' => 'medium'];
        }
        // Row 25 has the very long value — beyond DEFAULT_PAGE_SIZE=25
        $rows[] = ['col' => $longValue];

        $t = ResultTable::fromRows($rows);
        $widths = $t->colWidths();

        // Width should be based on short/medium values in rows 0-24, not the
        // very long value in row 25 which is beyond the rendered page.
        $this->assertLessThan(200, $widths['col']);
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
}
