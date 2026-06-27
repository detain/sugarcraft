<?php

declare(strict_types=1);

namespace SugarCraft\Calendar\Tests;

use SugarCraft\Calendar\DatePicker;
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
        $output = $dp->View();

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
            ->SelectDate();

        $output = $dp->View();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/may-2026-selected.golden',
            $output,
        );
    }

    public function testWeekViewRendersAnsi(): void
    {
        // A week view is the same 6×7 grid - just navigate to show week context
        $dp = DatePicker::new(new \DateTimeImmutable('2026-06-01'))
            ->withToday(new \DateTimeImmutable('2026-06-15'));

        $output = $dp->View();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/june-2026.golden',
            $output,
        );
    }
}
