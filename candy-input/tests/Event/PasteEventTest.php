<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Event\PasteEvent;
use SugarCraft\Input\Event;

/**
 * Tests for PasteEvent — bracketed paste content events.
 */
final class PasteEventTest extends TestCase
{
    public function testConstructorSetsContent(): void
    {
        $content = 'hello world';
        $event = new PasteEvent($content);

        $this->assertSame($content, $event->content);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new PasteEvent('test');

        $this->assertInstanceOf(Event::class, $event);
    }

    public function testMaxSizeConstant(): void
    {
        $this->assertSame(1 << 20, PasteEvent::MAX_SIZE);
    }

    public function testTruncateReturnsNewInstance(): void
    {
        $event = PasteEvent::truncate('short content');

        $this->assertInstanceOf(PasteEvent::class, $event);
        $this->assertSame('short content', $event->content);
    }

    public function testTruncateUnderMaxSizeUnchanged(): void
    {
        $content = str_repeat('a', 100);
        $event = PasteEvent::truncate($content);

        $this->assertSame($content, $event->content);
    }

    public function testTruncateExactMaxSizeUnchanged(): void
    {
        $content = str_repeat('a', PasteEvent::MAX_SIZE);
        $event = PasteEvent::truncate($content);

        $this->assertSame($content, $event->content);
    }

    public function testTruncateOverMaxSizeTruncates(): void
    {
        $content = str_repeat('a', PasteEvent::MAX_SIZE + 100);
        $event = PasteEvent::truncate($content);

        $this->assertSame(PasteEvent::MAX_SIZE, strlen($event->content));
        $this->assertSame(substr($content, 0, PasteEvent::MAX_SIZE), $event->content);
    }

    public function testTruncateEmptyString(): void
    {
        $event = PasteEvent::truncate('');

        $this->assertSame('', $event->content);
    }

    public function testTruncateBinaryContent(): void
    {
        // Each repetition is 3 bytes; use 400k reps = 1.2 MB > 1 MiB MAX_SIZE
        $content = str_repeat("\x00\xff\xfe", 400000);
        $truncated = PasteEvent::truncate($content);

        $this->assertSame(PasteEvent::MAX_SIZE, strlen($truncated->content));
    }
}
