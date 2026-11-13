<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Connections;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\AsyncCachingServerContext;
use SugarCraft\Sql\Admin\Connections\ConnectionsPage;
use SugarCraft\Sql\Admin\ServerContext;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Tests\Admin\FakeDatabase;

/**
 * SQL-P09 (adminpages-010) pins: the Connections grid builds Row objects for
 * a head window only — the pane clips everything past the frame edge anyway
 * — while the FULL filtered list still drives counters, cursor resolution
 * and the detail tab.
 */
final class ConnectionsRowWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        AdminQueryCache::reset();
    }

    /**
     * @return array<string, string|int|null>
     */
    private function showRow(string $id): array
    {
        return [
            'Id' => $id,
            'User' => 'u' . $id,
            'Host' => 'localhost',
            'db' => 'my',
            'Command' => 'Query',
            'Time' => '0',
            'State' => '',
            'Info' => 'SELECT 1',
        ];
    }

    private function wiredPage(int $rows): ConnectionsPage
    {
        $seed = [];
        for ($i = 1; $i <= $rows; $i++) {
            $seed[] = $this->showRow((string) $i);
        }
        AdminQueryCache::instance()->store('SHOW FULL PROCESSLIST', $seed);

        $inner = new ServerContext(new FakeDatabase(), Flavor::MySQL);
        $page = ConnectionsPage::new(new AsyncCachingServerContext($inner, null, ['max_connections' => '151'], false));
        // One read warms the fetch path (mirrors ConnectionsPageMemoTest).
        $this->assertCount($rows, $page->filteredProcesslist(), 'non-vacuity: the full list is derived');
        return $page;
    }

    private function dataRowCount(string $view): int
    {
        $count = 0;
        // The User cell is right-aligned and SGR-wrapped, so no byte needle
        // survives adjacent to the border glyph; strip the styling and match
        // the cell shape (the header's 'User' cannot match u\d+).
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $view);
        foreach (\explode("\n", $plain) as $line) {
            if (\preg_match("/\u{2502}\s+u\d+\u{2502}/u", $line)) {
                $count++;
            }
        }
        return $count;
    }

    public function testTableBuildsOnlyTheHeadWindow(): void
    {
        $view = $this->wiredPage(120)->table()->View();

        $this->assertSame(50, $this->dataRowCount($view), 'rows built = ROW_WINDOW, not 120');
        $this->assertStringContainsString('u50', $view, 'window bottom edge renders');
        $this->assertStringNotContainsString('u51', $view, 'rows past the window must not be built');
        $this->assertStringNotContainsString('u120', $view);
    }

    public function testSmallListsRenderUnchanged(): void
    {
        $view = $this->wiredPage(12)->table()->View();

        $this->assertSame(12, $this->dataRowCount($view), 'a list inside the window renders complete');
        $this->assertStringContainsString('u12', $view);
    }

    public function testCursorStillResolvesRowsOutsideTheBuiltWindow(): void
    {
        $page = $this->wiredPage(120);
        $deep = $page->withSelectedIndex(95);

        // The action/detail layer reads the FULL filtered list — row-build
        // windowing must not renumber what the cursor indexes.
        $this->assertSame(95, $deep->selectedIndex());
        $row = $deep->filteredProcesslist()[95] ?? null;
        $this->assertNotNull($row, 'row 96 of 120 is still addressable');
        $this->assertSame('96', (string) $row->processId);
    }
}
