<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests;

use SugarCraft\TreeList\{DefaultPrefixer, DefaultSuffixer, Model, StringItem};
use PHPUnit\Framework\TestCase;

/**
 * DefaultPrefixer behaviour pins.
 *
 * The original suite asserted only `assertGreaterThan(0, $width)` /
 * `assertIsString` — it never pinned the computed width or marker text, so the
 * per-item number sizing (ragged at ≥10 items) stayed invisible (audit M6/#16
 * fix wave). Every width and shape claim below is now exact.
 *
 * DISCLOSED GOLDEN MOVE (treelist-17, 2026-11-13): the absolute number
 * column flipped from the 0-based index to 1-based ordinals by default. The
 * four number pins below were re-pinned; the legacy bytes live on behind
 * `numberZeroBased`.
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

    /** sugar-treelist-027: the house factory yields the stock knob set. */
    public function testNewFactoryReturnsStockKnobInstance(): void
    {
        $p = DefaultPrefixer::new();
        $this->assertTrue($p->number);
        $this->assertFalse($p->numberRelative);
        $this->assertFalse($p->numberZeroBased);
        $this->assertSame('>', $p->currentMarker);
        $this->assertSame(6, $p->initPrefixer(new StringItem('i'), 0, 0, 5, 80, 24, 5));
        $this->assertSame('╭ 1 > ', $p->prefix(0, 1));
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

    /**
     * treelist-002: the reservation counts the WIDEST join glyph across
     * firstSep/separator/lastSep/separatorWrap and the widest marker column
     * across currentMarker/emptyMarker — not just separator + currentMarker.
     */
    public function testReservationCoversEveryGlyphFieldVariant(): void
    {
        $p = new DefaultPrefixer();
        $p->firstSep = '╭─';        // 2 cells
        $p->lastSep  = '╰───';      // 4 cells — widest join
        $p->emptyMarker = '·─';     // 2 cells — widest marker column
        // sepMax(4) + number(1)+space(1) + markMax(2) + trailing(2) = 10
        $this->assertSame(10, $p->initPrefixer(new StringItem('i'), 0, 0, 5, 80, 24, 5));

        // The reservation is pass-invariant: every item of the pass pays for
        // the widest variant, so position never changes the content budget.
        $p->lastSep = '╰'; // back to 1 cell
        $this->assertSame(
            8, // sepMax(firstSep '╭─' = 2) + number(1)+1 + markMax(2) + 2
            $p->initPrefixer(new StringItem('i'), 0, 0, 5, 80, 24, 5)
        );
    }

    public function testStockDefaultReservationUnchanged(): void
    {
        // All six stock glyph fields are single-cell: the widened max() math
        // must reproduce the old separator+currentMarker figure byte-for-byte.
        $p = new DefaultPrefixer();
        $this->assertSame(6, $p->initPrefixer(new StringItem('i'), 0, 0, 5, 80, 24, 5));
    }

    public function testPrefixShapeFirstLineOfCurrentItem(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('first'), 0, 0, 5, 80, 24, 5);
        // "╭ " + "1 " + ">" + " " (treelist-17: 1-based ordinal)
        $this->assertSame('╭ 1 > ', $p->prefix(0, 3));
    }

    public function testPrefixShapeNonCurrentItem(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('other'), 1, 0, 5, 80, 24, 5);
        // 2026-11-03 fix wave: the first-line join glyph follows the item's
        // POSITION (╭ first / ├ middle / ╰ last). The old pin '╭ 1   ' froze
        // the tree-lie — firstSep on every item made ├/╰ unreachable.
        // treelist-17: the number is the 1-based ordinal (index 1 → '2').
        $this->assertSame('├ 2   ', $p->prefix(0, 3));
    }

    /** Corners track the ends of the rendered list; ├ owns the middle. */
    public function testGutterCornersTrackListEnds(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('a'), 0, 0, 5, 80, 24, 3);
        $this->assertStringStartsWith('╭', $p->prefix(0, 1));
        $p->initPrefixer(new StringItem('b'), 1, 0, 5, 80, 24, 3);
        $this->assertStringStartsWith('├', $p->prefix(0, 1));
        $p->initPrefixer(new StringItem('c'), 2, 0, 5, 80, 24, 3);
        $this->assertStringStartsWith('╰', $p->prefix(0, 1));

        // A one-item list is both ends and renders as the top corner;
        // a two-item list uses ╭ and ╰ and never ├.
        $p->initPrefixer(new StringItem('only'), 0, 0, 5, 80, 24, 1);
        $this->assertStringStartsWith('╭', $p->prefix(0, 1));
        $p->initPrefixer(new StringItem('last'), 1, 0, 5, 80, 24, 2);
        $this->assertStringStartsWith('╰', $p->prefix(0, 1));

        // Wrap continuations are position-independent: always │.
        $p->initPrefixer(new StringItem('c'), 2, 0, 5, 80, 24, 3);
        $this->assertStringStartsWith('│', $p->prefix(1, 2));
    }

    public function testLastSepIsCustomisable(): void
    {
        $p = new DefaultPrefixer();
        $p->lastSep = '└';
        $p->initPrefixer(new StringItem('end'), 2, 0, 5, 80, 24, 3);
        $this->assertStringStartsWith('└', $p->prefix(0, 1));
    }

    /**
     * Gutter glyphs are terminal content: control bytes (the old
     * firstSep="\x1b]0;owned\x07" shape) are refused at init, empty is legal.
     */
    public function testGlyphFieldsRejectControlBytes(): void
    {
        foreach (['firstSep', 'separator', 'lastSep', 'separatorWrap', 'currentMarker', 'emptyMarker'] as $field) {
            $p = new DefaultPrefixer();
            $p->$field = "x\x1b]0;owned\x07";
            try {
                $p->initPrefixer(new StringItem('i'), 0, 0, 5, 80, 24, 3);
                $this->fail("DefaultPrefixer:\${$field} accepted control bytes");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }

        $blank = new DefaultPrefixer();
        $blank->firstSep = '';
        $blank->initPrefixer(new StringItem('i'), 0, 0, 5, 80, 24, 3);
        $this->assertStringStartsWith(' ', $blank->prefix(0, 1));
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
        $this->assertSame('├ 11   ', $p->prefix(0, 1)); // middle item → ├ (2026-11-03 gutter fix); ordinal 11 (treelist-17)
        $p->initPrefixer(new StringItem('nine'), 9, 0, 5, 80, 24, 12);
        $this->assertSame('├ 10   ', $p->prefix(0, 1)); // ordinal 10, 2-cell column sized on max printed 12
    }

    public function testPrefixWithRelativeNumbers(): void
    {
        $p = new DefaultPrefixer();
        $p->numberRelative = true;
        $p->initPrefixer(new StringItem('rel'), 3, 5, 3, 80, 24, 12);
        // distance |3-5| = 2 in a 2-cell right-aligned field; no marker (not the cursor)
        $this->assertSame('├  2   ', $p->prefix(0, 1)); // middle item → ├ (2026-11-03 gutter fix)
        $p->initPrefixer(new StringItem('cur'), 5, 5, 3, 80, 24, 12);
        $this->assertSame('├  0 > ', $p->prefix(0, 1));
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
        $m = Model::new()->withViewport(40, 24); // default cursorOffset 5: no follow-shift for 11 lines
        foreach (\range(0, 10) as $i) {
            $m = $m->withItem(new StringItem("item $i"));
        }
        $m = $m->withPrefixer(new DefaultPrefixer());

        $lines = $m->lines();
        $this->assertCount(11, $lines);
        $columns = \array_map(static fn(string $line): int => \strpos($line, 'item '), $lines);
        $this->assertSame(\array_fill(0, 11, $columns[0]), $columns,
            'content must start at one column for every item (M6 alignment)');
    }

    /**
     * treelist-17 (finding 023): the absolute column prints 1-based ordinals
     * by default — the first row is '1', not '0' — because numbered terminal
     * lists read as ordinals, while the zero-based index in the same column
     * doubled as cursor-distance in relative mode with no signal. The legacy
     * bytes live on behind the numberZeroBased opt-out.
     */
    public function testAbsoluteNumberIsOneBasedOrdinalByDefault(): void
    {
        $p = new DefaultPrefixer();
        $p->initPrefixer(new StringItem('a'), 0, 0, 5, 80, 24, 3);
        $this->assertSame('╭ 1 > ', $p->prefix(0, 1));
        $p->initPrefixer(new StringItem('c'), 2, 0, 5, 80, 24, 3);
        $this->assertSame('╰ 3   ', $p->prefix(0, 1));

        $legacy = (new DefaultPrefixer())->withNumberZeroBased(true);
        $legacy->initPrefixer(new StringItem('a'), 0, 0, 5, 80, 24, 3);
        $this->assertSame('╭ 0 > ', $legacy->prefix(0, 1), 'numberZeroBased opt-out keeps the legacy bytes');
    }

    /**
     * treelist-17: the column sizes on the widest value the mode actually
     * prints — the 1-based default widens a 10-item list (1..10 → two cells)
     * where the zero-based legacy (0..9) still fits one.
     */
    public function testColumnWidthTracksWidestPrintedNumber(): void
    {
        $p = new DefaultPrefixer();
        // sep(1) + number(2)+space(1) + marker(1) + trailing(2) = 7
        $this->assertSame(7, $p->initPrefixer(new StringItem('i'), 0, 0, 5, 80, 24, 10));

        $legacy = (new DefaultPrefixer())->withNumberZeroBased(true);
        // sep(1) + number(1)+space(1) + marker(1) + trailing(2) = 6
        $this->assertSame(6, $legacy->initPrefixer(new StringItem('i'), 0, 0, 5, 80, 24, 10));
    }

    /**
     * treelist-17 (finding 020): every knob has a clone-returning with*() —
     * the receiver (and any model already attached to it) is untouched.
     */
    public function testFluentSettersReturnClonesAndNeverTouchTheReceiver(): void
    {
        $p = new DefaultPrefixer();
        $q = $p->withNumber(false)
               ->withNumberRelative(true)
               ->withPrefixWrap(false)
               ->withFirstSep('A')
               ->withSeparator('B')
               ->withLastSep('C')
               ->withSeparatorWrap('D')
               ->withCurrentMarker('E')
               ->withEmptyMarker('·');
        $this->assertNotSame($p, $q);
        $this->assertTrue($p->number, 'receiver untouched');
        $this->assertFalse($p->numberRelative, 'receiver untouched');
        $this->assertSame(' ', $p->emptyMarker, 'receiver untouched');
        $this->assertFalse($q->number);
        $this->assertTrue($q->numberRelative);
        $this->assertFalse($q->prefixWrap);
        $this->assertSame('A', $q->firstSep);
        $this->assertSame('B', $q->separator);
        $this->assertSame('C', $q->lastSep);
        $this->assertSame('D', $q->separatorWrap);
        $this->assertSame('E', $q->currentMarker);
        $this->assertSame('·', $q->emptyMarker);
        // Empty is still a legal glyph through the door (it drops the decoration).
        $this->assertSame('', $p->withFirstSep('')->firstSep);
    }

    /**
     * treelist-17: the public-field parity path is honest — a write after
     * attach reaches EVERY fork sharing the instance (cross-clone coupling).
     * Pinned so the documented deviation stays true, not folklore.
     */
    public function testPublicFieldWriteAfterAttachReachesEveryFork(): void
    {
        $p = new DefaultPrefixer();
        $m1 = Model::new()->withViewport(20, 10)->withItemsFromArray(['a', 'b'])->withPrefixer($p);
        $m2 = $m1->withCursor(1);
        $p->emptyMarker = '.'; // legacy parity write: mutates the SHARED instance
        $this->assertStringContainsString('.', $m1->lines()[1], 'fork one sees the write');
        $this->assertStringContainsString('.', $m2->lines()[0], 'fork two sees the same write');
    }

    /**
     * treelist-17: glyph setters fail fast at the DOOR — a control byte
     * never survives to init, let alone the terminal.
     */
    public function testGlyphSettersRefuseControlBytesAtTheDoor(): void
    {
        $p = new DefaultPrefixer();
        foreach (['withFirstSep', 'withSeparator', 'withLastSep', 'withSeparatorWrap', 'withCurrentMarker', 'withEmptyMarker'] as $setter) {
            try {
                $p->$setter("x\x1b]0;owned\x07");
                $this->fail("DefaultPrefixer::$setter() accepted control bytes");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('DefaultPrefixer:', $e->getMessage());
            }
        }
    }
}
