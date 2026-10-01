<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use SugarCraft\Lister\{DefaultPrefixer, Model, StringItem};
use PHPUnit\Framework\TestCase;

/**
 * DefaultPrefixer behaviour pins.
 *
 * The original suite asserted only `assertGreaterThan(0, $width)` /
 * `assertIsString` — it never pinned the computed width or marker text, so the
 * per-item number sizing (ragged at ≥10 items) stayed invisible (audit M6/#16
 * fix wave). Every width and shape claim below is now exact.
 */
final class DefaultPrefixerTest extends TestCase
{
    public function testInitPrefixerWidthFormulaSmallList(): void
    {
        $p = new DefaultPrefixer();
        // sep(1) + number(1) + number-space(1) + marker(1) + trailing(2) = 6
        $width = $p->initPrefixer(new StringItem('item'), 0, 0, 5, 80, 24, 5);
        $this->assertSame(6, $width);
    }

    public function testNumberColumnPadsToTotalNotCurrentIndex(): void
    {
        // The M6 defect: item 9 reserved a narrower column than item 10.
        // With 12 items the column is 2 cells wide for EVERY item.
        $p = new DefaultPrefixer();
        $w9  = $p->initPrefixer(new StringItem('i'), 9, 0, 5, 80, 24, 12);
        $w10 = $p->initPrefixer(new StringItem('i'), 10, 0, 5, 80, 24, 12);
        $this->assertSame($w9, $w10, 'prefix width must be pass-invariant');
        $this->assertSame(7, $w9); // 6 + one extra digit
    }

    public function testNumberColumnSingleDigitList(): void
    {
        $p = new DefaultPrefixer();
        $this->assertSame(6, $p->initPrefixer(new StringItem('i'), 8, 0, 5, 80, 24, 9));
    }

    public function testWidthWithoutNumbers(): void
    {
        $p = new DefaultPrefixer();
        $p->number = false;
        // sep(1) + marker(1) + trailing(2) = 4, independent of total
        $this->assertSame(4, $p->initPrefixer(new StringItem('i'), 42, 0, 5, 80, 24, 999));
    }

    public function testPrefixShapeFirstLineOfCurrentItem(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('first'), 0, 0, 5, 80, 24, 5);
        // "╭ " + "0 " + ">" + " "
        $this->assertSame('╭ 0 > ', $p->prefix(0, 3));
    }

    public function testPrefixShapeNonCurrentItem(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('other'), 1, 0, 5, 80, 24, 5);
        $this->assertSame('╭ 1   ', $p->prefix(0, 3)); // first line of EVERY item uses firstSep
    }

    public function testPrefixShapeWrapContinuation(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('item'), 2, 1, 5, 80, 24, 5);
        // Wrap lines blank out the number field (2 chars at this total) and marker.
        $this->assertSame('│     ', $p->prefix(1, 3));
    }

    public function testPrefixPadsNumberFieldTwoDigits(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('ten'), 10, 0, 5, 80, 24, 12);
        $this->assertSame('╭ 10   ', $p->prefix(0, 1));
        $p->initPrefixer(new StringItem('nine'), 9, 0, 5, 80, 24, 12);
        $this->assertSame('╭  9   ', $p->prefix(0, 1));
    }

    public function testPrefixWithRelativeNumbers(): void
    {
        $p = new DefaultPrefixer();
        $p->numberRelative = true;
        $p->initPrefixer(new StringItem('rel'), 3, 5, 3, 80, 24, 12);
        // distance |3-5| = 2 in a 2-cell right-aligned field; no marker (not the cursor)
        $this->assertSame('╭  2   ', $p->prefix(0, 1));
        $p->initPrefixer(new StringItem('cur'), 5, 5, 3, 80, 24, 12);
        $this->assertSame('╭  0 > ', $p->prefix(0, 1));
    }

    public function testPrefixWithoutNumbersHasNoDigits(): void
    {
        $p = new DefaultPrefixer();
        $p->number = false;
        $p->initPrefixer(new StringItem('nonum'), 42, 42, 5, 80, 24, 999);
        $this->assertStringNotContainsString('9', $p->prefix(0, 1));
        $this->assertStringNotContainsString('4', $p->prefix(0, 1));
    }

    /**
     * Marker on the cursor item only, regardless of index (kept from the
     * original suite — it pinned real behaviour).
     */
    public function testMarkerOnNonZeroCursorItem(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('item2'), 2, 2, 5, 80, 24, 5);
        $this->assertStringContainsString('>', $p->prefix(0, 1));

        $p2 = new DefaultPrefixer();
        $p2->initPrefixer(new StringItem('item0'), 0, 2, 5, 80, 24, 5);
        $prefix0 = $p2->prefix(0, 1);
        $this->assertStringContainsString(' ', $prefix0);
        $this->assertStringNotContainsString('>', $prefix0);
    }

    /**
     * End-to-end alignment: through Model::lines() with 11 items, the content
     * ("item N") must start at the SAME column for single-digit and
     * double-digit items — the user-visible symptom of the M6 defect.
     */
    public function testContentColumnIsUniformAcrossTenItemBoundary(): void
    {
        $m = Model::new()->setViewport(40, 24); // default cursorOffset 5: no follow-shift for 11 lines
        foreach (\range(0, 10) as $i) {
            $m = $m->addItem(new StringItem("item $i"));
        }
        $m = $m->setPrefixer(new DefaultPrefixer());

        $lines = $m->lines();
        $this->assertCount(11, $lines);
        $columns = \array_map(static fn(string $line): int => \strpos($line, 'item '), $lines);
        $this->assertSame(\array_fill(0, 11, $columns[0]), $columns,
            'content must start at one column for every item (M6 alignment)');
    }
}
