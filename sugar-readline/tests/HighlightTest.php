<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Highlight;

final class HighlightTest extends TestCase
{
    public function testHighlightReturnsSingleUnstyledSpan(): void
    {
        $h = new Highlight();
        $spans = $h->highlight('hello world');
        $this->assertCount(1, $spans);
        $this->assertSame('hello world', $spans[0]['text']);
        $this->assertSame('', $spans[0]['style']);
    }

    public function testHighlightWithEmptyString(): void
    {
        $h = new Highlight();
        $spans = $h->highlight('');
        $this->assertCount(1, $spans);
        $this->assertSame('', $spans[0]['text']);
        $this->assertSame('', $spans[0]['style']);
    }

    public function testHighlightReturnsListOfSpans(): void
    {
        $h = new Highlight();
        $result = $h->highlight('abc');
        $this->assertIsList($result);
        $this->assertArrayHasKey('text', $result[0]);
        $this->assertArrayHasKey('style', $result[0]);
    }
}
