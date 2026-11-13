<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\AsciiRenderer;
use SugarCraft\Video\Render\GraphicsRenderer;
use SugarCraft\Video\Render\LumaRamp;
use SugarCraft\Video\Render\Mode;

/**
 * SV-H12 (findings sugar-video-012 + sugar-video-085).
 *
 * The Ansi256 rung used to render through the sugar-image bridge: every
 * frame paid a per-pixel imagesetpixel walk, a level-9 PNG encode, a PNG
 * decode and a GD resample — a TEXT rung paying the graphics tax while its
 * TrueColor sibling read the raw rgb24 buffer directly. sugar-cellbuffer's
 * palette-slot identity (Style::withFgSlot, the CB-30 SgrEmitter) makes the
 * 38;5;N pen a first-class cell style, so Player now paints Ansi256 on the
 * Buffer path like the rest of its text family.
 *
 * These pins hold what the reroute promises:
 *
 *  1. per-cell SLOT parity — old bridge and new rung quantise through the
 *     very same {@see \SugarCraft\ColorProfile\Color::toAnsi256Index()}
 *     wheel (the bridge's own import);
 *  2. per-cell GLYPH parity with the shared text-rung LUT — the runtime-wide
 *     floor quantiser the Ascii/TrueColor rungs already ride (the documented
 *     LumaRampQuantisationSeamTest seam; the scripted fixture's lumas 76,
 *     149, 178, 105, 255, 128, 38, 71 all sit OUTSIDE the divergent set);
 *  3. the rerouted stream and the canonical bridge stream decode to the
 *     SAME per-cell (glyph, slot) sequence on the 4×2 fixture;
 *  4. the MOVED golden (disclosed in the commit): Ansi256 view bytes go
 *     from the sugar-image pen-delta stream to the Buffer run stream;
 *  5. no per-frame renderer churn (085): the GraphicsRenderer delegate and
 *     the AsciiRenderer bridge are armed once; quarterCell's hoisted math
 *     still splits the same 2-means groups.
 */
final class Ansi256BufferRungTest extends TestCase
{
    /**
     * Scripted 4×2 fixture — eight saturated/neutral pixels. No cell is
     * black: the bridge elides the default pen (black → "terminal theme"),
     * the Buffer rung paints explicit slot 16, and the two would compare
     * as different pens for the same picture. Avoided by construction.
     *
     * @var list<array{int,int,int}> row-major
     */
    private const PIXELS = [
        [255, 0, 0],     // red      luma  76 → ';' slot 196
        [0, 255, 0],     // lime     luma 149 → 'f' slot  46
        [0, 255, 255],   // cyan     luma 178 → 'C' slot  51
        [255, 0, 255],   // magenta  luma 105 → '1' slot 201
        [255, 255, 255], // white    luma 255 → '@' slot 231
        [128, 128, 128], // grey     luma 128 → 't' slot 244
        [128, 0, 0],     // darkred  luma  38 → ',' slot  88
        [64, 64, 128],   // slate    luma  71 → ';' slot  60
    ];

    /**
     * The exact view() bytes the Buffer-rung Ansi256 paints for the fixture
     * (assertive 0;38;5;N pens, "\n" row join, "\x1b[0m" tail — the same
     * stream shape its TrueColor sibling emits). DISCLOSED golden move: the
     * pre-reroute bytes were the sugar-image pen-delta stream; the picture
     * parity pin below, not this constant, carries the semantic law.
     */
    private const GOLDEN_B64 =
        'G1swOzM4OzU7MTk2bTsbWzA7Mzg7NTs0Nm1mG1swOzM4OzU7NTFtQxtbMDszODs1OzIwMW0xChtbMDszODs1'
        . 'OzIzMW1AG1swOzM4OzU7MjQ0bXQbWzA7Mzg7NTs4OG0sG1swOzM4OzU7NjBtOxtbMG0=';

    private static function frame(): RgbFrame
    {
        $bytes = '';
        foreach (self::PIXELS as [$r, $g, $b]) {
            $bytes .= chr($r) . chr($g) . chr($b);
        }
        return new RgbFrame($bytes, 4, 2);
    }

    /**
     * @return list<array{string,?int}> expected (glyph, slot) per cell
     */
    private static function expectedCells(): array
    {
        $expected = [];
        foreach (self::PIXELS as [$r, $g, $b]) {
            $expected[] = [
                LumaRamp::char((float) LumaRamp::compute($r, $g, $b), 'standard'),
                (new \SugarCraft\ColorProfile\Color($r, $g, $b))->toAnsi256Index(),
            ];
        }
        return $expected;
    }

    /**
     * Decode one painted stream into its per-cell (glyph, slot) list.
     *
     * Handles BOTH pen spellings in play: the Buffer run stream (assertive
     * "\x1b[0;38;5;Nm" pens, "\n" rows, "\x1b[0m" tail) and the sugar-image
     * bridge stream (bare "\x1b[38;5;Nm" runs, scoped "\x1b[39m" row-end
     * restore, leading default-pen text before the first run). A pen is
     * read as its 256-slot, or null (terminal default) for any other SGR.
     *
     * @return list<array{string,?int}>
     */
    private static function decodeCells(string $stream): array
    {
        $cells = [];
        foreach (preg_split("/\r\n|\n/", $stream) as $line) {
            // Sentinel default pen so leading unrunned text is captured.
            preg_match_all('/\x1b\[([0-9;]*)m([^\x1b]*)/', "\x1b[39m" . $line, $m, PREG_SET_ORDER);
            $slot = null;
            foreach ($m as [, $params, $text]) {
                if (preg_match('/(?:^|;)38;5;(\d+)(?:;|$)/', $params, $s) === 1) {
                    $slot = (int) $s[1];
                } else {
                    $slot = null;
                }
                foreach (mb_str_split($text) as $ch) {
                    $cells[] = [$ch, $slot];
                }
            }
        }
        return $cells;
    }

    /**
     * A Player holding the scripted fixture, painted through view().
     *
     * @param Mode $mode        rung to paint (tests the reroute law itself)
     * @param bool $nullRenderer drop the factory-built twin entirely
     */
    private static function paintFixture(Mode $mode = Mode::Ansi256, bool $nullRenderer = false): string
    {
        // 30 identical frames: the two-interval tick lands mid-stream, so
        // the pane is never 'ended' and view() returns the pure picture.
        $player = Player::openForTest(
            new FakeDecoder(array_fill(0, 30, self::frame())),
            24.0,
            totalFrames: 30,
            cellsW: 20,
            cellsH: 4,
        )->play();

        $mutate = new \ReflectionMethod(Player::class, 'mutate');
        $mutate->setAccessible(true);
        $lastTick = new \ReflectionProperty(Player::class, 'lastTickTime');
        $lastTick->setAccessible(true);

        $player = $mutate->invoke($player, ['lastTickTime' => $lastTick->getValue($player) - 1.0 / 12.0]);
        [$player] = $player->update(new TickMsg());
        self::assertNotNull($player->currentFrame, 'precondition: the scripted frame reached the model');

        $player = $mutate->invoke($player, [
            'mode' => $mode,
            'renderer' => $nullRenderer ? null : new AsciiRenderer('standard'),
        ]);

        return $player->view();
    }

    /**
     * @testdox the rerouted rung paints the Buffer stream: explicit 38;5 pens, no truecolor leak
     */
    public function testAnsi256PaintsSlotPensNotTruecolor(): void
    {
        $out = self::paintFixture();

        $this->assertStringContainsString("\x1b[0;38;5;196m", $out, 'red keeps the wheel slot 196');
        $this->assertStringNotContainsString("\x1b[38;2;", $out, 'a palette rung must not leak truecolor pens');
    }

    /**
     * @testdox decoded per-cell (glyph, slot) stream equals the LUT + wheel answers
     */
    public function testTheDecodedStreamEqualsTheLutAndWheelAnswers(): void
    {
        $this->assertSame(
            self::expectedCells(),
            self::decodeCells(self::paintFixture()),
            'every cell carries the shared text-rung glyph and the canonical palette slot'
        );
    }

    /**
     * @testdox semantic parity: the rerouted rung and the canonical bridge decode to the SAME cell stream
     */
    public function testTheBridgeAndTheRungDecodeToTheSameCellStream(): void
    {
        $bridge = (new AsciiRenderer('standard'))->render(self::frame(), Mode::Ansi256);

        $this->assertSame(
            self::decodeCells($bridge),
            self::decodeCells(self::paintFixture()),
            'SV-H12 moves the ENCODING (pen-delta → Buffer runs), never the picture'
        );
    }

    /**
     * @testdox the moved golden pins the Buffer-rung bytes for the whole fixture
     */
    public function testTheByteGoldenOfTheBufferRung(): void
    {
        $this->assertSame(base64_decode(self::GOLDEN_B64, true), self::paintFixture());
    }

    /**
     * @testdox a Buffer rung needs no cached renderer: Ansi256 paints with it null
     */
    public function testAnsi256ViewSurvivesANullRenderer(): void
    {
        // The old direct path threw LogicException here; Ansi256 now shares
        // TrueColor's twin law (factory renderer is for direct users only).
        $out = self::paintFixture(nullRenderer: true);

        $this->assertStringContainsString("\x1b[0;38;5;", $out);
    }

    /**
     * @testdox SV-085: the GraphicsRenderer delegate is armed once, not per frame
     */
    public function testGraphicsRendererKeepsOneDelegateAcrossFrames(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('graphics rung construction needs ext-gd');
        }

        $renderer = new GraphicsRenderer(Mode::Iterm2, 10, 20);
        $frame = new RgbFrame(str_repeat("\xff\x00\x00", 4), 2, 2);
        $read = static function (GraphicsRenderer $r): ?object {
            $p = new \ReflectionProperty(GraphicsRenderer::class, 'delegate');
            $p->setAccessible(true);
            return $p->getValue($r);
        };

        $renderer->render($frame, Mode::Iterm2);
        $afterFirst = $read($renderer);
        $renderer->render($frame, Mode::Iterm2);

        $this->assertNotNull($afterFirst, 'the delegate is armed by the first render');
        $this->assertSame(
            spl_object_id($afterFirst),
            spl_object_id($read($renderer)),
            'frame 2 reuses the SAME renderer instance (was: new per frame)'
        );
    }

    /**
     * @testdox SV-085: the AsciiRenderer bridge cache holds one instance per colour depth
     */
    public function testBridgeCacheKeepsOneInstancePerMode(): void
    {
        $renderer = new AsciiRenderer('standard');
        $frame = self::frame();
        $read = static function (AsciiRenderer $r): array {
            $p = new \ReflectionProperty(AsciiRenderer::class, 'bridgeByKey');
            $p->setAccessible(true);
            return $p->getValue($r);
        };

        $renderer->render($frame, Mode::Ansi256);
        $renderer->render($frame, Mode::Ansi256);
        $this->assertCount(1, $read($renderer), 'two frames of one colour depth = one bridge instance');

        $renderer->render($frame, Mode::TrueColor);
        $this->assertCount(2, $read($renderer), 'a different projected depth arms its own instance — keyed cache, no thrash');
    }

    /**
     * @testdox SV-085: the quarterCell closure hoist preserves the 2-means picture
     */
    public function testQuarterCellStillSplitsTheGroupsTheOldClosuresDid(): void
    {
        $quarterCell = new \ReflectionMethod(Player::class, 'quarterCell');
        $quarterCell->setAccessible(true);

        // UL/LR bright, UR/LL dark: fg mask 0b1001 → '▚', pure white/black pens.
        [$glyph, $fg, $bg] = $quarterCell->invoke(null, [[255, 255, 255], [0, 0, 0], [0, 0, 0], [255, 255, 255]]);
        $this->assertSame('▚', $glyph);
        $this->assertSame(0xFFFFFF, $fg);
        $this->assertSame(0x000000, $bg);

        // Flat quads: full block, both pens the common colour.
        [$flat, $ffg, $fbg] = $quarterCell->invoke(null, [[10, 20, 30], [10, 20, 30], [10, 20, 30], [10, 20, 30]]);
        $this->assertSame('█', $flat);
        $this->assertSame(0x0A141E, $ffg);
        $this->assertSame(0x0A141E, $fbg);

        // 3/1 split: mask 0b0111 → '▛', group averages stay exact.
        [$three, $tfg, $tbg] = $quarterCell->invoke(null, [[255, 255, 255], [255, 255, 255], [255, 255, 255], [0, 0, 0]]);
        $this->assertSame('▛', $three);
        $this->assertSame(0xFFFFFF, $tfg);
        $this->assertSame(0x000000, $tbg);
    }
}
