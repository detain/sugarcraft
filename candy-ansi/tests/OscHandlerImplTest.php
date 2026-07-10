<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\OscHandlerImpl;
use PHPUnit\Framework\TestCase;

final class OscHandlerImplTest extends TestCase
{
    public function testTitleStoresValue(): void
    {
        $impl = new OscHandlerImpl();

        $impl->title('My Window Title');

        $this->assertSame('My Window Title', $impl->lastTitle());
    }

    public function testHyperlinkDoesNotAffectTitle(): void
    {
        $impl = new OscHandlerImpl();

        $impl->hyperlink('https://example.com', 'my-id');

        $this->assertSame('', $impl->lastTitle(), 'hyperlink() should not affect lastTitle()');
    }

    public function testHyperlinkStoresUriAndId(): void
    {
        $impl = new OscHandlerImpl();

        $impl->hyperlink('https://example.com', 'anchor-1');

        $this->assertSame('https://example.com', $impl->hyperlinkUri());
        $this->assertSame('anchor-1', $impl->hyperlinkId());
    }

    public function testHyperlinkInitiallyEmpty(): void
    {
        $impl = new OscHandlerImpl();

        $this->assertSame('', $impl->hyperlinkUri());
        $this->assertSame('', $impl->hyperlinkId());
    }

    public function testHyperlinkEmptyUriClosesLink(): void
    {
        $impl = new OscHandlerImpl();

        $impl->hyperlink('https://example.com', 'anchor-1');
        $impl->hyperlink('', '');

        $this->assertSame('', $impl->hyperlinkUri(), 'empty URI closes the hyperlink');
        $this->assertSame('', $impl->hyperlinkId());
    }

    public function testLastTitleInitiallyEmpty(): void
    {
        $impl = new OscHandlerImpl();

        $this->assertSame('', $impl->lastTitle());
    }

    public function testTitleOverwritesPrevious(): void
    {
        $impl = new OscHandlerImpl();

        $impl->title('First Title');
        $impl->title('Second Title');

        $this->assertSame('Second Title', $impl->lastTitle());
    }

    public function testHyperlinkAfterTitleDoesNotClearTitle(): void
    {
        $impl = new OscHandlerImpl();

        $impl->title('Window Title');
        $impl->hyperlink('https://example.com', 'id');

        $this->assertSame('Window Title', $impl->lastTitle());
    }
}
