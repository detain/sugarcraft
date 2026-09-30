<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Kit\Logo;

final class LogoTest extends TestCase
{
    /**
     * Regression pin (fix wave): every line of the built-in preset shares one
     * display width, so the right rail forms a straight column. The original
     * figlet rows overflowed the box (widths 63,63,63,65,64,65,65,63); a
     * re-flowed preset that breaks alignment reddens here before a careless
     * golden re-roll could hide it.
     */
    public function testSugarcraftPresetIsUniformDisplayWidth(): void
    {
        $lines = explode("\n", Logo::sugarcraft()->render());
        self::assertCount(8, $lines);
        $widths = array_map(static fn (string $l): int => Width::string($l), $lines);
        self::assertSame(
            [$widths[0]],
            array_values(array_unique($widths)),
            'logo lines must all share one display width, got: ' . implode(', ', $widths),
        );
        foreach ($lines as $i => $line) {
            self::assertContains(
                mb_substr($line, -1),
                ['╗', '║', '╝'],
                "line $i must terminate on the box rail",
            );
        }
    }

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