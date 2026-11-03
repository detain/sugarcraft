<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\{Column, Row, RowData, Table};

/**
 * Pins for the scroll/cursor stranding family (audit M9, M12, M19).
 *
 * Every state transition that shrinks or moves the visible window must keep
 * the viewport pointed at real rows: the page-blind End key used to park
 * scrollY past the current page (header-only box), SelectNext moved the page
 * but never the scroll (cursor vanished off-screen), a filter applied after a
 * deep scroll stranded the view, and replaced Row objects leaked forever in
 * the expansion list.
 */
final class TableScrollStrandingTest extends TestCase
{
    private const ROWS = 20;

    private function table(int $pageSize = 0, int $viewport = 0): Table
    {
        $rows = [];
        for ($i = 0; $i < self::ROWS; $i++) {
            $rows[] = Row::new(RowData::from(['n' => "row{$i}"]));
        }
        $t = Table::fromColumns([Column::new('n', 'N', self::ROWS + 5)])
            ->withRows($rows);
        if ($pageSize > 0) {
            $t = $t->withPageSize($pageSize);
        }
        if ($viewport > 0) {
            $t = $t->withViewportHeight($viewport);
        }

        return $t;
    }

    private static function plain(string $s): string
    {
        return \preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }

    private function dataLineCount(Table $t): int
    {
        $n = 0;
        foreach (\explode("\n", self::plain($t->View())) as $line) {
            if (\str_contains($line, 'row')) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * End key with pagination: the scrollable region is the CURRENT page.
     * Pre-fix, maxScrollY counted all 20 filtered rows, End parked scrollY
     * at 15 against a 10-row page, and the frame rendered zero data lines.
     */
    public function testEndKeyStaysInsideTheCurrentPage(): void
    {
        $t = $this->table(pageSize: 10, viewport: 5)->handleKey(Table::KEY_END);

        $this->assertSame(5, $t->maxScrollY());
        $this->assertSame(5, $this->dataLineCount($t), 'viewport stays full of data rows');
        $this->assertStringContainsString('row9', $t->View(), 'End shows the tail of the page, not a blank box');
        $this->assertStringNotContainsString('row4', $t->View());
    }

    /**
     * The public accessor is page-aware too (M19): no viewport means no
     * scroll domain; a page of 10 under viewport 5 scrolls 0..5.
     */
    public function testMaxScrollYIsPublicAndPageAware(): void
    {
        $this->assertSame(0, $this->table()->maxScrollY());
        $this->assertSame(15, $this->table(viewport: 5)->maxScrollY());
        $this->assertSame(5, $this->table(pageSize: 10, viewport: 5)->maxScrollY());
    }

    /**
     * SelectNext auto-pages but must also keep the cursor inside the
     * viewport window — pre-fix the highlight walked out of existence.
     */
    public function testSelectNextKeepsCursorInsideViewport(): void
    {
        $t = $this->table(pageSize: 10, viewport: 5);
        for ($i = 0; $i < 6; $i++) {
            $t = $t->SelectNext();
        }

        $this->assertSame(6, $t->SelectedIndex());
        $reflection = new \ReflectionProperty(Table::class, 'scrollY');
        $scrollY = $reflection->getValue($t);
        $this->assertLessThanOrEqual(6, $scrollY);
        $this->assertGreaterThanOrEqual(2, $scrollY, 'window followed the cursor down');
        $this->assertStringContainsString('row6', $t->View(), 'the selected row is rendered');
    }

    /**
     * Expansion is tracked by object identity (documented contract); stale
     * identities must be pruned when the rows are replaced, or the list
     * retains dead Row objects that can never be un-toggled (M12).
     */
    public function testReplacedRowsPruneStaleExpansionIdentities(): void
    {
        $t = $this->table();
        $rows = $t->Rows();
        $t = $t->toggleExpanded(0);
        $this->assertTrue($t->isExpanded(0));

        $fresh = $t->withRows([Row::new(RowData::from(['n' => 'brand-new']))]);
        $property = new \ReflectionProperty(Table::class, 'expandedRows');
        $this->assertCount(0, $property->getValue($fresh));
        $this->assertFalse($fresh->isExpanded(0));

        // Same-value re-wrap un-expands (identity contract) but leaves NO leak.
        $repeated = \array_map(
            static fn (Row $r): Row => Row::new(RowData::from(['n' => $r->data->get('n')])),
            $t->Rows(),
        );
        $rewrapped = $t->withRows($repeated);
        $this->assertFalse($rewrapped->isExpanded(0));
        $this->assertCount(0, $property->getValue($rewrapped));
    }

    /**
     * Deep scroll then a narrowing filter: the cursor re-anchor resets the
     * scroll too, so the surviving matches render instead of a blank box.
     */
    public function testFilterAfterDeepScrollShowsMatches(): void
    {
        $t = $this->table(viewport: 5)->withScrollY(12)->Filter('n', 'row1');

        $this->assertSame(0, (new \ReflectionProperty(Table::class, 'scrollY'))->getValue($t));
        $this->assertGreaterThanOrEqual(5, $this->dataLineCount($t));
        $this->assertStringContainsString('row10', $t->View());
    }

    /**
     * A stale scrollY set directly is clamped at render, never stranded:
     * offset 500 on a 20-row frame shows the last viewport of data.
     */
    public function testStaleScrollClampsAtRender(): void
    {
        $t = $this->table(viewport: 5)->withScrollY(500);

        $this->assertSame(15, $t->maxScrollY());
        $this->assertSame(5, $this->dataLineCount($t));
        $this->assertStringContainsString('row19', $t->View());
    }
}
