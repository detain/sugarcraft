<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\App;
use SugarCraft\Sql\ConnectionState;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Renderer;
use SugarCraft\Sql\ResultTable;
use PDO;

/**
 * D-C2 (vertical paging window follows the row cursor) and D-C10 (cells pad
 * plain then style, so SGR bytes never consume column width).
 */
final class ResultTableWindowTest extends TestCase
{
    private const SGR = '/\x1b\[[0-9;]*m/u';

    public function testStyledRowsStayColumnAlignedAcrossNullAndJsonCells(): void
    {
        $rows = [
            ['id' => 1, 'note' => null, 'meta' => ['k' => 'value']],
            ['id' => 22222222, 'note' => 'wide plain text here', 'meta' => null],
        ];
        $lines = explode("\n", ResultTable::fromRows($rows)->withVisibleWidth(200)->render());
        $widths = array_map(
            static fn (string $l): int => mb_strlen((string) preg_replace(self::SGR, '', $l)),
            array_slice($lines, 1, 2),
        );
        $this->assertCount(2, $widths);
        $this->assertSame($widths[0], $widths[1], 'styled rows must paint to identical widths');
    }

    public function testDefaultWindowShowsFirstPageAndDisclosesCount(): void
    {
        $rows = array_map(static fn (int $i): array => ['v' => "a{$i}"], range(1, 30));
        $out = ResultTable::fromRows($rows)->withVisibleWidth(200)->render();
        $this->assertStringContainsString('a1', $out);
        $this->assertStringNotContainsString('a26', $out);
        $this->assertStringContainsString('rows 1–25 of 30', $out);
    }

    public function testRowOffsetReachesTheTailRows(): void
    {
        $rows = array_map(static fn (int $i): array => ['v' => "a{$i}"], range(1, 30));
        $out = ResultTable::fromRows($rows)
            ->withVisibleWidth(200)
            ->withRowOffset(25)
            ->render();
        $this->assertStringContainsString('a26', $out);
        $this->assertStringContainsString('a30', $out);
        $this->assertStringNotContainsString('a2 ', $out);
        $this->assertStringContainsString('rows 26–30 of 30', $out);
    }

    public function testTheAppWindowFollowsTheRowCursor(): void
    {
        // D-C06 re-derives D-C2: the window CENTRES on the cursor instead of
        // page-aligning under it. At 200x60 the budget affords the full
        // 25-row page; cursor 26 (row "a27") pulls the start to the tail
        // clamp (row 6) so the cursor sits mid-window — the old intdiv window
        // started at row 26 and left the first 25 rows unreachable.
        $rows = array_map(static fn (int $i): array => ['v' => "a{$i}"], range(1, 30));
        $db = new SqliteDatabase(new PDO('sqlite::memory:'));
        $app = App::builder()
            ->withDb($db)
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Rows)
            ->withRows($rows)
            ->withResultTable(ResultTable::fromRows($rows))
            ->withRowCursor(26)
            ->build();
        try {
            Renderer::setSize(200, 60);
            $out = Renderer::render($app);
            $this->assertStringContainsString('a27', $out, 'the cursor row itself must render');
            $this->assertStringContainsString('a26', $out);
            $this->assertStringContainsString('rows 6–30 of 30', $out);
            $this->assertStringNotContainsString('a5', $out, 'centring clips the head, not the tail');
        } finally {
            Renderer::resetSizeCache();
        }
    }

    public function testRowOffsetSurvivesColumnScrollClones(): void
    {
        $rows = array_map(static fn (int $i): array => ['v' => "a{$i}"], range(1, 30));
        $table = ResultTable::fromRows($rows)->withRowOffset(25)->scrollRight();
        $out = $table->withVisibleWidth(200)->render();
        $this->assertStringContainsString('rows 26–30 of 30', $out, 'clones must carry the window');
    }
}
