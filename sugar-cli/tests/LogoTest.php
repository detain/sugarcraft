<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Cli\Logo;

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
        // The docblock advertises one exact width, and the narrower-art
        // advice below is phrased against it: pin the numeral so prose and
        // art can never drift apart again.
        self::assertSame(66, $widths[0], 'preset width advertised in docs is 66 cells');
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

    /**
     * Ingress contract: art is plain text. Escapes and controls die (TAB
     * folds to one space so word boundaries survive — SC-HT2), line feeds
     * survive (they ARE the layout), so a forged splash cannot ride a
     * caller's logo page onto the terminal.
     */
    public function testFromAsciiStripsEscapesButKeepsLineStructure(): void
    {
        $logo = Logo::fromAscii("a\x1b[2Jb\nc\td\r\x1b]0;pwn\x07e");
        $this->assertSame("ab\nc de", $logo->render());
    }

    /**
     * withColor() sets the render-time colour rather than wrapping stored
     * bytes: repeated calls REPLACE (last wins), and no SGR ever accumulates
     * in the art itself (the first call's escape stack was the old defect).
     */
    public function testWithColorReplacesInsteadOfStacking(): void
    {
        $pink = Logo::fromAscii('art')->withColor('#ff5fd2');
        $blue = Logo::fromAscii('art')->withColor('#5fd2ff');
        $both = $pink->withColor('#5fd2ff');

        $this->assertSame($blue->render(), $both->render(), 'the later colour must win outright');
        // The old defect was composition: withColor wrapping already-wrapped
        // bytes stacked two different colours into one output. Equal-render
        // above proves replacement; the ESC census proves the art stays a
        // single (per-line-styled) SGR pass, not a nested stack.
        $rendered = $both->render();
        $this->assertStringNotContainsString(
            '38;2;255;95;210',
            $rendered,
            'the replaced colour must not linger anywhere in the output',
        );
        $this->assertStringContainsString('38;2;95;210;255', $rendered);
    }

    /** A colourless logo renders its (sanitized) art byte-for-byte, no SGR. */
    public function testUncoloredRenderCarriesNoStyling(): void
    {
        $this->assertStringNotContainsString("\x1b", Logo::fromAscii("plain\nart")->render());
        $this->assertStringNotContainsString("\x1b", Logo::sugarcraft()->render());
    }

    /** SC-F2: a NoTty profile removes the tint; the default path is untouched. */
    public function testRenderWithNoTtyProfileDowngradesTint(): void
    {
        $logo = Logo::fromAscii("plain\nart")->withColor('#ff5fd2');
        $out = $logo->render(ColorProfile::NoTty);
        $this->assertStringNotContainsString("\x1b", $out, 'NoTty must emit no escape bytes at all');
        [$first, $second] = explode("\n", $out);
        $this->assertSame('plain', $first);
        $this->assertSame('art', rtrim($second), 'trailing Style layout residue only, colour gone');
        $this->assertStringContainsString("\x1b[", $logo->render(), 'default keeps the tint');
    }

    /** SC-F2: TrueColor profile is the identity tier for an already-truecolor tint. */
    public function testRenderWithTrueColorProfileMatchesDefault(): void
    {
        $logo = Logo::sugarcraft()->withColor('#ff5fd2');
        $this->assertSame($logo->render(), $logo->render(ColorProfile::TrueColor));
    }

    public function testNarrowPresetLinesStayUnderFortyCells(): void
    {
        // The 40-cell budget is the preset's whole point; measure every line
        // in display cells (not eyeball the art in an editor with soft wrap).
        $lines = explode("\n", Logo::sugarcraftNarrow()->render());
        $this->assertCount(5, $lines);
        foreach ($lines as $i => $line) {
            $this->assertLessThanOrEqual(40, Width::string($line), "narrow preset line {$i} exceeds 40 cells");
        }
    }

    public function testFitsBoundariesForBothPresets(): void
    {
        $narrow = Logo::sugarcraftNarrow();
        $this->assertTrue($narrow->fits(40));
        $this->assertTrue($narrow->fits(39)); // widest measured line is 39 cells
        $this->assertFalse($narrow->fits(38));

        $wide = Logo::sugarcraft();
        $this->assertTrue($wide->fits(66)); // the pinned shared-width preset
        $this->assertFalse($wide->fits(40)); // the narrow preset's budget
    }

    public function testFitsCountsDisplayCellsNotCodepoints(): void
    {
        // Art with a wide glyph: the predicate must answer in cells, matching
        // what the renderer measures — the width is taken from Width::string
        // itself so the pin cannot silently drift with a Unicode update.
        $logo = Logo::fromAscii('a日');
        $cells = Width::string('a日');
        $this->assertGreaterThan(1, $cells, 'guard: the fixture must actually be multi-cell');
        $this->assertTrue($logo->fits($cells));
        $this->assertFalse($logo->fits($cells - 1));
    }
}
