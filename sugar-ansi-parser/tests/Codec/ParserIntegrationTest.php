<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests\Codec;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiParser\Codec\Dcs;
use SugarCraft\AnsiParser\Codec\Iterm1337;
use SugarCraft\AnsiParser\Codec\KittyApc;
use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\AnsiParser\Parser\Parser;

/**
 * F-16: end-to-end byte sequences through the real parser into the codecs —
 * the "not silently swallowed" proof for all three gaps.
 */
final class ParserIntegrationTest extends TestCase
{
    public function testOsc1337FileTransferReachesTheCodecThroughTheParser(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1b]1337;File=name=a.png;size=3;inline=1:QUJD\x07");

        $osc = $handler->filter('osc');
        self::assertCount(1, $osc);
        $seq = Iterm1337::parse($osc[0]['detail']);
        self::assertNotNull($seq);
        self::assertTrue($seq->isFile());
        self::assertSame('a.png', $seq->name());
        self::assertSame(3, $seq->declaredSize());
        self::assertTrue($seq->inline());
    }

    public function testKittyApcReachesTheCodecThroughTheParser(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1b_Ga=T,i=3,s=4,v=5;QQ==\x1b\\");

        $apc = $handler->filter('apc');
        self::assertCount(1, $apc);
        $frame = KittyApc::parse($apc[0]['detail']);
        self::assertNotNull($frame);
        self::assertSame('T', $frame->action());
        self::assertSame(3, $frame->imageId());
        self::assertSame(4, $frame->sourceWidth());
        self::assertSame(5, $frame->sourceHeight());
        self::assertSame(4, $frame->payloadSize());
        self::assertSame('kitty graphics a=T i=3 4x5px (4 bytes)', $frame->describe());
    }

    public function testSixelAtEndOfStreamIsStillDetectedThroughTheParser(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // No ST: the stream simply ends mid-payload (the streaming-inspector gap).
        $parser->parseComplete("\x1bP0;1;0q\"1;1;2;2##");

        $dcs = $handler->filter('dcs');
        self::assertCount(1, $dcs);
        $detail = $dcs[0]['detail'];
        $decoded = Dcs::parse(
            $detail['final'],
            $detail['params'],
            $detail['prefix'],
            $detail['intermediate'],
            $detail['data'],
        );
        self::assertFalse($decoded->isTruncated());
        self::assertTrue($decoded->isSixel());
        self::assertSame('sixel image (10 bytes)', $decoded->describe());
    }

    public function testNoFinalByteQuirkIsVisibleToTheCodecAsTruncated(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        // The VT500 DcsEntry Put edge: 0x08 enters the payload with no final byte.
        $parser->parseComplete("\x1bP\x08abc");

        $dcs = $handler->filter('dcs');
        self::assertCount(1, $dcs);
        $detail = $dcs[0]['detail'];
        $decoded = Dcs::parse(
            $detail['final'],
            $detail['params'],
            $detail['prefix'],
            $detail['intermediate'],
            $detail['data'],
        );
        self::assertTrue($decoded->isTruncated());
        self::assertSame(Dcs::KIND_TRUNCATED, $decoded->kind());
    }

    public function testPreludeCutAtEndOfStreamIsNotFabricatedIntoADispatch(): void
    {
        $handler = new DebugHandler();
        $parser = new Parser($handler);

        $parser->parseComplete("\x1bP1;2");

        self::assertCount(0, $handler->filter('dcs'), 'the aborted prelude stays a raw caller tail');
    }

    public function testTmuxWrappedOsc1337ReachesTheDcsArm(): void
    {
        $inner = "\x1b]1337;File=name=b.png;size=7;inline=1:QUJD\x1b\\";
        $wrapped = "\x1bPtmux;" . str_replace("\x1b", "\x1b\x1b", $inner) . "\x1b\\";

        $handler = new DebugHandler();
        $parser = new Parser($handler);
        $parser->parseComplete($wrapped);

        // The state machine unwraps the envelope itself: the DCS aborts at the
        // first doubled ESC ("mux;" is all it carries) and the second ESC starts
        // the inner OSC, which arrives as a normal oscDispatch — no data lost.
        $dcs = $handler->filter('dcs');
        self::assertCount(1, $dcs);
        self::assertSame(\ord('t'), $dcs[0]['detail']['final']);
        self::assertSame('mux;', $dcs[0]['detail']['data']);

        $osc = $handler->filter('osc');
        self::assertCount(1, $osc);
        $seq = Iterm1337::parse($osc[0]['detail']);
        self::assertNotNull($seq);
        self::assertSame('b.png', $seq->name());
        self::assertSame(7, $seq->declaredSize());
    }
}
