<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Variables;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\AsyncCachingServerContext;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Admin\Variables\VariablesPage;
use SugarCraft\Sql\Core\Msg\ReloadReportMsg;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-P09 (adminpages-010/-017) pins: the Variables grid builds rows for a
 * window around the cursor, the derived filter list memoizes on exactly its
 * inputs, and neither may cost the user a reachable row — actions and
 * arrivals still read the FULL list.
 */
final class VariablesRowWindowTest extends TestCase
{
    /** @param list<array{Variable_name:string,Value:string}> $rows */
    private function page(array $rows): VariablesPage
    {
        $db = new FakeDatabase();
        $db->setQueryResult($rows);
        return new VariablesPage(new ServerContext($db));
    }

    /** @return list<array{Variable_name:string,Value:string}> */
    private function names(int $count, string $prefix = 'var_'): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['Variable_name' => $prefix . \sprintf('%03d', $i), 'Value' => 'x'];
        }
        return $rows;
    }

    private function read(VariablesPage $page, string $field): mixed
    {
        return (new ReflectionProperty(VariablesPage::class, $field))->getValue($page);
    }

    private function press(VariablesPage $page, string $ch): VariablesPage
    {
        [$next, ] = $page->update(new KeyMsg(KeyType::Char, $ch));
        return $next;
    }

    /**
     * Count rendered data rows: every name cell draws as "│var_" on its own
     * line (the widget's border glyph, never a stray ESC parameter — the P06
     * anti-literal family keeps this needle payload-specific).
     */
    private function renderedDataRowCount(string $view, string $prefix = 'var_'): int
    {
        $count = 0;
        foreach (\explode("\n", $view) as $line) {
            if (\str_contains($line, "\u{2502}" . $prefix)) {
                $count++;
            }
        }
        return $count;
    }

    public function testFramesDoNotRecomputeTheFilterList(): void
    {
        $page = $this->page($this->names(120));
        $page->view();
        $page->view();
        $third = $page->view();

        $this->assertStringContainsString('var_001', $third, 'non-vacuity: the grid rendered');
        $this->assertSame(1, $this->read($page, 'dataEpoch'), 'unchanged map content must not bump the data identity');
        $this->assertSame(1, $this->read($page, 'filterComputes'), 'three frames must run ONE filter pass');
    }

    public function testGridBuildsOnlyTheWindow(): void
    {
        $view = $this->page($this->names(120))->view();

        $this->assertSame(50, $this->renderedDataRowCount($view), 'rows built = GRID_WINDOW, not 120');
        $this->assertStringContainsString('var_050', $view, 'window bottom edge must render');
        $this->assertStringNotContainsString('var_051', $view, 'rows past the window must not be built');
        $this->assertStringNotContainsString('var_120', $view);
    }

    public function testWindowShiftsOnlyWhenTheCursorLeavesIt(): void
    {
        $page = $this->page($this->names(120));
        // Keys are live only after the first frame (the page's contract since
        // before this row: loadVariables runs in build()); prime like the app.
        $page->view();
        for ($i = 0; $i < 3; $i++) {
            $page = $this->press($page, 'j');
        }
        $this->assertSame(0, $this->read($page, 'gridScrollOffset'), 'a cursor inside the window must not shift it');
        $this->assertStringContainsString('var_001', $page->view(), 'top bytes stay while the cursor rides inside');

        for ($i = 3; $i < 60; $i++) {
            $page = $this->press($page, 'j');
        }
        $this->assertSame(60, $this->read($page, 'selectedRowIndex'), 'non-vacuity: the cursor arrived');
        $this->assertSame(11, $this->read($page, 'gridScrollOffset'), 'minimal shift: cursor - WINDOW + 1');

        $view = $page->view();
        $this->assertStringNotContainsString('var_011', $view, 'the row just above the window is gone');
        $this->assertStringContainsString('var_012', $view, 'window top edge renders');
        $this->assertStringContainsString('var_060', $view, 'the cursor row itself must be rendered');
        $this->assertStringNotContainsString('var_062', $view);
        $this->assertSame(50, $this->renderedDataRowCount($view));
    }

    public function testChangedQueryRecomputesOnceStableQueryRidesTheMemo(): void
    {
        $page = $this->page($this->names(120));
        $page->view();
        $before = $this->read($page, 'filterComputes');

        $typed = $page->withSearch('var_00');
        $firstTyped = $typed->view();
        $this->assertStringContainsString('var_001', $firstTyped, 'non-vacuity: filtered view rendered');
        $this->assertGreaterThan($before, $this->read($typed, 'filterComputes'), 'a changed query must recompute');

        $secondTyped = $typed->view();
        $this->assertStringContainsString('var_001', $secondTyped);
        $this->assertSame($this->read($typed, 'filterComputes'), $this->read($typed, 'filterComputes'));
        // Two frames on the SAME query: the second rides the memo — proves the
        // counter is a pass counter, not a read counter (the 120-name base
        // page had ONE compute across three frames in the sibling test).
        $typed->view();
        $typed->view();
        $this->assertSame($before + 1, $this->read($typed, 'filterComputes'));
    }

    public function testEditorTargetsTheCursorRowBeyondTheOriginalWindow(): void
    {
        $page = $this->page($this->names(120));
        $page->view(); // prime like the app: the first frame precedes any key
        for ($i = 0; $i < 60; $i++) {
            $page = $this->press($page, 'j');
        }
        $page = $this->press($page, 'e');
        $view = $page->view();

        // handleEdit resolves filtered[60] = var_061 from the FULL list — the
        // sliced grid build must not shift what the action layer sees.
        $this->assertStringContainsString('var_061', $view, 'the edit dialog opens on the cursor row by name');
    }

    public function testArrivalRecomputesExactlyOnceAndSurfacesNewData(): void
    {
        $db = new FakeDatabase();
        $db->setQueryResult($this->names(120));
        $inner = new ServerContext($db, Flavor::MySQL);
        $context = new AsyncCachingServerContext($inner, null, [], false);

        $page = new VariablesPage($context);
        $baseMap = [];
        for ($i = 1; $i <= 120; $i++) {
            $baseMap['var_' . \sprintf('%03d', $i)] = 'x';
        }
        // The admin tick delivers the status snapshot through A06's typed
        // slot — that is the arrival channel the async context adopts.
        AdminQueryCache::instance()->storeStatusSnapshot($baseMap);
        [$page, ] = $page->update(new ReloadReportMsg());
        $page->view();
        $computesAfterPrime = $this->read($page, 'filterComputes');
        $this->assertSame(1, $computesAfterPrime, 'non-vacuity: the primed arrival derived once');

        // A second arrival adds one variable (sorted to the TOP, so it must
        // surface inside the window) — frames between arrivals ride the memo.
        $page->view();
        $page->view();
        $this->assertSame($computesAfterPrime, $this->read($page, 'filterComputes'), 'frames after the arrival add no passes');

        $fresh = $baseMap;
        $fresh = ['aaa_arrived' => 'new'] + $fresh;
        AdminQueryCache::instance()->storeStatusSnapshot($fresh);

        [$page, ] = $page->update(new ReloadReportMsg());
        $view = $page->view();

        $this->assertStringContainsString('aaa_arrived', $view, 'the arrival must surface through the window');
        $this->assertSame($computesAfterPrime + 1, $this->read($page, 'filterComputes'), 'one recompute for the arrival, not per frame');
    }

    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }
}
