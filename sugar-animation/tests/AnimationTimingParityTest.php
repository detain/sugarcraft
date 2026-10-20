<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Decoder;
use SugarCraft\Animation\Frame;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\TickMsg;

/**
 * E-04: one floor policy for zero/near-zero frame delays.
 *
 * The cross-convention table this pin stands on: chafa's chicle loader
 * rewrites every GIF delay of 0 to a fixed 50 ms
 * (tools/chafa/chicle-gif-loader.c:228-231); sugar-animation's Decoder
 * instead follows the GIF-spec marker and inherits the previous frame's
 * delay (default 10cs) — KEPT, parsed timing stays spec-honest; and the
 * Player floors every scheduled tick at Player::MIN_TICK_CS centiseconds
 * so a public-API Frame with delay 0 cannot arm a Cmd::tick(0.0) flood —
 * shipped. sugar-image's AnimationDriver floors its own loop at 10 ms for
 * the same hazard. The chicle 50 ms value was the rejected alternative:
 * baking a non-spec number into Frame::$delay would rewrite the value
 * Frame documents as "no delay specified", and the flood is a scheduling
 * property, so it is answered at the scheduler instead.
 *
 * Tick seconds are pinned through the same closure seam as
 * PlayerRateDocblockTest: the command returned by init()/update() is a
 * closure producing a tick request carrying ->seconds.
 */
final class AnimationTimingParityTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->temp = [];
    }

    /**
     * @param list<int> $delaysCs centiseconds, one synthetic frame per entry
     */
    private function playerFromDelays(array $delaysCs): Player
    {
        $frames = [];
        foreach ($delaysCs as $delay) {
            $frames[] = new Frame([[0, 0, 0]], $delay);
        }

        return new Player($frames);
    }

    private function floorSeconds(): float
    {
        $const = (new \ReflectionClass(Player::class))->getReflectionConstant('MIN_TICK_CS');
        self::assertNotNull($const, 'Player must own a tick floor constant');

        return $const->getValue() / 100.0;
    }

    public function testZeroDelayFrameArmsTheFlooredTickFromInit(): void
    {
        $closure = $this->playerFromDelays([0])->init();
        self::assertNotNull($closure);
        self::assertGreaterThan(0.0, $closure()->seconds, 'a zero delay must never schedule a 0-second flood');
        self::assertSame($this->floorSeconds(), $closure()->seconds);
    }

    public function testZeroDelayFrameArmsTheFlooredTickOnTheReschedulePath(): void
    {
        [$player, $closure] = $this->playerFromDelays([0, 0])->update(new TickMsg());
        self::assertNotNull($closure, 'playing player must re-arm after a tick');
        self::assertSame($this->floorSeconds(), $closure()->seconds,
            'update() re-arms from the CURRENT frame (delay 0) — the floor must hold there too');
        self::assertSame($this->floorSeconds(), $player->init()()->seconds,
            'the advanced frame is also delay 0 and stays floored');
    }

    public function testPositiveDelaysPassThroughTheFloorUnchanged(): void
    {
        $player = $this->playerFromDelays([0, 30]);
        // init() arms off the CURRENT (index 0, delay 0) frame — floored;
        // E-03's schedule arms off the frame the tick ARRIVES on (index 1,
        // 30cs): the floor is a minimum, never a rewrite of a real delay,
        // and it holds on both seams.
        [, $closure] = $player->update(new TickMsg());
        self::assertNotNull($closure);
        self::assertSame(30 / 100.0, $closure()->seconds);
        $closure2 = $player->update(new TickMsg())[0]->init();
        self::assertNotNull($closure2);
        self::assertSame(30 / 100.0, $closure2()->seconds);
    }

    public function testDecoderKeepsInheritPreviousSemanticsUnfloored(): void
    {
        if (extension_loaded('gd') === false) {
            self::markTestSkipped('ext-gd not available');
        }
        // Frame 2 declares delay 0 and must inherit frame 1's 20cs VERBATIM
        // at the Decoder level — the floor belongs to the Player, parsed
        // timing keeps the GIF-spec meaning (chicle's fixed 50 ms rewrite
        // rejected, see class docblock).  Leading zero inherits the 10cs
        // default.
        self::assertSame([20, 20], $this->decodedDelays(20, 0));
        self::assertSame([10, 5], $this->decodedDelays(0, 5));
    }

    /**
     * @return list<int>
     */
    private function decodedDelays(int $delay1, int $delay2): array
    {
        $gif = "\x47\x49\x46\x38\x39\x61"                        // GIF89a
            . "\x01\x00\x01\x00\x80\x00\x00"                     // 1x1 screen; packed 0x80 = GCT flag, size 0 → 2 ENTRIES
            . "\x00\x00\x00\xff\x00\x00\x00\x00\x00\x00\x00\x00" // black, red (the two live entries) + 6 slack bytes the tolerant byte-walk steps over
            . "\x21\xF9\x04\x00" . chr($delay1 & 0xFF) . chr(($delay1 >> 8) & 0xFF) . "\x00\x00"
            . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00"
            . "\x02\x03\x04\x01\x05\x00"                         // LZW: clear, index 1, EOI, terminator
            . "\x21\xF9\x04\x00" . chr($delay2 & 0xFF) . chr(($delay2 >> 8) & 0xFF) . "\x00\x00"
            . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00"
            . "\x02\x03\x04\x01\x05\x00"
            . "\x3B";

        $path = sys_get_temp_dir() . '/flip-e04-' . uniqid('', true) . '.gif';
        file_put_contents($path, $gif);
        $this->temp[] = $path;

        return array_map(
            static fn (Frame $frame): int => $frame->delay,
            Decoder::decode($path, 1, 1),
        );
    }
}
