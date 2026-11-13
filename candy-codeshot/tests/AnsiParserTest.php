<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use SugarCraft\CodeShot\AnsiParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnsiParserTest extends TestCase
{
    public function testPlainTextOneSegment(): void
    {
        $segs = AnsiParser::parse('hello');
        $this->assertCount(1, $segs);
        $this->assertSame('hello', $segs[0]->text);
        $this->assertNull($segs[0]->fg);
        $this->assertFalse($segs[0]->bold);
    }

    public function testForegroundChangeProducesSegments(): void
    {
        $segs = AnsiParser::parse("a\x1b[31mb\x1b[0mc");
        $this->assertCount(3, $segs);
        $this->assertSame('a', $segs[0]->text);
        $this->assertNull($segs[0]->fg);
        $this->assertSame('b', $segs[1]->text);
        $this->assertSame('#cd0000', $segs[1]->fg);
        $this->assertSame('c', $segs[2]->text);
        $this->assertNull($segs[2]->fg);
    }

    public function testBoldAttribute(): void
    {
        $segs = AnsiParser::parse("\x1b[1mhi\x1b[22m bye");
        $this->assertSame('hi',   $segs[0]->text);
        $this->assertTrue($segs[0]->bold);
        $this->assertSame(' bye', $segs[1]->text);
        $this->assertFalse($segs[1]->bold);
    }

    public function testTrueColor(): void
    {
        $segs = AnsiParser::parse("\x1b[38;2;255;128;0morange");
        $this->assertSame('#ff8000', $segs[0]->fg);
        $this->assertSame('orange',  $segs[0]->text);
    }

    public function testXterm256Greyscale(): void
    {
        $segs = AnsiParser::parse("\x1b[38;5;232mlast");
        // 232 = grey level 8 → #080808.
        $this->assertSame('#080808', $segs[0]->fg);
    }

    public function testOscPassesThroughSilently(): void
    {
        $segs = AnsiParser::parse("\x1b]0;title\x07hi");
        $this->assertCount(1, $segs);
        $this->assertSame('hi', $segs[0]->text);
    }

    public function testResetClearsFgAndAttrs(): void
    {
        $segs = AnsiParser::parse("\x1b[1;31mhot\x1b[0mcold");
        $this->assertTrue($segs[0]->bold);
        $this->assertSame('#cd0000', $segs[0]->fg);
        $this->assertFalse($segs[1]->bold);
        $this->assertNull($segs[1]->fg);
    }

    public function testMixedXterm256AndTrueColor(): void
    {
        // Red fg (256-color) + green bg (truecolor)
        $segs = AnsiParser::parse("\x1b[38;5;196m\x1b[48;2;0;255;0mcolored\x1b[0m");
        $this->assertCount(1, $segs);
        $this->assertSame('#ff0000', $segs[0]->fg); // ANSI 196 = bright red
        $this->assertSame('#00ff00', $segs[0]->bg); // truecolor 0;255;0
        $this->assertSame('colored', $segs[0]->text);
    }

    public function testXterm256ToHexPalette(): void
    {
        // ANSI 0-15 are the 16-color palette.
        $this->assertSame('#000000', AnsiParser::xterm256ToHex(0));
        $this->assertSame('#cd0000', AnsiParser::xterm256ToHex(1));
        $this->assertSame('#00cd00', AnsiParser::xterm256ToHex(2));
        $this->assertSame('#cdcd00', AnsiParser::xterm256ToHex(3));
        $this->assertSame('#0000ee', AnsiParser::xterm256ToHex(4));
        $this->assertSame('#cd00cd', AnsiParser::xterm256ToHex(5));
        $this->assertSame('#00cdcd', AnsiParser::xterm256ToHex(6));
        $this->assertSame('#e5e5e5', AnsiParser::xterm256ToHex(7));
        $this->assertSame('#7f7f7f', AnsiParser::xterm256ToHex(8));
        $this->assertSame('#ff0000', AnsiParser::xterm256ToHex(9));
        $this->assertSame('#00ff00', AnsiParser::xterm256ToHex(10));
        $this->assertSame('#ffff00', AnsiParser::xterm256ToHex(11));
        $this->assertSame('#5c5cff', AnsiParser::xterm256ToHex(12));
        $this->assertSame('#ff00ff', AnsiParser::xterm256ToHex(13));
        $this->assertSame('#00ffff', AnsiParser::xterm256ToHex(14));
        $this->assertSame('#ffffff', AnsiParser::xterm256ToHex(15));
    }

    public function testXterm256ToHex256ColorRange(): void
    {
        // ANSI 16-231: 6x6x6 color cube.
        $this->assertSame('#000000', AnsiParser::xterm256ToHex(16));
        // Verify first color cube entry is not zero (sanity).
        $c17 = AnsiParser::xterm256ToHex(17);
        $this->assertNotSame('#000000', $c17);
        // Last entry before greyscale.
        $this->assertSame('#ffffff', AnsiParser::xterm256ToHex(231));
    }

    public function testXterm256ToHexGreyscaleRange(): void
    {
        // ANSI 232-255: 24-step greyscale starting at #080808.
        $this->assertSame('#080808', AnsiParser::xterm256ToHex(232));
        // Verify greys are not black or white at middle range.
        $grey = AnsiParser::xterm256ToHex(244);
        $this->assertNotSame('#000000', $grey);
        $this->assertNotSame('#ffffff', $grey);
        // All greys should have equal RGB components (monochrome).
        [$r, $g, $b] = array_values(sscanf($grey, '#%2x%2x%2x'));
        $this->assertSame($r, $g);
        $this->assertSame($g, $b);
    }

    public function testXterm256ToHexReturnsValidHexStrings(): void
    {
        // Sample various indices and verify they return valid 6-digit hex color strings.
        foreach ([0, 15, 16, 50, 100, 150, 200, 231, 232, 244, 253] as $i) {
            $hex = AnsiParser::xterm256ToHex($i);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex, "Index $i produced '$hex'");
        }
    }

    // -------------------------------------------------------------------------
    // cs-23 out-of-range colour contract (one decision, two surfaces):
    // the public helper THROWS outside 0..255, the dispatch IGNORES
    // out-of-range params like xterm does — never a clamped repaint, never
    // the malformed `#2b02b02b0` the old unguarded table emitted.
    // -------------------------------------------------------------------------

    public static function outOfTableIndicesProvider(): array
    {
        // The -032 measurements: 300 produced #2b02b02b0 (a 9-digit
        // renderer-killer), -1 produced #ffffff (historic silent default),
        // 256 walked off the end of the $levels row.
        return ['negative five' => [-5], 'historic minus one' => [-1], 'one past the table' => [256], 'the measured repro' => [300], 'parser ceiling' => [65535]];
    }

    #[DataProvider('outOfTableIndicesProvider')]
    public function testPublicPaletteHelperRefusesIndicesOutsideTheTable(int $index): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('xterm-256 palette index out of range');
        AnsiParser::xterm256ToHex($index);
    }

    public function testEveryTableIndexProducesASixDigitHex(): void
    {
        // The whole table, not a sample: 256 entries, each a CSS colour.
        for ($i = 0; $i <= 255; $i++) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', AnsiParser::xterm256ToHex($i), "Index $i");
        }
    }

    public function testOutOfRangePaletteParamIsParsedAndIgnored(): void
    {
        // xterm ignores `38;5;999` instead of repainting #eeeeee, and the
        // README doctrine for such params is "parsed and ignored" — the
        // old dispatch clamp was the undisclosed divergence (-066).
        // The text still flows; only the colour is dropped.
        foreach ([999, 256, 300] as $index) {
            $segments = AnsiParser::parse("\x1b[38;5;{$index}mpayload");
            $this->assertCount(1, $segments);
            $this->assertSame('payload', $segments[0]->text);
            $this->assertNull($segments[0]->fg, "38;5;{$index} must change no colour");

            $backgrounds = AnsiParser::parse("\x1b[48;5;{$index}mpayload");
            $this->assertNull($backgrounds[0]->bg, "48;5;{$index} must change no colour");
        }
    }

    public function testOutOfRangeGroupedPaletteParamIsParsedAndIgnored(): void
    {
        // The ECMA-48 grouped spelling takes the same decision.
        $segments = AnsiParser::parse("\x1b[38:5:999mpayload");
        $this->assertNull($segments[0]->fg, '38:5:999 must change no colour');

        // A valid neighbour still paints — the refusal is range-based, not
        // a blanket drop of extended colours after a bad param.
        $valid = AnsiParser::parse("\x1b[38;5;255mpaint");
        $this->assertSame('#eeeeee', $valid[0]->fg);
        $zero = AnsiParser::parse("\x1b[38;5;0mpaint");
        $this->assertSame('#000000', $zero[0]->fg);
    }

    public function testIgnoredOutOfRangeParamDoesNotLeakIntoFollowingText(): void
    {
        // After the ignored param the stream keeps its prior state: the
        // following explicit colour still applies normally.
        $segments = AnsiParser::parse("\x1b[38;5;999m\x1b[31mred");
        $this->assertSame('#cd0000', $segments[count($segments) - 1]->fg);
    }
    public function testHorizontalTabExpandsToTheNextEightCellStop(): void
    {
        $segments = AnsiParser::parse("a\tb");
        self::assertCount(1, $segments);
        self::assertSame('a       b', $segments[0]->text);
    }

    public function testHorizontalTabAtColumnZeroPadsFullStop(): void
    {
        $segments = AnsiParser::parse("\tb");
        self::assertSame('        b', $segments[0]->text);
    }

    public function testWideGlyphCountsCellsBeforeTab(): void
    {
        $segments = AnsiParser::parse("\u{5bbd}\tb");
        self::assertSame("\u{5bbd}      b", $segments[0]->text);
    }

    public function testSgrStateCarriesAcrossLinesWhenThreaded(): void
    {
        $carry = null;
        $first = AnsiParser::parse("\x1b[31mA", $carry);
        $second = AnsiParser::parse('B', $carry);
        self::assertSame('#cd0000', $first[0]->fg);
        self::assertSame('#cd0000', $second[0]->fg);
    }

    public function testSgrStateStaysFreshWithoutCarry(): void
    {
        AnsiParser::parse("\x1b[31mA");
        $second = AnsiParser::parse('B');
        self::assertNull($second[0]->fg);
    }

    public function testMalformedUtf8IsScrubbedNotFatal(): void
    {
        $segments = AnsiParser::parse("\xff\xfe safe");
        $text = '';
        foreach ($segments as $seg) {
            $text .= $seg->text;
        }
        self::assertStringContainsString('safe', $text);
    }

    /**
     * cs-24 (-039): the parser's per-rune validation must be OFF — the
     * line-level normalise above the door already guarantees well-formed
     * UTF-8, so `new Parser($handler, false)` is the byte-identical hot
     * shape. Structural pin: the call site carries the false literal.
     */
    public function testParserIsConstructedWithValidationDisabled(): void
    {
        $src = (string) \file_get_contents(__DIR__ . '/../src/AnsiParser.php');
        self::assertMatchesRegularExpression(
            '/new\s+Parser\(\s*\$handler,\s*false\s*\)/',
            $src,
            'AnsiParser must feed the parser a line already validated by the line-level normalise (replaceMalformed stays false)',
        );
        self::assertStringContainsString('mb_convert_encoding($line', $src, 'the line-level UTF-8 door must stay in place');
    }

    /**
     * cs-24 (-043): the cube intensities live in a class constant, and the
     * constant still equals the canonical xterm formula for every 6..231
     * index (old code recomputed the local array per call; formula equality
     * here is the zero-behaviour proof for that band).
     */
    public function testXtermLevelsIsAConstantMatchingTheCubeFormula(): void
    {
        $levels = (new \ReflectionClass(AnsiParser::class))->getConstant('XTERM_LEVELS');
        self::assertSame([0, 95, 135, 175, 215, 255], $levels);

        for ($i = 16; $i <= 231; $i++) {
            $idx  = $i - 16;
            $want = \sprintf(
                '#%02x%02x%02x',
                $levels[intdiv($idx, 36)],
                $levels[intdiv($idx, 6) % 6],
                $levels[$idx % 6],
            );
            self::assertSame($want, AnsiParser::xterm256ToHex($i), "cube index {$i}");
        }
    }

    /**
     * cs-24 (-040): applySgr dispatches opcodes directly — no closure-per
     * parameter, no variable-variable writes. Structural pin over the method
     * source: neither `fn(` nor `$$` may appear inside its body.
     */
    public function testApplySgrDispatchesWithoutClosuresOrVariableVars(): void
    {
        $method = new \ReflectionMethod(\SugarCraft\CodeShot\SgrStateHandler::class, 'applySgr');
        $file   = (string) \file_get_contents((string) $method->getFileName());
        $body   = implode(
            "\n",
            \array_slice(
                explode("\n", $file),
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ),
        );

        self::assertStringNotContainsString('fn(', $body, 'applySgr must not build closures per parameter (cs-24)');
        self::assertStringNotContainsString('$$', $body, 'applySgr must not write state through variable-vars (cs-24)');
    }

    /**
     * cs-25 (-047): a sender that re-emits an unchanged SGR must not inflate
     * the segment list — adjacent runs with an identical style merge.
     */
    public function testAdjacentIdenticallyStyledRunsCoalesce(): void
    {
        $segments = AnsiParser::parse("aaa\x1b[31mb\x1b[31mc");
        self::assertCount(2, $segments);
        self::assertSame('aaa', $segments[0]->text);
        self::assertSame('bc', $segments[1]->text);
        self::assertSame('#cd0000', $segments[1]->fg);
    }

    public function testCoalescingSplitsOnEveryStyleDimension(): void
    {
        // fg differs
        self::assertCount(2, AnsiParser::parse("a\x1b[31mb"));
        // bg differs
        self::assertCount(2, AnsiParser::parse("a\x1b[41mb"));
        // bold differs
        self::assertCount(2, AnsiParser::parse("a\x1b[1mb"));
        // italic differs
        self::assertCount(2, AnsiParser::parse("a\x1b[3mb"));
        // underline differs
        self::assertCount(2, AnsiParser::parse("a\x1b[4mb"));
    }

    public function testNoopSgrBetweenEqualRunsCoalescesAcrossTheDispatch(): void
    {
        // SGR 7 (inverse) is untracked: state unchanged, one merged run.
        $segments = AnsiParser::parse("\x1b[32mx\x1b[7my\x1b[32mz");
        self::assertCount(1, $segments);
        self::assertSame('xyz', $segments[0]->text);
    }

    public function testCoalescingNeverExceedsStyledRunCount(): void
    {
        // Segment count is bounded by the number of DISTINCT adjacent styles,
        // the -047 inflation measure: repeated same-style emissions collapse.
        $segments = AnsiParser::parse(
            "\x1b[38;5;204mstr\x1b[38;5;204ming\x1b[38;5;204mify\x1b[0m(\x1b[0m)\x1b[0m1"
        );
        self::assertCount(2, $segments);
        self::assertSame('stringify', $segments[0]->text);
        self::assertSame('()1', $segments[1]->text);
    }
}
