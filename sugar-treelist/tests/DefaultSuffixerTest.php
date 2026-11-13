<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests;

use SugarCraft\TreeList\DefaultSuffixer;
use SugarCraft\TreeList\StringItem;
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

    /**
     * The marker lands verbatim inside every framed row, so escape and control
     * bytes must be refused at the boundary — the same class of glyph-injection
     * hole the prefixer fields carried before 2026-11-03.
     */
    public function testControlByteMarkerIsRefused(): void
    {
        $s = new DefaultSuffixer();
        $s->currentMarker = "\x1b[1m";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DefaultSuffixer:$currentMarker must be a printable glyph string without control characters (C0/DEL/C1).');

        $s->initSuffixer(new StringItem('item'), 0, 0, 5, 80, 24);
    }

    /**
     * treelist-027: the refusal now routes through the shared
     * Support\AssertGlyph::field() helper — pin the exact reduced message so a
     * future change to the helper reddens here (the old inline copy emitted the
     * identical string; the contract is byte-preserved).
     */
    public function testSuffixerRefusalMessageIsTheSharedHelperShape(): void
    {
        $s = new DefaultSuffixer();
        $s->currentMarker = "\x07";
        try {
            $s->initSuffixer(new StringItem('i'), 0, 0, 5, 80, 24);
            $this->fail('expected control-byte refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(
                'DefaultSuffixer:$currentMarker must be a printable glyph string without control characters (C0/DEL/C1).',
                $e->getMessage(),
            );
        }
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
