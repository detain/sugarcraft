<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use SugarCraft\DatePicker\DatePicker;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for DatePicker Buffer rendering.
 *
 * These tests capture the byte-exact ANSI output of View() to detect
 * unintended changes to terminal rendering.
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testJanuary2026RendersAnsi(): void
    {
        // January 2026: first day is a Thursday (offset 4), has 31 days
        $dp = DatePicker::new(new \DateTimeImmutable('2026-01-15'))
            ->withToday(new \DateTimeImmutable('2026-06-15'));
        $output = $dp->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/january-2026.golden',
            $output,
        );
    }

    public function testMay2026WithSelectionRendersAnsi(): void
    {
        // May 2026: first day is a Friday (offset 5), has 31 days
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-06-15'))
            ->selectDate();

        $output = $dp->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/may-2026-selected.golden',
            $output,
        );
    }

    public function testPlainViewJuneGoldenRendersAnsi(): void
    {
        // Historically misnamed testWeekViewRendersAnsi (sugar-datepicker-019):
        // it called view() WITHOUT the week flag — a third plain month golden,
        // not a week view. Renamed to what it actually pins; the real
        // week-column golden is testWeekColumnFebruaryGoldenRendersAnsi below.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-06-01'))
            ->withToday(new \DateTimeImmutable('2026-06-15'));

        $output = $dp->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/june-2026.golden',
            $output,
        );
    }

    public function testWeekColumnFebruaryGoldenRendersAnsi(): void
    {
        // sugar-datepicker-019: the genuine week-number column golden.
        // February 2026 starts on a Sunday and is 28 days, so grid rows 5
        // and 6 are entirely empty — this month exercises weekRowHasDay
        // ghost suppression (no ISO number is printed for a dead row), the
        // WEEK_COL_CHARS=4 content offsets, and the isoWeekNumber math on a
        // year-boundary-adjacent month (ISO weeks 6-9).
        $dp = DatePicker::new(new \DateTimeImmutable('2026-02-15'))
            ->withToday(new \DateTimeImmutable('2026-06-15'));

        $output = $dp->view(21, true);

        $this->assertNotEmpty($output);
        $rows = explode("\n", $output);
        // Ghost suppression: the last two grid weeks of February 2026 hold
        // no days, so they must be bare whitespace — no ISO number, no
        // separator, no stray digit.
        $this->assertCount(9, $rows);
        $this->assertSame(trim(str_replace("\x1b", '', $rows[7])), '', 'dead row 8 must render empty');
        $this->assertSame(trim(str_replace("\x1b", '', $rows[8])), '', 'dead row 9 must render empty');
        $this->assertMatchesRegularExpression('/\d/', $rows[6], 'the last live row still carries week numbers and days');
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/february-2026-week.golden',
            $output,
        );
    }
}
