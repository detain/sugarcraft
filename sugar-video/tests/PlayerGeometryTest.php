<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\Mode;

/**
 * SV-PGEO — the two geometry-paint holes (sugar-video-068, 069).
 *
 * 068: a terminal genuinely narrower than the MIN_COLS floor used to get
 * pane-width lines anyway — every line soft-wrapped and the whole picture
 * corrupted. The pane now answers such a host with ONE honest line painted
 * at the real width, and the refusal tracks the host's size live (during
 * the resize debounce, not only after the rebuild lands).
 *
 * 069: the Decoder interface constrains no frame geometry. A lying decoder
 * painting pixels wider/taller than the pane used to emit ceil(frame/cell)
 * over-wide rows with no clamp. The grid is now clipped to cellsW×cellsH,
 * and dev builds (SUGAR_VIDEO_DEBUG / the testing seam) are told loudly.
 */
final class PlayerGeometryTest extends TestCase
{
    protected function tearDown(): void
    {
        Player::setGeometryAssertsForTesting(null);
    }

    public function testABelowFloorTerminalGetsOneHonestLineNotOverWidePaint(): void
    {
        // 40x12 HalfBlock pixels — a legitimately PAINTED pane 10 rows deep
        // for its grid, then soft-wrapped to mush on an 8-col terminal.
        $decoder = new FakeDecoder([new RgbFrame(str_repeat(chr(7), 40 * 12 * 3), 40, 12)]);
        $player = Player::fromDecoder(decoder: $decoder, cellsW: 8, cellsH: 6, fps: 24.0, totalFrames: 1, paused: false);

        [$painted] = $player->update(new TickMsg());
        self::assertNotNull($painted->currentFrame, 'the tick painted — the refusal must still win the view');

        $out = $painted->view();

        self::assertStringContainsString('termin', $out);
        self::assertSame(0, substr_count($out, "\r\n"), 'a refusal is ONE line, never a wrapped frame block');
        self::assertLessThanOrEqual(8, Width::of($out), 'the refusal itself must fit the real terminal');
        self::assertSame(8, self::narrow($painted));
    }

    public function testTheRefusalRestatesWhileAlreadyAtTheFloorAndKeepsItsIdentityOnceSettled(): void
    {
        $decoder = new FakeDecoder([new RgbFrame(str_repeat(chr(7), 10 * 12 * 3), 10, 12)]);
        $player = Player::fromDecoder(decoder: $decoder, cellsW: 9, cellsH: 6, fps: 24.0, totalFrames: 1);
        self::assertSame(9, self::narrow($player));

        // Another 9-col event: clamped geometry equals the pane and the floor
        // state already matches — identity, no rebuild, no churn.
        [$again, $cmd] = $player->update(new WindowSizeMsg(9, 6));
        self::assertSame($player, $again);
        self::assertNull($cmd);

        // The terminal narrows to 6 — same clamped pane, but the refusal must
        // restate the REAL width now, not 9.
        [$narrower, $cmd] = $player->update(new WindowSizeMsg(6, 6));
        self::assertNull($cmd, 'a below-floor restatement rebuilds nothing');
        self::assertSame(6, self::narrow($narrower));
        self::assertLessThanOrEqual(6, Width::of($narrower->view()));
    }

    public function testGrowingAboveTheFloorClearsTheRefusalBeforeTheRebuildLands(): void
    {
        $decoder = new FakeDecoder([new RgbFrame(str_repeat(chr(7), 10 * 12 * 3), 10, 12)]);
        $player = Player::fromDecoder(decoder: $decoder, cellsW: 6, cellsH: 6, fps: 24.0, totalFrames: 1);
        self::assertStringContainsString('termin', $player->view());

        [$wide, $cmd] = $player->update(new WindowSizeMsg(60, 20));
        self::assertNotNull($cmd, 'the real rebuild is still deferred to the debounce');
        self::assertNull(self::narrow($wide), 'the host outgrew the floor — the refusal clears NOW');
        self::assertStringNotContainsString('terminal too small', $wide->view());

        // A repeated 60x20 event while that target is pending must no-op whole.
        [$again, $againCmd] = $wide->update(new WindowSizeMsg(60, 20));
        self::assertSame($wide, $again);
        self::assertNull($againCmd);
    }

    public function testALyingDecoderIsClampedToThePane(): void
    {
        // 200x120 pixels into a 10x6-cell HalfBlock pane: the raw grid would
        // be 200 wide (colsPerCell=1) — over-wide paint with no clamp.
        $frame = new RgbFrame(str_repeat(chr(3), 200 * 120 * 3), 200, 120);
        $decoder = new FakeDecoder([$frame]);
        $player = Player::fromDecoder(decoder: $decoder, cellsW: 10, cellsH: 6, fps: 24.0, totalFrames: 1);
        Player::setGeometryAssertsForTesting(false); // production: clamp, stay quiet

        $buffer = self::frameToBuffer($player, $frame, Mode::HalfBlock);

        self::assertSame(10, $buffer->width(), 'the grid can never exceed the pane width');
        self::assertSame(6, $buffer->height(), 'the grid can never exceed the pane height');
    }

    public function testDevBuildsHearAboutTheLyingDecoder(): void
    {
        $frame = new RgbFrame(str_repeat(chr(3), 200 * 120 * 3), 200, 120);
        $decoder = new FakeDecoder([$frame]);
        $player = Player::fromDecoder(decoder: $decoder, cellsW: 10, cellsH: 6, fps: 24.0, totalFrames: 1);
        Player::setGeometryAssertsForTesting(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('exceeds the pane');

        self::frameToBuffer($player, $frame, Mode::HalfBlock);
    }

    public function testConformingFramesStillPaintTheWholeGridUntouched(): void
    {
        // A conforming HalfBlock decoder emits cellsW × cellsH*2 pixels — the
        // clamp is a no-op there (zero golden movement through this row), and
        // even in loud mode an honest decoder hears nothing.
        $frame = new RgbFrame(str_repeat(chr(5), 10 * 12 * 3), 10, 12);
        $decoder = new FakeDecoder([$frame]);
        $player = Player::fromDecoder(decoder: $decoder, cellsW: 10, cellsH: 6, fps: 24.0, totalFrames: 1);
        Player::setGeometryAssertsForTesting(true);

        $buffer = self::frameToBuffer($player, $frame, Mode::HalfBlock);

        self::assertSame(10, $buffer->width());
        self::assertSame(6, $buffer->height());
    }

    /** @return int|null the recorded below-floor host width, null when at/above it */
    private static function narrow(Player $player): ?int
    {
        return (new \ReflectionProperty($player, 'narrowCols'))->getValue($player);
    }

    /** Invoke the private Buffer rung with an explicit frame and mode. */
    private static function frameToBuffer(Player $player, RgbFrame $frame, Mode $mode): \SugarCraft\CellBuffer\Buffer
    {
        return (new \ReflectionMethod($player, 'frameToBuffer'))->invoke($player, $frame, $mode);
    }
}
