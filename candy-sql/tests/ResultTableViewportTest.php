<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Renderer;
use SugarCraft\Sql\ResultTable;

/**
 * SQL-C06 (candy-sql-client-006 + client-034): the result grid must honour
 * the pane's height budget. The old unconditional 25-row page overflowed a
 * 100x28 terminal — the Frame clipped the counter, the help line and the
 * query pane away (the editor was unreachable until a resize), and the
 * page-aligned intdiv window could park the row cursor itself inside the
 * clipped band. The window now shrinks to the budget and slides to keep the
 * cursor centred.
 */
final class ResultTableViewportTest extends TestCase
{
    /**
     * @return list<array<string,mixed>>
     */
    private static function rows(int $count, string $prefix = 'r'): array
    {
        return array_map(
            static fn (int $i): array => ['v' => "{$prefix}{$i}"],
            range(1, $count),
        );
    }

    public function testUnboundedRenderKeepsTheLegacyTwentyFiveRowPage(): void
    {
        $out = ResultTable::fromRows(self::rows(40))->withVisibleWidth(120)->render();
        $lines = explode("\n", $out);
        // header + 25 rows + counter — byte-identical to the pre-C06 shape.
        $this->assertCount(27, $lines);
        $this->assertStringContainsString('rows 1–25 of 40', $out);
        $this->assertStringContainsString('r25', $out);
        $this->assertStringNotContainsString('r26', $out);
    }

    public function testBudgetShrinksTheWindowToThePane(): void
    {
        $out = ResultTable::fromRows(self::rows(60))->withVisibleWidth(120)
            ->withViewport(12, 0)
            ->render();
        $lines = explode("\n", $out);
        // header + 9 rows (12 - 3 reserved) + counter = 11 ≤ 12.
        $this->assertLessThanOrEqual(12, count($lines));
        $this->assertStringContainsString('rows 1–9 of 60', $out);
        $this->assertStringContainsString('r9', $out);
        $this->assertStringNotContainsString('r10', $out);
    }

    public function testCursorNeverSitsInTheClippedBand(): void
    {
        // Cursor on the last row (index 55) of 60, pane budget 12: the old
        // page-aligned window (start 50) would clip rows 53-60 away — the
        // cursor row itself would vanish. Centred, it must be visible.
        $out = ResultTable::fromRows(self::rows(60))->withVisibleWidth(120)
            ->withViewport(12, 55)
            ->render();
        $lines = explode("\n", $out);
        $this->assertLessThanOrEqual(12, count($lines));
        $this->assertStringContainsString('r56', $out, 'the cursor row must render');
        $this->assertStringContainsString('rows 52–60 of 60', $out);
        $this->assertStringNotContainsString('r51', $out);
    }

    public function testRowOffsetStillWindowsWithoutACursor(): void
    {
        $out = ResultTable::fromRows(self::rows(30))->withVisibleWidth(120)
            ->withRowOffset(25)
            ->withViewport(8)
            ->render();
        $lines = explode("\n", $out);
        // header + min(25, 8-3) = 5 rows + counter = 7.
        $this->assertCount(7, $lines);
        $this->assertStringContainsString('rows 26–30 of 30', $out);
    }

    public function testEmptyResultSetIgnoresTheBudget(): void
    {
        $out = ResultTable::fromRows([])->withViewport(4, 3)->render();
        $this->assertStringContainsString('(empty result set)', $out);
    }

    // ── End-to-end frame probes (the live 100x28 acceptance) ────────────────

    public function testAFortyRowGridFitsATwentyEightRowTerminalWholeFrame(): void
    {
        $rows = self::rows(40, 'a');
        $db = new SqliteDatabase(new PDO('sqlite::memory:'));
        $app = App::builder()
            ->withDb($db)
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Rows)
            ->withRows($rows)
            ->withResultTable(ResultTable::fromRows($rows))
            ->withRowCursor(39)
            ->build();
        try {
            Renderer::setSize(100, 28);
            $out = Renderer::render($app);
            $lines = explode("\n", $out);
            $this->assertLessThanOrEqual(28, count($lines), 'the whole frame must fit the pane');
            // The cursor row — the deep tail of the result — is visible…
            $this->assertStringContainsString('a40', $out);
            // …and everything below the grid survived the clip: help line,
            // query prompt. (Pre-C06 these vanished at 28 rows.)
            $this->assertStringContainsString('esc', $out, 'help line visible');
            $this->assertStringContainsString('type SQL', $out, 'query pane visible');
        } finally {
            Renderer::resetSizeCache();
        }
    }
}
