<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Player;

/**
 * SV-L86 — the frameAt() thumbnail memo (sugar-video-086).
 *
 * Scrubbing hovers emit frameAt() per mouse event. Every call used to cost a
 * throwaway decoder walk — two reopens for in-place decoders, one full
 * throwaway ffmpeg spawn for factory players. The memo answers one rounded
 * second with one walk, and the monotonic spawn floor keeps a bucket-crossing
 * burst from turning hover events into process churn.
 */
final class PlayerFrameAtMemoTest extends TestCase
{
    public function testOneWalkPerSecondBucketAcrossAScrubBurst(): void
    {
        $t = 0.0;
        $clock = static function () use (&$t): float {
            return $t;
        };
        $decoder = new FakeDecoder([new RgbFrame(str_repeat(chr(9), 10 * 12 * 3), 10, 12)]);
        $player = Player::openForTest($decoder, 24.0, cellsW: 10, cellsH: 6, clock: $clock);

        $first = $decoder->reopenCount();
        $a = $player->frameAt(3.2);
        $b = $player->frameAt(3.5);
        $c = $player->frameAt(3.999);

        self::assertNotNull($a);
        self::assertSame($a, $b, 'the same bucket answers from the memo object');
        self::assertSame($a, $c);
        self::assertSame($first + 2, $decoder->reopenCount(), 'one bucket costs exactly one thumbnail read (thumb + playhead restore)');

        // Crossing the bucket after the floor spawns the next walk.
        $t = 1.5;
        $d = $player->frameAt(4.1);
        self::assertSame($first + 4, $decoder->reopenCount(), 'a crossed bucket walks again');

        // Crossing again WITHIN the floor serves the last thumbnail — no walk,
        // even though the bucket differs.
        $t = 1.9;
        $e = $player->frameAt(5.9);
        self::assertSame($d, $e, 'mid-burst bucket crosses answer from the memo');
        self::assertSame($first + 4, $decoder->reopenCount());

        // Once the floor elapses, the hovered bucket gets its own walk.
        $t = 3.0;
        $player->frameAt(5.9);
        self::assertSame($first + 6, $decoder->reopenCount(), 'the spawn floor is a rate, not a denial');
    }

    public function testAGeometryChangeInvalidatesTheMemo(): void
    {
        $t = 0.0;
        $clock = static function () use (&$t): float {
            return $t;
        };
        $decoder = new FakeDecoder([new RgbFrame(str_repeat(chr(9), 10 * 12 * 3), 10, 12)]);
        $player = Player::openForTest($decoder, 24.0, cellsW: 10, cellsH: 6, clock: $clock);

        $first = $decoder->reopenCount();
        $player->frameAt(3.2);
        self::assertSame($first + 2, $decoder->reopenCount());

        // Same bucket, same instant — but the pane geometry changed, so the
        // memoized thumbnail is the wrong picture and must not be served.
        $mutate = new \ReflectionMethod($player, 'mutate');
        /** @var Player $rebuilt */
        $rebuilt = $mutate->invoke($player, ['cellsW' => 20]);
        $rebuilt->frameAt(3.2);

        self::assertSame($first + 4, $decoder->reopenCount(), 'a stale-geometry memo is void, not a saving');
    }

    public function testTheWalkLivesBehindTheMemoGate(): void
    {
        // Structural: the throwaway-spawn and reopen-walk bodies must stay in
        // frameAtUncached — if a future edit inlines either back into
        // frameAt(), the throttle silently stops guarding it.
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Player.php');
        $at = (int) strpos($src, 'public function frameAt(');
        self::assertGreaterThan(0, $at);
        $body = substr($src, $at, (int) strpos($src, 'private function frameAtUncached(', $at) - $at);
        self::assertStringNotContainsString('DecoderFactory::new(', $body, 'no throwaway spawn may bypass the memo');
        self::assertStringNotContainsString('->reopen(', $body, 'no walk may bypass the memo');
        self::assertSame(2, substr_count($src, 'frameAtUncached('), 'one definition, one caller');
    }
}
