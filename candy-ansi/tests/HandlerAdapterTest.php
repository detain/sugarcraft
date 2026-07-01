<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\CsiHandler;
use SugarCraft\Ansi\Parser\HandlerAdapter;
use SugarCraft\Ansi\Parser\OscHandler;
use SugarCraft\Ansi\Parser\Parser;
use PHPUnit\Framework\TestCase;

final class HandlerAdapterTest extends TestCase
{
    private CsiHandler $csi;
    private OscHandler $osc;
    private HandlerAdapter $adapter;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->csi = $this->createMock(CsiHandler::class);
        $this->osc = $this->createMock(OscHandler::class);
        $this->adapter = new HandlerAdapter($this->csi, $this->osc);
        $this->parser = new Parser($this->adapter);
    }

    public function testCsiDispatchCUU(): void
    {
        $this->csi->expects($this->once())->method('cuu')->with(3);

        $this->parser->feed("\x1b[3A");
    }

    public function testCsiDispatchCUD(): void
    {
        $this->csi->expects($this->once())->method('cud')->with(5);

        $this->parser->feed("\x1b[5B");
    }

    public function testCsiDispatchCUF(): void
    {
        $this->csi->expects($this->once())->method('cuf')->with(2);

        $this->parser->feed("\x1b[2C");
    }

    public function testCsiDispatchCUB(): void
    {
        $this->csi->expects($this->once())->method('cub')->with(4);

        $this->parser->feed("\x1b[4D");
    }

    public function testCsiDispatchCUP(): void
    {
        $this->csi->expects($this->once())->method('cup')->with(10, 20);

        $this->parser->feed("\x1b[10;20H");
    }

    public function testCsiDispatchCUPWithDefaultParams(): void
    {
        $this->csi->expects($this->once())->method('cup')->with(1, 1);

        $this->parser->feed("\x1b[H");
    }

    public function testCsiDispatchSGR(): void
    {
        $this->csi->expects($this->once())->method('sgr')->with([31]);

        $this->parser->feed("\x1b[31m");
    }

    public function testCsiDispatchSGRMultipleParams(): void
    {
        $this->csi->expects($this->once())->method('sgr')->with([1, 31, 40]);

        $this->parser->feed("\x1b[1;31;40m");
    }

    public function testCsiDispatchED(): void
    {
        $this->csi->expects($this->once())->method('ed')->with(2);

        $this->parser->feed("\x1b[2J");
    }

    public function testCsiDispatchEDDefaultMode(): void
    {
        $this->csi->expects($this->once())->method('ed')->with(0);

        $this->parser->feed("\x1b[J");
    }

    public function testCsiDispatchEL(): void
    {
        $this->csi->expects($this->once())->method('el')->with(1);

        $this->parser->feed("\x1b[1K");
    }

    public function testCsiDispatchELDefaultMode(): void
    {
        $this->csi->expects($this->once())->method('el')->with(0);

        $this->parser->feed("\x1b[K");
    }

    public function testCsiDispatchDECSET(): void
    {
        $this->csi->expects($this->once())->method('decset')->with(25, 0);

        $this->parser->feed("\x1b[25h");
    }

    public function testCsiDispatchDECSETWithPrivateMarker(): void
    {
        $this->csi->expects($this->once())->method('decset')->with(25, ord('?'));

        $this->parser->feed("\x1b[?25h");
    }

    public function testCsiDispatchDECRST(): void
    {
        $this->csi->expects($this->once())->method('decrst')->with(25, 0);

        $this->parser->feed("\x1b[25l");
    }

    public function testCsiDispatchDECRSTWithPrivateMarker(): void
    {
        $this->csi->expects($this->once())->method('decrst')->with(25, ord('?'));

        $this->parser->feed("\x1b[?25l");
    }

    public function testCsiDispatchDECSTBM(): void
    {
        $this->csi->expects($this->once())->method('decstbm')->with(5, 20);

        $this->parser->feed("\x1b[5;20r");
    }

    public function testCsiDispatchTBC(): void
    {
        $this->csi->expects($this->once())->method('tbc')->with(0);

        $this->parser->feed("\x1b[g");
    }

    public function testCsiDispatchCBT(): void
    {
        $this->csi->expects($this->once())->method('cbt')->with(3);

        $this->parser->feed("\x1b[3Z");
    }

    public function testCsiDispatchCHT(): void
    {
        $this->csi->expects($this->once())->method('cht')->with(2);

        $this->parser->feed("\x1b[2I");
    }

    public function testOscDispatchTitle(): void
    {
        $this->osc->expects($this->once())->method('title')->with('Hello World');

        $this->parser->feed("\x1b]2;Hello World\x07");
    }

    public function testOscDispatchTitleWithStTerminator(): void
    {
        $this->osc->expects($this->once())->method('title')->with('Test Title');

        $this->parser->feed("\x1b]2;Test Title\x1b\\");
    }

    public function testPrintableAsciiTriggersPrintable(): void
    {
        $this->csi->expects($this->once())->method('printable')->with('A');

        $this->parser->feed("A");
    }

    public function testNonPrintableAsciiInGroundTriggersNothing(): void
    {
        $this->csi->expects($this->never())->method('printable');

        $this->parser->feed("\x00");
    }

    public function testBackspaceTriggersCub(): void
    {
        $this->csi->expects($this->once())->method('cub')->with(1);

        $this->parser->feed("\x08");
    }

    public function testTabTriggersCht(): void
    {
        $this->csi->expects($this->once())->method('cht')->with(1);

        $this->parser->feed("\x09");
    }

    public function testCarriageReturnDoesNothing(): void
    {
        $this->csi->expects($this->never())->method('printable');

        $this->parser->feed("\x0d");
    }

    public function testEscDispatchDoesNothing(): void
    {
        $this->csi->expects($this->never())->method('printable');

        $this->parser->feed("\x1bD");
    }

    public function testDcsDispatchDoesNothing(): void
    {
        $this->csi->expects($this->never())->method('printable');

        $this->parser->feed("\x1bP1;2mystring\x1b\\");
    }

    public function testSosPmApcDispatchDoNothing(): void
    {
        $this->csi->expects($this->never())->method('printable');

        $this->parser->feed("\x1bXtest\x1b\\");
    }

    public function testUnknownCsiFinalByteDoesNothing(): void
    {
        $this->csi->expects($this->never())->method('printable');

        $this->parser->feed("\x1b[99Z");
    }

    public function testOscDispatchUnknownCommandDoesNothing(): void
    {
        $this->osc->expects($this->never())->method('title');

        $this->parser->feed("\x1b]99;something\x07");
    }

    public function testUnknownCsiFinalByteInAdapterDoesNothing(): void
    {
        $this->csi->expects($this->never())->method('cuu');
        $this->csi->expects($this->never())->method('cud');
        $this->csi->expects($this->never())->method('cuf');
        $this->csi->expects($this->never())->method('cub');
        $this->csi->expects($this->never())->method('cup');
        $this->csi->expects($this->never())->method('sgr');
        $this->csi->expects($this->never())->method('ed');
        $this->csi->expects($this->never())->method('el');
        $this->csi->expects($this->never())->method('decset');
        $this->csi->expects($this->never())->method('decrst');
        $this->csi->expects($this->never())->method('decstbm');
        $this->csi->expects($this->never())->method('tbc');
        $this->csi->expects($this->never())->method('cbt');
        $this->csi->expects($this->never())->method('cht');

        $this->parser->feed("\x1b[99X");
    }

    public function testCsiDispatchDefaultCaseDoesNothing(): void
    {
        $this->csi->expects($this->never())->method('printable');

        $this->parser->feed("\x1b[1;2q");
    }

    public function testPrintCharForwardsMultiByteRune(): void
    {
        $this->csi->expects($this->once())
            ->method('printable')
            ->with('é'); // Full UTF-8 rune, not just the lead byte

        $this->parser->feed("\xc3\xa9"); // UTF-8 encoding of 'é'
    }

    public function testOscClearTitle(): void
    {
        // "2;" is the legitimate OSC 2 clear-title sequence
        $this->osc->expects($this->once())->method('title')->with('');

        $this->parser->feed("\x1b]2;\x07"); // OSC 2 + BEL terminator
    }

    public function testExecuteControlByteTriggersNoCsiHandlerMethod(): void
    {
        // Control byte LF (0x0A) falls to default => null in HandlerAdapter::execute()
        // Verify no CsiHandler method is invoked
        $this->csi->expects($this->never())->method('cht');
        $this->csi->expects($this->never())->method('cub');
        $this->csi->expects($this->never())->method('cud');
        $this->csi->expects($this->never())->method('cuf');
        $this->csi->expects($this->never())->method('cuu');
        $this->csi->expects($this->never())->method('cup');
        $this->csi->expects($this->never())->method('sgr');
        $this->csi->expects($this->never())->method('ed');
        $this->csi->expects($this->never())->method('el');
        $this->csi->expects($this->never())->method('decset');
        $this->csi->expects($this->never())->method('decrst');
        $this->csi->expects($this->never())->method('decstbm');
        $this->csi->expects($this->never())->method('tbc');
        $this->csi->expects($this->never())->method('cbt');
        $this->csi->expects($this->never())->method('cht');
        $this->csi->expects($this->never())->method('printable');

        $this->parser->feed("\x0A"); // LF
    }

    // -------------------------------------------------------------------------
    // Item 4.1: HandlerAdapter edge cases
    // -------------------------------------------------------------------------

    public function testOscMalformedNoCommandNumberDoesNothing(): void
    {
        // OSC without a valid command number before semicolon
        $this->osc->expects($this->never())->method('title');

        $this->parser->feed("\x1b];Hello World\x07");
    }

    public function testOscMalformedNoSemicolonDoesNothing(): void
    {
        // OSC with command but no semicolon separator - the regex won't match
        $this->osc->expects($this->never())->method('title');

        $this->parser->feed("\x1b]2Hello World\x07");
    }

    public function testOscVeryLongPayloadHandled(): void
    {
        // Very long OSC payload should not cause issues
        $longTitle = str_repeat('x', 10000);
        $this->osc->expects($this->once())->method('title')->with($longTitle);

        $this->parser->feed("\x1b]2;" . $longTitle . "\x07");
    }

    public function testOscCommand3DoesNothing(): void
    {
        // OSC 3 (X11 window name) is not in [0-2] pattern, should be ignored
        $this->osc->expects($this->never())->method('title');

        $this->parser->feed("\x1b]3;Some Window Name\x07");
    }

    public function testOscCommand4AndAboveDoNothing(): void
    {
        // OSC commands 4+ are not in [0-2] pattern, should be ignored
        $this->osc->expects($this->never())->method('title');

        $this->parser->feed("\x1b]4;Some Data\x07");
    }

    public function testOscEmptyPayloadDispatched(): void
    {
        // OSC with empty payload after semicolon should dispatch empty string
        $this->osc->expects($this->once())->method('title')->with('');

        $this->parser->feed("\x1b]2;\x07");
    }

    // -------------------------------------------------------------------------
    // Item 4.2: DCS passthrough completeness
    // -------------------------------------------------------------------------

    public function testDcsPassthroughToHandlerInterface(): void
    {
        // Verify the parser correctly dispatches DCS sequences to the Handler interface
        $debugHandler = new \SugarCraft\Ansi\Parser\DebugHandler();
        $parser = new \SugarCraft\Ansi\Parser\Parser($debugHandler);

        // Feed a DCS sequence: ESC P (0x50) starts DCS
        // Format: DCS p s ... F (ST)
        // Feed DCS with params 1;2;3, final 'm', data 'test'
        $parser->feed("\x1bP1;2;3m\x1b\\");

        $dcsEvents = $debugHandler->filter('dcs');
        $this->assertNotEmpty($dcsEvents, 'DCS should be dispatched');
        $this->assertSame(ord('m'), $dcsEvents[0]['detail']['final']);
        $this->assertSame([1, 2, 3], $dcsEvents[0]['detail']['params']);
    }

    public function testDcsWithSubparams(): void
    {
        $debugHandler = new \SugarCraft\Ansi\Parser\DebugHandler();
        $parser = new \SugarCraft\Ansi\Parser\Parser($debugHandler);

        // DCS with sub-parameter separator ':'
        $parser->feed("\x1bP1:2:3m\x1b\\");

        $dcsEvents = $debugHandler->filter('dcs');
        $this->assertNotEmpty($dcsEvents);
        $this->assertSame([1, 2, 3], $dcsEvents[0]['detail']['params']);
    }
}
