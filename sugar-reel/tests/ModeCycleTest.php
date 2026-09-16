<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Tests\Concerns\DrivesPlayerInternals;

/**
 * Finding #13: pressing `m` used to tear down the decoder AND re-decode forward
 * to the playhead on every press, even when the incoming mode wanted exactly the
 * same pixel grid from the source.
 *
 * A decoder only ever consults the mode for `isGraphics()`, `colsPerCell()` and
 * `rowsPerCell()`, so those three are the whole invalidation surface. These tests
 * drive the mode cycle with fakes that COUNT their own open/reopen/close calls, so
 * "the decoder survived the switch" is an observed fact rather than an inference
 * from a rendering result. No ffmpeg binary is involved anywhere: the injected
 * decoders replay synthetic frames.
 *
 * @covers \SugarCraft\Reel\Player
 */
final class ModeCycleTest extends TestCase
{
    use DrivesPlayerInternals;

    private const M = 'm';

    private function player(Mode $mode, FakeDecoder $decoder): Player
    {
        return Player::fromDecoder(
            $decoder,
            cellsW: 20,
            cellsH: 10,
            fps: 24.0,
            mode: $mode,
            totalFrames: 4,
            videoPath: '/fake',
        );
    }

    private function pressM(Player $player): Player
    {
        [$next,] = $player->update(new KeyMsg(KeyType::Char, self::M));

        return $next;
    }

    /**
     * @return array<string, array{Mode, Mode}>
     */
    public static function equalGeometryPairs(): array
    {
        return [
            'ascii -> ansi256' => [Mode::Ascii, Mode::Ansi256],
            'ansi256 -> truecolor' => [Mode::Ansi256, Mode::TrueColor],
            'ascii -> truecolor' => [Mode::Ascii, Mode::TrueColor],
        ];
    }

    /**
     * The three 1:1 text modes sit next to each other in the cycle, so this walks
     * exactly that stretch without depending on what Mosaic::diagnose() reports.
     *
     * @dataProvider equalGeometryPairs
     */
    public function testEqualGeometryModeSwitchReusesTheDecoder(Mode $from, Mode $to): void
    {
        // Reaching `$to` from `$from` may pass through other equal-geometry modes;
        // the invariant under test is that none of those hops re-spawns.
        $cycle = [Mode::Ascii, Mode::Ansi256, Mode::TrueColor, Mode::HalfBlock, Mode::QuarterBlock];
        $hops = (int) (array_search($to, $cycle, true) - array_search($from, $cycle, true) + count($cycle)) % count($cycle);
        self::assertGreaterThan(0, $hops);

        $decoder = new FakeDecoder(array_fill(0, 4, new RgbFrame("\x40\x50\x60", 20, 10)));
        $player = $this->mutatePlayer(
            $this->player($from, $decoder),
            ['currentFrame' => new RgbFrame("\x11\x22\x33", 20, 10)],
        );
        $frame = $player->currentFrame;
        self::assertNotNull($frame);

        for ($i = 0; $i < $hops; $i++) {
            $player = $this->pressM($player);
        }

        self::assertSame($to, $player->mode, 'the cycle must land on the target mode');
        self::assertSame(0, $decoder->reopenCount(), 'an equal-geometry switch must not reopen the decoder');
        self::assertSame(0, $decoder->openCount(), 'an equal-geometry switch must not open a new decoder');
        self::assertSame(0, $decoder->closeCount(), 'an equal-geometry switch must not close the live decoder');
        self::assertSame($decoder, $player->decoder, 'the very same decoder instance must carry over');
        self::assertSame($frame, $player->currentFrame, 'the frame in hand must be kept, not re-decoded');
        self::assertNotNull($this->playerProperty($player, 'renderer'), 'the renderer must still be armed');
        self::assertNotSame(
            $this->playerProperty($this->player($from, $decoder), 'renderer'),
            $this->playerProperty($player, 'renderer'),
            'the renderer is the one thing a mode switch always replaces',
        );
    }

    public function testCrossingGeometryStillRebuildsTheDecoder(): void
    {
        // HalfBlock wants 2 source rows per cell; the 1:1 modes want 1. Crossing
        // that line MUST rebuild, or the frame grid no longer fits the mode.
        // NOTE: GeometryFakeDecoder::reopen() re-enters open() to regenerate its
        // frames, so this double counts a re-spawn in openCount().
        $decoder = new GeometryFakeDecoder(4);
        $player = $this->player(Mode::TrueColor, $decoder);

        $player = $this->pressM($player); // TrueColor -> HalfBlock
        self::assertSame(Mode::HalfBlock, $player->mode);
        self::assertSame(1, $decoder->openCount(), 'a geometry change must reopen the decoder');
        self::assertNotNull($player->currentFrame);
        self::assertSame(10 * Mode::HalfBlock->rowsPerCell(), $player->currentFrame->h, 'frames must be decoded at the new geometry');

        $player = $this->pressM($player); // HalfBlock -> QuarterBlock (2x2)
        self::assertSame(Mode::QuarterBlock, $player->mode);
        self::assertSame(2, $decoder->openCount(), 'another geometry change must reopen again');

        $player = $this->pressM($player); // QuarterBlock -> Ascii (1x1)
        self::assertSame(Mode::Ascii, $player->mode);
        self::assertSame(3, $decoder->openCount());
        self::assertSame(10, $player->currentFrame->h);
    }

    public function testFullTextCycleReopensOnlyOnGeometryBoundaries(): void
    {
        // Ascii → Ansi256 → TrueColor (0 reopens) → HalfBlock (1) → QuarterBlock (2)
        // → back to Ascii (3). Five presses, three rebuilds — pre-fix this was five.
        //
        // CAVEAT (round-1 review n3): the text-only window is exactly these five
        // modes, so the assertion holds only while `Mosaic::diagnose()` on this host
        // advertises no graphics protocol. On a graphics-capable runner the cycle
        // continues past Ascii; the sixth press is the boundary where a reopen into
        // graphics would land. The count below is pinned to the text-only segment,
        // which both the first assertion (mode back at Ascii) and CI hosts guarantee.
        $decoder = new FakeDecoder(array_fill(0, 8, new RgbFrame("\x01\x02\x03", 20, 10)));
        $player = $this->player(Mode::Ascii, $decoder);

        for ($i = 0; $i < 5; $i++) {
            $player = $this->pressM($player);
        }

        self::assertSame(Mode::Ascii, $player->mode, 'five presses from Ascii land back on Ascii in the text-only cycle');
        self::assertSame(3, $decoder->reopenCount(), 'only the geometry boundaries may reopen');
    }

    public function testEqualGeometrySwitchPreservesPlayheadAndOtherState(): void
    {
        $decoder = new FakeDecoder(array_fill(0, 4, new RgbFrame("\x40\x50\x60", 20, 10)));
        $player = $this->mutatePlayer(
            $this->player(Mode::Ascii, $decoder),
            ['frameIndex' => 3, 'videoTime' => 0.125, 'ended' => true, 'speed' => 2.0, 'paused' => true],
        );

        $next = $this->pressM($player);

        self::assertSame(Mode::Ansi256, $next->mode);
        self::assertSame(3, $next->frameIndex, 'the playhead must not move on a pure mode swap');
        self::assertSame(0.125, $next->videoTime);
        self::assertTrue($next->ended, 'a mode switch must not silently resume an ended player');
        self::assertSame(2.0, $next->speed);
        self::assertTrue($next->paused);
        self::assertSame(20, $next->cellsW);
        self::assertSame(10, $next->cellsH);
    }

    public function testReusedDecoderKeepsServingFramesAfterTheSwitch(): void
    {
        // The payoff check: the live stream must continue from where it was rather
        // than replaying from the head, which is what the old close-and-reopen did.
        $frames = [
            new RgbFrame("\x01\x01\x01", 20, 10),
            new RgbFrame("\x02\x02\x02", 20, 10),
            new RgbFrame("\x03\x03\x03", 20, 10),
        ];
        $decoder = new FakeDecoder($frames);
        $decoder->open('/fake', 20, 10, 24.0, Mode::Ascii);
        self::assertSame($frames[0], $decoder->next());

        $player = $this->mutatePlayer($this->player(Mode::Ascii, $decoder), ['currentFrame' => $frames[0]]);
        $player = $this->pressM($player);
        self::assertSame(Mode::Ansi256, $player->mode);

        // next() on the SAME decoder must yield frame 2, not frame 1 again.
        self::assertSame($frames[1], $player->decoder->next(), 'the decoder position must survive the mode switch');
        self::assertSame(0, $decoder->reopenCount());
    }
}
