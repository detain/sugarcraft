<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests;

use SugarCraft\Bits\Progress\Progress;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
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

    public function testProgressBarWithFillColorRendersAnsi(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withShowPercent(false)
            ->withRunes('#', '.')
            ->withFillColor(Color::hex('#ff0000'))
            ->withColorProfile(ColorProfile::TrueColor)
            ->withPercent(1.0);

        $output = $p->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/progress-fill-color.golden',
            $output,
        );
    }

    public function testProgressBarWithGradientRendersAnsi(): void
    {
        $p = Progress::new()
            ->withWidth(5)
            ->withShowPercent(false)
            ->withRunes('#', '.')
            ->withGradient(Color::hex('#ff0000'), Color::hex('#00ff00'))
            ->withPercent(1.0);

        $output = $p->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/progress-gradient.golden',
            $output,
        );
    }

    public function testProgressBarSlimModeWithColorRendersAnsi(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withShowPercent(true)
            ->withFillColor(Color::hex('#0000ff'))
            ->withColorProfile(ColorProfile::TrueColor)
            ->withPercent(0.5);

        $output = $p->view();

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/progress-slim-color.golden',
            $output,
        );
    }
}
