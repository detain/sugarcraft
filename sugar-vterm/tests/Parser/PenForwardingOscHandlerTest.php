<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cursor;
use SugarCraft\Vterm\Hyperlink\Hyperlink;
use SugarCraft\Vterm\Parser\CsiHandlerImpl;
use SugarCraft\Vterm\Parser\PenForwardingOscHandler;
use SugarCraft\Vterm\Theme;

final class PenForwardingOscHandlerTest extends TestCase
{
    private PenForwardingOscHandler $osc;

    protected function setUp(): void
    {
        $this->osc = new PenForwardingOscHandler();
    }

    public function testTitleStoresLastSeenTitle(): void
    {
        $this->osc->title('Hello Terminal');

        $this->assertSame('Hello Terminal', $this->osc->lastTitle());
    }

    public function testTitleEmptyByDefault(): void
    {
        $this->assertSame('', $this->osc->lastTitle());
    }

    /**
     * ESC-2: OSC 8 used to be parsed-and-discarded on the renderer path;
     * the handler now stores the link (and forwards it when a sink is wired).
     */
    public function testHyperlinkStoresLink(): void
    {
        $this->osc->hyperlink('https://example.com', 'id123');

        $link = $this->osc->currentHyperlink();
        $this->assertNotNull($link);
        $this->assertSame('https://example.com', $link->uri);
        $this->assertSame('id123', $link->id);
        // Storing the link must not disturb the title channel.
        $this->assertSame('', $this->osc->lastTitle());
    }

    public function testEmptyUriClosesLink(): void
    {
        // sugar-ansi-parser HandlerAdapter contract: empty URI closes (xterm:
        // "an empty URI … terminates the current hyperlink").
        $this->osc->hyperlink('https://example.com', 'a');
        $this->osc->hyperlink('', '');

        $this->assertNull($this->osc->currentHyperlink());
    }

    public function testForwardsLinkToGridPen(): void
    {
        $csi = new CsiHandlerImpl(new Buffer(10, 2), new Cursor(), new Theme());
        $osc = new PenForwardingOscHandler($csi);

        $osc->hyperlink('https://example.com', 'x');
        $this->assertTrue((new Hyperlink('x', 'https://example.com'))->equals($csi->hyperlink()));

        $osc->hyperlink('', '');
        $this->assertNull($csi->hyperlink());
    }

    public function testMultipleTitlesOverwrite(): void
    {
        $this->osc->title('First');
        $this->osc->title('Second');

        $this->assertSame('Second', $this->osc->lastTitle());
    }

    public function testOldNameIsTheSameClass(): void
    {
        // class_alias targets are invisible to static analysis, so the
        // façade is spelled as a runtime string and asserted into its type.
        $legacyName = 'SugarCraft\Vterm\Parser\OscHandlerImpl';
        $this->assertTrue(\class_exists($legacyName));

        $legacy = new $legacyName();
        $this->assertInstanceOf(PenForwardingOscHandler::class, $legacy);
        $this->assertSame(PenForwardingOscHandler::class, \get_class($legacy));

        $legacy->title('through the old spelling');
        $legacy->hyperlink('https://example.com', 'a');
        $this->assertSame('through the old spelling', $legacy->lastTitle());
        $this->assertNotNull($legacy->currentHyperlink());
    }
}
