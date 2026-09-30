<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Hyperlink\Hyperlink;
use SugarCraft\Vt\Parser\CsiHandlerImpl;
use SugarCraft\Vt\Parser\OscHandlerImpl;
use SugarCraft\Vt\Theme;

final class OscHandlerImplTest extends TestCase
{
    private OscHandlerImpl $osc;

    protected function setUp(): void
    {
        $this->osc = new OscHandlerImpl();
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
        // candy-ansi HandlerAdapter contract: empty URI closes (xterm:
        // "an empty URI … terminates the current hyperlink").
        $this->osc->hyperlink('https://example.com', 'a');
        $this->osc->hyperlink('', '');

        $this->assertNull($this->osc->currentHyperlink());
    }

    public function testForwardsLinkToGridPen(): void
    {
        $csi = new CsiHandlerImpl(new Buffer(10, 2), new Cursor(), new Theme());
        $osc = new OscHandlerImpl($csi);

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
}
