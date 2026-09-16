<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
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
        $this->expectExceptionMessage('must be an integer');
        KittyStream::decode($stream);
    }

    public function testNegativeZIndexIsAccepted(): void
    {
        // The kitty spec types `z` as a signed integer (negative z-index / gapless
        // frame gap); it must not be rejected by the numeric-parameter gate.
        $image = KittyImage::fromTransmit(['a' => 't', 'i' => '9', 'z' => '-2'], 'payload');

        self::assertSame(-2, $image->zIndex());
    }

    public function testChunkedApcStitchesIntoOneImage(): void
    {
        // The shape candy-mosaic emits since the ANSI audit fix: a begin
        // frame ending in `,m=1;` (empty first data chunk), self-framed
        // `m=1;` payload chunks, and a final `m=0;` closer. All three must
        // reassemble into ONE image with the begin frame's attributes.
        $png = Iterm2Stream::decode(Fixture::bytes('iterm2_red.iterm2'))->png();
        $b64 = base64_encode($png);
        $cut = intdiv(strlen($b64), 2);

        $stream =
            "\x1b_Ga=T,c=8,r=4,f=100,m=1;\x1b\\"
            . "\x1b_Gm=1;" . substr($b64, 0, $cut) . "\x1b\\"
            . "\x1b_Gm=0;" . substr($b64, $cut) . "\x1b\\";

        $kitty = KittyStream::decode($stream);

        self::assertSame(1, $kitty->count(), 'a chunked transaction is ONE image');
        self::assertSame(8, $kitty->image()->cols(), 'attributes come from the begin frame');
        self::assertSame(4, $kitty->image()->rows());
        self::assertArrayNotHasKey('m', $kitty->image()->params(), 'the chunking flag is framing state, not image metadata');
        self::assertSame($png, $kitty->image()->png());
    }

    public function testUnterminatedChunkedApcThrows(): void
    {
        // A transaction opened with `m=1` but never closed by `m=0` is a
        // truncated transmission — fail loud rather than emit a partial image.
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('missing its m=0 end marker');
        KittyStream::decode("\x1b_Ga=T,c=8,r=4,m=1;\x1b\\" . "\x1b_Gm=1;aGVsbG8=\x1b\\");
    }

    public function testStitchesFourFrameApcTransaction(): void
    {
        // The begin frame may carry the first payload slice, so a real mosaic
        // stream is four frames deep: `m=1` begin + two `m=1` continuations +
        // the `m=0` closer. All four must collapse into ONE image.
        $frames = str_split(base64_encode($this->redPng()), 40);
        $stream = "\x1b_Ga=T,c=8,r=4,f=12,m=1;" . array_shift($frames) . "\x1b\\";
        while (count($frames) > 1) {
            $stream .= "\x1b_Gm=1;" . array_shift($frames) . "\x1b\\";
        }
        $stream .= "\x1b_Gm=0;" . array_shift($frames) . "\x1b\\";

        $kitty = KittyStream::decode($stream);

        self::assertSame(1, $kitty->count(), 'four APC frames are one transmission');
        self::assertSame($this->redPng(), $kitty->image()->png(), 'the payload slices must concatenate in order');
        self::assertSame(8, $kitty->image()->cols(), 'attributes are inherited from the begin frame');
        self::assertSame('12', $kitty->image()->format());
    }

    public function testChunkFramesMergeAndOverrideTransactionAttributes(): void
    {
        // Per-chunk keys are legal in the protocol: a later frame overrides a
        // shared key and adds new ones, while unmentioned begin-frame
        // attributes survive to the decoded image.
        $b64 = base64_encode($this->redPng());
        $cut = intdiv(strlen($b64), 2);

        $stream = "\x1b_Ga=T,c=8,r=4,f=12,m=1;" . substr($b64, 0, $cut) . "\x1b\\"
            . "\x1b_Gc=6,i=44,m=1;" . substr($b64, $cut) . "\x1b\\"
            . "\x1b_Gm=0;\x1b\\";

        $image = KittyStream::decode($stream)->image();

        self::assertSame(6, $image->cols(), 'the later frame wins the shared key');
        self::assertSame(44, $image->id(), 'a key introduced mid-transaction is additive');
        self::assertSame('12', $image->format(), 'an unmentioned attribute survives');
        self::assertSame($this->redPng(), $image->png());
    }

    public function testInterleavedTrafficDoesNotSplitAnApcTransaction(): void
    {
        // Chunk state lives on the decoder, not on frame adjacency, so a
        // producer may spray unrelated traffic between chunks: printable text,
        // SGR, a BEL-terminated OSC 1337, a foreign APC string sequence
        // (`ESC _ X`, the same ECMA-48 class Kitty's `ESC _ G` rides in), and a
        // whole Sixel DCS image. None of those carries a Kitty introducer, so
        // the transaction must still close into exactly one image.
        $b64 = base64_encode($this->redPng());
        $cut = intdiv(strlen($b64), 2);

        $stream = "\x1b_Ga=T,c=8,r=4,f=12,m=1;" . substr($b64, 0, $cut) . "\x1b\\"
            . "hello \x1b[31mworld\x1b[0m"
            . "\x1b]1337;File=inline=1:AAAA\x07"
            . "\x1b_Xtmux-style foreign APC payload\x1b\\"
            . Fixture::bytes('sixel_red.six')
            . "\x1b_Gm=0;" . substr($b64, $cut) . "\x1b\\";

        $kitty = KittyStream::decode($stream);

        self::assertSame(1, $kitty->count(), 'foreign traffic must not register as a Kitty transmit');
        self::assertSame($this->redPng(), $kitty->image()->png(), 'the interleaved bytes must not enter the payload');
    }

    public function testSixelPathStaysIndependentOfChunkedApcStitching(): void
    {
        // A whole Sixel DCS may sit between two chunks of one Kitty transaction:
        // it rides `\x1bP0;1;0q`, never the bare `\x1bPq` Kitty legacy introducer,
        // so the scanner walks past it and the frames still belong together. The
        // mirror pin (the same mixed stream decoding as a Sixel image) lives in
        // SixelStreamTest::testDecodesSixelWrappedInChunkedKittyApcFrames().
        $b64 = base64_encode($this->redPng());
        $stream = "\x1b_Ga=T,c=8,r=4,f=12,m=1;" . $b64 . "\x1b\\"
            . Fixture::bytes('sixel_red.six')
            . "\x1b_Gm=0;\x1b\\";

        $kitty = KittyStream::decode($stream);

        self::assertSame(1, $kitty->count(), 'the sixel DCS must not register as a Kitty transmit');
        self::assertSame($this->redPng(), $kitty->image()->png(), 'the sixel bytes must not leak into the Kitty payload');
    }

    public function testUnterminatedChunkedApcWithInterleavedTrafficStillFailsFast(): void
    {
        // Interleaving is not an escape hatch for truncation: a transaction
        // closed only by end-of-input must still raise the missing-end marker.
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('missing its m=0 end marker');
        KittyStream::decode(
            "\x1b_Ga=T,c=8,r=4,m=1;aGVsbG8=\x1b\\"
            . "trailing screen text\x1b[0m"
            . "\x1b]1337;File=inline=1:AAAA\x07"
        );
    }

    public function testStitchesBelTerminatedApcFrames(): void
    {
        // `findApcEnd` accepts either terminator; BEL-closed chunk frames are a
        // real-world emitter quirk and must stitch like ST-closed ones.
        $b64 = base64_encode($this->redPng());
        $cut = intdiv(strlen($b64), 2);

        $stream = "\x1b_Ga=T,c=8,r=4,f=12,m=1;" . substr($b64, 0, $cut) . "\x07"
            . "\x1b_Gm=0;" . substr($b64, $cut) . "\x07";

        self::assertSame($this->redPng(), KittyStream::decode($stream)->image()->png());
    }

    public function testMalformedDcsChunkFlagThrows(): void
    {
        // The legacy DCS path only admits `m=0`/`m=1` chunks; anything else is a
        // framing error, not data to be guessed at.
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('chunk is malformed');
        KittyStream::decode("\x1bPqa=T,c=8,r=4\x1b\\m=9,AAAA\x1b\\m=0\x1b\\");
    }

    public function testMalformedApcControlParameterThrows(): void
    {
        // The APC path validates its parameter block with the same strictness
        // as the DCS path does.
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('key=value pair');
        KittyStream::decode("\x1b_Ggarbage;" . base64_encode($this->redPng()) . "\x1b\\");
    }

    public function testPngPassthroughFormatTravelsUntransformed(): void
    {
        // `f=12` is PNG passthrough: the payload already IS the image, so the
        // decoder must neither inflate it (a PNG is not a zlib stream — that
        // would abort with decompress_failed) nor re-encode it.
        $image = KittyStream::decode($this->apcTransmit(['a' => 'T', 'f' => '12', 'i' => '5']))->image();

        self::assertSame('12', $image->format());
        self::assertTrue($image->pngPassthrough());
        self::assertFalse($image->compressed());
        self::assertSame($this->redPng(), $image->rawPayload(), 'passthrough bytes must arrive byte for byte');
        self::assertSame([8, 4], $image->pixelDimensions());
    }

    public function testPngPassthroughIsNotInflatedWhenZIsOne(): void
    {
        // `z` is the kitty z-index, NOT a transmission-compression flag — the
        // format key alone decides that. candy-mosaic's
        // `KittyOptions::transmit()->withZIndex(1)` emits this pair over a plain
        // PNG for both PNG spellings, so inflating here would reject its own
        // wire output. (Upstream's compression key is `o=z`, which this decoder
        // does not act on — see `testCompressionFlagOnPngPassthroughIsNotInflated`.)
        foreach (['12', '100'] as $format) {
            $image = KittyStream::decode($this->apcTransmit(['a' => 'T', 'f' => $format, 'z' => '1', 'i' => '9']))->image();

            self::assertSame(1, $image->zIndex(), "f={$format}: z stays the stacking index");
            self::assertTrue($image->pngPassthrough(), "f={$format} must report PNG passthrough");
            self::assertFalse($image->compressed(), "f={$format} must not report a zlib transmit");
            self::assertSame($this->redPng(), $image->rawPayload(), "f={$format} must travel byte for byte");
        }
    }

    public function testCompressionFlagOnPngPassthroughIsNotInflated(): void
    {
        // Upstream signals transmission compression with `o=z` for any format.
        // This decoder keys inflate on `f=1` alone, so an `o=z` capture arrives
        // exactly as sent — the documented gap, pinned so a future change to it
        // is a decision rather than a surprise.
        $stream = $this->apcFrame(['a' => 'T', 'f' => '100', 'o' => 'z'], base64_encode(gzcompress($this->redPng())));
        $image = KittyStream::decode($stream)->image();

        self::assertFalse($image->compressed());
        self::assertTrue($image->pngPassthrough());
        self::assertSame(gzcompress($this->redPng()), $image->rawPayload(), 'the zlib bytes must not be inflated');

        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('does not decode to a readable image');
        $image->pixelDimensions();
    }

    public function testZlibFormatInflatesWhateverTheZIndexSays(): void
    {
        // The converse pin: `f=1` still inflates when a z-index rides along.
        $stream = $this->apcFrame(['a' => 'T', 'f' => '1', 'z' => '3', 'i' => '8'], base64_encode(gzcompress($this->redPng())));
        $image = KittyStream::decode($stream)->image();

        self::assertTrue($image->compressed());
        self::assertSame(3, $image->zIndex());
        self::assertFalse($image->pngPassthrough());
        self::assertSame($this->redPng(), $image->png());
    }

    public function testF100IsAcceptedAsPngPassthrough(): void
    {
        // `f=100` is what candy-mosaic's KittyRenderer and sugar-charts' Picture
        // put on the wire for a PNG — the upstream protocol's own PNG code.
        $image = KittyStream::decode($this->apcTransmit(['a' => 'T', 'f' => '100', 'i' => '11']))->image();

        self::assertSame('100', $image->format());
        self::assertTrue($image->pngPassthrough());
        self::assertFalse($image->compressed());
        self::assertSame($this->redPng(), $image->rawPayload());
    }

    public function testBothPngSpellingsDecodeIdentically(): void
    {
        $png12 = KittyStream::decode($this->apcTransmit(['a' => 'T', 'f' => '12', 'i' => '3']))->image();
        $png100 = KittyStream::decode($this->apcTransmit(['a' => 'T', 'f' => '100', 'i' => '3']))->image();

        self::assertSame($png12->rawPayload(), $png100->rawPayload(), 'both spellings must be behaviourally identical');
        self::assertTrue($png12->pngPassthrough());
        self::assertTrue($png100->pngPassthrough());
    }

    public function testF100SurvivesChunkedStitching(): void
    {
        $b64 = base64_encode($this->redPng());
        $cut = intdiv(strlen($b64), 2);

        $stream = "\x1b_Ga=T,c=8,r=4,f=100,m=1;" . substr($b64, 0, $cut) . "\x1b\\"
            . "\x1b_Gm=0;" . substr($b64, $cut) . "\x1b\\";

        $image = KittyStream::decode($stream)->image();

        self::assertTrue($image->pngPassthrough());
        self::assertSame($this->redPng(), $image->png());
    }

    public function testUnknownFormatTravelsUntransformed(): void
    {
        // Anything outside the zlib row of the format table is documented as raw
        // passthrough — here `f=24`, upstream's three-bytes-per-pixel RGB code —
        // so no inflate is attempted and nothing is rejected either.
        $jpegish = "\xff\xd8\xff\xe0not-a-real-jpeg";
        $image = KittyStream::decode($this->apcFrame(['a' => 'T', 'f' => '24'], base64_encode($jpegish)))->image();

        self::assertSame($jpegish, $image->rawPayload());
        self::assertFalse($image->pngPassthrough());
        self::assertFalse($image->compressed());
    }

    public function testNonNumericFormatNeverTriggersInflate(): void
    {
        // `f` is an enumerated key rather than a counter, so the numeric
        // parameter gate does not police it — but it must never be read as a
        // compression signal either.
        $image = KittyStream::decode($this->apcFrame(['a' => 'T', 'f' => 'png'], base64_encode($this->redPng())))->image();

        self::assertSame('png', $image->format());
        self::assertFalse($image->compressed());
        self::assertFalse($image->pngPassthrough());
        self::assertSame($this->redPng(), $image->rawPayload());
    }

    public function testDecodesCandyCoreAnsiChunkedEmitterWithPngPassthrough(): void
    {
        // Producer/consumer interop: the frames `Ansi::kittyGraphicsBegin()` +
        // `kittyGraphicsChunk()` emit (exactly what candy-mosaic renders) decode
        // back into the sender's PNG with the chunking flag stripped.
        $b64 = base64_encode($this->redPng());
        $cut = intdiv(strlen($b64), 2);

        $stream = Ansi::kittyGraphicsBegin(['a' => 'T', 'c' => 8, 'r' => 4, 'f' => 12])
            . Ansi::kittyGraphicsChunk(substr($b64, 0, $cut), true)
            . Ansi::kittyGraphicsChunk(substr($b64, $cut), false);

        $image = KittyStream::decode($stream)->image();

        self::assertSame($this->redPng(), $image->png());
        self::assertTrue($image->pngPassthrough());
        self::assertArrayNotHasKey('m', $image->params());
    }

    /** The real 8x4 red PNG the committed iTerm2 fixture carries. */
    private function redPng(): string
    {
        return Iterm2Stream::decode(Fixture::bytes('iterm2_red.iterm2'))->png();
    }

    /**
     * A minimal standard-APC transmit wrapping the real 8x4 red PNG.
     *
     * @param array<string, string> $params
     */
    private function apcTransmit(array $params): string
    {
        // Reuse the PNG that the iTerm2 fixture carries so the APC path is tested
        // against a genuine image rather than a hand-made header.
        return $this->apcFrame($params, base64_encode($this->redPng()));
    }

    /**
     * One self-framed standard-APC transmit with an explicit base64 payload.
     *
     * @param array<string, string> $params
     */
    private function apcFrame(array $params, string $payloadBase64): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return "\x1b_G" . implode(',', $pairs) . ';' . $payloadBase64 . "\x1b\\";
    }
}
