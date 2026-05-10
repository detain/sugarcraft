<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Progress;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Progress\Progress;
use SugarCraft\Bits\Progress\ProgressRenderMode;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

final class ProgressRenderModeTest extends TestCase
{
    public function testEnumCasesExist(): void
    {
        $this->assertSame('Block', ProgressRenderMode::Block->name);
        $this->assertSame('Line', ProgressRenderMode::Line->name);
        $this->assertSame('Slim', ProgressRenderMode::Slim->name);
    }

    public function testDefaultRenderModeIsBlock(): void
    {
        $p = Progress::new();
        $this->assertSame(ProgressRenderMode::Block, $p->renderMode);
    }

    public function testLineModeZeroPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withRenderMode(ProgressRenderMode::Line);
        $rendered = $p->withPercent(0.0)->view();
        // All empty: ─ (U+2500) × 10
        $this->assertSame(str_repeat("\xe2\x94\x80", 10), $rendered);
    }

    public function testLineModeFullPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withRenderMode(ProgressRenderMode::Line);
        $rendered = $p->withPercent(1.0)->view();
        // All filled: ━ (U+2501) × 10
        $this->assertSame(str_repeat("\xe2\x94\x81", 10), $rendered);
    }

    public function testLineModeHalfPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withRenderMode(ProgressRenderMode::Line);
        $rendered = $p->withPercent(0.5)->view();
        // 5 filled ━ + 5 empty ─
        $this->assertSame(
            str_repeat("\xe2\x94\x81", 5) . str_repeat("\xe2\x94\x80", 5),
            $rendered
        );
    }

    public function testLineModeIgnoresShowPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withRenderMode(ProgressRenderMode::Line)
            ->withShowPercent(true);
        $rendered = $p->withPercent(0.5)->view();
        // No percentage text should appear in Line mode
        $this->assertSame(10, \SugarCraft\Core\Util\Width::string($rendered));
        $this->assertStringNotContainsString('%', $rendered);
    }

    public function testLineModeSnapshot(): void
    {
        $p = Progress::new()
            ->withWidth(20)
            ->withRenderMode(ProgressRenderMode::Line);
        $rendered = $p->withPercent(0.75)->view();
        // 15 filled ━ + 5 empty ─
        $expected = str_repeat("\xe2\x94\x81", 15) . str_repeat("\xe2\x94\x80", 5);
        $this->assertSame($expected, $rendered);
    }

    public function testLineModeWithColor(): void
    {
        $p = Progress::new()
            ->withWidth(5)
            ->withRenderMode(ProgressRenderMode::Line)
            ->withFillColor(Color::hex('#ff0000'))
            ->withColorProfile(ColorProfile::TrueColor);
        $rendered = $p->withPercent(1.0)->view();
        $this->assertStringStartsWith("\x1b[38;2;255;0;0m", $rendered);
        $this->assertStringContainsString("\xe2\x94\x81", $rendered);
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    public function testSlimModeZeroPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withRenderMode(ProgressRenderMode::Slim)
            ->withShowPercent(true)
            ->withPercentFormat('%d%%');
        $rendered = $p->withPercent(0.0)->view();
        // At 0% with width=10, barWidth is reduced to 7 (10 - 3 for "0%")
        // So we get 7 empty ▒ chars plus " 0%"
        $this->assertStringContainsString(str_repeat("\xe2\x96\x92", 7), $rendered);
        $this->assertStringContainsString('0%', $rendered);
    }

    public function testSlimModeFullPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withRenderMode(ProgressRenderMode::Slim)
            ->withShowPercent(true)
            ->withPercentFormat('%d%%');
        $rendered = $p->withPercent(1.0)->view();
        // All filled ▌ (width reduced for percent text)
        $this->assertStringContainsString("\xe2\x96\x8c", $rendered);
        $this->assertStringContainsString('100%', $rendered);
    }

    public function testSlimModeHalfPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withRenderMode(ProgressRenderMode::Slim)
            ->withShowPercent(true)
            ->withPercentFormat('%d%%');
        $rendered = $p->withPercent(0.5)->view();
        // Some filled ▌ + some empty ▒ + " 50%"
        $this->assertStringContainsString("\xe2\x96\x8c", $rendered); // ▌ filled
        $this->assertStringContainsString("\xe2\x96\x92", $rendered); // ▒ empty
        $this->assertStringContainsString('50%', $rendered);
    }

    public function testSlimModeSnapshot(): void
    {
        $p = Progress::new()
            ->withWidth(20)
            ->withRenderMode(ProgressRenderMode::Slim)
            ->withShowPercent(true)
            ->withPercentFormat('%d%%');
        $rendered = $p->withPercent(0.65)->view();
        // Width reduced for "65%" suffix (3 cells + 1 space = 4)
        // filled = round(0.65 * 16) = 10, empty = 6
        $this->assertStringContainsString("\xe2\x96\x8c", $rendered); // ▌
        $this->assertStringContainsString("\xe2\x96\x92", $rendered); // ▒
        $this->assertStringContainsString('65%', $rendered);
    }

    public function testSlimModeWithColor(): void
    {
        $p = Progress::new()
            ->withWidth(5)
            ->withRenderMode(ProgressRenderMode::Slim)
            ->withShowPercent(true)
            ->withPercentFormat('%d%%')
            ->withFillColor(Color::hex('#00ff00'))
            ->withColorProfile(ColorProfile::TrueColor);
        $rendered = $p->withPercent(1.0)->view();
        $this->assertStringStartsWith("\x1b[38;2;0;255;0m", $rendered);
        $this->assertStringContainsString("\xe2\x96\x8c", $rendered); // ▌
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    public function testWithRenderModeReturnsNewInstance(): void
    {
        $p1 = Progress::new();
        $p2 = $p1->withRenderMode(ProgressRenderMode::Line);
        $this->assertNotSame($p1, $p2);
        $this->assertSame(ProgressRenderMode::Block, $p1->renderMode);
        $this->assertSame(ProgressRenderMode::Line, $p2->renderMode);
    }

    public function testBlockModeStillWorks(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withShowPercent(false)
            ->withRunes('#', '.')
            ->withRenderMode(ProgressRenderMode::Block);
        $this->assertSame('#####.....', $p->withPercent(0.5)->view());
    }

    public function testFluentWithRenderMode(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withRenderMode(ProgressRenderMode::Line)
            ->withPercent(0.5);
        $this->assertSame(
            str_repeat("\xe2\x94\x81", 5) . str_repeat("\xe2\x94\x80", 5),
            $p->view()
        );
    }
}
