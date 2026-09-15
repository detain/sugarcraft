<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Graphics\KittyImage;
use SugarCraft\Testing\Graphics\KittyStream;
use SugarCraft\Testing\Graphics\Iterm2Stream;
use SugarCraft\Testing\Graphics\MalformedGraphicsException;

/**
 * @covers \SugarCraft\Testing\Graphics\KittyStream
 * @covers \SugarCraft\Testing\Graphics\KittyImage
 */
final class KittyStreamTest extends TestCase
{
    public function testDecodesSingleChunkTransmit(): void
    {
        $kitty = KittyStream::decode(Fixture::bytes('kitty_red.kitty'));
        $image = $kitty->image();

        self::assertSame(1, $kitty->count());
        self::assertSame('t', strtolower($image->action()));
        self::assertSame(8, $image->cols());
        self::assertSame(4, $image->rows());
        self::assertStringStartsWith("\x89PNG", $image->png());
        self::assertSame([8, 4], $image->pixelDimensions());
    }

    public function testReassemblesMoreFlagChunks(): void
    {
        $kitty = KittyStream::decode(Fixture::bytes('kitty_chunked.kitty'));
        $image = $kitty->image();

        // 88 cells of 2px = a 176x176 source PNG split across two m= chunks.
        self::assertSame([176, 176], $image->pixelDimensions());
        self::assertStringStartsWith("\x89PNG", $image->png());
    }

    public function testInflatesZlibPayload(): void
    {
        $kitty = KittyStream::decode(Fixture::bytes('kitty_zlib.kitty'));
        $image = $kitty->image();

        self::assertTrue($image->compressed());
        self::assertSame(7, $image->id());
        self::assertSame('T', $image->action());
        self::assertStringStartsWith("\x89PNG", $image->png(), 'the f=1 payload must inflate back to a PNG');
    }

    public function testPlacementCarriesNoPayload(): void
    {
        $kitty = KittyStream::decode(Fixture::bytes('kitty_place.kitty'));
        $image = $kitty->image();

        self::assertSame('p', $image->action());
        self::assertSame(7, $image->id());
        self::assertSame(3, $image->offsetX());
        self::assertSame(5, $image->offsetY());
        self::assertFalse($image->hasPayload());
    }

    public function testPlacementPngThrows(): void
    {
        $image = KittyStream::decode(Fixture::bytes('kitty_place.kitty'))->image();

        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('carries no payload');
        $image->png();
    }

    public function testParsesStandardApcTransmit(): void
    {
        $stream = $this->apcTransmit(['a' => 'T', 'f' => '100', 'i' => '42', 'c' => '8', 'r' => '4']);
        $image = KittyStream::decode($stream)->image();

        self::assertSame('T', $image->action());
        self::assertSame(42, $image->id());
        self::assertSame([8, 4], $image->pixelDimensions());
    }

    public function testParsesApcDeleteAsPayloadlessControl(): void
    {
        $image = KittyStream::decode("\x1b_Ga=d,i=0\x1b\\")->image();

        self::assertSame('d', $image->action());
        self::assertFalse($image->hasPayload());
    }

    public function testDecodesMultipleTransmitsInOneStream(): void
    {
        $stream = Fixture::bytes('kitty_red.kitty') . Fixture::bytes('kitty_zlib.kitty');
        $kitty = KittyStream::decode($stream);

        self::assertSame(2, $kitty->count());
        self::assertSame([8, 4], $kitty->images()[0]->pixelDimensions());
        self::assertSame(7, $kitty->images()[1]->id());
    }

    public function testStreamConvenienceDelegatesToFirstImage(): void
    {
        $kitty = KittyStream::decode(Fixture::bytes('kitty_red.kitty'));

        self::assertSame($kitty->image()->cols(), $kitty->cols());
        self::assertSame($kitty->image()->rows(), $kitty->rows());
        self::assertSame($kitty->image()->png(), $kitty->png());
        self::assertSame($kitty->image()->action(), $kitty->action());
        self::assertSame($kitty->image()->id(), $kitty->id());
    }

    public function testImageExposesRawParamsAndNumber(): void
    {
        $image = KittyImage::fromTransmit(['a' => 't', 'I' => '3', 'z' => '5'], 'payload');

        self::assertSame(3, $image->number());
        self::assertSame(5, $image->zIndex());
        self::assertSame(['a' => 't', 'I' => '3', 'z' => '5'], $image->params());
        self::assertSame('payload', $image->rawPayload());
        self::assertNull($image->pixelWidth());
        self::assertNull($image->pixelHeight());
    }

    public function testToGdImageMaterialisesPayload(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('ext-gd is required to materialise a Kitty image');
        }

        $image = KittyStream::decode(Fixture::bytes('kitty_red.kitty'))->image()->toGdImage();

        self::assertSame(8, imagesx($image));
    }

    public function testMissingBeginThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('no graphics transmit');
        KittyStream::decode('nothing to see here');
    }

    public function testUnterminatedHeaderThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('not closed by ST');
        // DCS-`q` begin with no ST anywhere — the header never terminates.
        KittyStream::decode("\x1bPqc=8,r=4");
    }

    public function testMissingEndMarkerThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('missing its m=0 end marker');
        // Header closes, a chunk follows, but the terminator never appears.
        KittyStream::decode("\x1bPqc=8,r=4\x1b\\m=1,AAAA");
    }

    public function testInvalidBase64Throws(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('not valid base64');
        KittyStream::decode("\x1bPqc=8,r=4\x1b\\m=0,!!!notbase64!!!m=0\x1b\\");
    }

    public function testCorruptZlibPayloadThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('failed to inflate');
        // f=1 but the (valid-base64) body is not a zlib stream.
        KittyStream::decode("\x1bPqa=T,f=1,c=8,r=4\x1b\\m=0,aGVsbG8=m=0\x1b\\");
    }

    public function testUnterminatedApcThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('APC transmit is not closed');
        KittyStream::decode("\x1b_Ga=T,c=8,r=4");
    }

    public function testMalformedControlParameterThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('key=value pair');
        // A header token with no `=` must fail loud, not be silently dropped.
        KittyStream::decode("\x1bPqc=8,garbage\x1b\\m=0,AAAA=m=0\x1b\\");
    }

    public function testDecodesHeaderFollowedByWhitespace(): void
    {
        // candy-mosaic emits no gap, but a padded `\x1b\\ m=` must not be misread
        // as a bare placement: skip the whitespace and still reassemble the chunk.
        $raw = Fixture::bytes('kitty_red.kitty');
        $st = strpos($raw, "\x1b\\");
        $stream = substr($raw, 0, $st + 2) . " \t" . substr($raw, $st + 2);

        $image = KittyStream::decode($stream)->image();

        self::assertSame(8, $image->cols());
        self::assertSame(4, $image->rows());
        self::assertStringStartsWith("\x89PNG", $image->png());
    }

    public function testPlacementFollowedByTransmitIsNotMisConsumed(): void
    {
        // A bare placement self-closes; the rescan must then find the next DCS-`q`
        // transmit rather than swallowing it as the placement's (absent) chunks.
        $stream = Fixture::bytes('kitty_place.kitty') . Fixture::bytes('kitty_red.kitty');
        $kitty = KittyStream::decode($stream);

        self::assertSame(2, $kitty->count());
        self::assertSame('p', $kitty->images()[0]->action());
        self::assertSame(8, $kitty->images()[1]->cols());
        self::assertStringStartsWith("\x89PNG", $kitty->images()[1]->png());
    }

    public function testNonNumericControlParameterThrows(): void
    {
        // `i=abc` must fail loud at the boundary, not surface as a misleading id 0.
        $png = Iterm2Stream::decode(Fixture::bytes('iterm2_red.iterm2'))->png();
        $stream = "\x1b_Gi=abc;" . base64_encode($png) . "\x1b\\";

        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('non-negative integer');
        KittyStream::decode($stream);
    }

    public function testChunkedApcThrows(): void
    {
        // Standard-APC continuation (`m=1`) is unsupported framing; reject rather
        // than silently emit a stream of truncated partial images.
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('not supported');
        KittyStream::decode("\x1b_Gm=1;aGVsbG8=\x1b\\");
    }

    /**
     * A minimal standard-APC transmit wrapping the real 8x4 red PNG.
     *
     * @param array<string, string> $params
     */
    private function apcTransmit(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        // Reuse the PNG that the iTerm2 fixture carries so the APC path is tested
        // against a genuine image rather than a hand-made header.
        $png = Iterm2Stream::decode(Fixture::bytes('iterm2_red.iterm2'))->png();

        return "\x1b_G" . implode(',', $pairs) . ';' . base64_encode($png) . "\x1b\\";
    }
}
