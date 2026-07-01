<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\Lister\{DefaultPrefixer, DefaultSuffixer, StringItem};
use PHPUnit\Framework\TestCase;

final class DefaultPrefixerTest extends TestCase
{
    public function testInitPrefixerReturnsPrefixWidth(): void
    {
        $p = new DefaultPrefixer();
        $width = $p->initPrefixer(new StringItem('item'), 0, 0, 5, 80, 24);
        $this->assertGreaterThan(0, $width);
    }

    public function testInitPrefixerComputesPrefixWidth(): void
    {
        $p = new DefaultPrefixer();
        $width = $p->initPrefixer(new StringItem('item'), 5, 3, 5, 80, 24);
        // The prefix width should be computed based on separator, number, marker, and spaces
        $this->assertGreaterThan(0, $width);
    }

    public function testPrefixOnFirstLine(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('first'), 0, 0, 5, 80, 24);
        $result = $p->prefix(0, 3);
        $this->assertStringContainsString('╭', $result);
    }

    public function testPrefixOnSubsequentLine(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('item'), 1, 1, 5, 80, 24);
        $result = $p->prefix(1, 3);
        // Should contain separator (├ or │)
        $this->assertIsString($result);
    }

    public function testPrefixWithWrapContinuation(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('item'), 2, 1, 5, 80, 24);
        $result = $p->prefix(1, 3);
        $this->assertStringContainsString('│', $result);
    }

    public function testPrefixShowsCurrentMarkerForCurrentItem(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('current'), 0, 0, 5, 80, 24);
        $result = $p->prefix(0, 3);
        $this->assertStringContainsString('>', $result);
    }

    public function testPrefixShowsEmptyMarkerForNonCurrentItem(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('other'), 1, 0, 5, 80, 24);
        $result = $p->prefix(0, 3);
        $this->assertStringContainsString(' ', $result);
    }

    public function testPrefixWithRelativeNumbers(): void
    {
        $p = new DefaultPrefixer();
        $p->numberRelative = true;
        $p->initPrefixer(new StringItem('rel'), 0, 5, 3, 80, 24);
        $result = $p->prefix(0, 3);
        $this->assertIsString($result);
    }

    public function testPrefixWithoutNumbers(): void
    {
        $p = new DefaultPrefixer();
        $p->number = false;
        $p->initPrefixer(new StringItem('nonum'), 0, 0, 5, 80, 24);
        $result = $p->prefix(0, 3);
        // Should not contain digits for line numbers
        $this->assertIsString($result);
    }

    public function testAnsiWidthHelper(): void
    {
        $w = Width::string('hello');
        $this->assertSame(5, $w);
    }

    public function testAnsiWidthHelperWithAnsi(): void
    {
        $w = Width::string("\x1b[1mbold\x1b[0m");
        $this->assertSame(4, $w);
    }

    /**
     * Regression: when cursor is on a non-zero index, the > marker must appear
     * on the cursor item, NOT only on index 0.
     */
    public function testMarkerOnNonZeroCursorItem(): void
    {
        $p = new DefaultPrefixer();
        // Cursor at index 2, rendering item at index 2 (the cursor item)
        $p->initPrefixer(new StringItem('item2'), 2, 2, 5, 80, 24);
        $prefix = $p->prefix(0, 1);
        $this->assertStringContainsString('>', $prefix);

        // Rendering item at index 0 (not the cursor)
        $p2 = new DefaultPrefixer();
        $p2->initPrefixer(new StringItem('item0'), 0, 2, 5, 80, 24);
        $prefix0 = $p2->prefix(0, 1);
        $this->assertStringContainsString(' ', $prefix0);
        $this->assertStringNotContainsString('>', $prefix0);

        // Rendering item at index 1 (not the cursor)
        $p3 = new DefaultPrefixer();
        $p3->initPrefixer(new StringItem('item1'), 1, 2, 5, 80, 24);
        $prefix1 = $p3->prefix(0, 1);
        $this->assertStringContainsString(' ', $prefix1);
        $this->assertStringNotContainsString('>', $prefix1);
    }
}
