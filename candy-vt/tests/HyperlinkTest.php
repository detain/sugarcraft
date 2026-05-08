<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Hyperlink\Hyperlink;

final class HyperlinkTest extends TestCase
{
    public function testFromRaw(): void
    {
        $h = Hyperlink::fromRaw('my-id', 'https://example.com/path');
        $this->assertSame('my-id', $h->id);
        $this->assertSame('https://example.com/path', $h->uri);
    }

    public function testEmptyByDefault(): void
    {
        $h = new Hyperlink();
        $this->assertSame('', $h->id);
        $this->assertSame('', $h->uri);
    }

    public function testEquals(): void
    {
        $a = Hyperlink::fromRaw('id1', 'https://a.com');
        $b = Hyperlink::fromRaw('id1', 'https://a.com');
        $c = Hyperlink::fromRaw('id2', 'https://a.com');
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
