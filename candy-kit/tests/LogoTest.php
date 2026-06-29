<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Kit\Logo;

final class LogoTest extends TestCase
{
    public function testFromAsciiReturnsRawString(): void
    {
        $logo = Logo::fromAscii("hello\nworld");
        $this->assertSame("hello\nworld", $logo->render());
    }

    public function testSugarcraftPresetIsAsciiArtBox(): void
    {
        $logo = Logo::sugarcraft();
        $rendered = $logo->render();
        // The ASCII art spells out SugarCraft in box-drawing chars.
        $this->assertStringContainsString('╔', $rendered);
        $this->assertStringContainsString('║', $rendered);
        $this->assertStringContainsString('╚', $rendered);
    }

    public function testSugarcraftPresetContainsBoxDrawingChars(): void
    {
        $logo = Logo::sugarcraft();
        $rendered = $logo->render();
        $this->assertStringContainsString('╔', $rendered);
        $this->assertStringContainsString('║', $rendered);
        $this->assertStringContainsString('╚', $rendered);
    }

    public function testSugarcraftPresetIsMultiLine(): void
    {
        $logo = Logo::sugarcraft();
        $lines = explode("\n", $logo->render());
        $this->assertGreaterThan(5, count($lines));
    }

    public function testWithColorWrapsInSgr(): void
    {
        $logo = Logo::fromAscii("hello")->withColor('#ff5fd2');
        $rendered = $logo->render();
        $this->assertStringContainsString("\x1b[", $rendered);
        $this->assertStringContainsString('hello', $rendered);
    }

    public function testWithColorAcceptsHexString(): void
    {
        $logo = Logo::fromAscii("test")->withColor('#abcdef');
        $this->assertStringContainsString('test', $logo->render());
    }

    public function testWithColorIsImmutable(): void
    {
        $original = Logo::fromAscii("hello");
        $colored = $original->withColor('#ff5fd2');
        $this->assertNotSame($original, $colored);
        $this->assertStringNotContainsString("\x1b[", $original->render());
        $this->assertStringContainsString("\x1b[", $colored->render());
    }

    /** The $color instanceof Color branch in withColor() must be exercised too. */
    public function testWithColorAcceptsColorInstance(): void
    {
        $logo = Logo::fromAscii("test")->withColor(Color::hex('#ff5fd2'));
        $rendered = $logo->render();
        // Must produce SGR and contain the ASCII text.
        $this->assertStringContainsString("\x1b[", $rendered);
        $this->assertStringContainsString('test', $rendered);
    }

    public function testRenderReturnsString(): void
    {
        $logo = Logo::fromAscii("test");
        $this->assertIsString($logo->render());
    }

    public function testEmptyAscii(): void
    {
        $logo = Logo::fromAscii('');
        $this->assertSame('', $logo->render());
    }

    public function testSugarcraftWithColorIsChained(): void
    {
        $logo = Logo::sugarcraft()->withColor('#ff5fd2');
        $rendered = $logo->render();
        $this->assertStringContainsString('╔', $rendered);
        $this->assertStringContainsString("\x1b[", $rendered);
    }
}