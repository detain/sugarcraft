<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Position, Toast, ToastType};
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for Toast Buffer rendering.
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

    public function testEmptyQueueRendersBackground(): void
    {
        $t = Toast::new(50)->withPosition(Position::TopLeft);
        $bg = \str_repeat("line\n", 12);
        $output = $t->View($bg, 80, 12);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/empty-queue.golden',
            $output,
        );
    }

    public function testSingleInfoAlertRendersAnsi(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->info('Operation completed successfully');
        $bg = \str_repeat("line\n", 12);
        $output = $t->View($bg, 80, 12);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/single-info-alert.golden',
            $output,
        );
    }

    public function testThreeStackedAlertsRendersAnsi(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->error('Something went wrong')
            ->warning('This is a warning')
            ->info('Here is some information');
        $bg = \str_repeat("line\n", 12);
        $output = $t->View($bg, 80, 12);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/three-stacked-alerts.golden',
            $output,
        );
    }

    public function testMaxConcurrentAlertsRendersAnsi(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->withMaxConcurrent(3)
            ->info('Alert 1')
            ->info('Alert 2')
            ->info('Alert 3')
            ->info('Alert 4'); // This one should be dropped (DropOldest)
        $bg = \str_repeat("line\n", 12);
        $output = $t->View($bg, 80, 12);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/max-concurrent-alerts.golden',
            $output,
        );
    }
}
