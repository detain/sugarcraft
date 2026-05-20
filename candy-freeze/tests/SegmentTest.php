<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\Segment;
use PHPUnit\Framework\TestCase;

final class SegmentTest extends TestCase
{
    public function testDefaultBgIsNull(): void
    {
        $seg = new Segment('hello', null, false, false, false);
        $this->assertNull($seg->bg);
    }

    public function testBgCanBeSetViaConstructor(): void
    {
        $seg = new Segment('hello', null, false, false, false, '#ff0000');
        $this->assertSame('#ff0000', $seg->bg);
    }

    public function testWithBgReturnsNewInstance(): void
    {
        $seg = new Segment('hello', null, false, false, false);
        $newSeg = $seg->withBg('#00ff00');

        $this->assertNull($seg->bg);
        $this->assertSame('#00ff00', $newSeg->bg);
        $this->assertSame($seg->text, $newSeg->text);
        $this->assertSame($seg->fg, $newSeg->fg);
        $this->assertSame($seg->bold, $newSeg->bold);
        $this->assertSame($seg->italic, $newSeg->italic);
        $this->assertSame($seg->underline, $newSeg->underline);
    }

    public function testWithBgPreservesOtherProperties(): void
    {
        $seg = new Segment('test', '#ff0000', true, true, true, null);
        $newSeg = $seg->withBg('#0000ff');

        $this->assertSame('test', $newSeg->text);
        $this->assertSame('#ff0000', $newSeg->fg);
        $this->assertTrue($newSeg->bold);
        $this->assertTrue($newSeg->italic);
        $this->assertTrue($newSeg->underline);
        $this->assertSame('#0000ff', $newSeg->bg);
    }

    public function testWithBgNullRemovesBackground(): void
    {
        $seg = new Segment('hello', null, false, false, false, '#ff0000');
        $newSeg = $seg->withBg(null);

        $this->assertSame('#ff0000', $seg->bg);
        $this->assertNull($newSeg->bg);
    }
}
