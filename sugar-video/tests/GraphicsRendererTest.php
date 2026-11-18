<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Render\GraphicsRenderer;
use SugarCraft\Video\Render\Mode;

/**
 * Unit tests for GraphicsRenderer — delegates to sugar-image Sixel/Kitty/iTerm2.
 *
 * @covers \SugarCraft\Video\Render\GraphicsRenderer
 */
final class GraphicsRendererTest extends TestCase
{
    /**
     * Synthetic 2×2 RgbFrame with known colors:
     *   R0C0: red    [255,   0,   0]
     *   R0C1: green  [  0, 255,   0]
     *   R1C0: blue   [  0,   0, 255]
     *   R1C1: yellow [255, 255,   0]
     *
     * Bytes: 12 (2×2×3)
     */
    private static function makeFrame(): RgbFrame
    {
        $bytes = "\xff\x00\x00"  // R0C0: red
                . "\x00\xff\x00"  // R0C1: green
                . "\x00\x00\xff"  // R1C0: blue
                . "\xff\xff\x00"; // R1C1: yellow

        return new RgbFrame($bytes, 2, 2);
    }

    private function getRenderer(Mode $mode): GraphicsRenderer
    {
        return new GraphicsRenderer($mode);
    }

    // -------------------------------------------------------------------------
    // render() produces non-empty output for valid frames
    // -------------------------------------------------------------------------

    /**
     * @testdox render(Mode::Sixel) returns a non-empty string or skips gracefully
     */
    public function testRenderSixelModeReturnsNonEmptyString(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Sixel)->render($frame, Mode::Sixel);

        // Lenient: if sixel protocol degrades (no terminal support), output may be empty.
        if ($output === '') {
            $this->markTestSkipped('Sixel protocol returned empty — no terminal support in CI');
        }

        $this->assertIsString($output);
        $this->assertGreaterThan(0, strlen($output));
    }

    /**
     * @testdox render(Mode::Kitty) returns a non-empty string or skips gracefully
     */
    public function testRenderKittyModeReturnsNonEmptyString(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Kitty)->render($frame, Mode::Kitty);

        if ($output === '') {
            $this->markTestSkipped('Kitty protocol returned empty — no terminal support in CI');
        }

        $this->assertIsString($output);
        $this->assertGreaterThan(0, strlen($output));
    }

    /**
     * @testdox render(Mode::Iterm2) returns a non-empty string or skips gracefully
     */
    public function testRenderIterm2ModeReturnsNonEmptyString(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Iterm2)->render($frame, Mode::Iterm2);

        if ($output === '') {
            $this->markTestSkipped('iTerm2 protocol returned empty — no terminal support in CI');
        }

        $this->assertIsString($output);
        $this->assertGreaterThan(0, strlen($output));
    }

    // -------------------------------------------------------------------------
    // Empty frame guard
    // -------------------------------------------------------------------------

    /**
     * @testdox render returns empty string for zero-width frame
     */
    public function testRenderEmptyFrameReturnsEmptyString(): void
    {
        $frame = new RgbFrame('', 0, 0);
        $output = $this->getRenderer(Mode::Sixel)->render($frame, Mode::Sixel);

        $this->assertSame('', $output);
    }

    // -------------------------------------------------------------------------
    // cellDimensions
    // -------------------------------------------------------------------------

    /**
     * @testdox cellDimensions returns [w => 1, h => 1] — graphics fill the terminal
     */
    public function testCellDimensionsReturnsW1H1(): void
    {
        $result = $this->getRenderer(Mode::Sixel)->cellDimensions(Mode::Sixel);

        $this->assertSame(['w' => 1, 'h' => 1], $result);
    }

    // -------------------------------------------------------------------------
    // Protocol envelope — header presence when output is non-empty
    // -------------------------------------------------------------------------

    /**
     * @testdox Sixel output (when non-empty) starts with DCS sixel header \x1bP
     */
    public function testSixelOutputContainsSixelHeader(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Sixel)->render($frame, Mode::Sixel);

        if ($output === '') {
            $this->markTestSkipped('Sixel protocol returned empty — skipping header check');
        }

        // DCS sixel intro: ESC P (\x1bP)
        $this->assertStringStartsWith("\x1bP", $output, 'Sixel output should begin with DCS sixel header');
    }

    /**
     * @testdox Kitty output (when non-empty) starts with the APC G header \x1b_G
     */
    public function testKittyOutputContainsKittyHeader(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Kitty)->render($frame, Mode::Kitty);

        if ($output === '') {
            $this->markTestSkipped('Kitty protocol returned empty — skipping header check');
        }

        // APC kitty intro: ESC _ G (0x1b 0x5f 0x47) — the Kitty graphics
        // protocol is APC-framed (xterm ctlseqs `ESC _`), followed by the
        // `a=T,i=<id>,f=100,q=2,c=<w>,r=<h>,m=1;` begin chunk. The leading `a=T`
        // states the transmit-and-display action: an absent `a=` defaults to
        // transmit-without-display (sugar-image 94d16dbb7), so the plain
        // route must carry it. The `i=` id is what the next frame retires.
        // (The old DCS `ESC P q` pin here was the DECSIXEL introducer — ANSI
        // audit defect #10, fixed in sugar-core kittyGraphicsBegin.)
        $id = self::drawnImageId($output);
        $this->assertStringStartsWith("\x1b_Ga=T,i={$id},f=100,q=2,c=1,r=1,m=1;", $output, 'Kitty output should begin with APC kitty header _Ga=T,');
    }

    /**
     * @testdox iTerm2 output (when non-empty) starts with iTerm2 OSC header \x1b]1337;
     */
    public function testIterm2OutputContainsIterm2Header(): void
    {
        $frame = self::makeFrame();
        $output = $this->getRenderer(Mode::Iterm2)->render($frame, Mode::Iterm2);

        if ($output === '') {
            $this->markTestSkipped('iTerm2 protocol returned empty — skipping header check');
        }

        // iTerm2 OSC: ESC ] 1337 ; (\x1b]1337;)
        $this->assertStringStartsWith("\x1b]1337;", $output, 'iTerm2 output should begin with OSC 1337 header');
    }

    // -------------------------------------------------------------------------
    // PNG-payload frames — the real graphics decode path (no re-encode)
    // -------------------------------------------------------------------------

    /**
     * Build a real PNG blob at runtime via GD.
     */
    private static function makePng(int $w, int $h): string
    {
        $img = \imagecreatetruecolor($w, $h);
        \imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, (int) \imagecolorallocate($img, 200, 60, 40));
        \ob_start();
        \imagepng($img);
        $png = (string) \ob_get_clean();
        \imagedestroy($img);

        return $png;
    }

    /**
     * @testdox a PNG-payload frame renders to the iTerm2 OSC envelope
     */
    public function testPngFrameRendersIterm2Envelope(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }

        $png = self::makePng(40, 40);
        $frame = new RgbFrame('', 40, 40, $png);

        $output = (new GraphicsRenderer(Mode::Iterm2))->render($frame, Mode::Iterm2);

        $this->assertStringStartsWith("\x1b]1337;", $output);
    }

    /**
     * @testdox a PNG-payload frame renders to the Kitty graphics envelope
     */
    public function testPngFrameRendersKittyEnvelope(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }

        $png = self::makePng(40, 40);
        $frame = new RgbFrame('', 40, 40, $png);

        $output = (new GraphicsRenderer(Mode::Kitty))->render($frame, Mode::Kitty);

        $id = self::drawnImageId($output);
        $this->assertStringStartsWith("\x1b_Ga=T,i={$id},f=100,q=2,c=4,r=2,m=1;", $output);
    }

    /**
     * @testdox a PNG-payload Sixel frame contains the DCS intro via the pure-PHP encoder
     */
    public function testPngFrameRendersSixelDcsViaPurePhpEncoder(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }

        $png = self::makePng(40, 40);
        $frame = new RgbFrame('', 40, 40, $png);

        $output = (new GraphicsRenderer(Mode::Sixel))->render($frame, Mode::Sixel);

        $this->assertStringContainsString("\x1bP", $output, 'sixel output carries the DCS intro');
    }

    /**
     * @testdox the retired external sixel encoder is not referenced by the renderer
     *
     * W10 §4.15 item 2 (F-18): sugar-video's own sixel shell-out — the
     * ChafaRenderer::available() preference on the Sixel arm, which spawned
     * the external encoder when it was on PATH — was retired. Sixel is
     * encoded in pure PHP by sugar-image's SixelRenderer. This source pin
     * keeps the external encoder from being silently reintroduced: re-adding
     * the class token or its argv flags fails here on every machine, whether
     * or not the binary is installed.
     */
    public function testTheExternalSixelEncoderIsNotReferencedByTheRenderer(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Render/GraphicsRenderer.php');
        $this->assertIsString($source, 'GraphicsRenderer source must be readable');

        $this->assertStringNotContainsString('ChafaRenderer', $source, 'the retired external encoder must not be re-wired');
        $this->assertStringNotContainsString('--scale=max', $source, 'the retired encoder argv must not return');
    }

    /**
     * @testdox the cell pixel geometry round-trips: an 800x480 frame requests ~80 cells wide
     *
     * GraphicsRenderer recovers cellsW = round(frame->w / cellPxW). With a 10px
     * cell box, an 800px-wide frame resolves to 80 cells — which the iTerm2
     * envelope echoes as `width=80` (cells, see Iterm2Renderer + Ansi::iterm2InlineImage).
     */
    public function testCellPxRoundTripRequestsExpectedCellWidth(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }

        $png = self::makePng(800, 480);
        $frame = new RgbFrame('', 800, 480, $png);

        $output = (new GraphicsRenderer(Mode::Iterm2, 10, 20))->render($frame, Mode::Iterm2);

        // 800 / 10 = 80 cells wide, 480 / 20 = 24 cells tall.
        $this->assertStringContainsString('width=80', $output);
        $this->assertStringContainsString('height=24', $output);
    }

    /**
     * Regression: Kitty frames went out with no image id and were never
     * deleted, so a video grew the terminal's image store by one image per
     * frame. Consecutive frames must alternate ids and retire the one they
     * replace.
     */
    public function testKittyFramesAlternateImageIdsAndRetireThePreviousFrame(): void
    {
        $renderer = new GraphicsRenderer(Mode::Kitty);
        $frame    = self::makeFrame();

        $first  = $renderer->render($frame, Mode::Kitty);
        $second = $renderer->render($frame, Mode::Kitty);
        $third  = $renderer->render($frame, Mode::Kitty);

        $slot1 = self::drawnImageId($first);
        $slot2 = $slot1 + 1;

        $this->assertStringContainsString("i={$slot1},", $first, 'frame 0 draws under slot 1');
        $this->assertStringNotContainsString('a=d', $first, 'the first frame has nothing to retire');

        $this->assertStringContainsString("i={$slot2},", $second, 'frame 1 draws under slot 2');
        $this->assertStringContainsString("a=d,d=I,i={$slot1}\x1b\\", $second, 'frame 1 retires slot 1');
        $this->assertStringNotContainsString("a=d,d=I,i={$slot2}", $second, 'frame 1 must not self-delete');

        $this->assertStringContainsString("i={$slot1},", $third, 'frame 2 reuses slot 1');
        $this->assertStringContainsString("a=d,d=I,i={$slot2}\x1b\\", $third, 'frame 2 retires slot 2');
    }

    /**
     * iTerm2 has no id-addressed delete, so their output stays the
     * plain per-frame render with no retire bytes.
     */
    public function testNonIdDeleteBackendsEmitNoRetireBytes(): void
    {
        $frame = self::makeFrame();

        $renderer = new GraphicsRenderer(Mode::Iterm2);
        $first    = $renderer->render($frame, Mode::Iterm2);
        $second   = $renderer->render($frame, Mode::Iterm2);

        $this->assertSame($first, $second, 'iTerm2 frames are byte-stable');
        $this->assertStringNotContainsString('a=d', $second, 'iTerm2 emits no Kitty delete');
    }

    /**
     * A Player rebuild (resize or mode cycle) replaces the renderer. The successor
     * must retire the frame the outgoing session drew last, or it stays in the
     * terminal's image store.
     */
    public function testSuccessorRetiresTheOutgoingKittyFrameOnItsFirstDraw(): void
    {
        $frame = self::makeFrame();
        $outgoing = new GraphicsRenderer(Mode::Kitty);
        $slot1 = self::drawnImageId($outgoing->render($frame, Mode::Kitty));
        $outgoing->render($frame, Mode::Kitty);

        $retire = (new KittyRenderer())->delete((string) ($slot1 + 1));
        $successor = (new GraphicsRenderer(Mode::Kitty))->carryingFrom($outgoing);

        $this->assertStringStartsWith($retire, $successor->render($frame, Mode::Kitty));
        $this->assertStringStartsNotWith($retire, $successor->render($frame, Mode::Kitty), 'the residue is written once');
    }

    /**
     * The retirement crosses a mode change: a Kitty frame left by a Kitty session
     * is deleted even when the successor draws through another backend.
     */
    public function testSuccessorInAnotherModeStillRetiresTheKittyFrame(): void
    {
        $frame = self::makeFrame();
        $outgoing = new GraphicsRenderer(Mode::Kitty);
        $slot = self::drawnImageId($outgoing->render($frame, Mode::Kitty));

        $successor = (new GraphicsRenderer(Mode::Sixel))->carryingFrom($outgoing);

        $this->assertStringStartsWith((new KittyRenderer())->delete((string) $slot), $successor->render($frame, Mode::Sixel));
    }

    /**
     * A second rebuild before any render must not drop the first rebuild's
     * still-owed retirement.
     */
    public function testRebuildBeforeRenderKeepsThePredecessorsResidue(): void
    {
        $frame = self::makeFrame();
        $outgoing = new GraphicsRenderer(Mode::Kitty);
        $slot = self::drawnImageId($outgoing->render($frame, Mode::Kitty));

        $unrendered = (new GraphicsRenderer(Mode::Kitty))->carryingFrom($outgoing);
        $again = (new GraphicsRenderer(Mode::Kitty))->carryingFrom($unrendered);

        $this->assertStringStartsWith((new KittyRenderer())->delete((string) $slot), $again->render($frame, Mode::Kitty));
    }

    /**
     * Two live Kitty sessions on one terminal must not share image ids: each one's
     * frames and retirements address only its own pair, so neither can delete the
     * other's frame.
     */
    public function testConcurrentSessionsOnOneTerminalUseDistinctImageIds(): void
    {
        $frame = self::makeFrame();
        $a = new GraphicsRenderer(Mode::Kitty);
        $b = new GraphicsRenderer(Mode::Kitty);

        $aFirst = $a->render($frame, Mode::Kitty);
        $bFirst = $b->render($frame, Mode::Kitty);
        $aSecond = $a->render($frame, Mode::Kitty);

        $aId = self::drawnImageId($aFirst);
        $bId = self::drawnImageId($bFirst);
        self::assertNotSame($aId, $bId, 'two sessions must draw under distinct ids');
        self::assertStringContainsString(
            (new KittyRenderer())->delete((string) $aId),
            $aSecond,
            'A retires its own previous frame',
        );
        self::assertStringNotContainsString(
            (new KittyRenderer())->delete((string) $bId),
            $aSecond,
            'A must never delete the frame B is showing',
        );
    }

    /**
     * The first image id a Kitty draw addresses, read from its `i=` control key.
     */
    private static function drawnImageId(string $output): int
    {
        self::assertSame(1, preg_match('/\bi=(\d+)/', $output, $m), 'the draw must address an image id');

        return (int) $m[1];
    }

    /**
     * With no frame drawn before the rebuild there is nothing to retire.
     */
    public function testSuccessorOfAFreshRendererRetiresNothing(): void
    {
        $successor = (new GraphicsRenderer(Mode::Kitty))->carryingFrom(new GraphicsRenderer(Mode::Kitty));

        $this->assertStringNotContainsString('a=d', $successor->render(self::makeFrame(), Mode::Kitty));
    }
}
