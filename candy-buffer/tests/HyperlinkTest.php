<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Hyperlink;

/**
 * @covers \SugarCraft\Buffer\Hyperlink
 */
final class HyperlinkTest extends TestCase
{
    public function testAcceptsNormalUrl(): void
    {
        $link = new Hyperlink('https://example.com/a?b=c#d');
        $this->assertSame('https://example.com/a?b=c#d', $link->url());
        $this->assertSame('', $link->id());
    }

    public function testAcceptsNormalUrlWithId(): void
    {
        $link = new Hyperlink('https://example.com/path', 'myid');
        $this->assertSame('https://example.com/path', $link->url());
        $this->assertSame('myid', $link->id());
    }

    public function testRejectsEscInUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        new Hyperlink("https://example.com/\x1b\\evil");
    }

    public function testRejectsBelInUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        new Hyperlink("https://example.com/\x07evil");
    }

    public function testRejectsControlInId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        new Hyperlink('https://example.com', "id\x1bwithesc");
    }

    public function testRejectsAllC0ControlsInUrl(): void
    {
        // Test a representative sample: NUL, LF, TAB, ESC
        $this->expectException(\InvalidArgumentException::class);
        new Hyperlink("https://example.com/\x00null");
    }

    public function testFactoryNewAcceptsNormalUrl(): void
    {
        $link = Hyperlink::new('https://safe.site/page');
        $this->assertSame('https://safe.site/page', $link->url());
    }

    public function testFactoryNewRejectsControlChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Hyperlink::new("https://evil\x1b.site");
    }
}
