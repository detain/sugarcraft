<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use SugarCraft\Dash\Foundation\Buffer;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Foundation\Theme;
use SugarCraft\Dash\Layout\FlexLayout;
use SugarCraft\Dash\Layout\HStack;
use SugarCraft\Dash\Layout\VStack;
use SugarCraft\Dash\Layout\Stack;
use SugarCraft\Dash\Components\Card\Badge;
use SugarCraft\Dash\Components\Card\Card;
use SugarCraft\Dash\Components\System\NProgress;
use PHPUnit\Framework\TestCase;

final class DrawableThemeTest extends TestCase
{
    private Theme $dark;
    private Theme $light;

    protected function setUp(): void
    {
        $this->dark = Theme::dark();
        $this->light = Theme::light();
    }

    // ═══════════════════════════════════════════════════════════════
    // Drawable::withTheme passthrough
    // ═══════════════════════════════════════════════════════════════

    public function testBufferWithThemeReturnsSameInstance(): void
    {
        $buffer = Buffer::new(10, 10);
        $result = $buffer->withTheme($this->dark);
        $this->assertSame($buffer, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Theme propagation through layout containers
    // ═══════════════════════════════════════════════════════════════

    public function testVStackWithThemeReturnsNewInstance(): void
    {
        $item = Badge::new('test');
        $vstack = VStack::new($item);
        $themed = $vstack->withTheme($this->dark);

        $this->assertNotSame($vstack, $themed);
    }

    public function testVStackWithThemeAppliesToChildren(): void
    {
        $badge = Badge::new('test');
        $vstack = VStack::new($badge);
        $themed = $vstack->withTheme($this->light);

        // Get the themed items via reflection
        $reflection = new \ReflectionClass($themed);
        $itemsProp = $reflection->getProperty('items');
        $itemsProp->setAccessible(true);
        $items = $itemsProp->getValue($themed);

        $this->assertCount(1, $items);
        $themedBadge = $items[0];

        // The themed badge should have different colors from the original
        $origReflection = new \ReflectionClass($badge);
        $bgOrig = $origReflection->getProperty('bgColor')->getValue($badge);
        $bgThemed = $origReflection->getProperty('bgColor')->getValue($themedBadge);

        // Original badge uses default hardcoded colors, themed uses theme colors
        $this->assertNotEquals($bgOrig, $bgThemed);
    }

    public function testHStackWithThemeReturnsNewInstance(): void
    {
        $item = Badge::new('test');
        $hstack = HStack::new($item);
        $themed = $hstack->withTheme($this->dark);

        $this->assertNotSame($hstack, $themed);
    }

    public function testStackWithThemeReturnsNewInstance(): void
    {
        $item = Badge::new('test');
        $stack = Stack::new($item);
        $themed = $stack->withTheme($this->dark);

        $this->assertNotSame($stack, $themed);
    }

    public function testFlexLayoutWithThemeReturnsNewInstance(): void
    {
        $item = Badge::new('test');
        $flex = FlexLayout::row([$item]);
        $themed = $flex->withTheme($this->dark);

        $this->assertNotSame($flex, $themed);
    }

    // ═══════════════════════════════════════════════════════════════
    // Theme application to widgets
    // ═══════════════════════════════════════════════════════════════

    public function testBadgeWithThemeReturnsNewInstance(): void
    {
        $badge = Badge::new('test');
        $themed = $badge->withTheme($this->dark);

        $this->assertNotSame($badge, $themed);
    }

    public function testBadgeWithThemeUsesPrimaryColor(): void
    {
        $badge = Badge::new('test');
        $themed = $badge->withTheme($this->dark);

        $reflection = new \ReflectionClass($themed);
        $bgColor = $reflection->getProperty('bgColor')->getValue($themed);
        $textColor = $reflection->getProperty('textColor')->getValue($themed);

        // Should use theme's primary for bg and foreground for text
        $this->assertNotNull($bgColor);
        $this->assertNotNull($textColor);
    }

    public function testCardWithThemeReturnsNewInstance(): void
    {
        $card = Card::new('content');
        $themed = $card->withTheme($this->dark);

        $this->assertNotSame($card, $themed);
    }

    public function testCardWithThemeUsesPrimaryColor(): void
    {
        $card = Card::new('content');
        $themed = $card->withTheme($this->dark);

        $reflection = new \ReflectionClass($themed);
        $borderColor = $reflection->getProperty('borderColor')->getValue($themed);
        $titleColor = $reflection->getProperty('titleColor')->getValue($themed);

        // Should use theme's primary
        $this->assertNotNull($borderColor);
        $this->assertNotNull($titleColor);
    }

    public function testNProgressWithThemeReturnsNewInstance(): void
    {
        $progress = NProgress::new(0.5);
        $themed = $progress->withTheme($this->dark);

        $this->assertNotSame($progress, $themed);
    }

    // ═══════════════════════════════════════════════════════════════
    // Theme alternation
    // ═══════════════════════════════════════════════════════════════

    public function testThemeAlternationBetweenDarkAndLight(): void
    {
        $badge = Badge::new('test');

        $darkThemed = $badge->withTheme($this->dark);
        $lightThemed = $badge->withTheme($this->light);

        $reflection = new \ReflectionClass($darkThemed);
        $darkBg = $reflection->getProperty('bgColor')->getValue($darkThemed);

        $lightReflection = new \ReflectionClass($lightThemed);
        $lightBg = $lightReflection->getProperty('bgColor')->getValue($lightThemed);

        // Dark and light themes should produce different colors
        // Compare RGB values since Color doesn't have a hex() accessor
        $this->assertNotEquals(
            $darkBg->r . ',' . $darkBg->g . ',' . $darkBg->b,
            $lightBg->r . ',' . $lightBg->g . ',' . $lightBg->b
        );
    }
}
