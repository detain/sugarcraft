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

    public function testEqualsTrue(): void
    {
        $a = new Hyperlink('https://example.com/path', 'myid');
        $b = new Hyperlink('https://example.com/path', 'myid');

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsFalseDueToUrl(): void
    {
        $a = new Hyperlink('https://a.com');
        $b = new Hyperlink('https://b.com');

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsFalseDueToId(): void
    {
        $a = new Hyperlink('https://example.com', 'id1');
        $b = new Hyperlink('https://example.com', 'id2');

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsBothEmptyId(): void
    {
        $a = new Hyperlink('https://example.com', '');
        $b = new Hyperlink('https://example.com', '');

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsDifferentInstancesSameValues(): void
    {
        $a = Hyperlink::new('https://safe.site', 'x');
        $b = Hyperlink::new('https://safe.site', 'x');

        $this->assertTrue($a->equals($b));
        $this->assertNotSame($a, $b);
    }

    public function testSerialize(): void
    {
        $link = new Hyperlink('https://example.com/path', 'myid');

        $data = $link->__serialize();

        $this->assertSame('https://example.com/path', $data['url']);
        $this->assertSame('myid', $data['id']);
    }

    public function testSerializeEmptyId(): void
    {
        $link = new Hyperlink('https://example.com');

        $data = $link->__serialize();

        $this->assertSame('https://example.com', $data['url']);
        $this->assertSame('', $data['id']);
    }

    public function testUnserializeRoundTrip(): void
    {
        $original = new Hyperlink('https://test.example.com/path?a=1#b', 'session123');
        $data = $original->__serialize();

        $reconstituted = new Hyperlink($data['url'], $data['id']);

        $this->assertSame($original->url(), $reconstituted->url());
        $this->assertSame($original->id(), $reconstituted->id());
        $this->assertTrue($original->equals($reconstituted));
    }

    public function testJsonSerialize(): void
    {
        $link = new Hyperlink('https://example.com', 'anchor');

        $json = json_encode($link);
        $decoded = json_decode($json, true);

        $this->assertSame('https://example.com', $decoded['url']);
        $this->assertSame('anchor', $decoded['id']);
    }

    public function testJsonSerializeEmptyId(): void
    {
        $link = Hyperlink::new('https://example.com');

        $json = json_encode($link);
        $decoded = json_decode($json, true);

        $this->assertSame('https://example.com', $decoded['url']);
        $this->assertSame('', $decoded['id']);
    }

    public function testUrlReturnsVerbatim(): void
    {
        $link = new Hyperlink('https://example.com/a b/c');
        $this->assertSame('https://example.com/a b/c', $link->url());
    }
}
