<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser\Tests;

use SugarCraft\AnsiParser\Parser\RecordingOscHandler;
use PHPUnit\Framework\TestCase;

final class RecordingOscHandlerTest extends TestCase
{
    public function testTitleStoresValue(): void
    {
        $impl = new RecordingOscHandler();

        $impl->title('My Window Title');

        $this->assertSame('My Window Title', $impl->lastTitle());
    }

    public function testHyperlinkDoesNotAffectTitle(): void
    {
        $impl = new RecordingOscHandler();

        $impl->hyperlink('https://example.com', 'my-id');

        $this->assertSame('', $impl->lastTitle(), 'hyperlink() should not affect lastTitle()');
    }

    public function testHyperlinkStoresUriAndId(): void
    {
        $impl = new RecordingOscHandler();

        $impl->hyperlink('https://example.com', 'anchor-1');

        $this->assertSame('https://example.com', $impl->hyperlinkUri());
        $this->assertSame('anchor-1', $impl->hyperlinkId());
    }

    public function testHyperlinkInitiallyEmpty(): void
    {
        $impl = new RecordingOscHandler();

        $this->assertSame('', $impl->hyperlinkUri());
        $this->assertSame('', $impl->hyperlinkId());
    }

    public function testHyperlinkEmptyUriClosesLink(): void
    {
        $impl = new RecordingOscHandler();

        $impl->hyperlink('https://example.com', 'anchor-1');
        $impl->hyperlink('', '');

        $this->assertSame('', $impl->hyperlinkUri(), 'empty URI closes the hyperlink');
        $this->assertSame('', $impl->hyperlinkId());
    }

    public function testCloseSequenceCarryingIdStillResetsBothFields(): void
    {
        // 'ESC]8;id=x;ST' parses to hyperlink('', 'x'); the
        // close path must clear the id too — the docblock always promised
        // BOTH fields reset, and a stale id outlives the link it named.
        $impl = new RecordingOscHandler();

        $impl->hyperlink('https://example.com', 'anchor-1');
        $impl->hyperlink('', 'stale-param');

        $this->assertSame('', $impl->hyperlinkUri());
        $this->assertSame('', $impl->hyperlinkId(), 'close resets the id even when the close carries one');
    }

    public function testLastTitleInitiallyEmpty(): void
    {
        $impl = new RecordingOscHandler();

        $this->assertSame('', $impl->lastTitle());
    }

    public function testTitleOverwritesPrevious(): void
    {
        $impl = new RecordingOscHandler();

        $impl->title('First Title');
        $impl->title('Second Title');

        $this->assertSame('Second Title', $impl->lastTitle());
    }

    public function testHyperlinkAfterTitleDoesNotClearTitle(): void
    {
        $impl = new RecordingOscHandler();

        $impl->title('Window Title');
        $impl->hyperlink('https://example.com', 'id');

        $this->assertSame('Window Title', $impl->lastTitle());
    }

    /**
     * Façade law for the deprecated name (finding 029, gate G4): the old
     * FQN must be the SAME class — not a copy, not a wrapper — so shipped
     * consumers (new via old name, `: OscHandlerImpl` type hints, instanceof
     * across the two spellings) keep working bit-identically.
     */
    public function testOldNameIsTheSameClass(): void
    {
        $old = new \SugarCraft\AnsiParser\Parser\OscHandlerImpl();

        $this->assertInstanceOf(RecordingOscHandler::class, $old);
        $this->assertSame(RecordingOscHandler::class, \get_class($old));

        $old->title('via old name');
        $this->assertSame('via old name', $old->lastTitle());
    }
}
