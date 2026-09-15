<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Freeze\AnsiParser;
use SugarCraft\Freeze\SvgRenderer;

/**
 * Colon sub-parameter goldens for the candy-freeze SGR parser.
 *
 * xterm ctlseqs carries an extended colour either as flat parameters
 * (`38;2;R;G;B`) or as one ECMA-48 §14.1.1 parameter group
 * (`38:2:CS:R:G:B`, where CS is a colour-space id that emitters habitually
 * leave empty). Flattening the group made the empty id the red component and
 * `sprintf('%02x', -1)` emitted sixteen hex digits, so `38:2::80:160:240`
 * froze as `#ffffffffffffffff50a0` — an SVG fill no renderer accepts.
 *
 * Every assertion here is on the bytes that reach the drawing: the resolved
 * `#rrggbb` string, and the `fill=` attribute the renderer writes with it.
 *
 * @see https://www.ecma-international.org/publications-and-standards/standards/ecma-48/
 */
final class AnsiParserColonSubparametersTest extends TestCase
{
    public function testColonTrueColorResolvesToItsOwnComponents(): void
    {
        $segments = AnsiParser::parse("\x1b[38:2::80:160:240msteel");

        $this->assertCount(1, $segments);
        // Regression (FZ-R3): '#ffffffffffffffff50a0'.
        $this->assertSame('#50a0f0', $segments[0]->fg);
        $this->assertSame('steel', $segments[0]->text);
    }

    public function testBothSeparatorSpellingsProduceIdenticalColourBytes(): void
    {
        $colon = AnsiParser::parse("\x1b[38:2::80:160:240mX")[0];
        $plain = AnsiParser::parse("\x1b[38;2;80;160;240mX")[0];

        $this->assertSame($plain->fg, $colon->fg);
        $this->assertSame('#50a0f0', $colon->fg);
    }

    public function testPopulatedColourSpaceIdIsNotReadAsRed(): void
    {
        $segments = AnsiParser::parse("\x1b[38:2:1:80:160:240mX");

        $this->assertSame('#50a0f0', $segments[0]->fg);
    }

    public function testGroupWithoutIdSlotStillResolves(): void
    {
        $segments = AnsiParser::parse("\x1b[38:2:80:160:240mX");

        $this->assertSame('#50a0f0', $segments[0]->fg);
    }

    public function testEmptySlotsBeforeComponentsAreSkipped(): void
    {
        // id slot *and* a tolerance slot left empty — the three components are
        // still the last three supplied values.
        $segments = AnsiParser::parse("\x1b[38:2:::5:6:7mX");

        $this->assertSame('#050607', $segments[0]->fg);
    }

    public function testColonBackgroundColourResolves(): void
    {
        $segments = AnsiParser::parse("\x1b[48:2::10:20:30mX");

        $this->assertSame('#0a141e', $segments[0]->bg);
        $this->assertNull($segments[0]->fg);
    }

    public function testColonTwentyFiveColourIndexResolves(): void
    {
        $segments = AnsiParser::parse("\x1b[38:5:196mX");

        $this->assertSame('#ff0000', $segments[0]->fg);
    }

    public function testColonGroupAlongsideAttributesKeepsThemAndSplitsOnce(): void
    {
        $segments = AnsiParser::parse("\x1b[1;38:2::255:0:128mpink\x1b[0mplain");

        $this->assertCount(2, $segments);
        $this->assertTrue($segments[0]->bold);
        $this->assertSame('#ff0080', $segments[0]->fg);
        $this->assertSame('pink', $segments[0]->text);
        $this->assertNull($segments[1]->fg);
        $this->assertFalse($segments[1]->bold);
        $this->assertSame('plain', $segments[1]->text);
    }

    public function testColonUnderlineStyleIsNotMistakenForAColour(): void
    {
        // `4:3` is one curly-underline group, not an extended colour: no
        // 38/48 introducer means no component lookup at all.
        $segments = AnsiParser::parse("\x1b[4:3mtext");

        $this->assertTrue($segments[0]->underline);
        $this->assertNull($segments[0]->fg);
        $this->assertNull($segments[0]->bg);
        $this->assertSame('text', $segments[0]->text);
    }

    public function testEveryColonSpellingResolvesToASixDigitHex(): void
    {
        $inputs = [
            "\x1b[38:2::80:160:240m",
            "\x1b[38:2:1:80:160:240m",
            "\x1b[38:2:80:160:240m",
            "\x1b[38:2:::5:6:7m",
            "\x1b[38:5:196m",
            "\x1b[48:2::10:20:30m",
            "\x1b[38:2;1;2;3m",
        ];

        foreach ($inputs as $input) {
            $segment = AnsiParser::parse($input . 'X')[0];
            foreach ([$segment->fg, $segment->bg] as $colour) {
                if ($colour === null) {
                    continue;
                }
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $colour, $input);
            }
        }
    }

    public function testTruncatedColonGroupLeavesTheColourUntouched(): void
    {
        // A group that cannot supply three components must not repaint — and
        // above all must not emit a malformed value.
        $segments = AnsiParser::parse("\x1b[31mA\x1b[38:2::1mB");

        $this->assertSame('#cd0000', $segments[0]->fg);
        $this->assertSame('#cd0000', $segments[1]->fg, 'unparsable colour leaves the previous one in force');
    }

    public function testOversizedComponentIsCoercedToEightBits(): void
    {
        // The parser caps a parameter at 65535; a value wider than two digits
        // is what used to stretch the hex string past six.
        $segments = AnsiParser::parse("\x1b[38;2;999;0;0mX");

        $this->assertSame('#ff0000', $segments[0]->fg);
    }

    public function testOmittedTwentyFiveColourIndexFallsBackToBlack(): void
    {
        // ECMA-48 §5.4.1: an omitted parameter takes its default value, which
        // for a colour index is 0 — not the palette's top slot.
        $segments = AnsiParser::parse("\x1b[38;5;mX");

        $this->assertSame('#000000', $segments[0]->fg);
    }

    public function testRendererWritesTheResolvedColourIntoTheSvgBytes(): void
    {
        $svg = SvgRenderer::dark()->render("\x1b[38:2::80:160:240msteel\x1b[0m");

        $this->assertStringContainsString('fill="#50a0f0"', $svg);
        $this->assertStringNotContainsString('#ffffffffffffffff50a0', $svg);
    }

    public function testRendererDrawsBothSeparatorSpellingsIdentically(): void
    {
        $colon = SvgRenderer::dark()->render("\x1b[38:2::255:128:0mA\x1b[0m");
        $plain = SvgRenderer::dark()->render("\x1b[38;2;255;128;0mA\x1b[0m");

        // Same colour, same drawing: the separator spelling is syntax, not data.
        $this->assertSame($plain, $colon);
        $this->assertStringContainsString('fill="#ff8000"', $plain);
    }
}
