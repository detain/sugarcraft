<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Tests\Concerns\DrivesPlayerInternals;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Msg\TickMsg;
use SugarCraft\Reel\Subtitle\WebVtt;

/**
 * Semantic contract tests for `Player::mutate()` (finding #8).
 *
 * The helper used to read `$changes['k'] ?? $this->k`, which conflated "the caller
 * passed nothing" with "the caller passed null" and left falsy state (`false`, `0`,
 * `0.0`) surviving only by operator accident. These tests pin the replacement
 * `array_key_exists()` semantics from both sides: presence wins for every falsy
 * value, an omitted key preserves the current value, an explicit null clears a
 * nullable field, and the three source-identity fields cannot be changed at all.
 *
 * Every assertion here is written so that flipping `array_key_exists()` back to
 * `??` (or dropping a field from the map) turns it RED and names the field.
 *
 * @covers \SugarCraft\Reel\Player
 */
final class PlayerMutateTest extends TestCase
{
    use DrivesPlayerInternals;

    private function player(?Decoder $decoder = null): Player
    {
        return Player::fromDecoder(
            $decoder ?? new FakeDecoder([self::red(), self::green()]),
            cellsW: 20,
            cellsH: 10,
            fps: 24.0,
            mode: Mode::TrueColor,
            totalFrames: 2,
        );
    }

    private static function red(): RgbFrame
    {
        return new RgbFrame("\xFF\x00\x00", 1, 1);
    }

    private static function green(): RgbFrame
    {
        return new RgbFrame("\x00\xFF\x00", 1, 1);
    }


    // -------------------------------------------------------------------------
    // Falsy-but-present values must pass through
    // -------------------------------------------------------------------------

    public function testFalsyEndedAndFrameIndexPassThrough(): void
    {
        $armed = $this->mutatePlayer($this->player(), ['ended' => true, 'frameIndex' => 7]);
        self::assertTrue($armed->ended, 'precondition: the field must start truthy');
        self::assertSame(7, $armed->frameIndex, 'precondition: the field must start truthy');

        $cleared = $this->mutatePlayer($armed, ['ended' => false, 'frameIndex' => 0]);

        self::assertFalse($cleared->ended, "mutate(['ended' => false]) must clear the end-of-stream flag");
        self::assertSame(0, $cleared->frameIndex, "mutate(['frameIndex' => 0]) must rewind to frame 0");
    }

    public function testEveryFalsyScalarPassesThrough(): void
    {
        $armed = $this->mutatePlayer($this->player(), [
            'ended' => true,
            'frameIndex' => 9,
            'videoTime' => 3.5,
            'paused' => true,
            'speed' => 2.0,
            'totalFrames' => 120,
            'cellsW' => 40,
            'cellsH' => 12,
            'cellPxW' => 8,
            'cellPxH' => 16,
            'loop' => true,
        ]);

        $falsy = $this->mutatePlayer($armed, [
            'ended' => false,
            'frameIndex' => 0,
            'videoTime' => 0.0,
            'paused' => false,
            'speed' => 0.0,
            'totalFrames' => 0,
            'cellsW' => 0,
            'cellsH' => 0,
            'cellPxW' => 0,
            'cellPxH' => 0,
            'loop' => false,
        ]);

        self::assertFalse($falsy->ended);
        self::assertSame(0, $falsy->frameIndex);
        self::assertSame(0.0, $falsy->videoTime);
        self::assertFalse($falsy->paused);
        self::assertSame(0.0, $falsy->speed);
        self::assertSame(0, $falsy->totalFrames);
        self::assertSame(0, $falsy->cellsW);
        self::assertSame(0, $falsy->cellsH);
        self::assertSame(0, $falsy->cellPxW);
        self::assertSame(0, $falsy->cellPxH);
        self::assertFalse($this->playerProperty($falsy, 'loop'), "mutate(['loop' => false]) must disable looping");
    }

    public function testEmptyStringAndEmptyArrayPassThrough(): void
    {
        $armed = $this->mutatePlayer($this->player(), ['ramp' => 'dense']);
        self::assertSame('dense', $this->playerProperty($armed, 'ramp'));

        $emptied = $this->mutatePlayer($armed, ['ramp' => '']);
        self::assertSame('', $this->playerProperty($emptied, 'ramp'), "mutate(['ramp' => '']) must apply the empty ramp name");

        $frame = $this->mutatePlayer($armed, ['currentFrame' => new RgbFrame('', 0, 0)]);
        self::assertSame('', $this->playerProperty($frame, 'currentFrame')->bytes, 'an empty-bytes frame must be stored as given');
    }

    // -------------------------------------------------------------------------
    // Omitted keys preserve the current value
    // -------------------------------------------------------------------------

    public function testOmittedKeysPreserveCurrentValue(): void
    {
        $armed = $this->mutatePlayer($this->player(), ['ended' => true, 'frameIndex' => 4]);

        $untouched = $this->mutatePlayer($armed, ['speed' => 1.5]);

        self::assertTrue($untouched->ended, 'an omitted `ended` key must preserve the flag');
        self::assertSame(4, $untouched->frameIndex, 'an omitted `frameIndex` key must preserve the index');
        self::assertSame(1.5, $untouched->speed, 'the changed key must apply');
    }

    // -------------------------------------------------------------------------
    // Explicit null clears a nullable field (the case `??` could not express)
    // -------------------------------------------------------------------------

    public function testExplicitNullClearsNullableFields(): void
    {
        $frame = self::red();
        $armed = $this->mutatePlayer($this->player(), [
            'currentFrame' => $frame,
            'subtitles' => WebVtt::parse("WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nhi\n"),
            'audioFactory' => static fn (string $p, ?int $ms = null) => null,
            'renderer' => null,
        ]);

        self::assertSame($frame, $this->playerProperty($armed, 'currentFrame'), 'precondition: currentFrame must be set');
        self::assertNotNull($this->playerProperty($armed, 'subtitles'), 'precondition: subtitles must be set');

        $cleared = $this->mutatePlayer($armed, [
            'currentFrame' => null,
            'subtitles' => null,
            'audioFactory' => null,
        ]);

        self::assertNull($this->playerProperty($cleared, 'currentFrame'), "mutate(['currentFrame' => null]) must clear the frame");
        self::assertNull($this->playerProperty($cleared, 'subtitles'), "mutate(['subtitles' => null]) must clear the track");
        self::assertNull($this->playerProperty($cleared, 'audioFactory'), "mutate(['audioFactory' => null]) must clear the factory");
        self::assertNull($this->playerProperty($cleared, 'renderer'), 'the renderer key must apply as given');
    }

    // -------------------------------------------------------------------------
    // Immutability of the source + pinned fields
    // -------------------------------------------------------------------------

    public function testPinnedFieldsIgnoreChanges(): void
    {
        $player = $this->player();
        $original = [
            'videoPath' => $this->playerProperty($player, 'videoPath'),
            'headers' => $this->playerProperty($player, 'headers'),
            'frameBudgetMs' => $this->playerProperty($player, 'frameBudgetMs'),
        ];

        $rebuilt = $this->mutatePlayer($player, [
            'videoPath' => '/etc/passwd',
            'headers' => ['Authorization' => 'Bearer stolen'],
            'frameBudgetMs' => 999.0,
            'mode' => Mode::Ascii,
        ]);

        self::assertSame($original['videoPath'], $this->playerProperty($rebuilt, 'videoPath'), 'videoPath is immutable for the player lifetime');
        self::assertSame($original['headers'], $this->playerProperty($rebuilt, 'headers'), 'the source headers outlive any rebuild');
        self::assertSame($original['frameBudgetMs'], $this->playerProperty($rebuilt, 'frameBudgetMs'), 'the host decode budget is not playback state');
        self::assertSame(Mode::Ascii, $rebuilt->mode, 'a non-pinned key must still apply');
    }

    public function testMutateReturnsANewInstanceAndLeavesTheSourceAlone(): void
    {
        $player = $this->player();
        $changed = $this->mutatePlayer($player, ['ended' => true, 'frameIndex' => 3]);

        self::assertNotSame($player, $changed);
        self::assertFalse($player->ended, 'mutate() must not mutate the receiver');
        self::assertSame(0, $player->frameIndex);
        self::assertSame($player->decoder, $changed->decoder, 'the decoder must be carried, not re-created');
    }

    // -------------------------------------------------------------------------
    // The real update paths that depend on falsy pass-through
    // -------------------------------------------------------------------------

    public function testBackwardSeekFromEndedStateRestartsFalsyValues(): void
    {
        $ended = $this->mutatePlayer($this->player(), ['ended' => true, 'frameIndex' => 2]);

        $seeked = $ended->withSeek(0);

        self::assertFalse($seeked->ended, 'a seek back to the head must clear the end-of-stream flag');
        self::assertSame(0, $seeked->frameIndex, 'a seek to frame 0 must land on frame 0, not stay at 2');
    }

    public function testSeekToSecondsZeroClearsFalsyValues(): void
    {
        $armed = $this->mutatePlayer($this->player(), ['ended' => true, 'frameIndex' => 2, 'videoTime' => 9.0]);

        $seeked = $armed->seekToSeconds(0.0);

        self::assertFalse($seeked->ended);
        self::assertSame(0, $seeked->frameIndex);
        self::assertSame(0.0, $seeked->videoTime);
    }

    public function testLoopingEndOfStreamWrapsFalsyValuesThroughTheRealTickPath(): void
    {
        $decoder = new FakeDecoder([self::red()]);
        $player = Player::fromDecoder(
            $decoder,
            cellsW: 20,
            cellsH: 10,
            fps: 24.0,
            mode: Mode::TrueColor,
            totalFrames: 1,
            loop: true,
            paused: false,
        );
        $player = $this->mutatePlayer($player, ['lastTickTime' => \microtime(true) - 10.0, 'frameIndex' => 1, 'ended' => true]);

        [$next, $cmd] = $player->update(new TickMsg());

        self::assertInstanceOf(Player::class, $next);
        self::assertFalse($next->ended, 'wrapping to the head must un-end the player');
        self::assertSame(0, $next->frameIndex, 'wrapping must rewind to frame 0');
        self::assertNotNull($cmd, 'a looping player keeps its tick chain alive');
    }
}
