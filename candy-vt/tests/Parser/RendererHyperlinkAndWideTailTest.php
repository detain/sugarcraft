<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Terminal;

/**
 * Renderer-path (vcr) pins for the ESC-2 hyperlink pen and the ESC-3 wide
 * tail unification: bytes go through {@see Terminal::feed()} — parser →
 * HandlerAdapter → OscHandlerImpl/CsiHandlerImpl — and the observable is the
 * cell grid, mirroring what candy-vcr snapshots.
 *
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html#h3-hyperlinks
 */
final class RendererHyperlinkAndWideTailTest extends TestCase
{
    public function testLinkOpensPrintsAndClosesOntoCells(): void
    {
        $t = Terminal::new(10, 2);
        // OSC 8 open, print two chars, OSC 8 close (empty URI), print one.
        $t->feed("\x1b]8;id=a;https://example.com\x07hi\x1b]8;;\x07!");

        $link = $t->grid()->cell(0, 0)->hyperlink;
        $this->assertNotNull($link, 'printed cell carries the open link');
        $this->assertSame('https://example.com', $link->uri);
        $this->assertSame('a', $link->id);
        $this->assertTrue($t->grid()->cell(0, 1)->hyperlink->equals($link), 'second char same link');
        $this->assertNull($t->grid()->cell(0, 2)->hyperlink, 'close after OSC 8;; drops the link');
    }

    public function testWideTailCarriesHeadLink(): void
    {
        $t = Terminal::new(10, 2);
        $t->feed("\x1b]8;;https://example.com\x07日");

        $head = $t->grid()->cell(0, 0);
        $tail = $t->grid()->cell(0, 1);
        $this->assertNotNull($head->hyperlink);
        $this->assertTrue($tail->continuation, 'ESC-3: natural tail is a continuation cell');
        $this->assertNotNull($tail->hyperlink, 'phantom column inherits the head link (Cell::continuation copy)');
        $this->assertSame('https://example.com', $tail->hyperlink->uri);
    }

    public function testSgrResetKeepsLinkButRisClearsIt(): void
    {
        // xterm scopes link state to OSC 8 itself; RIS resets everything.
        $t = Terminal::new(10, 2);
        $t->feed("\x1b]8;;https://example.com\x07");
        $t->feed("\x1b[0mA");
        $this->assertNotNull($t->grid()->cell(0, 0)->hyperlink, 'SGR 0 must not close the link pen');

        $t2 = Terminal::new(10, 2);
        $t2->feed("\x1b]8;;https://example.com\x07\x1bc" . 'B');
        // RIS also homes and (per reset semantics) erases; assert the PEN,
        // which the next printed cell reveals.
        $this->assertNull($t2->grid()->cell(0, 0)->hyperlink, 'RIS clears the link pen');
    }

    public function testWideTailIsColouredContinuationNotBlank(): void
    {
        // ESC-3: natural wide tails previously took Cell::empty() on this
        // path — a red-background CJK glyph rendered as glyph + default-colour
        // hole. The emulator always painted the head's rendition on the tail.
        $t = Terminal::new(10, 1);
        $t->feed("\x1b[41m日");

        $head = $t->grid()->cell(0, 0);
        $tail = $t->grid()->cell(0, 1);
        $this->assertSame(1, $head->bg);
        $this->assertTrue($tail->continuation);
        $this->assertSame('', $tail->grapheme, 'continuation cell prints nothing');
        $this->assertSame($head->bg, $tail->bg, 'tail carries the head background');
        $this->assertFalse($tail->equals(Cell::empty()), 'tail is not the blank cell any more');
    }

    public function testCombiningMarkAfterWideHeadIsDropped(): void
    {
        // Mirrors the emulator's attachCombiningChar continuation guard: a
        // mark whose host is the phantom column decorates nothing.
        $t = Terminal::new(10, 1);
        $t->feed("日\u0301");

        $tail = $t->grid()->cell(0, 1);
        $this->assertTrue($tail->continuation);
        $this->assertSame('', $tail->combining, 'phantom column stays undecorated');
        $this->assertSame('日', $t->grid()->cell(0, 0)->grapheme);
    }
}
