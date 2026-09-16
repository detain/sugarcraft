<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Input;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Vcr\Input\MouseModeTracker;
use SugarCraft\Vcr\Input\MouseReplayDecoder;

final class MouseReplayDecoderTest extends TestCase
{
    private static function trackerWith(int ...$modes): MouseModeTracker
    {
        $tracker = new MouseModeTracker();
        $sequence = implode('', array_map(static fn (int $m): string => ";{$m}", $modes));
        $tracker->observe("\x1b[?" . ltrim($sequence, ';') . 'h');

        return $tracker;
    }

    public function testX10PressIsDecodedWhenTrackingModeIsOn(): void
    {
        $msg = MouseReplayDecoder::decode("\x1b[M\x20\x21\x22", self::trackerWith(1000));

        self::assertInstanceOf(MouseClickMsg::class, $msg);
        self::assertSame(1, $msg->x);
        self::assertSame(2, $msg->y);
        self::assertSame(MouseButton::Left, $msg->button);
        self::assertSame(MouseAction::Press, $msg->action);
    }

    public function testX10BytesAreIgnoredWhenNoTrackingModeIsOn(): void
    {
        // Nothing enabled: guessing an encoding is exactly the bug this
        // decoder removes, so the bytes defer to the fallback parser.
        self::assertNull(MouseReplayDecoder::decode("\x1b[M\x20\x21\x22", new MouseModeTracker()));
    }

    /**
     * @param class-string<\SugarCraft\Core\Msg> $class
     */
    #[DataProvider('legacyButtonBitsProvider')]
    public function testButtonBitSemanticsMirrorInputReader(int $buttonCode, string $class, MouseButton $button, MouseAction $action): void
    {
        $bytes = "\x1b[M" . chr(32 + $buttonCode) . "\x25\x26";
        $msg = MouseReplayDecoder::decode($bytes, self::trackerWith(1003));

        self::assertInstanceOf($class, $msg);
        self::assertSame($button, $msg->button);
        self::assertSame($action, $msg->action);
        self::assertSame(5, $msg->x);
        self::assertSame(6, $msg->y);
    }

    /**
     * @return array<string, array{int, class-string<\SugarCraft\Core\Msg>, MouseButton, MouseAction}>
     */
    public static function legacyButtonBitsProvider(): array
    {
        return [
            'motion with middle held' => [0x20 | 1, MouseMotionMsg::class, MouseButton::Middle, MouseAction::Motion],
            'release' => [3, MouseReleaseMsg::class, MouseButton::None, MouseAction::Release],
            'wheel up' => [0x40, MouseWheelMsg::class, MouseButton::WheelUp, MouseAction::Press],
            'wheel down' => [0x41, MouseWheelMsg::class, MouseButton::WheelDown, MouseAction::Press],
            'extra forward' => [0x81, MouseClickMsg::class, MouseButton::Forward, MouseAction::Press],
            'right press' => [2, MouseClickMsg::class, MouseButton::Right, MouseAction::Press],
        ];
    }

    public function testModifierBitsAreCarried(): void
    {
        $bytes = "\x1b[M" . chr(32 + (0x04 | 0x08 | 0x10)) . "\x21\x21";
        $msg = MouseReplayDecoder::decode($bytes, self::trackerWith(1000));

        self::assertInstanceOf(MouseMsg::class, $msg);
        self::assertTrue($msg->shift);
        self::assertTrue($msg->alt);
        self::assertTrue($msg->ctrl);
    }

    public function testUtf8CoordinatesWhenMode1005IsOn(): void
    {
        // xterm's 1005 form: each coordinate is the UTF-8 encoding of
        // (coord + 32), so 1000 → U+0408 → 0xD0 0x88 and 200 → U+00E8 → 0xC3 0xA8.
        $bytes = "\x1b[M\x20\xD0\x88\xC3\xA8";
        $msg = MouseReplayDecoder::decode($bytes, self::trackerWith(1000, 1005));

        self::assertInstanceOf(MouseMsg::class, $msg);
        self::assertSame(1000, $msg->x);
        self::assertSame(200, $msg->y);
    }

    public function testUtf8CoordinatesRequireExactPayload(): void
    {
        $bytes = "\x1b[M\x20\xE0\x90\xA8\xC3\xA8\x41"; // trailing printable
        self::assertNull(MouseReplayDecoder::decode($bytes, self::trackerWith(1005)));
    }

    public function testThreeByteUtf8CoordinateSpansAreConsumedExactly(): void
    {
        // 0xE1 0x80 0x80 is U+1000 (4096) → coordinate 4064; the 1-byte
        // lead check must not confuse the following ASCII coordinate.
        $bytes = "\x1b[M\x20\xE1\x80\x80\x21";
        $msg = MouseReplayDecoder::decode($bytes, self::trackerWith(1000, 1005));

        self::assertInstanceOf(MouseMsg::class, $msg);
        self::assertSame(4064, $msg->x);
        self::assertSame(1, $msg->y);
    }

    public function testUtf8CoordinatesRejectControlLeadBytes(): void
    {
        $bytes = "\x1b[M\x20\x00\x21";
        self::assertNull(MouseReplayDecoder::decode($bytes, self::trackerWith(1005)));
    }

    public function testUrxvt1015IsDecodedOnlyWhenEnabled(): void
    {
        $bytes = "\x1b[35;10;20M"; // button 3 (release), x 10, y 20

        self::assertNull(MouseReplayDecoder::decode($bytes, self::trackerWith(1000)));

        $msg = MouseReplayDecoder::decode($bytes, self::trackerWith(1015));
        self::assertInstanceOf(MouseReleaseMsg::class, $msg);
        self::assertSame(10, $msg->x);
        self::assertSame(20, $msg->y);
    }

    public function testSgrIsDeferredToInputReader(): void
    {
        $bytes = "\x1b[<0;12;30M";

        // The decoder hands SGR over — InputReader has always decoded it…
        self::assertNull(MouseReplayDecoder::decode($bytes, self::trackerWith(1006)));
        $reader = new InputReader();
        $msgs = iterator_to_array($reader->parse($bytes), false);
        self::assertCount(1, $msgs);
        self::assertInstanceOf(MouseClickMsg::class, $msgs[0]);

        // …and the replay path sends the same Msg whichever branch produced it.
        self::assertNull(MouseReplayDecoder::decode($bytes, self::trackerWith(1000, 1006)));
    }

    public function testPixelReportsAreDroppedNotGuessed(): void
    {
        // 1016 sends pixel coordinates; MouseMsg is cell-based, so a
        // faithful decode is impossible — null keeps the documented
        // pre-existing drop behaviour instead of inventing a mapping.
        $bytes = "\x1b[<0;640;480;0M";

        self::assertNull(MouseReplayDecoder::decode($bytes, self::trackerWith(1016)));
    }

    public function testTruncatedX10PayloadFallsBack(): void
    {
        self::assertNull(MouseReplayDecoder::decode("\x1b[M\x20\x21", self::trackerWith(1000)));
    }

    public function testX10CoordinateBytesBelowBiasAreRejected(): void
    {
        self::assertNull(MouseReplayDecoder::decode("\x1b[M\x20\x01\x02", self::trackerWith(1000)));
        self::assertNull(MouseReplayDecoder::decode("\x1b[M\x01\x21\x22", self::trackerWith(1000)));
    }

    public function testUrxvtButtonParamBelowBiasIsRejected(): void
    {
        // 1015 carries the same +32 button bias as X10; a smaller param is
        // garbage (or a different report entirely), not a negative button —
        // decoding it would light phantom wheel/motion bits via two's
        // complement, so it must fall back instead.
        self::assertNull(MouseReplayDecoder::decode("\x1b[0;5;6M", self::trackerWith(1015)));
        self::assertNull(MouseReplayDecoder::decode("\x1b[31;5;6M", self::trackerWith(1015)));
    }

    public function testUtf8OverlongCoordinateIsRejected(): void
    {
        // \xE0\x80\xA0 is an overlong encoding of U+00A0 — canonical 1005
        // terminals emit \xC2\xA0; accepting the 3-byte form would invent
        // coordinates outside any encoder's range.
        self::assertNull(MouseReplayDecoder::decode("\x1b[M\x20\xE0\x80\xA0\x21", self::trackerWith(1005)));

        // The canonical spelling of the same coordinate decodes fine.
        $msg = MouseReplayDecoder::decode("\x1b[M\x20\xC2\xA0\x21", self::trackerWith(1005));
        self::assertInstanceOf(MouseMsg::class, $msg);
        self::assertSame(128, $msg->x);

        // U+10FFFF is the last legal scalar value; F4 8F BF BF decodes to it,
        // while F4 90 80 80 (U+110000) is out of the UTF-8 range entirely.
        self::assertNotNull(MouseReplayDecoder::decode("\x1b[M\x20\xF4\x8F\xBF\xBF\x21", self::trackerWith(1005)));
        self::assertNull(MouseReplayDecoder::decode("\x1b[M\x20\xF4\x90\x80\x80\x21", self::trackerWith(1005)));
    }
}
