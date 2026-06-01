<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for ANSI rendering output.
 *
 * These tests capture the byte-exact output of render() methods
 * to detect unintended changes to terminal output.
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testSparklineBasicRendersAnsi(): void
    {
        $s = Sparkline::new([1, 4, 2, 7, 5])->withWidth(5);
        $output = $s->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/sparkline-basic.golden',
            $output,
        );
    }

    public function testSparklineWithStyleRendersAnsi(): void
    {
        $s = Sparkline::new([1, 4, 2, 7, 5, 3])
            ->withWidth(6)
            ->withStyle(Style::new()->fg('196'));

        $output = $s->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/sparkline-styled.golden',
            $output,
        );
    }

    public function testSparklineWithExplicitMinMaxRendersAnsi(): void
    {
        $s = Sparkline::new([10, 20, 15, 25])
            ->withWidth(4)
            ->withMin(0)
            ->withMax(100);

        $output = $s->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/sparkline-minmax.golden',
            $output,
        );
    }
}
