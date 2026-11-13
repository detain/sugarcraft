<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;

/**
 * Pins the empty-state render family (audit sugar-table CR1 + MAJOR 10).
 *
 * Before the fix, the no-data line rendered ONLY when the header was hidden —
 * and that one working path crashed: the message row was never budgeted into
 * the buffer height, so bordered renders wrote past a too-short buffer
 * (OutOfBoundsException) and borderless ones asked Buffer for zero height
 * (InvalidArgumentException). An empty table WITH a header rendered a silent
 * blank box. These tests hold all four shapes open.
 */
final class TableEmptyStateTest extends TestCase
{
    /** Strip SGR so assertions see the painted text. */
    private static function plain(string $ansi): string
    {
        return (string) \preg_replace('/\x1b\[[0-9;]*m/', '', $ansi);
    }

    private static function wideTable(): Table
    {
        return Table::fromColumns([Column::new('name', 'Name', 30)]);
    }

    public function testEmptyTableWithoutHeaderRendersNoDataMessage(): void
    {
        $view = self::wideTable()->withRows([])->withShowHeader(false)->View();
        self::assertStringContainsString('No data', self::plain($view));
    }

    public function testEmptyTableWithHeaderRendersMessageUnderneathTheHeader(): void
    {
        // MAJOR 10: the empty state used to be UNREACHABLE with a header —
        // the box went silently blank.
        $view = self::plain(self::wideTable()->withRows([])->View());
        self::assertStringContainsString('Name', $view);
        self::assertStringContainsString('No data', $view);
    }

    public function testEmptyBorderlessTableDoesNotCrash(): void
    {
        // Buffer dimensions must be positive: the un-budgeted message row
        // produced height 0 here and threw.
        $view = self::wideTable()->withRows([])->withBorderless(true)->View();
        self::assertStringContainsString('No data', self::plain($view));
    }

    public function testEmptyTableWithFooterArmedDoesNotCrash(): void
    {
        $view = self::wideTable()
            ->withRows([])
            ->withShowHeader(false)
            ->withPageSize(5)
            ->withShowFooter(true)
            ->View();
        self::assertStringContainsString('No data', self::plain($view));
    }

    public function testFilteringToNothingRendersTheMessageNotABlankBox(): void
    {
        $rows = [
            Row::new(RowData::from(["name" => "alice"])),
            Row::new(RowData::from(["name" => "bob"])),
        ];
        $view = self::plain(
            self::wideTable()->withRows($rows)->Filter('name', 'zzz-miss')->View()
        );
        // ST-21: the narrowed-to-zero state names itself — finding 021's
        // pixel-identical empty store is exactly what this separates.
        self::assertStringContainsString('No rows match your filter', $view);
        self::assertStringNotContainsString('No data', $view);
        self::assertStringNotContainsString('alice', $view);
    }

    public function testSearchNarrowedToZeroSaysNoMatches(): void
    {
        $rows = [
            Row::new(RowData::from(['name' => 'alice'])),
            Row::new(RowData::from(['name' => 'bob'])),
        ];
        $view = self::plain(
            self::wideTable()->withRows($rows)->search('zzz-miss')->View()
        );
        self::assertStringContainsString('No rows match your filter', $view);
    }

    public function testEmptyStoreKeepsSayingNoData(): void
    {
        $view = self::plain(self::wideTable()->withRows([])->View());
        self::assertStringContainsString('No data', $view);
        self::assertStringNotContainsString('match', $view);
    }

    public function testHidingEveryColumnRendersNothingInsteadOfCrashing(): void
    {
        $table = Table::fromColumns([
            Column::new('a', 'A', 5),
            Column::new('b', 'B', 5),
        ])->withRows([Row::new(RowData::from(["a" => "1", "b" => "2"]))])->withHiddenCols([0, 1]);

        self::assertSame('', $table->View());
        self::assertSame('', $table->withBorderless(true)->View());
    }
}
