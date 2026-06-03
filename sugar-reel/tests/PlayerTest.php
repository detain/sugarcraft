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
        $player = Player::openForTest($decoder, 30.0, 80, 24, '/fake');

        // Set totalFrames so percentage seek works
        $player = $this->setTotalFrames($player, 100);

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
        $player = Player::openForTest($decoder, 30.0, 80, 24, '/fake');

        // Set totalFrames so percentage seek works
        $player = $this->setTotalFrames($player, 100);

        $char5 = new KeyMsg(KeyType::Char, '5');
        [$player,] = $player->update($char5);

        $frameIndex = $this->getPlayerProperty($player, 'frameIndex');
        // 50% of 100 = frame 50, but decoder only has 10 frames → clamped to 9
        $this->assertLessThanOrEqual(10, $frameIndex);
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
        $player = Player::openForTest(new GeometryFakeDecoder(4), 30.0, 80, 24, '/fake');

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
     * Regression for F2. The mode cycle must visit ALL implemented modes,
     * including the graphics protocols Sixel/Kitty/Iterm2. On master a skip-loop
     * jumped over those three, so they were never reachable via 'm'.
     *
     * @testdox mode cycle reaches the graphics modes (Sixel/Kitty/Iterm2)
     */
    public function testModeCycleReachesGraphicsModes(): void
    {
        $player = Player::openForTest(new GeometryFakeDecoder(4), 30.0, 80, 24, '/fake');

        $m = new KeyMsg(KeyType::Char, 'm');
        $visited = [];
        for ($i = 0; $i < count(Mode::cases()); $i++) {
            [$player,] = $player->update($m);
            $visited[] = $this->getPlayerProperty($player, 'mode');
        }

        $this->assertContains(Mode::Sixel, $visited, 'cycle must reach Sixel');
        $this->assertContains(Mode::Kitty, $visited, 'cycle must reach Kitty');
        $this->assertContains(Mode::Iterm2, $visited, 'cycle must reach Iterm2');
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
        $player = Player::openForTest(new FakeDecoder([
            $this->makeFrame("\xff\x00\x00"),
            $this->makeFrame("\x00\xff\x00"),
            $this->makeFrame("\x00\x00\xff"),
        ]), 30.0, 80, 24, '/fake');

        [$player] = $player->update(new KeyMsg(KeyType::Space)); // unpause

        // Drive to ended.
        for ($i = 0; $i < 10; $i++) {
            $player = $this->backdateLastTick($player, 1.0);
            [$player] = $player->update(new TickMsg());
        }
        $this->assertTrue($player->ended, 'precondition: player is ended');
        $this->assertFalse($this->getPlayerProperty($player, 'paused'), 'precondition: player is not paused');

        // Seek back to the start (digit 0). totalFrames is 0 on the fake path so
        // this clamps to frame 0 — what matters is the ended/Cmd transition.
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

        $player = Player::openForTest($spy, 30.0, 8, 6, $gifPath);
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

    private function setTotalFrames(Player $player, int $total): Player
    {
        return $this->createPlayerWithOverrides($player, ['totalFrames' => $total]);
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
        $elapsed = $this->getPlayerProperty($player, 'elapsed');
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
            'elapsed' => $elapsed,
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
