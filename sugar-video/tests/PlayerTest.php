<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Video\AudioPlayer;
use SugarCraft\Video\Decode\FfmpegDecodeFailure;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Synthetic;
use SugarCraft\Video\Msg\ResizeRebuildMsg;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Player;
use SugarCraft\Video\Source\Probe;
use SugarCraft\Video\Subtitle\WebVtt;
use SugarCraft\Video\Tests\FakeDecoder;
use SugarCraft\Video\Decode\AnimatedWebpDecoder;
use SugarCraft\Video\Decode\FfmpegDecoder;
use SugarCraft\Video\Decode\GifDecoder;
use SugarCraft\Video\Render\HalfBlockRenderer;
use SugarCraft\Video\Render\LumaRamp;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\Testing\ProgramSimulator;
use SugarCraft\Testing\Input\ScriptedInput;

/**
 * Unit tests for Player TEA model.
 *
 * Uses FakeDecoder to provide deterministic frame sequences without
 * any real video file or external process.
 *
 * @covers \SugarCraft\Video\Player
 */
final class PlayerTest extends TestCase
{
    /** @var list<string> Temp files to remove in tearDown. */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a 1×1 RgbFrame with the given RGB bytes.
     */
    private function makeFrame(string $rgb, int $w = 1, int $h = 1): RgbFrame
    {
        return new RgbFrame($rgb, $w, $h);
    }

    /**
     * Create a small multi-color GIF on disk so DecoderFactory builds a real
     * GifDecoder (no ffmpeg) and rebuildDecoderAt() takes its real branch.
     * Registered for cleanup in tearDown.
     */
    private function createTempGif(int $w = 8, int $h = 6): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $color = imagecolorallocate($img, ($x * 31) % 256, ($y * 47) % 256, (($x + $y) * 17) % 256);
                imagesetpixel($img, $x, $y, $color);
            }
        }

        $path = sys_get_temp_dir() . '/sugar-video-player-gif-' . uniqid('', true) . '.gif';
        imagegif($img, $path);
        imagedestroy($img);

        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * Create a FakeDecoder pre-populated with $count black 1×1 frames.
     *
     * @param int $count Number of frames
     * @return FakeDecoder
     */
    private function makeFakeDecoder(int $count): FakeDecoder
    {
        $black = "\x00\x00\x00";
        $frames = array_fill(0, $count, $this->makeFrame($black));
        return new FakeDecoder($frames);
    }

    /**
     * Create a FakeDecoder with a single red 1×1 pixel frame.
     */
    private function makeRedFrameDecoder(): FakeDecoder
    {
        // Red pixel: R=255, G=0, B=0
        return new FakeDecoder([$this->makeFrame("\xff\x00\x00")]);
    }

    // -------------------------------------------------------------------------
    // Player::openForTest
    // -------------------------------------------------------------------------

    /**
     * @testdox Player::openForTest returns a Player instance (not null)
     */
    public function testOpenReturnsPlayer(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        $this->assertInstanceOf(Player::class, $player);
    }

    /**
     * @testdox Player::openForTest creates a paused player
     */
    public function testInitCreatesPausedPlayer(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        // Player starts paused via openForTest factory
        $this->assertTrue($this->getPlayerProperty($player, 'paused'));
    }

    /**
     * @testdox Player::openForTest stores the fps parameter in the player
     *
     * The fps is stored as a public readonly property so tests can access
     * it directly without reflection.
     */
    public function testOpenForTestStoresFps(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 60.0);

        // fps is a public readonly property on Player
        $this->assertSame(60.0, $player->fps);
    }

    /**
     * @testdox Player::openForTest stores different fps values correctly
     */
    public function testOpenForTestStoresVariousFpsValues(): void
    {
        $decoder = $this->makeFakeDecoder(10);

        $player24 = Player::openForTest($decoder, 24.0);
        $this->assertSame(24.0, $player24->fps);

        $player30 = Player::openForTest($decoder, 30.0);
        $this->assertSame(30.0, $player30->fps);

        $player5994 = Player::openForTest($decoder, 59.94);
        $this->assertSame(59.94, $player5994->fps);
    }

    // -------------------------------------------------------------------------
    // Cell pixel geometry (graphics-mode decode/render resolution)
    // -------------------------------------------------------------------------

    /**
     * @testdox Player::openForTest stores the cellPxW/cellPxH it was given
     *
     * Graphics modes decode at cells·cellPx; the Player threads the cell pixel
     * box into every decoder/renderer rebuild, exposing it as public readonly
     * properties.
     */
    public function testOpenForTestStoresCellPxGeometry(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0, cellPxW: 8, cellPxH: 16);

        $this->assertSame(8, $player->cellPxW);
        $this->assertSame(16, $player->cellPxH);
    }

    /**
     * @testdox the default cell pixel box is 10x20 when no cellPx args are passed
     */
    public function testOpenForTestDefaultCellPxGeometry(): void
    {
        $player = Player::openForTest($this->makeFakeDecoder(3), 30.0);

        $this->assertSame(10, $player->cellPxW);
        $this->assertSame(20, $player->cellPxH);
    }

    /**
     * @testdox the cell pixel box survives a withSeek() rebuild
     *
     * withSeek() rebuilds the Player (and its decoder/renderer) — the explicit
     * cellPx geometry must be carried over, not reset to the defaults.
     */
    public function testCellPxGeometrySurvivesSeek(): void
    {
        $decoder = $this->makeFakeDecoder(20);
        $player = Player::openForTest($decoder, 30.0, totalFrames: 20, cellPxW: 8, cellPxH: 16);

        $seeked = $player->withSeek(5);

        $this->assertSame(8, $seeked->cellPxW, 'cellPxW must survive the seek rebuild');
        $this->assertSame(16, $seeked->cellPxH, 'cellPxH must survive the seek rebuild');
    }

    // -------------------------------------------------------------------------
    // Space key toggles pause
    // -------------------------------------------------------------------------

    /**
     * @testdox Space key unpauses a paused player
     */
    public function testSpaceKeyTogglesPause(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        // Player starts paused
        $this->assertTrue($this->getPlayerProperty($player, 'paused'));

        // First Space unpauses
        $space = new KeyMsg(KeyType::Space);
        [$player,] = $player->update($space);

        $this->assertFalse($this->getPlayerProperty($player, 'paused'));

        // Second Space re-pauses
        [$player,] = $player->update($space);

        $this->assertTrue($this->getPlayerProperty($player, 'paused'));
    }

    // -------------------------------------------------------------------------
    // TickMsg advances frame
    // -------------------------------------------------------------------------

    /**
     * @testdox TickMsg when unpaused advances the frame index
     */
    public function testTickMsgAdvancesFrame(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        // Advance decoder to get first frame
        $firstFrame = $decoder->next();
        $this->assertNotNull($firstFrame);

        // Pre-seed currentFrame so TickMsg has something to advance from
        $player = $this->setCurrentFrame($player, $firstFrame, 0);

        // Unpause
        $space = new KeyMsg(KeyType::Space);
        [$player,] = $player->update($space);

        // Send TickMsg
        $tick = new TickMsg();
        [$player, $cmd] = $player->update($tick);

        $frameIndex = $this->getPlayerProperty($player, 'frameIndex');
        $this->assertSame(1, $frameIndex);
    }

    /**
     * @testdox TickMsg does not advance when paused
     */
    public function testTickMsgWhenPausedDoesNotAdvance(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        // Pre-seed currentFrame
        $firstFrame = $decoder->next();
        $player = $this->setCurrentFrame($player, $firstFrame, 0);

        $frameIndexBefore = $this->getPlayerProperty($player, 'frameIndex');

        // Send TickMsg while paused
        $tick = new TickMsg();
        [$player,] = $player->update($tick);

        $frameIndexAfter = $this->getPlayerProperty($player, 'frameIndex');
        $this->assertSame($frameIndexBefore, $frameIndexAfter);
    }

    // -------------------------------------------------------------------------
    // Arrow keys seek
    // -------------------------------------------------------------------------

    /**
     * @testdox LeftArrow seeks backward 10 frames
     */
    public function testLeftArrowSeeksBackward(): void
    {
        $decoder = $this->makeFakeDecoder(20);
        $player = Player::openForTest($decoder, 30.0);

        // Seek forward first (past frame 0) so we have room to seek back
        // Set frameIndex to 15
        $player = $this->setFrameIndex($player, 15);

        $left = new KeyMsg(KeyType::Left);
        [$player,] = $player->update($left);

        $frameIndex = $this->getPlayerProperty($player, 'frameIndex');
        // Left seeks back 10: max(0, 15 - 10) = 5
        $this->assertSame(5, $frameIndex);
    }

    /**
     * @testdox RightArrow seeks forward 10 frames
     */
    public function testRightArrowSeeksForward(): void
    {
        $decoder = $this->makeFakeDecoder(30);
        $player = Player::openForTest($decoder, 30.0);

        // Set frameIndex to 5
        $player = $this->setFrameIndex($player, 5);

        $right = new KeyMsg(KeyType::Right);
        [$player,] = $player->update($right);

        $frameIndex = $this->getPlayerProperty($player, 'frameIndex');
        // Right seeks forward 10: 5 + 10 = 15
        $this->assertSame(15, $frameIndex);
    }

    // -------------------------------------------------------------------------
    // [ ] keys adjust speed
    // -------------------------------------------------------------------------

    /**
     * @testdox LeftBracket decreases speed by 0.25
     */
    public function testLeftBracketDecreasesSpeed(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        $leftBracket = new KeyMsg(KeyType::Char, '[');
        [$player,] = $player->update($leftBracket);

        $speed = $this->getPlayerProperty($player, 'speed');
        // Started at 1.0, decreased by 0.25 = 0.75
        $this->assertSame(0.75, $speed);
    }

    /**
     * @testdox RightBracket increases speed by 0.25
     */
    public function testRightBracketIncreasesSpeed(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        $rightBracket = new KeyMsg(KeyType::Char, ']');
        [$player,] = $player->update($rightBracket);

        $speed = $this->getPlayerProperty($player, 'speed');
        // Started at 1.0, increased by 0.25 = 1.25
        $this->assertSame(1.25, $speed);
    }

    /**
     * @testdox Speed is clamped at minimum 0.25
     */
    public function testSpeedClampedAtMinimum(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        // Decrease speed many times to hit the floor
        $leftBracket = new KeyMsg(KeyType::Char, '[');
        for ($i = 0; $i < 10; $i++) {
            [$player,] = $player->update($leftBracket);
        }

        $speed = $this->getPlayerProperty($player, 'speed');
        $this->assertSame(0.25, $speed);
    }

    /**
     * @testdox Speed is clamped at maximum 4.0
     */
    public function testSpeedClampedAtMaximum(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        // Increase speed many times to hit the ceiling
        $rightBracket = new KeyMsg(KeyType::Char, ']');
        for ($i = 0; $i < 20; $i++) {
            [$player,] = $player->update($rightBracket);
        }

        $speed = $this->getPlayerProperty($player, 'speed');
        $this->assertSame(4.0, $speed);
    }

    // -------------------------------------------------------------------------
    // 0-9 keys seek to percentage
    // -------------------------------------------------------------------------

    /**
     * @testdox Char '0' seeks to 0 percent of duration
     */
    public function testChar0SeeksTo0Percent(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        // Pass totalFrames=100 via openForTest so digit-seek works.
        $player = Player::openForTest($decoder, 30.0, 100, 80, 24, '/fake');

        $char0 = new KeyMsg(KeyType::Char, '0');
        [$player,] = $player->update($char0);

        $frameIndex = $this->getPlayerProperty($player, 'frameIndex');
        // 0% of 100 = frame 0
        $this->assertSame(0, $frameIndex);
    }

    /**
     * @testdox Char '5' seeks to 50 percent of duration
     *
     * Note: with 10-frame FakeDecoder, forward seek to frame 50 stops at
     * the last available frame (frame 9) since the decoder runs out of frames.
     */
    public function testChar5SeeksTo50Percent(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        // Pass totalFrames=100 via openForTest so digit-seek works.
        $player = Player::openForTest($decoder, 30.0, 100, 80, 24, '/fake');

        $char5 = new KeyMsg(KeyType::Char, '5');
        [$player,] = $player->update($char5);

        $frameIndex = $this->getPlayerProperty($player, 'frameIndex');
        // 50% of 100 = frame 50, but decoder only has 10 frames → clamped to 9
        $this->assertLessThanOrEqual(10, $frameIndex);
    }

    /**
     * @testdox Digit-seek must no-op when totalFrames is 0 (unknown-length stream)
     */
    public function testDigitSeekNoOpsWhenTotalFramesZero(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        // totalFrames=0 (default) — can't seek by percentage on unknown-length stream.
        $player = Player::openForTest($decoder, 30.0, 0, 80, 24, '/fake');
        $player = $this->setFrameIndex($player, 5);

        $char5 = new KeyMsg(KeyType::Char, '5');
        [$player,] = $player->update($char5);

        // Frame index must be UNCHANGED — digit-seek is a no-op when totalFrames=0.
        $frameIndex = $this->getPlayerProperty($player, 'frameIndex');
        $this->assertSame(5, $frameIndex);
    }

    // -------------------------------------------------------------------------
    // F4: Speed change must NOT retroactively re-scale accumulated videoTime
    // -------------------------------------------------------------------------

    /**
     * Regression for F4. A speed change from 1.0× to 1.25× must only affect
     * FUTURE pacing — no retroactive jump. The next target frame must advance
     * by only ~delta*1.25*fps with no 0.25*oldVideoTime*fps skip-storm.
     *
     * FAILSON: On master the retroactive formula (`elapsed += delta; target = elapsed*fps*speed`)
     * re-scales ALL prior elapsed time, so switching from 1.0→1.25× causes a massive
     * forward jump (old code multiplies the accumulated elapsed by 1.25, so the new
     * target overshoots by ~25% of total elapsed time — sometimes an entire half of
     * the video).
     */
    public function testSpeedChangeDoesNotRetroactivelyJump(): void
    {
        // SV-M20/020: pacing time is SCRIPTED through the clock seam — no
        // reflection seeding of lastTickTime, no wall-clock race. Build a
        // 100-frame decoder at 30 fps, start playing from frame 0.
        $t = 0.0;
        $clock = static function () use (&$t): float {
            return $t;
        };
        $decoder = new FakeDecoder(array_fill(0, 100, $this->makeFrame("\x00\x00\x00")));
        $player = Player::openForTest($decoder, 30.0, clock: $clock);

        // Pre-seed first frame and unpause.
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);
        $player = $this->setPlayerProperty($player, 'videoTime', 0.0);

        [$player] = $player->update(new KeyMsg(KeyType::Space)); // unpause

        // First tick: advances to frame 1. After tick, videoTime ≈ 1/30.
        [$player] = $player->update(new TickMsg());
        $frameAfterTick1 = $this->getPlayerProperty($player, 'frameIndex');
        // videoTime is now ~1/30

        // Script the next tick one frame interval later (30fps).
        $t += 0.033;

        // Press ']' to increase speed to 1.25× — this must ONLY affect the NEXT
        // tick's delta, not retroactively re-scale videoTime.
        [$player] = $player->update(new KeyMsg(KeyType::Char, ']'));

        // The speed must be 1.25.
        $this->assertSame(1.25, $this->getPlayerProperty($player, 'speed'));

        // The next tick: with videoTime ≈ 1/30 and delta ≈ 0.033,
        // newVideoTime = (1/30) + 0.033*1.25 ≈ 0.063.
        // target = floor(0.063*30) = floor(1.875) = 1.
        // So the tick should advance by AT MOST 1 frame (delta*1.25*fps ≈ 1.25).
        // A retroactive re-scale would produce target = floor((1/30)*30*1.25) = floor(1.25) = 1
        // — actually let me use a larger gap to make the retroactivity obvious.
        //
        // Let's use a more obvious case: script a 0.5s gap so the old formula would jump.
        $t += 0.5;
        $frameBefore = $this->getPlayerProperty($player, 'frameIndex');
        [$player] = $player->update(new TickMsg());
        $frameAfter = $this->getPlayerProperty($player, 'frameIndex');

        // delta = 0.5s, speed = 1.25, so newVideoTime grows by 0.5*1.25 = 0.625 more seconds.
        // At 30fps, 0.625s more content = 18.75 more frames.
        // So frameAfter should be frameBefore + at most 19 (never 30+ which would
        // happen with the retroactive bug where videoTime gets scaled by 1.25).
        $deltaFrames = $frameAfter - $frameBefore;
        $this->assertGreaterThanOrEqual(
            15,
            $deltaFrames,
            'the scripted clock really drove pacing (a dropped seam would see microsecond real-time deltas and hold)'
        );
        $this->assertLessThanOrEqual(
            19,
            $deltaFrames,
            'Speed change must NOT cause retroactive frame skip — at most delta*speed*fps extra frames'
        );
    }

    /**
     * Regression for findings #44: the catch-up decode-forward loop used to run
     * unbounded, so a big lag (long seek / stalling decode) blocked the whole TEA
     * update inside a single tick. With a frameBudgetMs set, ONE tick may only do
     * about that much wall-clock work — it stops early, leaving the decoder behind
     * the screen but CONVERGING: successive ticks keep catching up. videoTime stays
     * content-correct throughout.
     */
    public function testFrameBudgetBoundsSingleTickCatchUp(): void
    {
        // 40 frames, each costing ~2ms to produce. fps 30.
        $decoder = new SlowFakeDecoder(array_fill(0, 40, $this->makeFrame("\x00\x00\x00")), 0.002);
        $player = Player::fromDecoder($decoder, 80, 24, 30.0, paused: false, frameBudgetMs: 0.5);
        // Lag of ~0.5s ⇒ target ≈ 15 frames ahead of index 0.
        $player = $this->backdateLastTick($player, 0.5);

        [$oneTick] = $player->update(new TickMsg());
        $afterOneTick = $this->getPlayerProperty($oneTick, 'frameIndex');

        // A 0.5ms budget vs 2ms/frame means the loop breaks after the first frame
        // that overshoots the deadline — never the full ~15. Leave timing slack.
        $this->assertLessThan(
            10,
            $afterOneTick,
            'a bounded tick must not drain the whole lag in one blocking loop'
        );
        $this->assertGreaterThanOrEqual(1, $afterOneTick, 'the tick still made forward progress');

        // Convergence: keep ticking; the decoder walks up to the target rather than
        // being stuck, so catch-up finishes across ticks instead of in one freeze.
        $target = $this->getPlayerProperty($oneTick, 'videoTime') * 30.0;
        $current = $oneTick;
        for ($i = 0; $i < 40; $i++) {
            [$next] = $current->update(new TickMsg());
            $current = $next;
            if ($this->getPlayerProperty($current, 'frameIndex') >= $target) {
                break;
            }
        }
        $this->assertGreaterThanOrEqual(
            $target,
            $this->getPlayerProperty($current, 'frameIndex'),
            'successive bounded ticks converge the decoder to the target frame'
        );
    }

    /**
     * @testdox an unbounded budget (0) drains the entire lag in a single tick (legacy behaviour)
     */
    public function testZeroBudgetCatchesUpInOneTick(): void
    {
        $decoder = new SlowFakeDecoder(array_fill(0, 40, $this->makeFrame("\x00\x00\x00")), 0.0);
        $player = Player::fromDecoder($decoder, 80, 24, 30.0, paused: false, frameBudgetMs: 0.0);
        $player = $this->backdateLastTick($player, 0.2); // target ≈ 6

        [$ticked] = $player->update(new TickMsg());

        $this->assertGreaterThanOrEqual(
            6,
            $this->getPlayerProperty($ticked, 'frameIndex'),
            'with no budget the single tick skips all the way to the target'
        );
    }

    /**
     * Regression for F4. Pressing '[' to slow down from 1.0× to 0.75× must NOT
     * cause a multi-tick hold/freeze. The retroactive bug makes old elapsed time
     * appear LONGER when speed decreases (divide-by-smaller), causing the target
     * frame to fall behind current → shouldHold fires → freeze.
     */
    public function testSpeedDecreaseDoesNotFreeze(): void
    {
        // SV-M20/020: scripted clock — reflection seeding gone.
        $t = 0.0;
        $clock = static function () use (&$t): float {
            return $t;
        };
        $decoder = new FakeDecoder(array_fill(0, 100, $this->makeFrame("\x00\x00\x00")));
        $player = Player::openForTest($decoder, 30.0, clock: $clock);
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);
        $player = $this->setPlayerProperty($player, 'videoTime', 0.0);

        [$player] = $player->update(new KeyMsg(KeyType::Space)); // unpause

        // Advance several ticks.
        for ($i = 0; $i < 5; $i++) {
            $t += 0.033;
            [$player] = $player->update(new TickMsg());
        }
        $frameBefore = $this->getPlayerProperty($player, 'frameIndex');
        $videoTimeBefore = $this->getPlayerProperty($player, 'videoTime');

        // Slow down: 1.0 → 0.75
        [$player] = $player->update(new KeyMsg(KeyType::Char, '['));

        // The next tick: delta*0.75 makes videoTime grow SLOWER.
        // target = (videoTimeBefore + 0.033*0.75)*30 = videoTimeBefore*30 + ~0.74.
        // On the old buggy code, videoTime gets re-scaled: newElapsed = oldElapsed*0.75
        // (retroactively slower), so target = newElapsed*30 = oldElapsed*30*0.75,
        // which is LESS than the current frameIndex → shouldHold fires → freeze.
        // The fix means target >= currentFrameIndex, so tick ADDS frames (doesn't hold).
        $t += 0.033;
        [$player] = $player->update(new TickMsg());
        $frameAfter = $this->getPlayerProperty($player, 'frameIndex');

        // Must not freeze — frame should advance (or at minimum not regress).
        $this->assertGreaterThanOrEqual(
            $frameBefore,
            $frameAfter,
            'Slowing speed must NOT freeze playback (no shouldHold retroactively firing)'
        );
    }

    // -------------------------------------------------------------------------
    // m key cycles mode
    // -------------------------------------------------------------------------

    /**
     * @testdox 'm' key cycles through rendering modes
     */
    public function testMcyclesMode(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        $initialMode = $this->getPlayerProperty($player, 'mode');

        $m = new KeyMsg(KeyType::Char, 'm');
        [$player,] = $player->update($m);

        $nextMode = $this->getPlayerProperty($player, 'mode');
        $this->assertNotSame($initialMode, $nextMode);
    }

    /**
     * Regression for F2. Switching mode must rebuild the decoder so the decoded
     * frame resolution tracks the new mode: HalfBlock packs 2 source rows per
     * cell (height 48 for 24 cells), the 1-row modes use 1 (height 24).
     *
     * On master 'm' only swapped the Mode enum and left currentFrame untouched,
     * so after switching from the initial HalfBlock frame (h=48) to a 1-row mode
     * the height stayed 48 — the assert fails.
     *
     * @testdox mode switch rebuilds the decoder so frame height matches the mode (F2)
     */
    public function testModeSwitchRebuildsDecoderToMatchGeometry(): void
    {
        $player = Player::openForTest(new GeometryFakeDecoder(4), 30.0, 0, 80, 24, '/fake');

        $m = new KeyMsg(KeyType::Char, 'm');

        // Cycle through every mode once (7 presses) and confirm the decoded
        // frame height tracks the mode each time.
        $modeCount = count(Mode::cases());
        for ($i = 0; $i < $modeCount; $i++) {
            [$player,] = $player->update($m);

            $mode = $this->getPlayerProperty($player, 'mode');
            $frame = $player->currentFrame;

            $this->assertNotNull($frame, 'mode switch must rebuild and decode a frame');
            $this->assertSame(
                24 * $mode->rowsPerCell(),
                $frame->h,
                "frame height must match mode {$mode->name} (rowsPerCell {$mode->rowsPerCell()})",
            );
            $this->assertSame(80, $frame->w, 'frame width must equal cellsW');
        }
    }

    /**
     * Regression for F2 (tail). The mode cycle is capability-aware: it only
     * includes modes the terminal reports support for via Mosaic::diagnose().
     *
     * Since we cannot mock Mosaic::diagnose() in plain PHPUnit, we verify the
     * observable contract: cycling works without error, the cycle wraps around
     * (pressing m many times stays within a bounded set), and that bounded set
     * is at least the 4 text modes (always included).
     *
     * @testdox mode cycle wraps around and includes all text modes
     */
    public function testModeCycleReachesGraphicsModes(): void
    {
        $player = Player::openForTest(new GeometryFakeDecoder(4), 30.0, 0, 80, 24, '/fake');

        $m = new KeyMsg(KeyType::Char, 'm');
        $visited = [];
        // Press enough times to wrap around at least once.
        $pressCount = 14;
        for ($i = 0; $i < $pressCount; $i++) {
            [$player,] = $player->update($m);
            $visited[] = $this->getPlayerProperty($player, 'mode');
        }

        // The cycle must be non-empty and bounded (wrap-around must happen).
        $unique = array_unique(array_map(fn (Mode $m) => $m->name, $visited));
        $this->assertLessThanOrEqual(
            $pressCount,
            count($unique),
            'unique modes visited cannot exceed press count'
        );

        // The 4 text modes must always be in the cycle.
        $this->assertContains(Mode::Ascii->name, $unique, 'cycle must include Ascii');
        $this->assertContains(Mode::Ansi256->name, $unique, 'cycle must include Ansi256');
        $this->assertContains(Mode::TrueColor->name, $unique, 'cycle must include TrueColor');
        $this->assertContains(Mode::HalfBlock->name, $unique, 'cycle must include HalfBlock');
    }

    // -------------------------------------------------------------------------
    // F10: WindowSizeMsg resizes the player
    // -------------------------------------------------------------------------

    /**
     * Regression for F10, re-scoped by finding #52. When a WindowSizeMsg arrives the
     * Player must take the new cellsW/cellsH AND rebuild the decoder at it (so frames
     * are decoded at the correct resolution for the new mode) — but the rebuild is now
     * DEBOUNCED: the signal handler only records the clamped target and arms a timer,
     * and the geometry lands when the ResizeRebuildMsg it dispatches is delivered.
     * Both halves are asserted here.
     *
     * On master update() had no WindowSizeMsg branch at all — this no-ops silently
     * and the video stays at the constructor's fixed 80×24.
     */
    public function testWindowSizeMsgUpdatesCellDimensions(): void
    {
        $decoder = $this->makeFakeDecoder(20);
        $player = Player::openForTest($decoder, 30.0, 20, 80, 24, '/fake');

        // Pre-seed a currentFrame and unpause so resize schedules a tick.
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);
        [$player] = $player->update(new KeyMsg(KeyType::Space)); // unpause

        $resize = new \SugarCraft\Core\Msg\WindowSizeMsg(120, 40);
        [$player2, $cmd] = $player->update($resize);

        // The signal handler must not block on a spawn — it schedules the rebuild.
        $this->assertNotNull($cmd, 'a resize must schedule the deferred rebuild');
        $this->assertSame(
            120,
            $this->pendingResizeTarget($player2)->cols,
            'the target must be recorded synchronously'
        );
        $this->assertSame(
            80,
            $this->getPlayerProperty($player2, 'cellsW'),
            'the applied grid must not change until the debounce elapses'
        );

        [$player3, $tick] = $player2->update(ResizeRebuildMsg::instance());

        $this->assertSame(
            120,
            $this->getPlayerProperty($player3, 'cellsW'),
            'cellsW must update once the debounce elapses'
        );
        $this->assertSame(
            40,
            $this->getPlayerProperty($player3, 'cellsH'),
            'cellsH must update once the debounce elapses'
        );
        $this->assertNotNull($tick, 'a resize landing while playing must keep the tick chain alive');
        $this->assertNull($this->getPlayerProperty($player3, 'pendingResize'), 'the apply must consume the pending slot');
    }

    /**
     * Finding #52 — a burst of WindowSizeMsgs (a dragged terminal edge reflows per
     * character cell) must cost ONE decoder rebuild, not one per event. The fake
     * decoder counts its own spawns, so this measures the process-level cost directly.
     */
    public function testResizeStormRebuildsTheDecoderOnce(): void
    {
        // GeometryFakeDecoder regenerates on reopen (reopen() delegates to open()),
        // so openCount() is the true count of "a new decode grid was built" — exactly
        // the expensive event the debounce must collapse.
        $decoder = new GeometryFakeDecoder(200);
        $decoder->open('/fake', 80, 24, 30.0, Mode::HalfBlock);
        $player = Player::openForTest($decoder, 30.0, 200, 80, 24, '/fake');
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);

        $opensBefore = $decoder->openCount();
        $current = $player;
        /** @var list<?\Closure> $cmds */
        $cmds = [];
        // 25 distinct sizes that never revisit the applied 80×24, with no rebuild
        // applied in between — a pure burst.
        for ($i = 0; $i < 25; $i++) {
            [$current, $cmd] = $current->update(new \SugarCraft\Core\Msg\WindowSizeMsg(40 + $i, 20 + intdiv($i, 5)));
            $cmds[] = $cmd;
        }

        $this->assertCount(25, $cmds);
        $this->assertSame(
            $opensBefore,
            $decoder->openCount(),
            'the resize signal handler must not touch the decoder at all'
        );

        // Every armed timer fires. Only the first may rebuild; the rest must find
        // nothing pending and no-op — that IS the debounce contract.
        $rebuilds = 0;
        foreach ($cmds as $cmd) {
            $this->assertNotNull($cmd, 'each new target must arm a debounce timer');
            $before = $decoder->openCount();
            [$current] = $current->update(ResizeRebuildMsg::instance());
            $rebuilds += $decoder->openCount() - $before;
        }

        $this->assertSame(1, $rebuilds, 'a 25-event burst must spawn exactly one decoder rebuild');
        $this->assertSame(64, $this->getPlayerProperty($current, 'cellsW'), 'the last geometry must win');
        $this->assertSame(24, $this->getPlayerProperty($current, 'cellsH'));
    }

    /**
     * Finding #52 — the deferred apply must not orphan a child. It goes through the
     * same close-then-create seam the F21 regression pins for a backward seek, so the
     * decoder it replaces is closed before the replacement is built. Driven with a
     * SpyDecoder (reopensInPlace() === false) on a real .gif path so the production
     * rebuild branch — not the in-memory reopen branch — is the one exercised.
     */
    public function testDeferredResizeClosesTheSupersededDecoder(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $gifPath = $this->createTempGif();
        $spy = new SpyDecoder(array_fill(0, 20, $this->makeFrame("\x10\x20\x30")));
        $player = Player::openForTest($spy, 30.0, 0, 8, 6, $gifPath);

        [$player, $cmd] = $player->update(new \SugarCraft\Core\Msg\WindowSizeMsg(16, 12));
        $this->assertNotNull($cmd);
        $this->assertSame(0, $spy->closeCount, 'the signal handler itself must close nothing');

        [$player] = $player->update(ResizeRebuildMsg::instance());

        $this->assertSame(1, $spy->closeCount, 'the applied rebuild must close the child it replaces');
        $this->assertSame(16, $this->getPlayerProperty($player, 'cellsW'));
    }

    /**
     * Finding #52 — a resize that snaps back to the applied geometry cancels the
     * burst's work: the stale timer must then apply nothing rather than rebuild twice.
     */
    public function testStaleDebounceTimerAppliesNothing(): void
    {
        $decoder = $this->makeFakeDecoder(20);
        $player = Player::openForTest($decoder, 30.0, 20, 80, 24, '/fake');
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);

        [$player, $cmd] = $player->update(new \SugarCraft\Core\Msg\WindowSizeMsg(120, 40));
        $this->assertNotNull($cmd);
        [$player] = $player->update(ResizeRebuildMsg::instance());
        $reopensAfterApply = $decoder->reopenCount();
        $this->assertSame(1, $reopensAfterApply, 'the burst must have rebuilt once');

        // The same timer firing again (a second armed event from the same burst)
        // finds nothing pending.
        [$player2, $cmd2] = $player->update(ResizeRebuildMsg::instance());
        $this->assertSame($player, $player2, 'a stale debounce must be identity');
        $this->assertNull($cmd2, 'a stale debounce must schedule nothing');
        $this->assertSame(
            $reopensAfterApply,
            $decoder->reopenCount(),
            'a stale debounce must not rebuild again'
        );
    }

    /**
     * Finding #52 — a pending resize must survive other updates. A tick landing
     * inside the debounce window must not swallow the deferred rebuild, or the player
     * would keep decoding at the superseded geometry forever.
     */
    public function testPendingResizeSurvivesAnInterleavedTick(): void
    {
        $decoder = $this->makeFakeDecoder(20);
        $player = Player::openForTest($decoder, 30.0, 20, 80, 24, '/fake');
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);
        [$player] = $player->update(new KeyMsg(KeyType::Space)); // unpause

        [$player] = $player->update(new \SugarCraft\Core\Msg\WindowSizeMsg(120, 40));
        [$player] = $player->update(TickMsg::instance());

        $this->assertNotNull(
            $this->getPlayerProperty($player, 'pendingResize'),
            'an interleaved tick must carry the pending resize forward'
        );

        [$player] = $player->update(ResizeRebuildMsg::instance());
        $this->assertSame(120, $this->getPlayerProperty($player, 'cellsW'));
        $this->assertSame(40, $this->getPlayerProperty($player, 'cellsH'));
    }

    /**
     * Finding #52 (round-1 review M1) — the snap-back case the debounce comment
     * promised but never implemented: play at A, burst to B (timer armed), then the
     * terminal returns to A while the timer is still in flight. The second event
     * must CANCEL the deferred target, or the armed timer fires and rebuilds the
     * decoder at B — a geometry the host no longer has, uncorrected until the next
     * resize. Drives the exact sequence A→B→A→(late timer) and asserts the player
     * stays at A with no reopen.
     */
    public function testSnapbackResizeCancelsThePendingRebuild(): void
    {
        $decoder = $this->makeFakeDecoder(20);
        $player = Player::openForTest($decoder, 30.0, 20, 80, 24, '/fake');
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);

        [$player, $cmd] = $player->update(new \SugarCraft\Core\Msg\WindowSizeMsg(120, 40));
        $this->assertNotNull($cmd, 'a new target arms the debounce timer');
        $this->assertNotNull($this->getPlayerProperty($player, 'pendingResize'));

        // Snap back to the APPLIED geometry while the armed timer is still in flight.
        [$player2, $cmd2] = $player->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
        $this->assertNull($cmd2, 'a snap-back schedules nothing new');
        $this->assertNull(
            $this->getPlayerProperty($player2, 'pendingResize'),
            'the snap-back must clear the deferred target, not leave it armed'
        );

        // The timer armed by the FIRST event fires late. It must find nothing pending.
        $reopensBefore = $decoder->reopenCount();
        [$player3, $cmd3] = $player2->update(ResizeRebuildMsg::instance());
        $this->assertSame($player2, $player3, 'a cancelled debounce must be identity');
        $this->assertNull($cmd3);
        $this->assertSame(
            $reopensBefore,
            $decoder->reopenCount(),
            'a cancelled debounce must not reopen the decoder at the stale geometry'
        );
        $this->assertSame(80, $this->getPlayerProperty($player3, 'cellsW'), 'the applied grid stays the snap-back size');
        $this->assertSame(24, $this->getPlayerProperty($player3, 'cellsH'));
    }

    /**
     * Finding #52 (round-1 review M2) — teardown must beat the debounce timer.
     * stop() closes the decoder; an in-flight ResizeRebuildMsg armed BEFORE the stop
     * must not resurrect it, or the deferred rebuild spawns a fresh ffmpeg child on
     * a player the host already tore down — exactly the orphan the child-lifetime
     * guarantee exists to prevent. Driven with a SpyDecoder (reopensInPlace() ===
     * false) on a real .gif path so the factory respawn branch is the one refused.
     */
    public function testStopCancelsAnInFlightDebounceTimer(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $gifPath = $this->createTempGif();
        $spy = new SpyDecoder(array_fill(0, 20, $this->makeFrame("\x10\x20\x30")));
        $player = Player::openForTest($spy, 30.0, 0, 12, 6, $gifPath);

        [$player, $cmd] = $player->update(new \SugarCraft\Core\Msg\WindowSizeMsg(16, 12));
        $this->assertNotNull($cmd, 'the resize arms the debounce timer');

        $player->stop(); // the host tears the stream down; the timer is still in flight
        $this->assertSame(1, $spy->closeCount, 'stop() closes the decoder exactly once');

        [$player2, $cmd2] = $player->update(ResizeRebuildMsg::instance());
        $this->assertSame($player, $player2, 'a stopped player must not derive a rebuilt instance');
        $this->assertNull($cmd2, 'a stopped player must not schedule further work');
        $this->assertSame($spy, $this->getPlayerProperty($player2, 'decoder'), 'no replacement decoder may be built after stop()');
        $this->assertSame(1, $spy->closeCount, 'the refused rebuild must close nothing further');
        $this->assertSame(12, $this->getPlayerProperty($player2, 'cellsW'), 'geometry stays as it was at teardown');

        // A late WindowSizeMsg on the stopped instance must arm nothing either.
        [$player3, $cmd3] = $player->update(new \SugarCraft\Core\Msg\WindowSizeMsg(20, 14));
        $this->assertSame($player, $player3);
        $this->assertNull($cmd3, 'a stopped player must not arm new debounce timers');
    }

    /**
     * Round-3 review R3-1: the stop() latch must cover the TICK path too, not just
     * the two resize sites. A looping player that is stopped mid-run used to let an
     * in-flight TickMsg walk to end-of-stream and reopen frame 0 through
     * onReachedEnd() — respawning a decoder child whose owner had already torn the
     * instance down. The latch refuses the tick outright.
     */
    public function testStopSilencesALoopingPlaybackTick(): void
    {
        $decoder = $this->makeFakeDecoder(2);
        $player = Player::openForTest($decoder, 30.0, totalFrames: 2, loop: true, paused: false);
        $decoder->next();
        $player = $this->setCurrentFrame($player, $decoder->next(), 1);

        $player->stop();

        [$after, $cmd] = $player->update(new TickMsg());

        $this->assertSame($player, $after, 'a stopped player must not derive a looping reopen');
        $this->assertNull($cmd, 'a stopped tick schedules nothing further');
        $this->assertSame(0, $decoder->reopenCount(), 'the end-of-stream loop must not respawn a closed player');
        $this->assertSame(1, $this->getPlayerProperty($after, 'frameIndex'), 'frame position stays frozen at teardown');
    }

    /**
     * Regression for F10. Resize with the SAME size must be a no-op
     * (no decoder rebuild, no tick reschedule).
     */
    public function testWindowSizeMsgNoOpWhenUnchanged(): void
    {
        $decoder = $this->makeFakeDecoder(20);
        $player = Player::openForTest($decoder, 30.0, 20, 80, 24, '/fake');
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);

        $resizeSame = new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24);
        [$player2, $cmd] = $player->update($resizeSame);

        // Must be the same instance (no mutation).
        $this->assertSame($player, $player2);
        $this->assertNull($cmd, 'no-op resize must not reschedule a tick');
    }

    /**
     * Regression for F10. Resize clamps to sane bounds (cols≥10, rows≥5).
     * A WindowSizeMsg(5, 3) must be clamped to cols=10, rows=5 before anything
     * else sees it — including the deferred rebuild, which must never be handed a
     * zero-area grid (that would crash the decoder).
     */
    public function testWindowSizeMsgClampsToMinimum(): void
    {
        $decoder = $this->makeFakeDecoder(20);
        $player = Player::openForTest($decoder, 30.0, 20, 80, 24, '/fake');
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);

        $tiny = new \SugarCraft\Core\Msg\WindowSizeMsg(5, 3);
        [$player2, $cmd] = $player->update($tiny);

        $this->assertNotNull($cmd, 'a clamped resize is still a resize');
        $this->assertSame(
            10,
            $this->pendingResizeTarget($player2)->cols,
            'cols below 10 must be clamped to 10 before they are recorded'
        );
        $this->assertSame(
            5,
            $this->pendingResizeTarget($player2)->rows,
            'rows below 5 must be clamped to 5 before they are recorded'
        );

        [$player3] = $player2->update(ResizeRebuildMsg::instance());
        $this->assertSame(10, $this->getPlayerProperty($player3, 'cellsW'), 'the applied grid must be the clamped width');
        $this->assertSame(5, $this->getPlayerProperty($player3, 'cellsH'), 'the applied grid must be the clamped height');
    }

    /**
     * Finding #52 — pin the wiring the debounce depends on: the command a resize
     * returns must be a one-shot timer that dispatches ResizeRebuildMsg (not a
     * playback tick, which would fork the tick chain), so the host that runs it gets
     * exactly one deferred apply per armed window.
     */
    public function testResizeCommandDispatchesTheRebuildMessage(): void
    {
        $decoder = $this->makeFakeDecoder(20);
        $player = Player::openForTest($decoder, 30.0, 20, 80, 24, '/fake');
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);

        [, $cmd] = $player->update(new \SugarCraft\Core\Msg\WindowSizeMsg(120, 40));
        $this->assertNotNull($cmd);

        $request = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\TickRequest::class, $request, 'a resize arms a timer, it does not inline work');
        $this->assertGreaterThan(0.0, $request->seconds, 'the debounce window must be a real delay');
        $this->assertLessThan(0.5, $request->seconds, '…but short enough that the reflow is not visibly late');
        $this->assertInstanceOf(ResizeRebuildMsg::class, ($request->produce)());
    }

    /**
     * The recorded (clamped) resize target, for asserting the debounce contract
     * without waiting on a real timer.
     */
    private function pendingResizeTarget(Player $player): \SugarCraft\Core\Msg\WindowSizeMsg
    {
        $pending = $this->getPlayerProperty($player, 'pendingResize');
        $this->assertInstanceOf(
            \SugarCraft\Core\Msg\WindowSizeMsg::class,
            $pending,
            'expected update() to record a pending resize'
        );

        return $pending;
    }

    // -------------------------------------------------------------------------
    // F2 tail: capability-aware mode cycle
    // -------------------------------------------------------------------------

    /**
     * Capability-door cycle pins (M1). The pre-M1 version of this test only
     * asserted that visited modes are Modes — vacuous against the defect it
     * names. Now it drives the scripted capability memo both ways: a
     * text-only seat must NEVER be cycled into a graphics rung, and a fully
     * capable seat must visit all three rungs within a wrap.
     */
    public function testModeCycleOnlyVisitsSupportedModes(): void
    {
        $m = new KeyMsg(KeyType::Char, 'm');

        \SugarCraft\Video\Render\RendererFactory::setDiagnoseForTesting(
            new \SugarCraft\ColorProfile\Probe\ProbeReport([]),
        );
        try {
            $player = Player::fromDecoder(
                decoder: new GeometryFakeDecoder(4),
                cellsW: 30,
                cellsH: 12,
                fps: 10.0,
                mode: Mode::Ascii,
            );
            $names = [];
            for ($i = 0; $i < 16; $i++) {
                [$player] = $player->update($m);
                $names[] = $player->mode->name;
            }
            foreach (['Sixel', 'Kitty', 'Iterm2'] as $forbidden) {
                $this->assertNotContains(
                    $forbidden,
                    $names,
                    "a seat without the {$forbidden} capability must never be cycled into it",
                );
            }
            $this->assertGreaterThanOrEqual(2, count(array_unique($names)));
        } finally {
            \SugarCraft\Video\Render\RendererFactory::setDiagnoseForTesting(null);
        }

        \SugarCraft\Video\Render\RendererFactory::setDiagnoseForTesting(
            new \SugarCraft\ColorProfile\Probe\ProbeReport([
                \SugarCraft\ColorProfile\Probe\Capability::Sixel->value => true,
                \SugarCraft\ColorProfile\Probe\Capability::KittyKeyboard->value => true,
                \SugarCraft\ColorProfile\Probe\Capability::ITerm2->value => true,
            ]),
        );
        try {
            $player = Player::fromDecoder(
                decoder: new GeometryFakeDecoder(4),
                cellsW: 30,
                cellsH: 12,
                fps: 10.0,
                mode: Mode::Ascii,
            );
            $names = [];
            for ($i = 0; $i < 24; $i++) {
                [$player] = $player->update($m);
                $names[] = $player->mode->name;
            }
            foreach (['Sixel', 'Kitty', 'Iterm2'] as $expected) {
                $this->assertContains(
                    $expected,
                    $names,
                    "a capable seat must cycle through {$expected}",
                );
            }
        } finally {
            \SugarCraft\Video\Render\RendererFactory::setDiagnoseForTesting(null);
        }
    }

    // -------------------------------------------------------------------------
    // Quit keys
    // -------------------------------------------------------------------------

    /**
     * @testdox 'q' key returns Cmd::quit()
     */
    public function testQKeyQuits(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        $q = new KeyMsg(KeyType::Char, 'q');
        [, $cmd] = $player->update($q);

        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);

        // Execute the cmd and check it returns QuitMsg
        $result = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\Msg\QuitMsg::class, $result);
    }

    /**
     * @testdox Escape key returns Cmd::quit()
     */
    public function testEscKeyQuits(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        $esc = new KeyMsg(KeyType::Escape);
        [, $cmd] = $player->update($esc);

        $this->assertNotNull($cmd);
        $result = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\Msg\QuitMsg::class, $result);
    }

    // -------------------------------------------------------------------------
    // view()
    // -------------------------------------------------------------------------

    /**
     * @testdox view() returns a string (not null)
     */
    public function testViewReturnsString(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        $output = $player->view();

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    /**
     * @testdox view() returns a string containing ANSI escape sequences for a red pixel
     */
    public function testViewContainsRenderedFrame(): void
    {
        $redDecoder = new FakeDecoder([$this->makeFrame("\xff\x00\x00")]);
        $player = Player::openForTest($redDecoder, 30.0);

        // Pre-seed currentFrame with the red pixel
        $redFrame = $redDecoder->next();
        $player = $this->setCurrentFrame($player, $redFrame, 0);

        $output = $player->view();

        // The output should contain ANSI SGR codes (ESC[...)
        $this->assertIsString($output);
        // At minimum we expect the output to be non-empty and contain content
        $this->assertNotEmpty(trim($output));
    }

    /**
     * @testdox view() is idempotent — calling it twice with the same frame produces identical output
     */
    public function testViewIsIdempotent(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        $firstFrame = $decoder->next();
        $player = $this->setCurrentFrame($player, $firstFrame, 0);

        $output1 = $player->view();
        $output2 = $player->view();

        $this->assertSame($output1, $output2);
    }

    /**
     * Regression: view() must emit the FULL current frame every call so
     * sugar-core's Renderer can diff it. A previous implementation diffed
     * inside view() and returned an empty string for every frame after the
     * first, freezing playback. Two visibly different frames must therefore
     * produce two different, non-empty renders.
     *
     * @testdox view() renders distinct, non-empty output for distinct frames
     */
    public function testViewRendersFullFrameForEachFrame(): void
    {
        // A solid-red frame and a solid-green frame of the same size.
        $red = $this->makeFrame("\xff\x00\x00");
        $green = $this->makeFrame("\x00\xff\x00");

        $player = Player::openForTest(new FakeDecoder([$red, $green]), 30.0);

        $playerRed = $this->setCurrentFrame($player, $red, 0);
        $outRed = $playerRed->view();

        $playerGreen = $this->setCurrentFrame($player, $green, 1);
        $outGreen = $playerGreen->view();

        $this->assertNotEmpty(trim($outRed), 'red frame must render content');
        $this->assertNotEmpty(trim($outGreen), 'green frame must render content');
        $this->assertNotSame(
            $outRed,
            $outGreen,
            'distinct frames must render distinct output (view() must emit full frames, not a self-diff)',
        );
    }

    /**
     * Regression for F14. The inline Player::frameToBuffer HalfBlock path
     * and the standalone HalfBlockRenderer (sugar-image via sugar-video's
     * HalfBlockRenderer) must both emit the same ▀ cell with fg/bg SGR.
     * This guards against the two paths drifting after geometry changes.
     */
    public function testHalfBlockViewOutputHasCorrectSgrAndChar(): void
    {
        // 2 cols × 4 rows = 4 cells (each cell = 2 pixel rows)
        $bytes =
            "\xff\x00\x00"  // R0C0 upper: red
            . "\x00\x00\xff"  // R0C0 lower: blue
            . "\x00\xff\x00"  // R0C1 upper: green
            . "\xff\xff\x00"  // R0C1 lower: yellow
            . "\x00\xff\xff"  // R1C0 upper: cyan
            . "\xff\xff\xff"  // R1C0 lower: white
            . "\x40\x40\x40"  // R1C1 upper: dark grey
            . "\xff\x00\xff"; // R1C1 lower: magenta
        $frame = new RgbFrame($bytes, 2, 4);

        $player = Player::openForTest(new FakeDecoder([$frame]), 30.0, 0, 2, 2, '/fake');
        $player = $this->setCurrentFrame($player, $frame, 0);

        $view = $player->view();

        // Must contain half-block character.
        $this->assertStringContainsString(
            "\u{2580}",
            $view,
            'HalfBlock output must contain ▀'
        );
        // Must have TrueColor foreground SGR (may be combined with reset: [0;38;2;).
        $this->assertStringContainsString(
            '38;2;',
            $view,
            'HalfBlock must use 38;2;R;G;B foreground'
        );
        // Must have TrueColor background SGR (may be combined with reset: [0;48;2;).
        $this->assertStringContainsString(
            '48;2;',
            $view,
            'HalfBlock must use 48;2;R;G;B background'
        );
    }

    /**
     * frameToBuffer() PERF parity. The per-cell Buffer::withCellAt() loop (each
     * call copy-on-write duplicated the whole grid → O(cells²)/frame) was
     * replaced with grid accumulation + a single Buffer::fromGrid() call. The
     * refactor MUST be byte-identical to the old path.
     *
     * These EXPECTED byte strings were captured from the pre-refactor per-cell
     * code (see the commit that introduces this test). Each covers one branch of
     * frameToBuffer: HalfBlock, Ascii (luma ramp), TrueColor, and QuarterBlock.
     * A future divergence in the grid ordering or wrapping would change view()
     * and fail this test.
     *
     * Re-captured after the sugar-cellbuffer value-equality fix made Buffer::toAnsi()
     * canonical: equal styles now emit ONE SGR per run instead of one per cell
     * (the HalfBlock pin below lost its per-glyph repeats). Parity semantics —
     * grid ordering, glyph choice, wrapping — are unchanged.
     *
     * @dataProvider frameToBufferParityCases
     */
    public function testFrameToBufferParityWithPerCellPath(string $bytes, int $w, int $h, Mode $mode, string $expectedB64): void
    {
        $frame = new RgbFrame($bytes, $w, $h);

        $base = Player::openForTest(new FakeDecoder([$frame]), 1.0, 0, $w, $h, '/fake', false, 'standard');
        $player = $this->createPlayerWithOverrides($base, [
            'currentFrame' => $frame,
            'frameIndex' => 0,
            'mode' => $mode,
        ]);

        $this->assertSame(
            base64_decode($expectedB64, true),
            $player->view(),
            "frameToBuffer() {$mode->name} output must be byte-identical to the old per-cell path",
        );
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: Mode, 4: string}>
     */
    public static function frameToBufferParityCases(): array
    {
        // QuarterBlock frame: 4×4 varied colors (mirrors the capture fixture).
        $qb = '';
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $qb .= chr(($x * 60) % 256) . chr(($y * 60) % 256) . chr((($x + $y) * 40) % 256);
            }
        }

        return [
            'HalfBlock' => [
                str_repeat("\xff\x00\x00", 4) . str_repeat("\x00\x80\x00", 4),
                4,
                2,
                Mode::HalfBlock,
                'G1swOzM4OzI7MjU1OzA7MDs0ODsyOzA7MTI4OzBt4paA4paA4paA4paAG1swbQ==',
            ],
            'Ascii' => [
                "\x00\x00\x00" . "\x7f\x7f\x7f" . "\x7f\x7f\x7f" . "\xff\xff\xff",
                2,
                2,
                Mode::Ascii,
                'IHQKdEA=',
            ],
            'TrueColor' => [
                "\xff\x00\x00" . "\x00\xff\x00" . "\x00\x00\xff" . "\xff\xff\x00",
                2,
                2,
                Mode::TrueColor,
                'G1swOzM4OzI7MjU1OzA7MG07G1swOzM4OzI7MDsyNTU7MG1mChtbMDszODsyOzA7MDsyNTVtLhtbMDszODsyOzI1NTsyNTU7MG04G1swbQ==',
            ],
            'QuarterBlock' => [
                $qb,
                4,
                4,
                Mode::QuarterBlock,
                'G1swOzM4OzI7NDA7NDA7NTM7NDg7MjswOzA7MG3ilp8bWzA7Mzg7MjsxNjA7NDA7MTMzOzQ4OzI7MTIwOzA7ODBt4pafChtbMDszODsyOzQwOzE2MDsxMzM7NDg7MjswOzEyMDs4MG3ilp8bWzA7Mzg7MjsxNjA7MTYwOzIxMzs0ODsyOzEyMDsxMjA7MTYwbeKWnxtbMG0=',
            ],
        ];
    }

    /**
     * Regression (tick path): after a tick advances the frame, view() must
     * still emit the full frame. The old implementation set the diff baseline
     * to the just-advanced frame inside updateTick(), so view() diffed the
     * current frame against itself and returned an empty string — the screen
     * froze on the placeholder. Driving update() through a real tick is the
     * path that exposed the bug.
     *
     * @testdox view() is non-empty after a tick advances the frame
     */
    public function testViewNonEmptyAfterTickAdvance(): void
    {
        $player = Player::openForTest(new FakeDecoder([
            $this->makeFrame("\xff\x00\x00"),
            $this->makeFrame("\x00\xff\x00"),
        ]), 30.0);

        [$player] = $player->update(new KeyMsg(KeyType::Space)); // unpause
        [$player] = $player->update(new TickMsg());              // advance one frame

        $this->assertNotEmpty(trim($player->view()));
    }

    /**
     * C1 egress leg: a hostile .vtt (ESC payload, entity-newline, forged zone
     * sentinels, CJK + emoji) must produce a caption row that is printable-
     * only AND stays exactly one row — the pane must remain cellsH rows, the
     * caption riding its reserved bottom row (SV-H5/005; the old law was
     * cellsH+1 — over-tall by design), never shifted by smuggled LFs.
     */
    public function testHostileCaptionKeepsOneRowAndCarriesNoControlBytes(): void
    {
        $vtt = \SugarCraft\Video\Subtitle\WebVtt::parse(
            "WEBVTT\n\n00:00:00.000 --> 00:01:00.000\n"
            . "\x1b[31mpwn\u{E000}zone\u{E001} 你好 🎬 &#10;second&#9;line"
        );
        $player = Player::fromDecoder(
            new FakeDecoder([
                $this->makeFrame(str_repeat("\xff\x00\x00", 80 * 6), 80, 6),
                $this->makeFrame(str_repeat("\x00\xff\x00", 80 * 6), 80, 6),
            ]),
            80,
            6,
            30.0,
            Mode::Ascii,
            2,
            '',
            false,
            'standard',
            null,
            null,
            false, // start playing
            subtitles: $vtt,
        );
        [$player] = $player->update(new TickMsg()); // pulls frame 1, videoTime stays inside the cue

        $out = $player->view();
        $rows = preg_split("/\r\n|\n/", $out) ?: [];

        // 5 frame rows + the reserved caption row = exactly cellsH (SV-H5);
        $this->assertCount(6, $rows, 'the caption rides the reserved bottom row — the pane stays cellsH');
        $caption = end($rows);
        $this->assertStringNotContainsString("\x1b", $caption);
        $this->assertStringNotContainsString("\xEE\x80\x80", $caption, 'zone sentinel gone at egress');
        $this->assertMatchesRegularExpression('/^[\x20-\x7e\x{80}-\x{10FFFF}]*$/u', rtrim($caption), 'printable-only caption row');
    }

    /**
     * C1 mis-pad leg: the caption must be padded to the CELL width, not the
     * byte/codepoint count — a wide-glyph caption previously overflowed the
     * row. mb_str_split + Width::of of the caption row must equal cellsW.
     */
    public function testCaptionRowIsPaddedToCellWidthForWideGlyphs(): void
    {
        $vtt = \SugarCraft\Video\Subtitle\WebVtt::parse(
            "WEBVTT\n\n00:00:00.000 --> 00:01:00.000\n"
            . '你好世界' // 8 cells
        );
        $player = Player::fromDecoder(
            new FakeDecoder([$this->makeFrame(str_repeat("\xff\x00\x00", 10 * 6), 10, 6), $this->makeFrame(str_repeat("\x00\xff\x00", 10 * 6), 10, 6)]),
            10,
            6,
            30.0,
            Mode::Ascii,
            2,
            '',
            false,
            'standard',
            null,
            null,
            false,
            subtitles: $vtt,
        );
        [$player] = $player->update(new TickMsg());

        $rows = preg_split("/\r\n|\n/", $player->view()) ?: [];
        $caption = rtrim(end($rows));

        $this->assertSame(8, \SugarCraft\Core\Util\Width::of($caption), 'CJK caption keeps its 8 cells');

        // And an over-long wide caption is CLIPPED at the cell budget (10 cells).
        $wide = \SugarCraft\Video\Subtitle\WebVtt::parse("WEBVTT\n\n00:00:00.000 --> 00:01:00.000\n你好世界你好世界"); // 16 cells
        $player2 = $player::fromDecoder(
            new FakeDecoder([$this->makeFrame(str_repeat("\xff\x00\x00", 10 * 6), 10, 6), $this->makeFrame(str_repeat("\x00\xff\x00", 10 * 6), 10, 6)]),
            10,
            6,
            30.0,
            Mode::Ascii,
            2,
            '',
            false,
            'standard',
            null,
            null,
            false,
            subtitles: $wide,
        );
        [$player2] = $player2->update(new TickMsg());
        $rows2 = preg_split("/\r\n|\n/", $player2->view()) ?: [];
        $clip = rtrim(end($rows2));
        $this->assertSame(10, \SugarCraft\Core\Util\Width::of($clip), 'clipped never past the cell budget');
        $this->assertSame('你好世界你', $clip, 'clip lands on a whole codepoint boundary');
    }

    // -------------------------------------------------------------------------
    // F9: end-of-stream handling + optional loop
    // -------------------------------------------------------------------------

    /**
     * Regression for F9. When the decoder is exhausted and the player is NOT
     * looping, playback must mark itself ended and STOP rescheduling ticks
     * (null Cmd) — otherwise the tick chain spins forever at the end. On the
     * un-fixed code there is no `ended` state and updateTick() always returns a
     * non-null tick Cmd, so this fails on both the null-Cmd and ended asserts.
     *
     * @testdox exhausted non-looping decoder marks ended and stops ticking (null Cmd)
     */
    public function testExhaustedDecoderStopsTickingWhenNotLooping(): void
    {
        // Three real frames, loop defaults false.
        $player = Player::openForTest(new FakeDecoder([
            $this->makeFrame("\xff\x00\x00"),
            $this->makeFrame("\x00\xff\x00"),
            $this->makeFrame("\x00\x00\xff"),
        ]), 30.0);

        // Unpause so ticks advance.
        [$player] = $player->update(new KeyMsg(KeyType::Space));

        // Drive ticks to exhaustion. Each tick re-anchors lastTickTime to now,
        // so to make wall-clock target outrun the frame index deterministically
        // we push lastTickTime ~1s into the past before each tick (large delta →
        // big target → skip path drains the decoder via next()). Ten passes far
        // exceeds the three available frames.
        $cmd = null;
        for ($i = 0; $i < 10; $i++) {
            $player = $this->backdateLastTick($player, 1.0);
            [$player, $cmd] = $player->update(new TickMsg());
        }

        $this->assertTrue($player->ended, 'player must be marked ended at end-of-stream');
        $this->assertNull($cmd, 'tick chain must stop (null Cmd) once the decoder is exhausted');
    }

    /**
     * Regression for F9. With looping enabled, exhausting the decoder must wrap
     * back to frame 0 and KEEP ticking (non-null Cmd) rather than ending. On the
     * un-fixed code there is no loop logic, so frameIndex would stay at the last
     * frame and the player would never reset — failing both asserts.
     *
     * @testdox looping decoder wraps to frame 0 and keeps ticking at end-of-stream
     */
    public function testLoopWrapsToFrameZeroAndKeepsTicking(): void
    {
        $player = Player::openForTest(
            new FakeDecoder([
                $this->makeFrame("\xff\x00\x00"),
                $this->makeFrame("\x00\xff\x00"),
                $this->makeFrame("\x00\x00\xff"),
            ]),
            30.0,
            0,
            80,
            24,
            '/fake',
            loop: true,
        );

        [$player] = $player->update(new KeyMsg(KeyType::Space)); // unpause

        $cmd = null;
        for ($i = 0; $i < 10; $i++) {
            $player = $this->backdateLastTick($player, 1.0);
            [$player, $cmd] = $player->update(new TickMsg());
        }

        $this->assertSame(0, $this->getPlayerProperty($player, 'frameIndex'), 'loop must wrap frameIndex back to 0');
        $this->assertFalse($player->ended, 'looping player must not be marked ended');
        $this->assertNotNull($cmd, 'looping player must keep ticking (non-null Cmd) after wrap');
    }

    /**
     * Regression for F9. After playback has ENDED (non-loop), a seek must clear
     * the ended state and — when not paused — re-arm the tick chain so playback
     * can resume from the new position. On the un-fixed code there is no ended
     * state to clear and seeks always returned a null Cmd.
     *
     * @testdox seek out of the ended state clears ended and reschedules a tick
     */
    public function testSeekClearsEndedAndReschedulesTick(): void
    {
        // Pass totalFrames=10 so digit-seek actually executes (new guard rejects 0).
        $player = Player::openForTest(new FakeDecoder([
            $this->makeFrame("\xff\x00\x00"),
            $this->makeFrame("\x00\xff\x00"),
            $this->makeFrame("\x00\x00\xff"),
        ]), 30.0, 10, 80, 24, '/fake');

        [$player] = $player->update(new KeyMsg(KeyType::Space)); // unpause

        // Drive to ended.
        for ($i = 0; $i < 10; $i++) {
            $player = $this->backdateLastTick($player, 1.0);
            [$player] = $player->update(new TickMsg());
        }
        $this->assertTrue($player->ended, 'precondition: player is ended');
        $this->assertFalse($this->getPlayerProperty($player, 'paused'), 'precondition: player is not paused');

        // Seek back to the start (digit 0). totalFrames=10 so digit-seek works.
        [$player, $cmd] = $player->update(new KeyMsg(KeyType::Char, '0'));

        $this->assertFalse($player->ended, 'seek must clear the ended state');
        $this->assertNotNull($cmd, 'seek out of ended (and not paused) must reschedule a tick');
    }

    // -------------------------------------------------------------------------
    // close
    // -------------------------------------------------------------------------

    /**
     * @testdox Decoder close() is called when 'q' key is pressed
     */
    public function testCloseClosesDecoder(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        $q = new KeyMsg(KeyType::Char, 'q');
        $player->update($q); // q key closes decoder

        // After close(), subsequent next() calls should return null
        $this->assertNull($decoder->next());
    }

    // -------------------------------------------------------------------------
    // F21: backward seek closes the old decoder (no process leak)
    // -------------------------------------------------------------------------

    /**
     * Regression for F21. A backward seek must CLOSE the current decoder before
     * building a fresh one — otherwise the old ffmpeg process (or GIF buffer) is
     * leaked. We drive it through a SpyDecoder + a real .gif path: because
     * videoPath is a real .gif, rebuildDecoderAt() takes the real branch and
     * closes the injected spy, then builds a GifDecoder via DecoderFactory.
     *
     * On master the backward branch called DecoderFactory::new() but NEVER
     * closed the old decoder → closeCount stays 0.
     *
     * @testdox backward seek closes the old decoder before rebuilding (F21)
     */
    public function testBackwardSeekClosesOldDecoder(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $gifPath = $this->createTempGif();

        // Spy yields enough frames that the rebuild's skip-to-target loop has data.
        $frames = array_fill(0, 20, $this->makeFrame("\x10\x20\x30"));
        $spy = new SpyDecoder($frames);

        $player = Player::openForTest($spy, 30.0, 0, 8, 6, $gifPath);
        $player = $this->setFrameIndex($player, 12);

        // Left = seek backward 10 frames → target 2 < 12 → backward rebuild path.
        [$player] = $player->update(new KeyMsg(KeyType::Left));

        $this->assertSame(1, $spy->closeCount, 'backward seek must close the old decoder exactly once');
    }

    // -------------------------------------------------------------------------
    // Helper methods (reflection-based Player property access)
    // -------------------------------------------------------------------------

    private function getPlayerProperty(Player $player, string $prop): mixed
    {
        $reflection = new \ReflectionClass($player);
        // Try public property first
        if ($reflection->hasProperty($prop)) {
            $propObj = $reflection->getProperty($prop);
            $propObj->setAccessible(true);
            return $propObj->getValue($player);
        }
        // Try reading from constructor property promotion (private)
        foreach ($reflection->getProperties() as $p) {
            if ($p->getName() === $prop) {
                $p->setAccessible(true);
                return $p->getValue($player);
            }
        }
        throw new \RuntimeException("Property {$prop} not found on Player");
    }

    private function setPlayerProperty(Player $player, string $prop, mixed $value): Player
    {
        return $this->createPlayerWithOverrides($player, [$prop => $value]);
    }

    private function setCurrentFrame(Player $player, ?RgbFrame $frame, int $index): Player
    {
        $reflection = new \ReflectionClass($player);
        $prop = $reflection->getProperty('currentFrame');
        $prop->setAccessible(true);

        $newPlayer = $this->createPlayerWithOverrides($player, [
            'currentFrame' => $frame,
            'frameIndex' => $index,
        ]);
        return $newPlayer;
    }

    private function setFrameIndex(Player $player, int $index): Player
    {
        return $this->createPlayerWithOverrides($player, ['frameIndex' => $index]);
    }

    /**
     * Push the player's lastTickTime $seconds into the past so the NEXT
     * TickMsg sees a large wall-clock delta — making Sync's target frame
     * outrun the current frame index and deterministically drive the
     * skip/advance path to decoder exhaustion (instead of holding on the
     * microsecond deltas of a tight test loop).
     */
    private function backdateLastTick(Player $player, float $seconds): Player
    {
        $current = $this->getPlayerProperty($player, 'lastTickTime');
        return $this->createPlayerWithOverrides($player, [
            'lastTickTime' => $current - $seconds,
        ]);
    }

    /**
     * Create a new Player instance with some properties overridden.
     *
     * Uses the private constructor via reflection to produce a Player
     * with specific field values for testing.
     *
     * @param array<string, mixed> $overrides
     */
    private function createPlayerWithOverrides(Player $player, array $overrides): Player
    {
        $fps = $this->getPlayerProperty($player, 'fps');
        $decoder = $this->getPlayerProperty($player, 'decoder');
        $mode = $this->getPlayerProperty($player, 'mode');
        $speed = $this->getPlayerProperty($player, 'speed');
        $paused = $this->getPlayerProperty($player, 'paused');
        $videoTime = $this->getPlayerProperty($player, 'videoTime');
        $frameIndex = $this->getPlayerProperty($player, 'frameIndex');
        $currentFrame = $this->getPlayerProperty($player, 'currentFrame');
        $lastTickTime = $this->getPlayerProperty($player, 'lastTickTime');
        $totalFrames = $this->getPlayerProperty($player, 'totalFrames');
        $cellsW = $this->getPlayerProperty($player, 'cellsW');
        $cellsH = $this->getPlayerProperty($player, 'cellsH');
        $videoPath = $this->getPlayerProperty($player, 'videoPath');
        // Read the live ended/loop so this faithfully COPIES the source player —
        // hardcoding false here would silently drop the loop flag when rebuilding
        // (e.g. across backdateLastTick), breaking the loop tests.
        $ended = $this->getPlayerProperty($player, 'ended');
        $loop = $this->getPlayerProperty($player, 'loop');
        $ramp = $this->getPlayerProperty($player, 'ramp');
        $audioFactory = $this->getPlayerProperty($player, 'audioFactory');
        // Copy the remaining ctor fields too: a rebuild that dropped them would
        // reset cellPx/subtitles/renderer/headers/frameBudget to their defaults,
        // so a test that backdates the clock on a graphics-mode or budgeted
        // Player would silently be testing a different Player. EXCEPTION:
        // audioPlayer stays null deliberately — a live AudioPlayer owns a child
        // process bound to its original Player; two Players driving one
        // companion would double-stop it. Audio wiring is asserted elsewhere.
        $cellPxW = $this->getPlayerProperty($player, 'cellPxW');
        $cellPxH = $this->getPlayerProperty($player, 'cellPxH');
        $subtitles = $this->getPlayerProperty($player, 'subtitles');
        $renderer = $this->getPlayerProperty($player, 'renderer');
        $headers = $this->getPlayerProperty($player, 'headers');
        $frameBudgetMs = $this->getPlayerProperty($player, 'frameBudgetMs');
        // Carried like any other playback state: dropping a pending resize here
        // would silently cancel a debounced rebuild the test is about to trigger.
        $pendingResize = $this->getPlayerProperty($player, 'pendingResize');
        // SV-M20: these four were silently DEFAULTED by the positional map —
        // a script-clock or restricted-transport Player rebuilt through here
        // (backdateLastTick, setCurrentFrame, ...) lost its seam. Carry them.
        $failure = $this->getPlayerProperty($player, 'failure');
        $audioMuted = $this->getPlayerProperty($player, 'audioMuted');
        $hostRestricted = $this->getPlayerProperty($player, 'hostRestricted');
        $clock = $this->getPlayerProperty($player, 'clock');

        // Order MUST match the Player constructor positionally — the new Player
        // instance is built via array_values($values) through the private ctor.
        $values = [
            'decoder' => $decoder,
            'mode' => $mode,
            'speed' => $speed,
            'paused' => $paused,
            'videoTime' => $videoTime,
            'frameIndex' => $frameIndex,
            'currentFrame' => $currentFrame,
            'lastTickTime' => $lastTickTime,
            'fps' => $fps,
            'totalFrames' => $totalFrames,
            'cellsW' => $cellsW,
            'cellsH' => $cellsH,
            'videoPath' => $videoPath,
            'audioPlayer' => null,
            'ended' => $ended,
            'loop' => $loop,
            'ramp' => $ramp,
            'audioFactory' => $audioFactory,
            'cellPxW' => $cellPxW,
            'cellPxH' => $cellPxH,
            'subtitles' => $subtitles,
            'renderer' => $renderer,
            'headers' => $headers,
            'frameBudgetMs' => $frameBudgetMs,
            'pendingResize' => $pendingResize,
            'failure' => $failure,
            'audioMuted' => $audioMuted,
            'hostRestricted' => $hostRestricted,
            'clock' => $clock,
        ];

        foreach ($overrides as $k => $v) {
            $values[$k] = $v;
        }

        $reflectionClass = new \ReflectionClass(Player::class);
        $ctor = $reflectionClass->getConstructor();
        $ctor->setAccessible(true);
        // Use Closure::bind to invoke the private constructor.
        $closure = \Closure::bind(fn (...$args) => new Player(...$args), null, Player::class);
        $newPlayer = $closure(...array_values($values));
        return $newPlayer;
    }

    // -------------------------------------------------------------------------
    // J2: Ramp selection
    // -------------------------------------------------------------------------

    /**
     * Regression test: minimal and dense ramps MUST produce different character
     * sequences for the same frame content.
     *
     * This fails on master (before J2 wiring) because both call sites hardcode
     * the default ramp, so the char sets are identical regardless of ramp param.
     */
    public function testRampSelectionProducesDifferentCharSets(): void
    {
        // 2×2 frame: TL=black, TR=gray(127), BL=gray, BR=white.
        // Luma values: 0 → ' ' (space) in both ramps; 127 → distinct chars.
        $bytes = "\x00\x00\x00"   // (0,0,0) luma=0
                . "\x7f\x7f\x7f" // (127,127,127) luma=127
                . "\x7f\x7f\x7f" // (1,0,0) luma=19 — ignored; row 2 same colors
                . "\xff\xff\xff"; // (255,255,255) luma=255
        $frame = new RgbFrame($bytes, 2, 2);

        // Build a base Player via openForTest in Ascii mode (so ramp char is visible).
        $basePlayer = Player::openForTest(new FakeDecoder([$frame]), 1.0, 1, 80, 24, '/fake', false, 'standard');
        // Override to Ascii mode so we get luma→char mapping (not half-block glyph).
        $basePlayer = $this->createPlayerWithOverrides($basePlayer, ['mode' => Mode::Ascii]);

        // Player with minimal ramp.
        $playerMinimal = $this->createPlayerWithOverrides(
            $basePlayer,
            [
                'currentFrame' => $frame,
                'ramp' => 'minimal',
            ]
        );

        // Player with dense ramp (same base, different ramp).
        $playerDense = $this->createPlayerWithOverrides(
            $basePlayer,
            [
                'currentFrame' => $frame,
                'ramp' => 'dense',
            ]
        );

        $viewMinimal = $playerMinimal->view();
        $viewDense = $playerDense->view();

        // Strip SGR escape sequences for character-level comparison.
        $charsMinimal = preg_replace('/\x1b\[[0-9;]*m/', '', $viewMinimal);
        $charsDense = preg_replace('/\x1b\[[0-9;]*m/', '', $viewDense);

        $this->assertNotEquals(
            $charsMinimal,
            $charsDense,
            'minimal and dense ramps must produce different char sequences for the same frame'
        );
    }

    /**
     * Verify LumaRamp::char() returns different chars for different ramps at the
     * same luminance (unit-level confirmation of ramp wiring).
     */
    public function testLumaRampCharDiffersByRampName(): void
    {
        $luma = 127;
        $minimalChar = \SugarCraft\Video\Render\LumaRamp::char((float)$luma, 'minimal');
        $denseChar = \SugarCraft\Video\Render\LumaRamp::char((float)$luma, 'dense');

        $this->assertNotSame(
            $minimalChar,
            $denseChar,
            "LumaRamp::char(127, 'minimal') and LumaRamp::char(127, 'dense') must differ"
        );
    }

    // -------------------------------------------------------------------------
    // J3: Test guards & caveats
    // -------------------------------------------------------------------------

    /**
     * J3.1 (F14): Half-block parity — inline Buffer path vs Mosaic renderer.
     *
     * The Player::view() route for HalfBlock goes through the inline frameToBuffer()
     * path (Buffer → toAnsi()), NOT through HalfBlockRenderer at RendererFactory:98.
     * HalfBlockRenderer is never hit by the runtime — only by direct factory use in
     * tests. This test guards that the two paths stay in sync.
     *
     * This test passes on current master (the implementations currently match).
     * It would FAIL if the inline path's color computation ever drifted from the
     * Mosaic renderer.
     */
    public function testHalfBlockInlineMatchesMosaicRenderer(): void
    {
        // 4×2 frame: top row red, bottom row green — each cell is a ▀ with
        // red foreground and green background via the inline Buffer path.
        $bytes = str_repeat("\xff\x00\x00", 4)  // row 0: red (255,0,0) × 4 pixels
               . str_repeat("\x00\x80\x00", 4); // row 1: green (0,128,0) × 4 pixels
        $frame = new RgbFrame($bytes, 4, 2);

        // Path A: inline via Player::view() — construct a HalfBlock Player with
        // this frame as currentFrame and read view().
        $decoder = new FakeDecoder([$frame]);
        $playerViaInline = Player::openForTest($decoder, 1.0, 1, 4, 2, '/fake', false, 'standard');
        $playerViaInline = $this->createPlayerWithOverrides(
            $playerViaInline,
            ['currentFrame' => $frame, 'mode' => Mode::HalfBlock]
        );
        $inlineView = $playerViaInline->view();

        // Path B: direct via HalfBlockRenderer (the Mosaic renderer).
        $renderer = new HalfBlockRenderer();
        $mosaicView = $renderer->render($frame, Mode::HalfBlock);

        // Glyph count alone is a weak guard — two paths that disagree on every
        // colour still produce four ▀ — so the comparison is now on the decoded
        // per-cell semantics (glyph, foreground, background). See HalfBlockStream.
        $stripSgr = static fn (string $s): string => preg_replace('/\x1b\[[0-9;]*m/', '', $s);
        $this->assertSame(substr_count($stripSgr($mosaicView), '▀'), substr_count($stripSgr($inlineView), '▀'));

        $inlineCells = \SugarCraft\Video\Tests\Support\HalfBlockStream::cells($inlineView);
        $mosaicCells = \SugarCraft\Video\Tests\Support\HalfBlockStream::cells($mosaicView);

        $this->assertCount(4, $inlineCells, 'one cell per column for a 4x2 frame');
        $this->assertSame(
            [
                ['glyph' => '▀', 'fg' => '255,0,0', 'bg' => '0,128,0'],
                ['glyph' => '▀', 'fg' => '255,0,0', 'bg' => '0,128,0'],
                ['glyph' => '▀', 'fg' => '255,0,0', 'bg' => '0,128,0'],
                ['glyph' => '▀', 'fg' => '255,0,0', 'bg' => '0,128,0'],
            ],
            $inlineCells,
            'the inline path must paint the upper pixel as foreground and the lower as background'
        );
        $this->assertSame(
            $mosaicCells,
            $inlineCells,
            'Inline HalfBlock path and Mosaic HalfBlockRenderer must agree on every cell, '
                . 'not merely on how many cells they emit'
        );
    }

    /**
     * J3.2 (F6): Pure-math seek offset formula.
     *
     * Verifies round($targetIndex / $fps * 1000) for several (index, fps) pairs.
     * This is the formula used at Player.php:777 (withSeek) to compute startMs.
     * Runs unconditionally (no ffplay dependency).
     */
    public function testSeekOffsetFormula(): void
    {
        // (targetIndex, fps) → expected startMs
        $this->assertEquals(5000, (int)round(5 / 1.0 * 1000));   // 5 frames @ 1fps = 5000ms
        $this->assertEquals(2500, (int)round(5 / 2.0 * 1000));   // 5 frames @ 2fps = 2500ms
        $this->assertEquals(333, (int)round(1 / 3.0 * 1000));   // frame 1 @ 3fps ≈ 333ms
        $this->assertEquals(0, (int)round(0 / 24.0 * 1000));  // frame 0 → 0ms
        $this->assertEquals(417, (int)round(10 / 24.0 * 1000)); // frame 10 @ 24fps ≈ 417ms
    }

    /**
     * J3.2 (F6): openForTest accepts an audioPlayer injection.
     *
     * Verifies that openForTest can receive a non-null audioPlayer and that the
     * audioFactory seam allows it to be passed through.
     */
    public function testOpenForTestAcceptsAudioPlayer(): void
    {
        $fakeAudioPlayer = new AudioPlayer('/fake/path', null);

        // openForTest with an explicit audioPlayer (and a factory that returns it).
        $factory = static fn (string $path, ?int $ms) => $fakeAudioPlayer;
        $decoder = new FakeDecoder([]);
        $player = Player::openForTest(
            decoder: $decoder,
            fps: 24.0,
            totalFrames: 0,
            cellsW: 80,
            cellsH: 24,
            videoPath: '/fake',
            loop: false,
            ramp: 'standard',
            audioFactory: $factory,
            audioPlayer: $fakeAudioPlayer,
            paused: true,
        );

        // The audioPlayer property should be the injected instance.
        $this->assertSame($fakeAudioPlayer, $this->getPlayerProperty($player, 'audioPlayer'));
    }

    /**
     * J3.2 (F6): AudioPlayer restart on seek — spy proves old-stopped + new-at-correct-startMs.
     *
     * This test FAILS on current master (before the audioFactory seam) because the
     * Player has no injectable seam to intercept AudioPlayer creation on seek.
     * After J3.2 (audioFactory) it passes.
     */
    public function testAudioPlayerRestartOnSeek(): void
    {
        $spyAudioPlayer = new SpyAudioPlayer('/fake/path', 0);
        $spyCreated = [];

        // Factory that returns our spy for the first call and captures call info.
        $factory = static function (string $path, ?int $startMs) use ($spyAudioPlayer, &$spyCreated): AudioPlayer {
            $spyCreated[] = ['path' => $path, 'startMs' => $startMs];
            return $spyAudioPlayer;
        };

        $decoder = new FakeDecoder([]);
        $player = Player::openForTest(
            decoder: $decoder,
            fps: 1.0,
            totalFrames: 10,
            cellsW: 80,
            cellsH: 24,
            videoPath: '/fake/path',
            loop: false,
            ramp: 'standard',
            audioFactory: \Closure::fromCallable($factory),
            audioPlayer: $spyAudioPlayer,
            paused: true,
        );

        // Seek to frame 5 (which is 5000ms at 1.0 fps).
        $playerAfterSeek = $player->withSeek(5);

        // The original spy's stop() was called exactly once (in withSeek).
        $this->assertEquals(
            1,
            $spyAudioPlayer->stopCallCount,
            'SpyAudioPlayer::stop() must be called exactly once on seek'
        );

        // A new AudioPlayer was created with startMs = round(5 / 1.0 * 1000) = 5000.
        $this->assertNotEmpty(
            $spyCreated,
            'Factory must have been called to create a new AudioPlayer on seek'
        );
        $this->assertEquals('/fake/path', $spyCreated[0]['path']);
        $this->assertEquals(5000, $spyCreated[0]['startMs']);

        // start() was NOT called because the player is paused.
        $this->assertFalse(
            $spyAudioPlayer->hasStarted(),
            'start() must NOT be called on seek when player is paused'
        );
    }

    // -------------------------------------------------------------------------
    // Time-based seek: position()/duration()/seekToSeconds()/frameAt()/stop()
    // -------------------------------------------------------------------------

    /**
     * @testdox duration() is totalFrames / fps; position() starts at 0
     */
    public function testPositionAndDurationReport(): void
    {
        $player = Player::openForTest($this->makeFakeDecoder(10), 30.0, totalFrames: 300);

        $this->assertSame(0.0, $player->position());
        $this->assertSame(10.0, $player->duration(), '300 frames @ 30fps = 10s');
    }

    /**
     * @testdox duration() is 0.0 for a stream of unknown length (totalFrames 0)
     */
    public function testDurationZeroWhenLengthUnknown(): void
    {
        $player = Player::openForTest($this->makeFakeDecoder(10), 30.0, totalFrames: 0);

        $this->assertSame(0.0, $player->duration());
    }

    /**
     * @testdox seekToSeconds() sets position to the time and frameIndex to time*fps
     */
    public function testSeekToSecondsSetsPositionAndFrameIndex(): void
    {
        // videoPath defaults to '/fake' → the index-based rebuild path (no ffmpeg).
        $player = Player::openForTest($this->makeFakeDecoder(100), 30.0, totalFrames: 300);

        $seeked = $player->seekToSeconds(2.0);

        $this->assertSame(2.0, $seeked->position());
        $this->assertSame(60, $seeked->frameIndex, '2.0s @ 30fps = frame 60');
        $this->assertFalse($seeked->ended, 'a seek clears the ended state');
    }

    /**
     * @testdox seekToSeconds() clamps beyond the duration to the end
     */
    public function testSeekToSecondsClampsToDuration(): void
    {
        $player = Player::openForTest($this->makeFakeDecoder(400), 30.0, totalFrames: 300);

        $seeked = $player->seekToSeconds(999.0);

        $this->assertSame(10.0, $seeked->position(), 'clamped to the 10s duration');
        $this->assertSame(300, $seeked->frameIndex);
    }

    /**
     * @testdox seekToSeconds() clamps a negative time to 0
     */
    public function testSeekToSecondsClampsNegativeToZero(): void
    {
        $player = Player::openForTest($this->makeFakeDecoder(100), 30.0, totalFrames: 300);

        $seeked = $player->seekToSeconds(-5.0);

        $this->assertSame(0.0, $seeked->position());
        $this->assertSame(0, $seeked->frameIndex);
    }

    /**
     * @testdox seekToSeconds() realigns audio to the target offset (stops old, recreates at startMs)
     */
    public function testSeekToSecondsRealignsAudio(): void
    {
        $spy = new SpyAudioPlayer('/fake', 0);
        $created = [];
        $factory = static function (string $path, ?int $startMs) use ($spy, &$created): AudioPlayer {
            $created[] = ['path' => $path, 'startMs' => $startMs];
            return $spy;
        };

        $player = Player::openForTest(
            decoder: $this->makeFakeDecoder(200),
            fps: 30.0,
            totalFrames: 600,
            videoPath: '/fake',
            audioFactory: \Closure::fromCallable($factory),
            audioPlayer: $spy,
            paused: true,
        );

        $player->seekToSeconds(4.0);

        $this->assertSame(1, $spy->stopCallCount, 'old audio stopped exactly once');
        $this->assertNotEmpty($created, 'a fresh audio companion is created on seek');
        $this->assertSame(4000, $created[0]['startMs'], '4.0s → startMs 4000');
    }

    /**
     * @testdox frameAt() serves an injected decoder instead of silently returning null
     *
     * Replaces the old "/fake → null" contract: the decoder now declares whether
     * it reopens in place, so frameAt() grabs a real frame from an injected
     * (reopensInPlace) decoder rather than special-casing a sentinel path string.
     */
    public function testFrameAtWorksForInjectedDecoder(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::fromDecoder($decoder, fps: 30.0);
        $frame = $player->frameAt(1.0);
        $this->assertInstanceOf(RgbFrame::class, $frame, 'an injected decoder yields a frame at the seek');
        // Observe the reopen itself: grab at 1.0, then a restore at the playhead
        // (0.0 here) — without either reopen the frame would be served from the
        // wrong position while the Player still believed it was mid-stream.
        $this->assertSame(2, $decoder->reopenCount(), 'frameAt() reopens to grab AND reopens again to restore the playhead');
        $this->assertSame(0.0, $decoder->lastReopenStartSec(), 'the second reopen returns the live decoder to the current playhead');
    }

    /**
     * @testdox frameAt() restores the playhead even when the throwaway read throws
     *
     * The reopen/restore pair runs inside try/finally: a decoder failure during
     * a scrub-hover must propagate AND still leave the live decoder at the
     * current playhead, never stranded at the hovered position while the
     * Player believes it is mid-stream (the exception-path stranding class).
     */
    public function testFrameAtRestoresPlayheadEvenWhenTheGrabThrows(): void
    {
        $black = "\x00\x00\x00";
        $decoder = new class (array_fill(0, 4, $this->makeFrame($black))) extends FakeDecoder {
            public function next(): ?RgbFrame
            {
                throw new \RuntimeException('simulated decode failure mid-scrub');
            }
        };
        $player = Player::fromDecoder($decoder, fps: 30.0);

        try {
            $player->frameAt(5.0);
            $this->fail('the decoder failure must propagate to the caller');
        } catch (\RuntimeException $thrown) {
            $this->assertSame('simulated decode failure mid-scrub', $thrown->getMessage());
        }

        $this->assertSame(2, $decoder->reopenCount(), 'the finally-restore still reopens after a throwing grab');
        $this->assertSame(0.0, $decoder->lastReopenStartSec(), 'and lands the live decoder back on the playhead, not the hovered 5.0s');
    }

    /**
     * @testdox frameAt() throws when a factory-owned decoder has no source path
     *
     * A DecoderFactory-owned decoder (reopensInPlace false) is snapshotted through
     * a throwaway decoder built from the source path; with no path there is
     * nothing to snapshot, which is a misconfiguration, not an empty result — so
     * it must fail loud rather than the old silent null.
     */
    public function testFrameAtThrowsForUnboundFactoryDecoder(): void
    {
        $spy = new SpyDecoder(array_fill(0, 3, $this->makeFrame("\x10\x20\x30")));
        $player = Player::openForTest($spy, 30.0, videoPath: '');
        $this->expectException(\LogicException::class);
        $player->frameAt(1.0);
    }

    /**
     * @testdox stop() stops the audio companion and closes the decoder
     */
    public function testStopStopsAudioAndClosesDecoder(): void
    {
        $spy = new SpyAudioPlayer('/fake', 0);
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest(
            decoder: $decoder,
            fps: 30.0,
            videoPath: '/fake',
            audioPlayer: $spy,
        );

        $player->stop();

        $this->assertSame(1, $spy->stopCallCount, 'audio stopped');
        $this->assertTrue($decoder->isClosed(), 'decoder closed (ffmpeg torn down)');
    }

    /**
     * @testdox frameAt() input-seeks a real clip, grabbing distinct frames at distinct times
     *
     * Live proof of the time-domain seek: with real ffmpeg, frameAt() spawns a
     * throwaway decoder with `-ss` and the frames at 0.2s vs 2.5s differ (the
     * seek lands at different content). Skipped without ffmpeg; watchdog-guarded
     * so a regression that wedged a subprocess can't hang the suite.
     */
    public function testFrameAtInputSeeksARealClip(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/sugar-video-seek-' . getmypid() . '.mp4';
        $wd = proc_open(['sh', '-c', 'sleep 20; pkill -9 -f sugar-video-seek'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $wdPipes);

        try {
            $gen = proc_open(
                [
                    Probe::ffmpeg(), '-hide_banner', '-loglevel', 'error',
                    '-f', 'lavfi', '-i', 'testsrc=duration=3:size=128x96:rate=15',
                    '-y', $clip,
                ],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $genPipes,
            );
            $this->assertIsResource($gen, 'ffmpeg clip generation must start');
            foreach ($genPipes as $p) {
                if (is_resource($p)) {
                    \fclose($p);
                }
            }
            proc_close($gen);
            $this->assertFileExists($clip);

            // SV-M39/039: fps is left to the probe — pinning it would now
            // skip the metadata child, and this test PROVES the probe ran
            // (the duration below is its answer). The clip is generated at
            // exactly rate=15, so the probed pacing number is the historical
            // pinned one, not a host-dependent guess.
            $player = Player::open($clip, 40, 20, null, Mode::HalfBlock);
            $this->assertEqualsWithDelta(3.0, $player->duration(), 0.5, 'probed duration ~3s');

            $a = $player->frameAt(0.2);
            // SV-L86/086: bucket-crossing hovers now respect a one-second
            // spawn floor. This test is about -ss CONTENT truth, not the
            // throttle (pinned in PlayerFrameAtMemoTest), so roll the memo's
            // clock back by hand rather than sleeping the suite.
            $memo = (new \ReflectionProperty($player, 'frameAtMemo'))->getValue($player);
            $memo['at'] = (float) $memo['at'] - 2.0;
            $b = $player->frameAt(2.5);
            $this->assertInstanceOf(RgbFrame::class, $a);
            $this->assertInstanceOf(RgbFrame::class, $b);
            $this->assertNotSame($a->bytes, $b->bytes, 'a -ss seek lands at different content per time');

            $seeked = $player->seekToSeconds(1.5);
            $this->assertSame(23, $seeked->frameIndex, '1.5s @ 15fps = frame 23 (round)');
            $this->assertSame(
                $seeked->frameIndex / 15.0,
                $seeked->position(),
                'SV-H4/013: the recorded playhead is the LANDED frame\'s own content time — the frame the decoder is actually on, never the requested float',
            );

            $player->stop();
            $seeked->stop();
        } finally {
            if (is_resource($wd)) {
                proc_terminate($wd);
                proc_close($wd);
            }
            if (is_file($clip)) {
                @unlink($clip);
            }
        }
    }

    /**
     * J3.3: Ended-hint shows digit "0 restart" only when totalFrames > 0.
     *
     * When totalFrames == 0 (e.g. live stream or synthetic without probe result),
     * digit-seek is a no-op so the "0 restart" hint is misleading and must be omitted.
     */
    public function testEndedHintShowsOrOmitsDigitBasedOnTotalFrames(): void
    {
        $frame = new RgbFrame("\xff\x00\x00\x00\x80\x00", 2, 1);
        $decoder = new FakeDecoder([$frame]);

        // Case 1: totalFrames = 0 — "0" must NOT appear in the hint line.
        // The view() returns the placeholder when currentFrame is null, so we
        // must pass a valid currentFrame to reach the ended-hint path.
        $player0 = Player::openForTest($decoder, 1.0, 0, 2, 1, '/fake', false, 'standard');
        $player0 = $this->createPlayerWithOverrides($player0, [
            'ended' => true,
            'currentFrame' => $frame,
        ]);
        $view0 = $player0->view();
        $this->assertStringNotContainsString(
            '0 restart',
            $view0,
            'When totalFrames=0 the ended hint must not show "0 restart"'
        );

        // Case 2: totalFrames > 0 — "0 restart" MUST appear.
        $playerGt0 = Player::openForTest($decoder, 1.0, 0, 2, 1, '/fake', false, 'standard');
        $playerGt0 = $this->createPlayerWithOverrides($playerGt0, [
            'ended' => true,
            'currentFrame' => $frame,
            'totalFrames' => 10,
        ]);
        $viewGt0 = $playerGt0->view();
        $this->assertStringContainsString(
            '0 restart',
            $viewGt0,
            'When totalFrames>0 the ended hint must show "0 restart"'
        );
    }

    /**
     * @testdox view() with currentFrame === null renders exactly cellsH lines:
     *           cellsH-1 blank rows + the status row (the SV-H5 pane budget)
     *
     * Player::openForTest starts with currentFrame = null (paused, before any
     * tick). The placeholder must not crash and must fit the pane: the OLD
     * contract was cellsH+1 lines (blank grid PLUS status appended below),
     * which is exactly the over-tall repaint SV-H5/005 outlaws — sugar-core's
     * Renderer has no height clamp, so one row past cellsH scrolls the alt
     * screen. The status row now rides the last row INSIDE the budget.
     */
    public function testPlaceholderViewIsBlankGridPlusStatusLine(): void
    {
        $cellsW = 12;
        $cellsH = 7;
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 1.0, 0, $cellsW, $cellsH, '/fake', false, 'standard');

        // currentFrame starts null in openForTest — view() hits renderPlaceholder.
        $view = $player->view();
        $lines = explode("\r\n", $view);

        $this->assertSame(
            $cellsH,
            count($lines),
            'the placeholder must fit the pane exactly (cellsH-1 blank rows + status)'
        );

        // First cellsH-1 lines must be exactly cellsW spaces.
        for ($i = 0; $i < $cellsH - 1; $i++) {
            $this->assertSame(
                $cellsW,
                strlen($lines[$i]),
                "Line $i must be exactly cellsW ({$cellsW}) spaces"
            );
            $this->assertSame(
                str_repeat(' ', $cellsW),
                $lines[$i],
                "Line $i must be all spaces"
            );
        }

        // Last line is the status text.
        // In-step flip: the start screen now carries the active mode label (M22).
        $this->assertStringStartsWith('[', $lines[$cellsH - 1]);
        $this->assertStringContainsString('] loading...  space play  q quit', $lines[$cellsH - 1]);
    }

    /**
     * @testdox SV-H5: caption and status rows are RESERVED out of the pane — the view never exceeds cellsH lines
     */
    public function testTheOverlayRowsRideInsideThePaneBudget(): void
    {
        $frames = [];
        for ($i = 0; $i < 3; $i++) {
            $frames[] = new RgbFrame(str_repeat("\x10\x20\x30", 80 * 24), 80, 24);
        }
        $decoder = new FakeDecoder($frames);
        $subs = WebVtt::parse("WEBVTT\n\n00:00.000 --> 00:59.000\nhello world\n");
        $rowsOf = static fn (string $v): array => preg_split("/\r\n|\n/", $v) ?: [];

        // Plain pane (no subtitles): the frame owns all 24 rows.
        $plain = Player::fromDecoder(decoder: new FakeDecoder($frames), cellsW: 80, cellsH: 24, fps: 10.0, mode: Mode::Ascii, totalFrames: 3);
        $plainPainted = $plain->withSeek(1);
        $this->assertSame(24, count($rowsOf($plainPainted->view())), 'no overlays: the frame owns all 24 rows');

        // Caption active: 23 frame rows + the caption row = exactly 24.
        $seeked = Player::fromDecoder(decoder: $decoder, cellsW: 80, cellsH: 24, fps: 10.0, mode: Mode::Ascii, totalFrames: 3, subtitles: $subs)->withSeek(2);
        $this->assertSame(24, count($rowsOf($seeked->view())));
        $captionRows = $rowsOf($seeked->view());
        $this->assertStringContainsString('hello world', end($captionRows), 'the caption owns the reserved bottom row');

        // Ended (no caption cue): 23 frame rows + the [ended] row = exactly 24.
        $noSubs = Player::fromDecoder(decoder: new FakeDecoder([new RgbFrame(str_repeat("\x10\x20\x30", 80 * 24), 80, 24)]), cellsW: 80, cellsH: 24, fps: 10.0, mode: Mode::Ascii, paused: false);
        $armed = $this->backdateLastTick($noSubs, 1.0);
        [$ended] = $armed->update(new TickMsg());
        $this->assertTrue($ended->ended);
        $this->assertSame(24, count($rowsOf($ended->view())), 'the pane never grows past cellsH');
        $endedRows = $rowsOf($ended->view());
        $this->assertStringContainsString('[ended]', end($endedRows));
    }

    /**
     * @testdox SV-M47: the active cue follows the playhead, not the wall
     *           clock — a paused player keeps painting the cue, and a paused
     *           seek repaints it at the landed time
     */
    public function testPausedPlayerKeepsPaintingTheActiveCue(): void
    {
        $frames = [];
        for ($i = 0; $i < 10; $i++) {
            $frames[] = new RgbFrame(str_repeat("\x10\x20\x30", 80 * 24), 80, 24);
        }
        $subs = WebVtt::parse("WEBVTT\n\n00:01.000 --> 00:02.000\nFIRST CUE\n\n00:03.000 --> 00:04.000\nSECOND CUE\n");
        $caption = static function (Player $p): string {
            $rows = preg_split("/\r\n|\n/", $p->view()) ?: [];
            return (string) end($rows);
        };

        // Frame 0 of fps 2.0 is 0.0 s — before any cue. Paused (the fromDecoder
        // default; playback starts paused), no caption row is owed yet.
        $idle = Player::fromDecoder(decoder: new FakeDecoder($frames), cellsW: 80, cellsH: 24, fps: 2.0, mode: Mode::Ascii, totalFrames: 10, subtitles: $subs);
        $this->assertTrue($idle->paused, 'the pin rides the paused state, which is also the shipped open state');
        $this->assertStringNotContainsString('FIRST CUE', $caption($idle));

        // Land inside the first cue while PAUSED: the caption paints at once.
        $first = $idle->withSeek(3);
        $this->assertTrue($first->paused, 'withSeek never un-pauses');
        $this->assertStringContainsString('FIRST CUE', $caption($first), 'the paused playhead paints its cue');

        // A tick while paused must not advance the playhead nor blank the
        // cue: pause is a legibility state, not a subtitle freeze.
        [$held] = $first->update(new TickMsg());
        $this->assertStringContainsString('FIRST CUE', $caption($held), 'the held cue keeps painting through ticks');

        // A seek while paused repaints at the landed time, skipping the gap.
        $second = $held->withSeek(7);
        $this->assertStringContainsString('SECOND CUE', $caption($second), 'a paused seek repaints the cue at the landed playhead');
    }

    /**
     * @testdox SV-H7: the documented pane window (10-200 x 5-80) binds at
     *           every size entry — a 9999x9999 pane is a memory DoS
     *
     * QA measured a 100,000,056-byte view() from Player::open('x',9999,9999)
     * because the documented cols/rows window was enforced only on the
     * resize path. clampCells is now the single law behind open,
     * fromDecoder (every Reel build and test seam) and updateResize.
     */
    public function testThePaneWindowBindsAtEverySizeEntry(): void
    {
        $frame = new RgbFrame(str_repeat("\x10\x20\x30", 12), 4, 1);

        $huge = Player::fromDecoder(decoder: new FakeDecoder([$frame]), cellsW: 9999, cellsH: 9999, fps: 30.0, mode: Mode::Ascii);
        $this->assertSame(200, $huge->cellsW);
        $this->assertSame(80, $huge->cellsH);

        $tiny = Player::fromDecoder(decoder: new FakeDecoder([$frame]), cellsW: 0, cellsH: 0, fps: 30.0, mode: Mode::Ascii);
        $this->assertSame(10, $tiny->cellsW);
        $this->assertSame(5, $tiny->cellsH);

        [$cols, $rows] = Player::clampCells(80, 24);
        $this->assertSame([80, 24], [$cols, $rows], 'in-window geometry passes through untouched');
    }
    /**
     * @testdox M11: a budgeted forward seek yields to the frame budget and records the landed playhead
     */
    public function testAForwardSeekYieldsToTheFrameBudget(): void
    {
        $frames = [];
        for ($i = 0; $i < 900; $i++) {
            $frames[] = new RgbFrame(str_repeat("\x10\x20\x30", 12), 4, 1);
        }
        $decoder = new CountingDecoder($frames);
        $player = Player::fromDecoder(
            decoder: $decoder,
            cellsW: 4,
            cellsH: 1,
            fps: 30.0,
            mode: Mode::Ascii,
            frameBudgetMs: 5.0,
        );

        $seeked = $player->withSeek(800);

        $this->assertLessThan(
            200,
            $decoder->pulls,
            'the walk must bail on the budget long before the 800-frame target',
        );
        $this->assertGreaterThan(0.0, $seeked->position(), 'guaranteed progress: at least one frame was consumed');
        // The forward walk from 0 pulls exactly `landed` frames, so the
        // recorded playhead must equal the pull count — the position the
        // decoder is ACTUALLY at, never ahead of it.
        $this->assertSame(
            (float) $decoder->pulls / 30.0,
            $seeked->position(),
            'the recorded playhead must be exactly where the walk stopped',
        );
        $this->assertLessThan(800, $decoder->pulls, 'the seek did not reach the requested target');
    }

    /**
     * @testdox M11: without a budget the forward and backward walks still land EXACTLY on the target
     */
    public function testAnUnbudgetedSeekStillLandsOnTheExactTarget(): void
    {
        $frames = [];
        for ($i = 0; $i < 900; $i++) {
            $frames[] = new RgbFrame(str_repeat("\x10\x20\x30", 12), 4, 1);
        }
        $decoder = new CountingDecoder($frames);
        $player = Player::fromDecoder(
            decoder: $decoder,
            cellsW: 4,
            cellsH: 1,
            fps: 30.0,
            mode: Mode::Ascii,
        );

        $forward = $player->withSeek(600);
        $this->assertSame(600, $decoder->pulls, 'budget 0 walks the whole span, exactly the pre-budget behaviour');
        $this->assertEqualsWithDelta(600 / 30.0, $forward->position(), 1e-9);

        $backward = $forward->withSeek(100);
        $this->assertEqualsWithDelta(100 / 30.0, $backward->position(), 1e-9, 'an unbudgeted backward rebuild lands on the requested index');
    }

    /**
     * @testdox SV-H4: a seek after a decode failure clears the error row, keeps the picture and re-arms the tick
     */
    public function testASeekAfterAFailureRecoversTheStream(): void
    {
        $frames = [];
        // SV-PPAINT/071: frames now conform to the decoder byte contract —
        // 4x1 needs EXACTLY 12 bytes (the old fixture carried a 36-byte
        // surplus that frameToBuffer silently ignored).
        for ($i = 0; $i < 20; $i++) {
            $frames[] = new RgbFrame(str_repeat("\x10\x20\x30", 4), 4, 1);
        }
        // Paints one frame, then crashes exactly once — the way a real ffmpeg
        // child dies mid-stream — then feeds fresh frames on the rebuilt walk.
        $decoder = new class($frames) extends FakeDecoder {
            private int $pulls = 0;

            public function next(): ?RgbFrame
            {
                $this->pulls++;
                if ($this->pulls === 2) {
                    throw new FfmpegDecodeFailure('sugar-video: ffmpeg exited with code 7 before the stream ended (/fake)');
                }
                return parent::next();
            }
        };
        $player = Player::fromDecoder(
            decoder: $decoder,
            cellsW: 40,
            cellsH: 1,
            fps: 10.0,
            mode: Mode::Ascii,
            paused: false,
        );

        // Tick 1 paints frame 0; tick 2 (backdated so the elapsed gate opens)
        // is the one that dies.
        [$painted] = $player->update(new TickMsg());
        $armed = $this->backdateLastTick($painted, 1.0);
        [$failed, $deadCmd] = $armed->update(new TickMsg());
        $this->assertNotNull($failed->failure, 'the crash lands as a visible failure state');
        $this->assertTrue($failed->ended);
        $this->assertNull($deadCmd, 'a crashed stream schedules no follow-up tick');
        $this->assertStringContainsString('[error]', $failed->view());

        // → from frame 0 walks forward: the seek must resurrect the stream.
        [$recovered, $rearm] = $failed->update(new KeyMsg(KeyType::Right));

        $this->assertNotSame($failed, $recovered, 'the seek rebuilt the player state');
        $this->assertNull($recovered->failure, 'a seek after a failure clears the dead session error row');
        $this->assertFalse($recovered->ended, 'a seek that landed a frame claims a live stream');
        $this->assertStringNotContainsString('[error]', $recovered->view(), 'the error row is gone from the picture');
        // onDecodeFailure PAUSES the dead session (nothing may auto-resume audio
        // behind an error row), and the seek honors that pause — Space, not a
        // seek, is what resumes playback. The clean-ended re-arm is pinned in
        // testTheArrowAtTheLastFrameIsANoOp.
        $this->assertTrue($recovered->paused, 'recovery does not sneak past the failure pause');
        $this->assertNull($rearm, 'a paused recovery arms no tick');

        // And a tick against the paused recovery is inert, never resurrecting
        // the crashed session's state.
        [$pumped, $nextCmd] = $recovered->update(new TickMsg());
        $this->assertNull($pumped->failure, 'the recovered stream stays clean');
        $this->assertLessThan(20, $pumped->frameIndex);
    }

    /**
     * @testdox SV-H4: seekToSeconds() records the LANDED playhead when the walk yields on the budget
     */
    public function testSeekToSecondsRecordsTheLandedPlayheadWhenTheWalkYields(): void
    {
        $frames = [];
        for ($i = 0; $i < 900; $i++) {
            $frames[] = new RgbFrame(str_repeat("\x10\x20\x30", 12), 4, 1);
        }
        $decoder = new CountingDecoder($frames);
        $player = Player::fromDecoder(
            decoder: $decoder,
            cellsW: 4,
            cellsH: 1,
            fps: 30.0,
            mode: Mode::Ascii,
            frameBudgetMs: 5.0,
        );

        $seeked = $player->seekToSeconds(20.0);

        $this->assertGreaterThan(0, $seeked->frameIndex, 'guaranteed progress: at least one frame landed');
        $this->assertLessThan(600, $seeked->frameIndex, 'the walk yielded on the budget long before the 600-frame target');
        $this->assertSame(
            $seeked->frameIndex / 30.0,
            $seeked->position(),
            'the recorded time is exactly the landed index — never the requested target (SV-H4/013)',
        );
        $this->assertFalse($seeked->ended, 'the yielded seek landed a frame, so the stream is live');
    }

    /**
     * @testdox SV-H4: an arrow at the last frame is a TRUE no-op — no respawned audio, no resurrected playhead
     */
    public function testTheArrowAtTheLastFrameIsANoOp(): void
    {
        $spy = new SpyAudioPlayer('/fake', 0);
        $created = [];
        $factory = static function (string $path, ?int $startMs) use ($spy, &$created): AudioPlayer {
            $created[] = $startMs;
            return $spy;
        };

        $player = Player::openForTest(
            decoder: $this->makeFakeDecoder(12),
            fps: 10.0,
            totalFrames: 12,
            videoPath: '/fake',
            audioFactory: \Closure::fromCallable($factory),
            audioPlayer: $spy,
            paused: false,
        );

        // Walk to the last frame, then run the decoder dry so ended=true.
        $atEnd = $player->withSeek(11);
        $armed = $this->backdateLastTick($atEnd, 1.0);
        [$endedPlayer] = $armed->update(new TickMsg());
        $this->assertTrue($endedPlayer->ended, 'running the decoder dry ends the stream');
        $this->assertSame(12, $endedPlayer->frameIndex, 'a fully consumed stream sits at frameIndex == totalFrames');

        $stopsBefore = $spy->stopCallCount;
        $createdBefore = count($created);

        [$same, $cmd] = $endedPlayer->update(new KeyMsg(KeyType::Right));

        $this->assertSame($endedPlayer, $same, '→ at the last frame does not pretend to move');
        $this->assertTrue($same->ended, 'the ended state survives the no-op (no half-dead "playing" claim)');
        $this->assertSame($stopsBefore, $spy->stopCallCount, 'the no-op must not stop the audio companion');
        $this->assertCount($createdBefore, $created, 'the no-op must not respawn audio');
        $this->assertNull($cmd, 'a no-op seek arms no tick');
        $this->assertStringContainsString('[ended]', $same->view(), 'the end row stays visible');

        // ← at frame 0 is the mirrored no-op.
        $fresh = Player::openForTest($this->makeFakeDecoder(5), 10.0, totalFrames: 5);
        [$sameZero, $cmdZero] = $fresh->update(new KeyMsg(KeyType::Left));
        $this->assertSame($fresh, $sameZero, '← at frame 0 is a no-op too');
        $this->assertNull($cmdZero);

        // A seek out of a CLEAN ended stream (not paused — onReachedEnd never
        // pauses) must re-arm the tick pump: that is seekTickCmd's whole job.
        [$replay, $rearm] = $endedPlayer->update(new KeyMsg(KeyType::Left));
        $this->assertNotSame($endedPlayer, $replay, '← from the true end rebuilds backward');
        $this->assertFalse($replay->ended, 'the replay seek landed a frame and resumed the stream');
        $this->assertNotNull($rearm, 'a seek out of the clean ended state re-arms the tick pump');
    }


    /**
     * @testdox M22: the paused start screen announces the active render mode
     */
    public function testTheModeLabelIsAnnouncedOnTheStartScreen(): void
    {
        $player = Player::fromDecoder(
            decoder: new FakeDecoder([new RgbFrame(str_repeat("\x20\x40\x60", 8), 4, 2)]),
            cellsW: 40,
            cellsH: 3,
            fps: 10.0,
        );
        $rows = preg_split("/\r\n|\n/", $player->view()) ?: [];
        $this->assertSame(
            '[Unicode half-block (▀)] loading...  space play  q quit',
            end($rows),
            'the start screen names the mode the viewer is actually seeing',
        );
    }

    /**
     * @testdox M24: withAudio(false) mutes the companion and carries through derived states
     */
    public function testTheMuteCarriesThroughDerivedStates(): void
    {
        $spy = new SpyAudioPlayer('/fake.mp4');
        $player = Player::fromDecoder(
            decoder: new FakeDecoder([new RgbFrame(str_repeat("\x30\x60\x90", 8), 4, 2)]),
            cellsW: 4,
            cellsH: 2,
            fps: 10.0,
            mode: Mode::Ascii,
            audioPlayer: $spy,
            paused: false,
        );

        $muted = $player->withAudio(false);
        $this->assertTrue($muted->audioMuted);
        $this->assertTrue($spy->isMuted(), 'the live companion is silenced too');
        $this->assertTrue($muted->pause()->audioMuted, 'mute rides every derived state');

        $unmuted = $muted->withAudio(true);
        $this->assertFalse($unmuted->audioMuted);
        $this->assertFalse($spy->isMuted(), 'unmute re-arms the companion');
    }

    /**
     * @testdox M23: Player::open decodes a poster frame instead of promising 'loading...'
     */
    public function testThePosterFrameGreetsThePausedViewer(): void
    {
        if (Probe::ffmpeg() === null) {
            $this->markTestSkipped('needs a live ffmpeg for the poster decode');
        }
        $dir = sys_get_temp_dir() . '/sv-poster-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $clip = $dir . '/clip.mp4';
        $gen = proc_open(
            [Probe::ffmpeg(), '-y', '-loglevel', 'error', '-f', 'lavfi',
             '-i', 'testsrc=duration=1:size=64x48:rate=10', '-pix_fmt', 'yuv420p', $clip],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $gpipes,
        );
        $ok = is_resource($gen) && proc_close($gen) === 0 && is_file($clip);
        if (!$ok) {
            @rmdir($dir);
            $this->markTestSkipped('could not generate a test clip');
        }

        try {
            $player = Player::open($clip, 8, 6, mode: Mode::Ascii);
            $rows = preg_split("/\r\n|\n/", $player->view()) ?: [];
            $body = array_slice($rows, 0, 6);
            $this->assertNotSame(
                array_fill(0, count($body), ''),
                array_values(array_filter($body, static fn (string $r): bool => trim($r) !== '')),
                'the paused screen shows real frame content, not the placeholder',
            );
            $this->assertStringNotContainsString('loading...', $player->view());
            $player->stop();
        } finally {
            @unlink($clip);
            @rmdir($dir);
        }
    }

    /**
     * @testdox M21: cell sampling averages the mapped source box, clamps safely, and is pixel-exact on 1:1 geometry
     */
    public function testTheAreaAverageIsExactOnFlatBoxesAndClampsBeyondTheFrame(): void
    {
        $white = "\xff\xff\xff";
        $black = "\x00\x00\x00";
        $checker = $white . $black . $black . $white;
        $this->assertSame([127, 127, 127], Player::areaAverageRgb($checker, 2, 12, 0, 0, 1, 1), 'a 2x2 checkerboard cell means to mid-grey');
        $this->assertSame([255, 255, 255], Player::areaAverageRgb($white . $white . $white . $white, 2, 12, 0, 0, 1, 1), 'a flat box keeps its level');
        $this->assertSame([127, 127, 127], Player::areaAverageRgb($white . $black, 2, 6, 0, 0, 1, 0), 'a 1-row box is a mean, not a point');

        $row = $white . $black;
        foreach ([0, 1, 2, 3] as $i) {
            $point = substr($row . $row, $i * 3, 3) === $white ? [255, 255, 255] : [0, 0, 0];
            $this->assertSame(
                $point,
                Player::areaAverageRgb($row . $row, 2, 12, $i % 2, intdiv($i, 2), $i % 2, intdiv($i, 2)),
                'degenerate 1x1 boxes stay pixel-identical to the old point read',
            );
        }

        $this->assertSame([255, 255, 255], Player::areaAverageRgb($white, 1, 3, 5, 5, 9, 9), 'a fully-outside box clamps to the last real pixel, the grid clamping law');
        $this->assertSame([0, 0, 0], Player::areaAverageRgb('', 2, 0, 0, 0, 1, 1), 'an empty buffer is black, never a crash');

        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Player.php');
        $arm = substr($src, strpos($src, '// M21: a cell covers'), strpos($src, 'self::areaAverageRgb(', strpos($src, '// M21: a cell covers')));
        $this->assertStringContainsString('intdiv($cx * $w, max(1, $bufW))', $arm, 'the cell box maps through the buffer grid');
    }
    /**
     * @testdox SV-M19: open() arms a default frame budget instead of an unbounded walk
     *
     * Load-bearing: revert the null-default in Player::open and the first
     * assertion reddens (0.0); drop the floor and the 100fps arm reddens.
     * The computed budget is max(50ms, 2 × frame interval); explicit 0.0
     * stays the documented unbounded opt-out (parity hosts).
     */
    public function testOpenArmsTheDefaultFrameBudgetFromTheFrameInterval(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }

        $computed = Player::open($this->createTempGif(), 40, 20, 15.0);
        $this->assertEqualsWithDelta(
            2000.0 / 15.0,
            $this->getPlayerProperty($computed, 'frameBudgetMs'),
            1e-6,
            'a 15fps source budgets 2 frame intervals (133.(3) ms)'
        );
        $computed->stop();

        $floored = Player::open($this->createTempGif(), 40, 20, 100.0);
        $this->assertSame(
            Player::DEFAULT_FRAME_BUDGET_FLOOR_MS,
            $this->getPlayerProperty($floored, 'frameBudgetMs'),
            'a high-fps source still gets the 50 ms floor'
        );
        $floored->stop();

        $optOut = Player::open($this->createTempGif(), 40, 20, 15.0, frameBudgetMs: 0.0);
        $this->assertSame(
            0.0,
            $this->getPlayerProperty($optOut, 'frameBudgetMs'),
            'explicit 0.0 remains the unbounded opt-out'
        );
        $optOut->stop();
    }

    /**
     * @testdox SV-H11/011: a factory-owned index seek lands by seconds, not by walking
     *
     * The GifDecoder route re-spawns at the target's seconds (cursor jump —
     * one pull), and the deep-landed frame must equal what the independent
     * throwaway-decoder oracle frameAt() sees at that time (dual oracle).
     * A seek past the end of a KNOWN-length stream clamps to the last frame
     * instead of collapsing to 0 (the route would read nothing past EOF).
     * Mutation-proof: restoring the old rebuildDecoderAt walk for the
     * factory branch reddens the structural pin below; removing the clamp
     * reddens the overshoot arm on probed hosts.
     */
    public function testAFactoryOwnedIndexSeekLandsByTheSecondsRoute(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $gif = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-h11-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.gif',
            16,
            8,
            60,
            4,
        );

        try {
            $player = Player::open($gif, 40, 20, 25.0);
            try {
                $deep = $player->withSeek(45);
                $this->assertSame(45, $this->getPlayerProperty($deep, 'frameIndex'), 'the seconds route lands exactly on the target index');
                $this->assertEqualsWithDelta(45 / 25.0, $deep->position(), 1e-9, 'videoTime records the landed seconds');
                $oracle = $player->frameAt(45 / 25.0);
                $painted = $this->getPlayerProperty($deep, 'currentFrame');
                $this->assertNotNull($oracle);
                $this->assertNotNull($painted);
                $this->assertSame($oracle->bytes, $painted->bytes, 'the walked route and the seconds route agree on the frame content');

                if ($deep->duration() > 0.0) {
                    $over = $deep->withSeek(10000);
                    $this->assertGreaterThan(
                        0,
                        $this->getPlayerProperty($over, 'frameIndex'),
                        'a seek past the end of a known-length stream clamps instead of collapsing to frame 0'
                    );
                    $over->stop();
                }
                $deep->stop();
            } finally {
                $player->stop();
            }
        } finally {
            @unlink($gif);
        }
    }

    /**
     * @testdox SV-H11/011: the seek split is structural - factory routes by seconds, in-place keeps the walk
     */
    public function testTheSeekSplitIsCarriedByTheReopensInPlaceCapability(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Player.php');
        $start = strpos($source, 'public function withSeek(int $targetIndex): self');
        $this->assertNotFalse($start);
        $end = strpos($source, 'public function position(): float', $start);
        $this->assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString(
            'if (!$this->decoder->reopensInPlace()) {',
            $body,
            'the factory branch must be gated on the capability, not on a path string or class'
        );
        $this->assertSame(
            1,
            substr_count($body, '$this->rebuildDecoderAtSeconds('),
            'the factory route calls the seconds rebuild exactly once'
        );
        $this->assertStringContainsString(
            '$this->rebuildDecoderAt($this->cellsW, $this->cellsH, $this->mode, $targetIndex)',
            $body,
            'the in-place backward arm keeps the index walk (deep-walk-on-everything is not allowed back)'
        );
    }

    /**
     * @testdox SV-M20/045: a clock jump can never burst — the delta is clamped to [0, ceiling]
     *
     * Load-bearing: remove the clamp in update() and the burst arm reddens
     * (a 10-hour scripted jump would charge 36,000 s of content time);
     * flipping the negative guard lets a backward step freeze the clock
     * bookkeeping — videoTime must only ever advance by the clamped delta.
     */
    public function testAClockJumpChargesAtMostTheClampedDelta(): void
    {
        $t = 100.0;
        $clock = static function () use (&$t): float {
            return $t;
        };

        $decoder = new FakeDecoder(array_fill(0, 200, $this->makeFrame("\x00\x00\x00")));
        $player = Player::openForTest($decoder, 30.0, clock: $clock, paused: false);

        // A ten-hour forward jump (NTP, suspend resume, hostile seam): at most
        // MAX_TICK_DELTA_SEC of content time is charged — at 30fps that is 30
        // frames plus the one the on-schedule arm may pull, never thousands.
        $t += 36000.0;
        [$player] = $player->update(new TickMsg());
        $burstIndex = (int) $this->getPlayerProperty($player, 'frameIndex');
        $this->assertGreaterThanOrEqual(
            30,
            $burstIndex,
            'the scripted seam really fed the delta (a dropped clock seam sees microsecond real-time deltas instead)'
        );
        $this->assertLessThanOrEqual(
            32,
            $burstIndex,
            'a 10-hour clock jump may not burst more than the clamped one-second budget'
        );
        $videoBefore = (float) $this->getPlayerProperty($player, 'videoTime');

        // A two-hour step BACKWARD: the negative skew reads as zero, videoTime
        // does not retreat, and no frames are refunded.
        $t -= 7200.0;
        [$player] = $player->update(new TickMsg());
        $this->assertLessThanOrEqual(
            $videoBefore + Player::MAX_TICK_DELTA_SEC,
            (float) $this->getPlayerProperty($player, 'videoTime'),
            'a backward step cannot rewind or inflate the content clock'
        );
        $this->assertGreaterThanOrEqual(
            $burstIndex,
            (int) $this->getPlayerProperty($player, 'frameIndex'),
            'the frame index never runs backward'
        );
    }

    /**
     * @testdox SV-M20/045: the default pacing clock is the monotonic one
     */
    public function testTheDefaultPacingClockIsMonotonic(): void
    {
        // Structural pin: the update() delta is built from now() (the
        // monotonic-based seam), and no wall-clock microtime call survives in
        // the playback timing path.
        $source = (string) file_get_contents(__DIR__ . '/../src/Player.php');
        $this->assertStringNotContainsString('microtime(true)', $source, 'the player no longer reads the wall clock at all');
        $this->assertStringContainsString('return hrtime(true) / 1e9;', $source, 'the base is hrtime-ns converted to seconds');
        $this->assertStringContainsString(
            '(float) $clock()',
            $source,
            'the scripted seam is consulted by now() — dropping it silently re-pins every pacing test to the real clock'
        );
        $this->assertSame(1.0, Player::MAX_TICK_DELTA_SEC, 'the burst ceiling is pinned');
    }

    /**
     * @testdox SV-M34/034: rendersWholeScreen() answers the scripted mode matrix
     *
     * The three image rungs ship one indivisible raster paint (true); every
     * text rung — Ansi256's direct row stream included, because its rows
     * carry their own SGR and split cleanly — is row-addressable (false).
     * Load-bearing: a consumer (candy-code's GRAPHICS_MODES copy + width
     * heuristic) replaces its guesswork with this predicate, so a rung that
     * changes routing must move the matrix here, loudly.
     */
    public function testRendersWholeScreenAnswersTheScriptedModeMatrix(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('graphics rung construction needs ext-gd');
        }

        // Pairs, not keys: PHP enums are not legal array offsets.
        $expected = [
            [Mode::Ascii, false],
            [Mode::Ansi256, false],
            [Mode::TrueColor, false],
            [Mode::HalfBlock, false],
            [Mode::QuarterBlock, false],
            [Mode::Sixel, true],
            [Mode::Kitty, true],
            [Mode::Iterm2, true],
        ];

        RendererFactory::setDiagnoseForTesting(new ProbeReport([
            Capability::Sixel->value => true,
            Capability::KittyKeyboard->value => true,
            Capability::ITerm2->value => true,
        ]));

        try {
            foreach ($expected as [$mode, $want]) {
                $player = Player::fromDecoder(
                    decoder: $this->makeFakeDecoder(1),
                    cellsW: 20,
                    cellsH: 10,
                    fps: 24.0,
                    mode: $mode,
                );
                try {
                    $this->assertSame(
                        $want,
                        $player->rendersWholeScreen(),
                        $mode->label() . ' must ' . ($want ? 'repaint whole' : 'line-diff') . ' per the predicate'
                    );
                } finally {
                    $player->stop();
                }
            }
        } finally {
            RendererFactory::setDiagnoseForTesting(null);
        }
    }

    /**
     * SV-PPAINT/067: caption/error clipping walks GRAPHEME CLUSTERS. A ZWJ
     * family is ONE 2-cell cluster; the old codepoint walk could emit its
     * lead person alone — a dangling half-glyph with hostile subtitle
     * content one keystroke away.
     */
    public function testTheCaptionClipNeverSplitsAGraphemeCluster(): void
    {
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $flag = "\u{1F1E6}\u{1F1E8}";
        $this->assertSame(2, \SugarCraft\Core\Util\Width::of($family), 'premise: the family is one 2-cell cluster');
        $this->assertSame(2, \SugarCraft\Core\Util\Width::of($flag), 'premise: the flag is one 2-cell cluster');

        $clip = new \ReflectionMethod(Player::class, 'clipToCells');
        $clip->setAccessible(true);

        // Budget INSIDE the sequence: bytes exist for a partial emission,
        // but no cluster boundary fits — the whole family is refused.
        $this->assertSame('', $clip->invoke(null, $family . 'A', 1), 'no head of a 2-cell cluster may paint');
        $this->assertSame($family . 'A', $clip->invoke(null, $family . 'A', 3), 'clusters plus the ASCII tail fit exactly');
        $this->assertSame($family, $clip->invoke(null, $family . 'A', 2), 'the second cluster is refused whole');
        $this->assertSame($flag . 'AB', $clip->invoke(null, $flag . 'AB', 4));
        $this->assertSame($flag . 'A', $clip->invoke(null, $flag . 'AB', 3));
    }

    /**
     * SV-PPAINT/071: a truncated RGB24 buffer painted silent BLACK in the
     * block rungs while the text rung refused the identical frame loudly.
     * One length check now fronts every raw rung, answering the typed
     * FfmpegDecodeFailure — pinned on BOTH rung families for the parity.
     */
    public function testATruncatedFrameRefusesTypedOnEveryRawRung(): void
    {
        // 4x4 grid wants 48 bytes; deliver 30 (the shape a killed ffmpeg
        // leaves in the pipe when the reader accepts a partial fill).
        $decoder = new FakeDecoder([new \SugarCraft\Video\Decode\RgbFrame(str_repeat("\xff\x00\x00", 10), 4, 4)]);
        $player = Player::openForTest($decoder, 30.0);
        $player = $player->play();
        $player = $this->backdateLastTick($player, 1.0 / 30.0);
        [$player] = $player->update(new TickMsg());
        $this->assertNotNull($player->currentFrame, 'precondition: the corrupt frame reached the model');

        // Rung family 1: the block rung (openForTest mounts HalfBlock).
        $mutate = new \ReflectionMethod(Player::class, 'mutate');
        $mutate->setAccessible(true);
        try {
            $player->view();
            $this->fail('the block rung must refuse a truncated frame');
        } catch (\SugarCraft\Video\Decode\FfmpegDecodeFailure $failure) {
            $this->assertStringContainsString('truncated', $failure->getMessage());
            $this->assertStringContainsString('needs 48', $failure->getMessage());
        }

        // Rung family 2: the palette/text rung (direct render through
        // ImageSource::fromRgb — the door that was ALWAYS loud). Parity is
        // the point: one corrupt buffer, both families refuse typed.
        $asText = $mutate->invoke($player, [
            'mode' => \SugarCraft\Video\Render\Mode::Ansi256,
            'renderer' => new \SugarCraft\Video\Render\AsciiRenderer('standard'),
        ]);
        $second = null;
        try {
            $asText->view();
        } catch (\Throwable $thrown) {
            $second = $thrown;
        }
        $this->assertNotNull($second, 'the text rung must refuse the identical corrupt buffer');
        $this->assertStringContainsString('30', $second->getMessage());
        $this->assertStringContainsString('48', $second->getMessage(), 'both doors name the same sizes: one corrupt buffer, one refusal story per rung');

        // The plain-text buffer rung answers the SAME typed failure as the
        // block rung (both ride frameToBuffer's one front check).
        $asAscii = $mutate->invoke($player, [
            'mode' => \SugarCraft\Video\Render\Mode::Ascii,
            'renderer' => new \SugarCraft\Video\Render\AsciiRenderer('standard'),
        ]);
        $third = null;
        try {
            $asAscii->view();
        } catch (\Throwable $thrown) {
            $third = $thrown;
        }
        $this->assertInstanceOf(\SugarCraft\Video\Decode\FfmpegDecodeFailure::class, $third);
    }

    /**
     * @testdox L54: subscriptions() answers null by design - a wall-clock subscription would double-tick the pump
     *
     * The M27 law, pinned: pacing rides the update()-returned Cmd::tick
     * chain alone; input and resize arrive through sugar-core's own wiring.
     * An edit that arms a subscription here would silently double-pump the
     * player, and until now zero tests mentioned the method at all.
     */
    public function testThePlayerShipsNoSubscriptionsByDesign(): void
    {
        $decoder = $this->makeFakeDecoder(1);
        $player = Player::openForTest($decoder, 10.0);

        $this->assertNull($player->subscriptions(), 'M27: null is the deliberate answer — the tick chain owns pacing');
    }

    /**
     * @testdox SV-M25/025: canSeekByTime() reads the decoder's own capability and the source binding — not a hopeful true
     */
    public function testCanSeekByTimeAnswersPerDecoderCapability(): void
    {
        $this->assertFalse(
            Player::fromDecoder(decoder: new FakeDecoder([]), cellsW: 4, cellsH: 2, fps: 10.0)
                ->canSeekByTime(),
            'a replay-from-head double has no time seek to promise'
        );

        // A seconds-capable decoder that rebuilds by RESPAWN needs the source
        // path the factory branch respawns from — unbound it honestly says no.
        $respawnSeeker = new class([]) extends FakeDecoder {
            public function reopensInPlace(): bool
            {
                return false;
            }

            public function seeksBySeconds(): bool
            {
                return true;
            }
        };
        $this->assertFalse(
            Player::fromDecoder(decoder: $respawnSeeker, cellsW: 4, cellsH: 2, fps: 10.0)
                ->canSeekByTime(),
            'an unbound player cannot time-seek: the throwaway route has nothing to respawn'
        );
        $this->assertTrue(
            Player::fromDecoder(decoder: $respawnSeeker, cellsW: 4, cellsH: 2, fps: 10.0, videoPath: '/tmp/clip.gif')
                ->canSeekByTime(),
            'the factory ffmpeg/GIF shape — seconds-capable and path-bound — is the acceptance truth'
        );

        // Reopening in place at a honoured second needs no path.
        $inPlaceSeeker = new class([]) extends FakeDecoder {
            public function seeksBySeconds(): bool
            {
                return true;
            }
        };
        $this->assertTrue(
            Player::fromDecoder(decoder: $inPlaceSeeker, cellsW: 4, cellsH: 2, fps: 10.0)
                ->canSeekByTime(),
            'per capability: an in-place decoder that honours $startSec can be time-seeked as-is'
        );

        $this->assertTrue((new FfmpegDecoder())->seeksBySeconds(), 'ffmpeg `-ss` before -i');
        $this->assertTrue((new GifDecoder())->seeksBySeconds(), 'GIF cursor jump over the capture (M38)');
        $this->assertTrue((new AnimatedWebpDecoder())->seeksBySeconds(), 'WebP container-truth skip');
    }

    /**
     * @testdox SV-M25/025: seekToSeconds()/withSeek() whose rebuild dies end the session with the failure row — no FfmpegDecodeFailure escapes to the RPC caller
     */
    public function testSeekDecodeFailureEndsWithFailureInsteadOfEscaping(): void
    {
        // A dead stream: the typed failure surfaces at the PULL, which is
        // where both public seek doors touch the decoder (the in-place
        // forward walk pulls next(); the seconds rebuild pulls after reopen).
        $decoder = new class(array_fill(0, 5, $this->makeFrame("\x00\x00\x00"))) extends FakeDecoder {
            public function next(): ?RgbFrame
            {
                throw new FfmpegDecodeFailure('child died (simulated seek)');
            }
        };
        $player = Player::fromDecoder(decoder: $decoder, cellsW: 4, cellsH: 2, fps: 10.0, totalFrames: 5);

        $afterTime = $player->seekToSeconds(2.0);
        $this->assertTrue($afterTime->ended, 'a dead seek ends the session loudly');
        $this->assertStringContainsString('child died', (string) $afterTime->failure, 'the failure text rides the row the view paints');

        // withSeek(3) from the initial index 0 is a real move (the same-index
        // guard returns identity before reaching the rebuild), so it takes the
        // reopen and hits the same failure law.
        $afterIndex = $player->withSeek(3);
        $this->assertTrue($afterIndex->ended, 'the index door carries the same law');
        $this->assertStringContainsString('child died', (string) $afterIndex->failure);
    }

    /**
     * @testdox SV-M25/025: a frameAt() peek that hits a decode failure announces on error_log and answers null — the playing session survives the advisory read
     */
    public function testFrameAtDecodeFailureAnnouncesNullAndKeepsTheSession(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'sv-frameat-');
        $this->assertNotFalse($log);
        $this->iniSet('error_log', $log);

        $decoder = new class(array_fill(0, 5, $this->makeFrame("\x00\x00\x00"))) extends FakeDecoder {
            public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
            {
                if ($startSec > 0.0) {
                    throw new FfmpegDecodeFailure('peek died (simulated)');
                }
                parent::reopen($source, $cellsW, $cellsH, $fps, $mode, $startSec, $headers);
            }
        };
        $player = Player::fromDecoder(decoder: $decoder, cellsW: 4, cellsH: 2, fps: 10.0, totalFrames: 5);

        $this->assertNull($player->frameAt(2.0), 'a dead peek answers null, never an exception at the RPC caller');
        $this->assertFalse($player->ended, 'a thumbnail read is advisory — it does not end the session');

        $written = (string) file_get_contents($log);
        $this->assertStringContainsString('frameAt', $written, 'the refusal is announced, not silent');
        $this->assertStringContainsString('peek died', $written);

        @unlink($log);
    }

}

/**
 * Spy AudioPlayer for F6 (audio realign on seek) testing.
 *
 * Records stop() call count and buildCommand() output without spawning any
 * real subprocess.
 */
final class SpyAudioPlayer extends AudioPlayer
{
    public int $stopCallCount = 0;

    public function __construct(string $videoPath, ?int $startMs = null)
    {
        // Don't call parent — parent starts the process on construct.
        // We override buildCommand to return [] so no subprocess spawns.
    }

    protected function buildCommand(): ?array
    {
        return []; // empty — we never actually run ffplay
    }

    public function start(): void
    {
        // Record but don't spawn
    }

    public function stop(): void
    {
        $this->stopCallCount++;
    }

    /**
     * @testdox M12: update() converts the typed decode failure into an ended [error] state
     */
    public function testATypedDecodeFailureBecomesAVisibleTerminalErrorState(): void
    {
        $decoder = new FailingDecoder();
        $player = Player::fromDecoder(
            decoder: $decoder,
            cellsW: 4,
            cellsH: 2,
            fps: 10.0,
            mode: Mode::Ascii,
            paused: false,
        );
        $this->assertNull($player->failure);

        [$failed, $cmd] = $player->update(new TickMsg());

        $this->assertNull($cmd, 'a crashed stream must schedule no follow-up tick');
        $this->assertTrue($failed->ended, 'the failure is terminal, like EOF');
        $this->assertNotNull($failed->failure);
        $this->assertStringContainsString('exited with code 7', (string) $failed->failure);
        $this->assertStringContainsString('[error]', $failed->view());
        $this->assertStringNotContainsString('[ended]', $failed->view(), 'an error must not masquerade as a clean end');
        $this->assertSame(1, $decoder->nextCount);
        $this->assertSame(1, $decoder->closeCount(), 'the failure path closes the dead decoder');

        [$again, $cmdAgain] = $failed->update(new TickMsg());
        $this->assertSame(1, $decoder->nextCount, 'the terminal guard must not re-pull a dead decoder');
        $this->assertNull($cmdAgain);
    }

    /**
     * @testdox M12: the failure text is swept of escape material before it reaches view()
     */
    public function testTheFailureTextCarriesNoEscapeMaterial(): void
    {
        $player = Player::fromDecoder(
            decoder: new FailingDecoder("\x1b[2Jpwned\x07"),
            cellsW: 4,
            cellsH: 2,
            fps: 10.0,
            mode: Mode::Ascii,
            paused: false,
        );
        [$failed, $cmd] = $player->update(new TickMsg());
        $this->assertNull($cmd);
        $this->assertStringContainsString('pwned', (string) $failed->failure);
        $this->assertStringNotContainsString("\x1b", (string) $failed->failure);
        $this->assertStringNotContainsString("\x07", (string) $failed->failure);
        $this->assertStringNotContainsString("\x1b[2J", $failed->view());
    }

    /**
     * @testdox M12: the update choke catches ONLY the typed decode failure
     */
    public function testTheUpdateChokeCatchesOnlyTheTypedDecodeFailure(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Player.php');
        $this->assertSame(1, substr_count($src, 'catch (FfmpegDecodeFailure'), 'exactly one choke point');
    }

    /**
     * @testdox M15: isStopped() reads the teardown latch and mutate() carries it
     */
    public function testStoppedIsObservableAndCarriedByDerivedInstances(): void
    {
        $player = Player::fromDecoder(decoder: new FakeDecoder([]), cellsW: 4, cellsH: 2, fps: 10.0);
        $this->assertFalse($player->isStopped());

        $player->stop();
        $this->assertTrue($player->isStopped());
        $this->assertTrue($player->pause()->isStopped(), 'derived instances own the same closed children');
    }

    /**
     * @testdox m11: a non-positive fps is refused at every public boundary
     */
    public function testNonPositiveFpsIsRefusedAtTheBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fps must be greater than zero');
        Player::open('/nonexistent.mp4', 80, 24, fpsOverride: 0.0);
    }

    public function testNonPositiveFpsIsRefusedByFromDecoder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fps must be greater than zero');
        Player::fromDecoder(decoder: new FakeDecoder([]), cellsW: 4, cellsH: 2, fps: -1.0);
    }

}
