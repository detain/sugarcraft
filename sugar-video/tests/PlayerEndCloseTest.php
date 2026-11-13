<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Player;

/**
 * SV-L91 — the parked end state must not keep the decoder alive
 * (sugar-video-091).
 *
 * Non-loop end-of-stream stopped the audio but left the ffmpeg decoder (and
 * its /tmp/sugar-video-ffmpeg-* stderr temp) open for the whole lifetime of
 * the frozen end view. The F21 house law closes it on the spot; the model
 * keeps the last frame so the view still paints.
 */
final class PlayerEndCloseTest extends TestCase
{
    public function testTheParkedEndStateClosesTheDecoderAndKeepsTheLastFrame(): void
    {
        $t = 0.0;
        $clock = static function () use (&$t): float {
            return $t;
        };
        $decoder = new FakeDecoder([new RgbFrame(str_repeat(chr(9), 10 * 12 * 3), 10, 12)]);
        $player = Player::openForTest(
            $decoder,
            24.0,
            totalFrames: 1,
            cellsW: 10,
            cellsH: 6,
            loop: false,
            paused: false,
            clock: $clock,
        );
        self::assertSame(0, $decoder->closeCount());

        $ticks = 0;
        while (!$player->ended && $ticks < 10) {
            $t += 1.0;
            [$player] = $player->update(new TickMsg());
            $ticks++;
        }

        self::assertTrue($player->ended);
        self::assertSame(1, $decoder->closeCount(), 'the end branch closes the child it no longer needs');
        self::assertNotNull($player->currentFrame, 'the last frame stays in the model');
        self::assertStringContainsString('[ended]', $player->view(), 'the parked view still paints frame + status row');

        // Idempotence contract: stop() re-runs close() on the dead session.
        $player->stop();
        self::assertSame(2, $decoder->closeCount(), 'a second close is safe (idempotent handle tear)');
    }

    public function testTheLoopPathStillRebuildsInsteadOfClosingIntoTheVoid(): void
    {
        $t = 0.0;
        $clock = static function () use (&$t): float {
            return $t;
        };
        $decoder = new FakeDecoder([new RgbFrame(str_repeat(chr(9), 10 * 12 * 3), 10, 12)]);
        $player = Player::openForTest(
            $decoder,
            24.0,
            totalFrames: 1,
            cellsW: 10,
            cellsH: 6,
            loop: true,
            paused: false,
            clock: $clock,
        );

        $reopensBefore = $decoder->reopenCount();
        for ($i = 0; $i < 3; $i++) {
            $t += 1.0;
            [$player] = $player->update(new TickMsg()); // paint, then exhaustion -> loop restart
        }

        self::assertFalse($player->ended, 'the loop restarts the pass');
        self::assertSame(0, $decoder->closeCount(), 'the loop path must not close what it is about to reopen');
        self::assertGreaterThan($reopensBefore, $decoder->reopenCount(), 'the loop walks the reopen path');
    }

    /**
     * SV-L92/092 — the stopped latch covers play()/pause() too. stop()
     * declares the instance dead and the tick/resize paths refuse it, but
     * play() used to resume() the audio companion into a fresh respawned
     * child: music without video, on a stream whose decoder is closed.
     */
    public function testPlayAndPauseCannotResurrectATornDownStream(): void
    {
        $audio = new class('/fake.mp4') extends \SugarCraft\Video\AudioPlayer {
            public int $resumeCount = 0;
            public int $pauseCount = 0;

            public function resume(): void
            {
                $this->resumeCount++;
            }

            public function pause(): void
            {
                $this->pauseCount++;
            }

            public function start(): void
            {
            }

            public function stop(): void
            {
            }
        };
        $decoder = new FakeDecoder([new RgbFrame(str_repeat(chr(9), 10 * 12 * 3), 10, 12)]);
        $player = Player::openForTest($decoder, 24.0, totalFrames: 1, cellsW: 10, cellsH: 6, audioPlayer: $audio);

        $live = $player->play();
        self::assertNotSame($player, $live, 'a live player still resumes through play()');
        self::assertSame(1, $audio->resumeCount);

        $live->stop();

        self::assertSame($live, $live->play(), 'the stopped latch answers with the stopped instance');
        self::assertSame($live, $live->pause());
        self::assertSame(1, $audio->resumeCount, 'a torn-down stream respawns no audio child');
        self::assertSame(0, $audio->pauseCount, 'and pauses nothing further');
        self::assertTrue($live->play()->isStopped());
    }
}
