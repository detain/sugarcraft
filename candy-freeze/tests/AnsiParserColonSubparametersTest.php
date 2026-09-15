<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Freeze\AnsiParser;
use SugarCraft\Freeze\Segment;
use SugarCraft\Freeze\SgrState;
use SugarCraft\Freeze\SgrStateHandler;
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
 * <p>Thirteen of these seventeen tests are regression guards: with `master`'s
 * `src/AnsiParser.php` checked back in they fail. Four are deliberate
 * backward-compatibility pins — `testGroupWithoutIdSlotStillResolves`,
 * `testColonTwentyFiveColourIndexResolves`,
 * `testColonUnderlineGroupDoesNotPaintAColour` and
 * `testTruncatedColonGroupLeavesTheColourUntouched` — each labelled below, so a
 * future refactor does not read them as coverage of the fixed path.
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
        // BC pin — with the id slot omitted entirely the positional reading was
        // already right on master. Guards the count rule, not the fix.
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
        // BC pin — `38:5:196` resolved correctly on master too (the index is the
        // first value after the mode, so flattening was harmless here).
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

    public function testColonUnderlineGroupDoesNotPaintAColour(): void
    {
        // BC pin — a `4:3` group never painted a colour on master either; this
        // documents where the known limitation lands, not a change.
        // `4:3` is one curly-underline group, not an extended colour: with no
        // 38/48 introducer the component lookup is never entered at all.
        $segments = AnsiParser::parse("\x1b[4:3mtext");

        $this->assertTrue($segments[0]->underline);
        $this->assertNull($segments[0]->fg);
        $this->assertNull($segments[0]->bg);
        // Known limitation (findings/candy-freeze.md item 30): the sub-parameter
        // `3` is still re-read as SGR 3, because only 38/48 groups are consumed
        // as units. Pinned so a future strict pass changes it knowingly.
        $this->assertTrue($segments[0]->italic);
        $this->assertSame('text', $segments[0]->text);
    }

    public function testUnresolvableColonGroupFallsBackAcrossParameterBoundaries(): void
    {
        // `38:2::;1;2;3` has an empty colour-space slot *and* an empty red slot,
        // so the group resolves to nothing and the historic flat reading takes
        // over — reaching past the `;` into parameters that xterm would treat as
        // independent SGRs. The cross-`;` reach is pre-existing and
        // deterministic — pinned, not blessed — but the *value* is new: on
        // master this same input froze as an 18-hex garbage colour.
        $segment = AnsiParser::parse("\x1b[38:2::;1;2;3mX")[0];

        $this->assertSame('#000001', $segment->fg);
        // …and the components it did not consume come back as attributes.
        $this->assertTrue($segment->italic);
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

        foreach (self::colourShapeCorpus() as $generated) {
            $inputs[] = $generated;
        }

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

    /**
     * Cartesian torture set: each extended-colour introducer crossed with each
     * colour-space id, each sub-parameter tail real emitters are known to send
     * (empty id slot, extra empty slots, a trailing colour-space triple, an
     * out-of-range component, nothing at all) and both separator spellings.
     *
     * <p>The invariant is the point: every component passes through
     * {@see SgrStateHandler::clampComponent()}, so no shape — however malformed —
     * may yield a value outside `#rrggbb`. Before this fix the positional read
     * handed `sprintf('%02x', -1)` to the renderer, which is how an 18-hex
     * truecolour like `#ffffffffffffffff50a0` reached an SVG `fill` attribute.
     *
     * @return array<string, string> label => CSI sequence
     */
    private static function colourShapeCorpus(): array
    {
        $tails = [
            'none' => [],
            'rgb' => [1, 2, 3],
            'cs:rgb' => ['', 1, 2, 3],
            'cs::rgb' => ['', '', 1, 2, 3],
            'rgb+extra' => [1, 2, 3, 4],
            'empty-then-index' => ['', '5'],
            'huge' => [999, 65535, 0],
            'all-empty' => ['', '', ''],
            'single-empty' => [''],
        ];
        $corpus = [];

        foreach (['38', '48', '58'] as $code) {
            foreach ([2, 5, 6] as $mode) {
                foreach ([':', ';'] as $separator) {
                    foreach ($tails as $tailName => $tail) {
                        $body = $code . ':' . $mode . $separator . implode($separator, array_map(strval(...), $tail));
                        $corpus[$body] = "\x1b[" . $body . 'm';
                    }
                }
            }
        }

        return $corpus;
    }

    public function testTruncatedColonGroupLeavesTheColourUntouched(): void
    {
        // BC pin on the outcome (master also left `#cd0000` in force) and a
        // guard on the reasoning: a group that cannot supply three components
        // must not repaint — and above all must not emit a malformed value.
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

    public function testHandlerWithoutBoundParserStillReadsFlatParameters(): void
    {
        // `bindParser()` is what unlocks colon grouping. A handler driven
        // without a binding must keep working for the flat spelling rather than
        // degrade into a crash or a malformed value.
        $state = new SgrState();
        $textBuf = '';
        $segments = [];
        $flush = static function () use (&$segments, &$textBuf, &$state): void {
            if ($textBuf === '') {
                return;
            }
            $segments[] = new Segment(
                text:      $textBuf,
                fg:        $state->fg,
                bold:      $state->bold,
                italic:    $state->italic,
                underline: $state->underline,
                bg:        $state->bg,
            );
            $textBuf = '';
        };

        $handler = new SgrStateHandler($state, $textBuf, $flush, $segments);
        $parser = new Parser($handler);
        $parser->feed("\x1b[38;2;80;160;240mX");
        $parser->flush();
        $flush();

        $this->assertCount(1, $segments);
        $this->assertSame('#50a0f0', $segments[0]->fg);

        // The bound path is what `AnsiParser::parse()` uses, and it is the one
        // that reads the group. Unbound, the same bytes degrade to the flat
        // reading — deterministic and still a colour, never a crash.
        $unbound = new SgrStateHandler($state, $textBuf, $flush, $segments);
        $unboundParser = new Parser($unbound);
        $unboundParser->feed("\x1b[38:2::80:160:240mW");
        $unboundParser->flush();
        $flush();

        $this->assertSame('#0050a0', $segments[1]->fg);

        $bound = AnsiParser::parse("\x1b[38:2::80:160:240mY");
        $this->assertSame('#50a0f0', $bound[0]->fg);
        $oversized = AnsiParser::parse("\x1b[38;2;999;999;999mZ");
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $oversized[0]->fg);
    }
}
