<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\SgrState;
use PHPUnit\Framework\TestCase;

final class SgrStateTest extends TestCase
{
    public function testDefaultFgIsNull(): void
    {
        $state = new SgrState();
        $this->assertNull($state->fg);
    }

    public function testDefaultBgIsNull(): void
    {
        $state = new SgrState();
        $this->assertNull($state->bg);
    }

    public function testDefaultBoldIsFalse(): void
    {
        $state = new SgrState();
        $this->assertFalse($state->bold);
    }

    public function testDefaultItalicIsFalse(): void
    {
        $state = new SgrState();
        $this->assertFalse($state->italic);
    }

    public function testDefaultUnderlineIsFalse(): void
    {
        $state = new SgrState();
        $this->assertFalse($state->underline);
    }

    public function testConstructorSetsFg(): void
    {
        $state = new SgrState(fg: '#ff0000');
        $this->assertSame('#ff0000', $state->fg);
    }

    public function testConstructorSetsBg(): void
    {
        $state = new SgrState(bg: '#00ff00');
        $this->assertSame('#00ff00', $state->bg);
    }

    public function testConstructorSetsBold(): void
    {
        $state = new SgrState(bold: true);
        $this->assertTrue($state->bold);
    }

    public function testConstructorSetsItalic(): void
    {
        $state = new SgrState(italic: true);
        $this->assertTrue($state->italic);
    }

    public function testConstructorSetsUnderline(): void
    {
        $state = new SgrState(underline: true);
        $this->assertTrue($state->underline);
    }

    public function testConstructorSetsAllProperties(): void
    {
        $state = new SgrState(
            fg: '#ff0000',
            bg: '#00ff00',
            bold: true,
            italic: true,
            underline: true,
        );

        $this->assertSame('#ff0000', $state->fg);
        $this->assertSame('#00ff00', $state->bg);
        $this->assertTrue($state->bold);
        $this->assertTrue($state->italic);
        $this->assertTrue($state->underline);
    }

    public function testPropertiesAreMutable(): void
    {
        $state = new SgrState();
        $state->fg = '#123456';
        $state->bg = '#654321';
        $state->bold = true;
        $state->italic = true;
        $state->underline = true;

        $this->assertSame('#123456', $state->fg);
        $this->assertSame('#654321', $state->bg);
        $this->assertTrue($state->bold);
        $this->assertTrue($state->italic);
        $this->assertTrue($state->underline);
    }
}
