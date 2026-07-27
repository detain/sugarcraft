<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\CsiHandler;
use SugarCraft\Ansi\Parser\DebugHandler;
use SugarCraft\Ansi\Parser\HandlerAdapter;
use SugarCraft\Ansi\Parser\OscHandler;
use SugarCraft\Ansi\Parser\OscHandlerImpl;
use SugarCraft\Ansi\Parser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the full hyperlink parsing chain, including the
 * private parseHyperlinkId method in HandlerAdapter (tested indirectly).
 */
final class HyperlinkParsingTest extends TestCase
{
    private DebugHandler $debug;

    protected function setUp(): void
    {
        $this->debug = new DebugHandler();
    }

    public function testHyperlinkIdExtractedFromIdPair(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        $parser->feed("\x1b]8;id=my-anchor;https://example.com\x07");

        $this->assertSame('https://example.com', $oscImpl->hyperlinkUri());
        $this->assertSame('my-anchor', $oscImpl->hyperlinkId());
    }

    public function testHyperlinkIdWithColonInValue(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        // URI contains colons which should NOT be split as param separators
        $parser->feed("\x1b]8;id=anchor;https://example.com/path:with:colons\x07");

        $this->assertSame('https://example.com/path:with:colons', $oscImpl->hyperlinkUri());
        $this->assertSame('anchor', $oscImpl->hyperlinkId());
    }

    public function testHyperlinkIdTakesFirstIdWhenMultiplePresent(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        // OSC 8 with id= appearing twice in the params field (colon-separated list).
        // parseHyperlinkId iterates left-to-right and returns the first match.
        // Params field: "id=first:id=second" (colon is the list separator per VT500 spec)
        $parser->feed("\x1b]8;id=first:id=second;https://example.com\x07");

        $this->assertSame('https://example.com', $oscImpl->hyperlinkUri());
        $this->assertSame('first', $oscImpl->hyperlinkId());
    }

    public function testHyperlinkIdIsEmptyWhenNoIdParam(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        $parser->feed("\x1b]8;;https://example.com\x07");

        $this->assertSame('https://example.com', $oscImpl->hyperlinkUri());
        $this->assertSame('', $oscImpl->hyperlinkId());
    }

    public function testHyperlinkIdWithEmptyParamsField(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        // OSC 8 with empty params field (just semicolons): ESC ] 8 ; ; uri ST
        $parser->feed("\x1b]8;;https://example.com\x07");

        $this->assertSame('', $oscImpl->hyperlinkId());
    }

    public function testHyperlinkIdWithOnlyIdNoUri(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        // With no URI after second semicolon, it's treated as closing
        $parser->feed("\x1b]8;id=test;\x07");

        // Empty URI closes the link; id is still stored during parse
        $this->assertSame('', $oscImpl->hyperlinkUri());
    }

    public function testHyperlinkCloseWithEmptyUriAndId(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        $parser->feed("\x1b]8;id=first;https://example.com\x07");
        $parser->feed("\x1b]8;;\x07"); // close

        $this->assertSame('', $oscImpl->hyperlinkUri());
        $this->assertSame('', $oscImpl->hyperlinkId());
    }

    public function testHyperlinkIdWithSpecialCharacters(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        // IDs can contain most characters
        $parser->feed("\x1b]8;id=anchor_123;https://example.com\x07");

        $this->assertSame('anchor_123', $oscImpl->hyperlinkId());
    }

    public function testOscTitleStripsCommandPrefix(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        $parser->feed("\x1b]2;My Window Title\x07");

        // The "2;" command prefix should be stripped, leaving just the title
        $this->assertSame('My Window Title', $oscImpl->lastTitle());
    }

    public function testOscTitleClearWithEmptyPayload(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        $parser->feed("\x1b]2;\x07"); // OSC 2 with empty payload clears title

        $this->assertSame('', $oscImpl->lastTitle());
    }

    public function testDebugHandlerLogsOscForHyperlink(): void
    {
        $oscImpl = new OscHandlerImpl();
        $adapter = new HandlerAdapter($this->createStub(CsiHandler::class), $oscImpl);
        $parser  = new Parser($adapter);

        $parser->feed("\x1b]8;id=anchor;https://example.com\x07");

        // OscHandlerImpl stores the hyperlink; use a DebugHandler to log the raw OSC
        $debugAdapter = new HandlerAdapter(
            $this->createStub(CsiHandler::class),
            new class ($this->debug) implements OscHandler {
                public function __construct(private DebugHandler $log) {}
                public function title(string $title): void { $this->log->oscDispatch("2;$title"); }
                public function hyperlink(string $uri, string $id): void { $this->log->oscDispatch("8;id=$id;$uri"); }
            }
        );
        $debugParser = new Parser($debugAdapter);
        $debugParser->feed("\x1b]8;id=anchor;https://example.com\x07");

        $oscs = $this->debug->filter('osc');
        $this->assertCount(1, $oscs);
        $this->assertSame('8;id=anchor;https://example.com', $oscs[0]['detail']);
    }

    public function testFullSequencePrintsThenHyperlink(): void
    {
        $oscImpl = new OscHandlerImpl();
        $csiImpl = new class implements CsiHandler {
            public function printable(string $byte): void {}
            public function cuu(int $count): void {}
            public function cud(int $count): void {}
            public function cuf(int $count): void {}
            public function cub(int $count): void {}
            public function cup(int $row, int $col): void {}
            public function hvp(int $row, int $col): void {}
            public function sgr(array $params): void {}
            public function ed(int $mode): void {}
            public function el(int $mode): void {}
            public function decset(int $mode, int $prefix): void {}
            public function decrst(int $mode, int $prefix): void {}
            public function decstbm(int $top, int $bottom): void {}
            public function tbc(int $mode): void {}
            public function cht(int $count = 1): void {}
            public function cbt(int $count = 1): void {}
            public function cr(): void {}
            public function lf(): void {}
            public function su(int $count = 1): void {}
            public function sd(int $count = 1): void {}
            public function il(int $count = 1): void {}
            public function dl(int $count = 1): void {}
            public function ich(int $count = 1): void {}
            public function dch(int $count = 1): void {}
            public function rep(int $count = 1): void {}
            public function scosc(): void {}
            public function scorc(): void {}
            public function gridRows(): int { return 24; }
            public function gridCols(): int { return 80; }
        };
        $adapter = new HandlerAdapter($csiImpl, $oscImpl);
        $parser = new Parser($adapter);

        // Print some text, open hyperlink, print link text, close hyperlink
        $parser->feed("Text ");
        $parser->feed("\x1b]8;id=link;https://example.com\x07");
        $parser->feed("click here");

        // After opening, URI should be set
        $this->assertSame('https://example.com', $oscImpl->hyperlinkUri());
        $this->assertSame('link', $oscImpl->hyperlinkId());

        // Close the hyperlink with empty URI
        $parser->feed("\x1b]8;;\x07");

        // After closing, both should be empty
        $this->assertSame('', $oscImpl->hyperlinkUri());
        $this->assertSame('', $oscImpl->hyperlinkId());
    }
}
