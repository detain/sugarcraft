<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\WindowChromeGeometry;
use PHPUnit\Framework\TestCase;

final class WindowChromeGeometryTest extends TestCase
{
    public function testMacosFactorySetsCorrectCy(): void
    {
        $geo = WindowChromeGeometry::macos(10);
        $this->assertSame(28, $geo->cy);
    }

    public function testMacosFactorySetsCorrectBase(): void
    {
        $geo = WindowChromeGeometry::macos(10);
        $this->assertSame(28, $geo->base);
    }

    public function testMacosFactorySetsCorrectRadius(): void
    {
        $geo = WindowChromeGeometry::macos(10);
        $this->assertSame(6, $geo->r);
    }

    public function testMacosFactorySetsCorrectGap(): void
    {
        $geo = WindowChromeGeometry::macos(10);
        $this->assertSame(18, $geo->gap);
    }

    public function testMacosFactoryHasNoTitleBar(): void
    {
        $geo = WindowChromeGeometry::macos(10);
        $this->assertSame(0, $geo->titleBarHeight);
    }

    public function testMacosFactoryWithDifferentShadowMargin(): void
    {
        $geo = WindowChromeGeometry::macos(20);
        // cy = shadowMargin + 18 = 20 + 18 = 38
        $this->assertSame(38, $geo->cy);
        // base = shadowMargin + 18 = 20 + 18 = 38
        $this->assertSame(38, $geo->base);
    }

    public function testITerm2FactorySetsSmallerRadius(): void
    {
        $geo = WindowChromeGeometry::iterm2(10);
        $this->assertSame(4, $geo->r);
    }

    public function testITerm2FactorySetsSmallerGap(): void
    {
        $geo = WindowChromeGeometry::iterm2(10);
        $this->assertSame(14, $geo->gap);
    }

    public function testITerm2FactorySetsCorrectCy(): void
    {
        $geo = WindowChromeGeometry::iterm2(10);
        // cy = shadowMargin + 14 = 10 + 14 = 24
        $this->assertSame(24, $geo->cy);
    }

    public function testITerm2FactoryHasNoTitleBar(): void
    {
        $geo = WindowChromeGeometry::iterm2(10);
        $this->assertSame(0, $geo->titleBarHeight);
    }

    public function testHyperFactorySetsTitleBarHeight(): void
    {
        $geo = WindowChromeGeometry::hyper(10, 100);
        $this->assertSame(24, $geo->titleBarHeight);
    }

    public function testHyperFactorySetsFrameWidth(): void
    {
        $geo = WindowChromeGeometry::hyper(10, 200);
        $this->assertSame(200, $geo->frameWidth);
    }

    public function testHyperFactoryCalculatesCyFromTitleBar(): void
    {
        $geo = WindowChromeGeometry::hyper(10, 100);
        // titleBarY = shadowMargin = 10
        // cy = titleBarY + (titleBarHeight - r * 2) / 2 = 10 + (24 - 10) / 2 = 10 + 7 = 17
        $this->assertSame(17, $geo->cy);
    }

    public function testHyperFactorySetsRadius(): void
    {
        $geo = WindowChromeGeometry::hyper(10, 100);
        $this->assertSame(5, $geo->r);
    }

    public function testHyperFactorySetsGap(): void
    {
        $geo = WindowChromeGeometry::hyper(10, 100);
        $this->assertSame(16, $geo->gap);
    }

    public function testWindowsTerminalFactorySetsTitleBarHeight(): void
    {
        $geo = WindowChromeGeometry::windowsTerminal(10, 100);
        $this->assertSame(28, $geo->titleBarHeight);
    }

    public function testWindowsTerminalFactorySetsButtonSize(): void
    {
        $geo = WindowChromeGeometry::windowsTerminal(10, 100);
        $this->assertSame(14, $geo->buttonSize);
    }

    public function testWindowsTerminalFactorySetsButtonGap(): void
    {
        $geo = WindowChromeGeometry::windowsTerminal(10, 100);
        $this->assertSame(8, $geo->buttonGap);
    }

    public function testWindowsTerminalFactorySetsFrameWidth(): void
    {
        $geo = WindowChromeGeometry::windowsTerminal(10, 200);
        $this->assertSame(200, $geo->frameWidth);
    }

    public function testWindowsTerminalFactoryHasZeroCyAndBase(): void
    {
        $geo = WindowChromeGeometry::windowsTerminal(10, 100);
        $this->assertSame(0, $geo->cy);
        $this->assertSame(0, $geo->base);
    }

    public function testWindowsTerminalFactoryHasZeroRadiusAndGap(): void
    {
        $geo = WindowChromeGeometry::windowsTerminal(10, 100);
        $this->assertSame(0, $geo->r);
        $this->assertSame(0, $geo->gap);
    }

    public function testAllPropertiesAreReadonly(): void
    {
        $reflection = new \ReflectionClass(WindowChromeGeometry::class);
        foreach (['cy', 'base', 'r', 'gap', 'colors', 'titleBarHeight', 'buttonSize', 'buttonGap', 'frameWidth'] as $prop) {
            $property = $reflection->getProperty($prop);
            $this->assertTrue($property->isReadOnly(), "Property {$prop} should be readonly");
        }
    }

    public function testColorsArrayIsEmptyForAllStyles(): void
    {
        $this->assertSame([], WindowChromeGeometry::macos(10)->colors);
        $this->assertSame([], WindowChromeGeometry::iterm2(10)->colors);
        $this->assertSame([], WindowChromeGeometry::hyper(10, 100)->colors);
        $this->assertSame([], WindowChromeGeometry::windowsTerminal(10, 100)->colors);
    }
}
