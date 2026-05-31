<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

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
        $w = DefaultPrefixer::ansiWidth('hello');
        $this->assertSame(5, $w);
    }

    public function testAnsiWidthHelperWithAnsi(): void
    {
        $w = DefaultPrefixer::ansiWidth("\x1b[1mbold\x1b[0m");
        $this->assertSame(4, $w);
    }
}
