<?php

declare(strict_types=1);

namespace CandyCore\SuperCandy\Tests;

use CandyCore\SuperCandy\Entry;
use PHPUnit\Framework\TestCase;

final class EntryTest extends TestCase
{
    public function testParentSentinelIsRecognised(): void
    {
        $this->assertTrue(Entry::parent()->isParentSentinel());
        $this->assertFalse((new Entry('foo', true, 0, 0))->isParentSentinel());
    }

    public function testDisplaySizeForDirectory(): void
    {
        $this->assertSame('DIR', (new Entry('a', true, 12345, 0))->displaySize());
    }

    public function testDisplaySizeForLink(): void
    {
        $this->assertSame('LINK', (new Entry('a', false, 1234, 0, isLink: true))->displaySize());
    }

    public function testDisplaySizeBytes(): void
    {
        $this->assertSame('512B', (new Entry('a', false, 512, 0))->displaySize());
    }

    public function testDisplaySizeKilobytes(): void
    {
        $this->assertSame('2.0KB', (new Entry('a', false, 2048, 0))->displaySize());
    }

    public function testDisplaySizeMegabytes(): void
    {
        $this->assertSame('1.5MB', (new Entry('a', false, 1024 * 1024 * 3 / 2, 0))->displaySize());
    }
}
