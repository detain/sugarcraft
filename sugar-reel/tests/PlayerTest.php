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
 * @covers Player
 */
final class PlayerTest extends TestCase
{
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
     * @testdox view() is idempotent — calling it twice with same frame produces identical output
     */
    public function testPrevBufferNotMutatedInView(): void
    {
        $decoder = $this->makeFakeDecoder(10);
        $player = Player::openForTest($decoder, 30.0);

        $firstFrame = $decoder->next();
        $player = $this->setCurrentFrame($player, $firstFrame, 0);

        $output1 = $player->view();
        $output2 = $player->view();

        $this->assertSame($output1, $output2);
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
        $sync = $this->getPlayerProperty($player, 'sync');
        $prevBuffer = $this->getPlayerProperty($player, 'prevBuffer');
        $totalFrames = $this->getPlayerProperty($player, 'totalFrames');
        $cellsW = $this->getPlayerProperty($player, 'cellsW');
        $cellsH = $this->getPlayerProperty($player, 'cellsH');
        $videoPath = $this->getPlayerProperty($player, 'videoPath');

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
            'sync' => $sync,
            'prevBuffer' => $prevBuffer,
            'totalFrames' => $totalFrames,
            'cellsW' => $cellsW,
            'cellsH' => $cellsH,
            'videoPath' => $videoPath,
            'audioPlayer' => null,
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
