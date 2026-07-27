<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use SugarCraft\Lister\DefaultSuffixer;
use SugarCraft\Lister\StringItem;
use PHPUnit\Framework\TestCase;

final class DefaultSuffixerTest extends TestCase
{
    public function testInitSuffixerReturnsSuffixWidth(): void
    {
        $s = new DefaultSuffixer();
        $width = $s->initSuffixer(new StringItem('item'), 0, 0, 5, 80, 24);
        $this->assertGreaterThan(0, $width);
    }

    public function testSuffixOnCursorFirstLineShowsMarker(): void
    {
        $s = new DefaultSuffixer();
        $s->initSuffixer(new StringItem('current'), 0, 0, 5, 80, 24);
        $result = $s->suffix(0, 1);
        $this->assertSame('<', $result);
    }

    public function testSuffixOnCursorSubsequentLineReturnsEmpty(): void
    {
        $s = new DefaultSuffixer();
        $s->initSuffixer(new StringItem('current'), 0, 0, 5, 80, 24);
        // When totalLines > 1 and currentLine > 0, suffix is empty
        $result = $s->suffix(1, 3);
        $this->assertSame('', $result);
    }

    public function testSuffixOnNonCursorItemReturnsEmpty(): void
    {
        $s = new DefaultSuffixer();
        $s->initSuffixer(new StringItem('other'), 1, 0, 5, 80, 24);
        $result = $s->suffix(0, 1);
        $this->assertSame('', $result);
    }

    public function testSuffixOnNonCursorFirstLineWithMultipleLines(): void
    {
        $s = new DefaultSuffixer();
        $s->initSuffixer(new StringItem('other'), 1, 0, 5, 80, 24);
        // Even on first line of non-cursor item, no marker
        $result = $s->suffix(0, 3);
        $this->assertSame('', $result);
    }

    public function testSuffixWithDifferentCurrentMarker(): void
    {
        $s = new DefaultSuffixer();
        $s->currentMarker = '*';
        $s->initSuffixer(new StringItem('current'), 0, 0, 5, 80, 24);
        $result = $s->suffix(0, 1);
        $this->assertSame('*', $result);
    }

    public function testCursorItemWithIndexFiveRendersCorrectly(): void
    {
        $s = new DefaultSuffixer();
        // Cursor at index 5, rendering item at index 5 (the cursor item)
        $s->initSuffixer(new StringItem('item5'), 5, 5, 5, 80, 24);
        $result = $s->suffix(0, 1);
        $this->assertSame('<', $result);
    }

    public function testNonCursorItemAtDifferentIndex(): void
    {
        $s = new DefaultSuffixer();
        // Cursor at index 5, rendering item at index 2 (not cursor)
        $s->initSuffixer(new StringItem('item2'), 2, 5, 5, 80, 24);
        $result = $s->suffix(0, 1);
        $this->assertSame('', $result);
    }

    public function testSuffixerIsStatefulAcrossMultipleInits(): void
    {
        $s = new DefaultSuffixer();
        // First item (cursor)
        $s->initSuffixer(new StringItem('first'), 0, 0, 5, 80, 24);
        $this->assertSame('<', $s->suffix(0, 1));

        // Second item (cursor)
        $s->initSuffixer(new StringItem('second'), 1, 1, 5, 80, 24);
        $this->assertSame('<', $s->suffix(0, 1));

        // Third item (not cursor)
        $s->initSuffixer(new StringItem('third'), 2, 1, 5, 80, 24);
        $this->assertSame('', $s->suffix(0, 1));
    }
}
