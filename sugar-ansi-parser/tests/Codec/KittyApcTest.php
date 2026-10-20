<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests\Codec;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Codec\KittyApc;

/**
 * F-16: KittyApc codec — control-pair decoding and the geometry/size labels
 * the inspector previously reduced to a byte count.
 */
final class KittyApcTest extends TestCase
{
    public function testNonKittyApcIsRejected(): void
    {
        self::assertNull(KittyApc::parse(''));
        self::assertNull(KittyApc::parse('candyzone:abc'));
        self::assertNull(KittyApc::parse('_G'));
    }

    public function testFullFrameExposesEveryLabel(): void
    {
        $apc = KittyApc::parse('Ga=T,f=100,i=7,s=100,v=50,c=10,r=5,x=1,y=2,z=3,m=0;QUJD');

        self::assertNotNull($apc);
        self::assertSame('T', $apc->action());
        self::assertSame(7, $apc->imageId());
        self::assertSame(100, $apc->format());
        self::assertSame(100, $apc->sourceWidth());
        self::assertSame(50, $apc->sourceHeight());
        self::assertSame(10, $apc->columns());
        self::assertSame(5, $apc->rows());
        self::assertSame(1, $apc->x());
        self::assertSame(2, $apc->y());
        self::assertSame(3, $apc->z());
        self::assertSame(0, $apc->more());
        self::assertFalse($apc->isChunked());
        self::assertSame(100, $apc->width());
        self::assertSame(50, $apc->height());
        self::assertSame('QUJD', $apc->payload());
        self::assertSame(4, $apc->payloadSize());
        self::assertSame(
            'kitty graphics a=T i=7 100x50px x=1 y=2 z=3 m=0 (4 bytes)',
            $apc->describe(),
        );
    }

    public function testControlOnlyFrameWithoutSeparatorIsTolerated(): void
    {
        $apc = KittyApc::parse('Gm=1');

        self::assertNotNull($apc);
        self::assertSame(1, $apc->more());
        self::assertTrue($apc->isChunked());
        self::assertSame(0, $apc->payloadSize());
        self::assertSame('kitty graphics m=1 (0 bytes)', $apc->describe());
    }

    public function testEmptyControlDataWithPayload(): void
    {
        $apc = KittyApc::parse('G;QUJD');

        self::assertNotNull($apc);
        self::assertSame([], $apc->control());
        self::assertSame('QUJD', $apc->payload());
        self::assertSame('kitty graphics (4 bytes)', $apc->describe());
    }

    public function testNonStandardWhAliasesResolveAsGeometry(): void
    {
        $apc = KittyApc::parse('Gw=64,h=32;');

        self::assertNotNull($apc);
        self::assertNull($apc->sourceWidth());
        self::assertSame(64, $apc->width());
        self::assertSame(32, $apc->height());
        self::assertSame('kitty graphics 64x32 (0 bytes)', $apc->describe());
    }

    public function testCellGeometryIsLabelledInCells(): void
    {
        $apc = KittyApc::parse('Gc=10,r=5;');

        self::assertNotNull($apc);
        self::assertSame('kitty graphics 10x5 cells (0 bytes)', $apc->describe());
    }

    public function testPixelGeometryWinsOverWhAndCellAliases(): void
    {
        $apc = KittyApc::parse('Gw=1,h=1,c=9,r=9,s=100,v=50;');

        self::assertNotNull($apc);
        self::assertSame(100, $apc->width());
        self::assertSame(50, $apc->height());
    }

    public function testNonNumericValuesAreIgnoredButRawControlIsKept(): void
    {
        $apc = KittyApc::parse('Gx=abc,i=1;');

        self::assertNotNull($apc);
        self::assertNull($apc->x());
        self::assertSame('abc', $apc->get('x'));
        self::assertSame(1, $apc->imageId());
        self::assertSame('kitty graphics i=1 (0 bytes)', $apc->describe());
    }

    public function testJunkTokensAreSkippedAndUnknownKeysPreserved(): void
    {
        $apc = KittyApc::parse('Gfoo=bar,,baz,a=1;');

        self::assertNotNull($apc);
        self::assertSame(['foo' => 'bar', 'a' => '1'], $apc->control());
        self::assertSame('kitty graphics a=1 (0 bytes)', $apc->describe());
    }

    public function testDuplicateKeysAreLastWins(): void
    {
        $apc = KittyApc::parse('Ga=T,a=p;');

        self::assertNotNull($apc);
        self::assertSame('p', $apc->action());
    }

    public function testBackslashBeforeADelimiterIsOrdinaryData(): void
    {
        // m1 (rev-w10-4): the protocol defines no escape grammar (upstream
        // parse-graphics-command.h splits on plain ','/';' and accepts no
        // string values), so `\,` and `\;` stay data bytes — pinned against
        // any future silent-unescape invention.
        $apc = KittyApc::parse('Ga=f,f=/p\,q;QUJD');

        self::assertNotNull($apc);
        self::assertSame(['a' => 'f', 'f' => '/p\\'], $apc->control());
        self::assertSame('QUJD', $apc->payload());
        self::assertSame(4, $apc->payloadSize());

        $escapedSemi = KittyApc::parse('Ga=f\;b;QUJD');

        self::assertNotNull($escapedSemi);
        // The ';' starts the payload whether or not a backslash precedes it.
        self::assertSame(['a' => 'f\\'], $escapedSemi->control());
        self::assertSame('b;QUJD', $escapedSemi->payload());
    }

    public function testDescribeNeutralizesControlBytesInTheAction(): void
    {
        // m2 (rev-w10-4): APC string states Put bytes 0x00-0x17, so the
        // quoted flag value must be escaped+clipped like Dcs::describe().
        $apc = KittyApc::parse("Ga=f\n\x07;QUJD");

        self::assertNotNull($apc);
        self::assertSame('kitty graphics a=f\x0A\x07 (4 bytes)', $apc->describe());
        self::assertStringNotContainsString("\n", $apc->describe());
        self::assertStringNotContainsString("\x07", $apc->describe());

        $long = KittyApc::parse('Ga=' . str_repeat('x', 97) . "\n;QUJD");

        self::assertNotNull($long);
        self::assertSame('kitty graphics a=' . str_repeat('x', 80) . '… (4 bytes)', $long->describe());
    }
}
