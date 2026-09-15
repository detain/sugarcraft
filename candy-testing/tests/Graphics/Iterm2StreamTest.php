<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Graphics\Iterm2Stream;
use SugarCraft\Testing\Graphics\MalformedGraphicsException;

/**
 * @covers \SugarCraft\Testing\Graphics\Iterm2Stream
 */
final class Iterm2StreamTest extends TestCase
{
    public function testDecodesInlineImageFile(): void
    {
        $iterm2 = Iterm2Stream::decode(Fixture::bytes('iterm2_red.iterm2'));

        self::assertSame('File', $iterm2->command());
        self::assertTrue($iterm2->hasPayload());
        self::assertStringStartsWith("\x89PNG", $iterm2->png());
        self::assertSame([8, 4], $iterm2->pixelDimensions());
    }

    public function testParsesSizeAndAspectRatioParams(): void
    {
        $iterm2 = Iterm2Stream::decode(Fixture::bytes('iterm2_red.iterm2'));

        self::assertSame('1', $iterm2->params()['preserveAspectRatio']);
        self::assertSame('1', $iterm2->params()['inline']);
        self::assertSame(8, $iterm2->cellsWidth());
        self::assertSame(4, $iterm2->cellsHeight());
    }

    public function testDecodesControlOnlySequence(): void
    {
        $iterm2 = Iterm2Stream::decode("\x1b]1337;Pop\x07");

        self::assertSame('Pop', $iterm2->command());
        self::assertFalse($iterm2->hasPayload());
        self::assertSame([], $iterm2->params());
    }

    public function testControlSequencePngThrows(): void
    {
        $iterm2 = Iterm2Stream::decode("\x1b]1337;Pop\x07");

        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('carries no image payload');
        $iterm2->png();
    }

    public function testAcceptsSTClosedSequence(): void
    {
        $stream = Fixture::bytes('iterm2_red.iterm2');
        // Re-close the fixture with ST instead of BEL.
        $st = substr($stream, 0, -1) . "\x1b\\";
        $iterm2 = Iterm2Stream::decode($st);

        self::assertSame('File', $iterm2->command());
        self::assertSame([8, 4], $iterm2->pixelDimensions());
    }

    public function testParsesImageEmbeddedInSurroundingText(): void
    {
        $stream = 'prompt$ ' . Fixture::bytes('iterm2_red.iterm2') . "\r\n";
        $iterm2 = Iterm2Stream::decode($stream);

        self::assertSame(8, $iterm2->cellsWidth());
    }

    public function testToGdImageMaterialisesPayload(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('ext-gd is required to materialise an iTerm2 image');
        }

        $image = Iterm2Stream::decode(Fixture::bytes('iterm2_red.iterm2'))->toGdImage();

        self::assertSame(8, imagesx($image));
        self::assertSame(4, imagesy($image));
    }

    public function testMissingOscThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('OSC 1337');
        Iterm2Stream::decode('no iterm2 sequence');
    }

    public function testUnterminatedThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('BEL or ST terminator');
        Iterm2Stream::decode("\x1b]1337;File=width=1:AAAA");
    }

    public function testMissingColonSeparatorThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('data separator');
        Iterm2Stream::decode("\x1b]1337;File=width=1;height=2\x07");
    }

    public function testInvalidBase64Throws(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('not valid base64');
        Iterm2Stream::decode("\x1b]1337;File=width=1:!!!nope!!!\x07");
    }

    public function testMalformedArgumentThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('key=value pair');
        // An argument segment with no `=` is malformed, not ignorable.
        Iterm2Stream::decode("\x1b]1337;File=width=1;oops:AAAA\x07");
    }
}
