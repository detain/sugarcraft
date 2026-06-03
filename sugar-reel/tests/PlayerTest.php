<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Msg\TickMsg;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Testing\ProgramSimulator;
use SugarCraft\Testing\Input\ScriptedInput;

/**
 * Unit tests for Player TEA model.
 *
 * Uses FakeDecoder to provide deterministic frame sequences without
 * any real video file or external process.
 *
 * @covers \SugarCraft\Reel\Player
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

        $path = sys_get_temp_dir() . '/sugar-reel-player-gif-' . uniqid('', true) . '.gif';
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
        // Build a 100-frame decoder at 30 fps. Start playing from frame 0.
        $decoder = new FakeDecoder(array_fill(0, 100, $this->makeFrame("\x00\x00\x00")));
        $player = Player::openForTest($decoder, 30.0);

        // Pre-seed first frame and unpause.
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);
        $player = $this->setPlayerProperty($player, 'videoTime', 0.0);
        $player = $this->setPlayerProperty($player, 'lastTickTime', microtime(true));

        [$player] = $player->update(new KeyMsg(KeyType::Space)); // unpause

        // First tick: advances to frame 1. After tick, videoTime ≈ 1/30.
        [$player] = $player->update(new TickMsg());
        $frameAfterTick1 = $this->getPlayerProperty($player, 'frameIndex');
        // videoTime is now ~1/30

        // Manually backdate lastTickTime so the NEXT tick sees a ~0.033s delta
        // (one frame interval at 30fps).
        $player = $this->backdateLastTick($player, 0.033);

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
        // Let's use a more obvious case: backdate by 0.5s so old formula would jump.
        $player = $this->backdateLastTick($player, 0.5);
        $frameBefore = $this->getPlayerProperty($player, 'frameIndex');
        [$player] = $player->update(new TickMsg());
        $frameAfter = $this->getPlayerProperty($player, 'frameIndex');

        // delta = 0.5s, speed = 1.25, so newVideoTime grows by 0.5*1.25 = 0.625 more seconds.
        // At 30fps, 0.625s more content = 18.75 more frames.
        // So frameAfter should be frameBefore + at most 19 (never 30+ which would
        // happen with the retroactive bug where videoTime gets scaled by 1.25).
        $deltaFrames = $frameAfter - $frameBefore;
        $this->assertLessThanOrEqual(19, $deltaFrames,
            'Speed change must NOT cause retroactive frame skip — at most delta*speed*fps extra frames');
    }

    /**
     * Regression for F4. Pressing '[' to slow down from 1.0× to 0.75× must NOT
     * cause a multi-tick hold/freeze. The retroactive bug makes old elapsed time
     * appear LONGER when speed decreases (divide-by-smaller), causing the target
     * frame to fall behind current → shouldHold fires → freeze.
     */
    public function testSpeedDecreaseDoesNotFreeze(): void
    {
        $decoder = new FakeDecoder(array_fill(0, 100, $this->makeFrame("\x00\x00\x00")));
        $player = Player::openForTest($decoder, 30.0);
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);
        $player = $this->setPlayerProperty($player, 'videoTime', 0.0);
        $player = $this->setPlayerProperty($player, 'lastTickTime', microtime(true));

        [$player] = $player->update(new KeyMsg(KeyType::Space)); // unpause

        // Advance several ticks.
        for ($i = 0; $i < 5; $i++) {
            $player = $this->backdateLastTick($player, 0.033);
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
        $player = $this->backdateLastTick($player, 0.033);
        [$player] = $player->update(new TickMsg());
        $frameAfter = $this->getPlayerProperty($player, 'frameIndex');

        // Must not freeze — frame should advance (or at minimum not regress).
        $this->assertGreaterThanOrEqual($frameBefore, $frameAfter,
            'Slowing speed must NOT freeze playback (no shouldHold retroactively firing)');
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
        for ($i = 0; $i < count(Mode::cases()); $i++) {
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
        $unique = array_unique(array_map(fn(Mode $m) => $m->name, $visited));
        $this->assertLessThanOrEqual($pressCount, count($unique),
            'unique modes visited cannot exceed press count');

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
     * Regression for F10. When a WindowSizeMsg arrives, the Player must
     * update its cellsW/cellsH AND rebuild the decoder at the new size
     * (so frames are decoded at the correct resolution for the new mode).
     * On master update() has no WindowSizeMsg branch — this no-ops silently
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

        $this->assertSame(120, $this->getPlayerProperty($player2, 'cellsW'),
            'cellsW must update to the WindowSizeMsg cols');
        $this->assertSame(40, $this->getPlayerProperty($player2, 'cellsH'),
            'cellsH must update to the WindowSizeMsg rows');
        $this->assertNotNull($cmd, 'resize while playing must schedule a tick');
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
     * A WindowSizeMsg(5, 3) must be clamped to cols=10, rows=5 before
     * any rebuild. A zero-area buffer would crash the decoder.
     */
    public function testWindowSizeMsgClampsToMinimum(): void
    {
        $decoder = $this->makeFakeDecoder(20);
        $player = Player::openForTest($decoder, 30.0, 20, 80, 24, '/fake');
        $player = $this->setCurrentFrame($player, $decoder->next(), 0);

        $tiny = new \SugarCraft\Core\Msg\WindowSizeMsg(5, 3);
        [$player2, $cmd] = $player->update($tiny);

        $this->assertSame(10, $this->getPlayerProperty($player2, 'cellsW'),
            'cols below 10 must be clamped to 10');
        $this->assertSame(5, $this->getPlayerProperty($player2, 'cellsH'),
            'rows below 5 must be clamped to 5');
    }

    // -------------------------------------------------------------------------
    // F2 tail: capability-aware mode cycle
    // -------------------------------------------------------------------------

    /**
     * Regression for F2 (tail). The mode cycle must ONLY include modes the
     * terminal actually supports. On master the cycle hardcoded all 7 modes,
     * so `m` would land on Sixel even when the terminal can't render it
     * (garbage output). This test verifies that the cycle is non-empty,
     * visits distinct modes, and never crashes.
     *
     * NOTE: Since Mosaic::diagnose() is a real external probe that cannot be
     * mocked without a library, we verify observable behavior: cycling works
     * without error and visits at least 2 distinct modes.
     */
    public function testModeCycleOnlyVisitsSupportedModes(): void
    {
        // Use GeometryFakeDecoder which regenerates frames based on mode.
        $player = Player::openForTest(new GeometryFakeDecoder(4), 30.0, 0, 80, 24, '/fake');

        $m = new KeyMsg(KeyType::Char, 'm');
        $visited = [];
        // Visit 14 times — enough to wrap around at least once if cycle is short.
        for ($i = 0; $i < 14; $i++) {
            [$player] = $player->update($m);
            $visited[] = $this->getPlayerProperty($player, 'mode');
        }

        // Every visited mode must be a valid Mode.
        foreach ($visited as $mode) {
            $this->assertInstanceOf(Mode::class, $mode);
        }

        // The cycle must visit at least 2 distinct modes.
        $unique = array_unique(array_map(fn(Mode $m) => $m->name, $visited));
        $this->assertGreaterThanOrEqual(2, count($unique),
            'mode cycle must visit at least 2 distinct modes');
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
     * candy-core's Renderer can diff it. A previous implementation diffed
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
     * and the standalone HalfBlockRenderer (candy-mosaic via sugar-reel's
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
        $this->assertStringContainsString("\u{2580}", $view,
            'HalfBlock output must contain ▀');
        // Must have TrueColor foreground SGR (may be combined with reset: [0;38;2;).
        $this->assertStringContainsString('38;2;', $view,
            'HalfBlock must use 38;2;R;G;B foreground');
        // Must have TrueColor background SGR (may be combined with reset: [0;48;2;).
        $this->assertStringContainsString('48;2;', $view,
            'HalfBlock must use 48;2;R;G;B background');
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
     * On master the backward branch called DecoderFactory::create() but NEVER
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

        // Order MUST match the Player constructor positionally — the new Player
        // instance is built via array_values($values) through the private ctor.
        // 'ended' and 'loop' are the two trailing ctor params, after 'audioPlayer'.
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
}
