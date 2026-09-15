<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Testing\Graphics\MalformedGraphicsException;
use SugarCraft\Testing\Graphics\Mode;

/**
 * @covers \SugarCraft\Testing\Graphics\Mode
 * @covers \SugarCraft\Testing\Graphics\MalformedGraphicsException
 */
final class ModeTest extends TestCase
{
    public function testDetectRecognisesSixelDcs(): void
    {
        self::assertSame(Mode::Sixel, Mode::detect(Fixture::bytes('sixel_red.six')));
    }

    public function testDetectRecognisesKittyDcs(): void
    {
        self::assertSame(Mode::Kitty, Mode::detect(Fixture::bytes('kitty_red.kitty')));
    }

    public function testDetectRecognisesIterm2Osc(): void
    {
        self::assertSame(Mode::Iterm2, Mode::detect(Fixture::bytes('iterm2_red.iterm2')));
    }

    public function testDetectRecognisesApcKitty(): void
    {
        self::assertSame(Mode::Kitty, Mode::detect("\x1b_Ga=T,c=8,r=4;AAAA\x1b\\"));
    }

    public function testDetectRecognisesZeroParameterSixel(): void
    {
        // img2sixel-style output omits the DCS device parameters, giving `ESC P q"`
        // — which shares its prefix with a Kitty `ESC P q` header. The mandatory `"`
        // raster attribute must route it to Sixel, not Kitty.
        $stream = "\x1bPq\"1;1;4;4#0;2;100;0;0#0!4;~\x1b\\";

        self::assertSame(Mode::Sixel, Mode::detect($stream));
    }

    public function testDetectRecognisesSixelWithPaddingBeforeRaster(): void
    {
        // SixelStream::parseHeader ltrims before the `"` raster decl, so the sniff
        // must tolerate the same `q "` gap and still route to Sixel, not Kitty.
        $stream = "\x1bPq \"1;1;4;4#0;2;100;0;0#0!4;~\x1b\\";

        self::assertSame(Mode::Sixel, Mode::detect($stream));
    }

    public function testDetectRecognisesTransmitEmbeddedInText(): void
    {
        // The decoders locate a transmit via strpos anywhere in the stream, so the
        // sniff must too — leading screen text must not defeat detection.
        self::assertSame(Mode::Sixel, Mode::detect('scrollbar ' . Fixture::bytes('sixel_red.six')));
        self::assertSame(Mode::Kitty, Mode::detect('frame ' . Fixture::bytes('kitty_red.kitty')));
    }

    public function testDetectFailsFastOnUnknownProtocol(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        Mode::detect('just some plain terminal text');
    }

    public function testExceptionIsARuntimeException(): void
    {
        // The decoders throw MalformedGraphicsException; callers commonly rescue
        // the broader RuntimeException, so the subtype relationship matters.
        self::assertInstanceOf(RuntimeException::class, new MalformedGraphicsException('x'));
    }

    public function testCaseValues(): void
    {
        self::assertSame('sixel', Mode::Sixel->value);
        self::assertSame('kitty', Mode::Kitty->value);
        self::assertSame('iterm2', Mode::Iterm2->value);
    }
}
