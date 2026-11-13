<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use SugarCraft\CodeShot\SgrState;
use SugarCraft\CodeShot\SvgRenderer;
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

    // -------------------------------------------------------------------------
    // cs-06 ECMA-48 default parameters: the bare SGR forms must reset.
    // Pre-cure, NO test could fail this — bold+red stuck to end of document
    // on 'ESC[m' (the form `tput sgr0` actually ships).
    // -------------------------------------------------------------------------

    /** @return array<string, array{0:string}> */
    public static function bareSgrForms(): array
    {
        return [
            'bare ESC[m (no params)'      => ["\x1b[m"],
            'empty slot ESC[;m'           => ["\x1b[;m"],
            'trailing empty ESC[1;m'      => ["\x1b[1;m"],
            'explicit ESC[0m regression'  => ["\x1b[0m"],
        ];
    }

    /**
     * @dataProvider bareSgrForms
     */
    public function testBareSgrFormsResetAttributes(string $reset): void
    {
        $svg = SvgRenderer::dark()->withWindow(false)->render("\x1b[1;31mRED" . $reset . "PLAIN\n");

        // PLAIN must be a plain <text> element: no fill override, no bold.
        $this->assertMatchesRegularExpression('#<text x="\d+\.\d+" y="\d+\.\d+">PLAIN</text>#', $svg);
        // The reset must not eat the earlier styling either: RED stays styled.
        $this->assertStringContainsString('font-weight="bold">RED</text>', $svg);
    }
}
