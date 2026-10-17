<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use SugarCraft\Log\Styles;
use SugarCraft\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

final class StylesTest extends TestCase
{
    public function testPadLevelTextPadsShortLabels(): void
    {
        $this->assertSame('DBG  ', Styles::padLevelText('DBG'));
        $this->assertSame('INF  ', Styles::padLevelText('INF'));
        $this->assertSame('WRN  ', Styles::padLevelText('WRN'));
    }

    public function testPadLevelTextLeavesLongLabelsUnchanged(): void
    {
        $this->assertSame('DEBUG', Styles::padLevelText('DEBUG'));
        $this->assertSame('ERROR', Styles::padLevelText('ERROR'));
        $this->assertSame('FATAL', Styles::padLevelText('FATAL'));
    }

    public function testKeyStyleReturnsStyleForKnownField(): void
    {
        $styles = Styles::default();

        $this->assertInstanceOf(Style::class, $styles->keyStyle('time'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('level'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('prefix'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('caller'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('message'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('key'));
        $this->assertInstanceOf(Style::class, $styles->keyStyle('value'));
    }

    public function testKeyStyleReturnsDefaultStyleForUnknownField(): void
    {
        $styles = Styles::default();
        $this->assertInstanceOf(Style::class, $styles->keyStyle('unknown'));
    }

    public function testKeysMapIsPopulated(): void
    {
        $styles = Styles::default();
        $this->assertArrayHasKey('time', $styles->keys);
        $this->assertArrayHasKey('level', $styles->keys);
        $this->assertArrayHasKey('prefix', $styles->keys);
        $this->assertArrayHasKey('caller', $styles->keys);
        $this->assertArrayHasKey('message', $styles->keys);
        $this->assertArrayHasKey('key', $styles->keys);
        $this->assertArrayHasKey('value', $styles->keys);
    }

    public function testDefaultReturnsNewInstance(): void
    {
        $a = Styles::default();
        $b = Styles::default();
        $this->assertInstanceOf(Styles::class, $a);
        $this->assertInstanceOf(Styles::class, $b);
    }
}
