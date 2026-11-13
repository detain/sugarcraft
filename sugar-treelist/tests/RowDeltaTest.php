<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\TreeList\DefaultPrefixer;
use SugarCraft\TreeList\DefaultSuffixer;
use SugarCraft\TreeList\Model;
use SugarCraft\TreeList\StringItem;

/**
 * Pins for the row-granular delta renderer in Model::view().
 *
 * 2026-11-03 (CRIT fix wave): the previous frame diff projected styled rows
 * into a sugar-cellbuffer grid with null styles, so SGR escape bytes rode the
 * grid as printable runes — every delta frame on a styled list leaked raw
 * escape bytes as visible cells and the corruption accumulated per keypress.
 * view() now memos the exact emitted ROW strings and rewrites changed rows at
 * absolute positions. These pins are byte-exact on purpose: size/substring
 * claims could not tell a correct delta from a corrupt one (that is how the
 * old suite stayed green on a broken renderer), and every one of them is
 * written against the small geometry whose bytes can be spelled out.
 */
final class RowDeltaTest extends TestCase
{
    /**
     * The memo is the exact list of styled rows of the last full or delta
     * frame — not a cell projection. Reads the private field directly.
     */
    public function testRowMemoIsTheLastFrameStyledRows(): void
    {
        $m = Model::new()
            ->withViewport(10, 3)
            ->withCurrentStyle('1')
            ->withItemsFromArray(['a', 'b', 'c']);

        $this->assertNull($this->memo($m), 'fresh model has no memo');
        $m->view();
        $this->assertSame($m->lines(), $this->memo($m));
        $this->assertStringContainsString("\x1b[1ma\x1b[0m", $this->memo($m)[0],
            'the memo must carry the styled bytes of the row, not styleless cells');
    }

    /**
     * First frame is the full newline-joined output; the cursor move rewrites
     * EXACTLY the two rows whose bytes changed, each at its absolute position,
     * each terminated with erase-to-end-of-line.
     */
    public function testCursorMoveRewritesExactlyTheChangedRowsWithExactBytes(): void
    {
        $m = Model::new()
            ->withViewport(10, 3)
            ->withCurrentStyle('1')
            ->withItemsFromArray(['a', 'b', 'c']);

        $this->assertSame("\x1b[1ma\x1b[0m\nb\nc\n", $m->view(), 'first frame must be the full output');

        $m2 = $m->withCursor(1);
        $this->assertSame(
            "\x1b[1;1Ha\x1b[K\n\x1b[2;1H\x1b[1mb\x1b[0m\x1b[K\n",
            $m2->view(),
        );

        $m3 = $m2->withCursor(2);
        $this->assertSame(
            "\x1b[2;1Hb\x1b[K\n\x1b[3;1H\x1b[1mc\x1b[0m\x1b[K\n",
            $m3->view(),
        );
    }

    /**
     * Re-rendering an unchanged instance emits NOTHING — the delta against an
     * identical memo is empty, byte for byte.
     */
    public function testUnchangedReRenderEmitsEmptyDelta(): void
    {
        $m = Model::new()->withViewport(10, 2)->withItem(new StringItem('solo'));
        $this->assertNotSame('', $m->view());
        $this->assertSame('', $m->view());
    }

    /**
     * When the frame shrinks (item removed), the row that vanished is cleared
     * in place — a blank write of exactly one erased row, never stale text.
     */
    public function testRemovedTailRowIsClearedNotLeftStale(): void
    {
        $m = Model::new()->withViewport(10, 3)->withItemsFromArray(['a', 'b', 'c']);
        $this->assertSame("a\nb\nc\n", $m->view());

        $shrunk = $m->removeItem(2);
        $this->assertSame("\x1b[3;1H\x1b[K\n", $shrunk->view());
    }

    /**
     * Anti-corruption pin for the old defect class: in every delta frame of a
     * styled list driven through repeated cursor moves, ALL escape bytes must
     * belong to well-formed CSI sequences. Stripping the well-formed ones must
     * leave zero stray ESC — the literal-escape-as-content leak is unreachable.
     */
    public function testNoStrayEscapeBytesAccumulateAcrossStyledDeltaFrames(): void
    {
        $m = Model::new()
            ->withViewport(20, 4)
            ->withCursorOffset(1)
            ->withPrefixer(new DefaultPrefixer())
            ->withSuffixer(new DefaultSuffixer())
            ->withLineStyle('2')
            ->withCurrentStyle('7')
            ->withItemsFromArray(['alpha', 'beta', 'gamma', 'delta', 'epsilon'])
            ->withCursor(1);

        $frame = $m->view();
        $this->assertNotSame('', $frame);

        foreach (['withCursorDown', 'withCursorDown', 'withCursorUp', 'withCursorDown'] as $move) {
            $m = $m->$move();
            $delta = $m->view();
            $residue = (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $delta);
            $this->assertStringNotContainsString("\x1b", $residue,
                "delta after {$move} leaked an escape byte outside a CSI sequence");
            $this->assertStringNotContainsString("\x07", $delta);
            $this->assertStringNotContainsString("\x00", $delta);
        }
    }

    /**
     * Applying the delta on top of the previous full frame must reconstruct
     * exactly what a fresh instance renders — the delta is complete, not a
     * partial or mispositioned write.
     */
    public function testDeltaAppliedToPreviousFrameEqualsFreshFullFrame(): void
    {
        $build = static fn(int $cursor): Model => Model::new()
            ->withViewport(16, 4)
            ->withCursorOffset(1)
            ->withPrefixer(new DefaultPrefixer())
            ->withLineStyle('1')
            ->withItemsFromArray(['one', 'two', 'three', 'four', 'five'])
            ->withCursor($cursor);

        $m = $build(0);
        $frame1 = $m->view();
        $m2 = $m->withCursor(1);
        $delta = $m2->view();

        $this->assertSame($build(1)->view(), $this->applyDelta($frame1, $delta),
            'delta applied over the previous frame must equal the fresh full frame');
    }

    /**
     * A failed frame (zero viewport throws inside lines()) must not leave the
     * memo poisoned: the error text is the sanitized message and the next
     * successful render on the lineage is a FULL frame again.
     */
    public function testFailedFrameResetsMemoAndNextRenderRepaintsFull(): void
    {
        $m = Model::new()->withViewport(10, 3)->withItemsFromArray(['a', 'b', 'c']);
        $this->assertSame("a\nb\nc\n", $m->view());

        $broken = $m->withViewport(10, 0);
        $error = $broken->view();
        $this->assertStringContainsString('viewport', $error);

        $repaired = $broken->withViewport(10, 3);
        $frame = $repaired->view();
        $this->assertSame("a\nb\nc\n", $frame, 'after a failed frame the next view must be a full repaint');
        $this->assertStringNotContainsString("\x1b[", $frame);
    }

    /**
     * The stronger shape: the frame fails at UNCHANGED dimensions (a throwing
     * Stringable walks into the window when the cursor moves onto it). The
     * resize check cannot rescue this one — only the catch-path memo reset
     * forces the full repaint after the error text covered the screen. Added
     * 2026-11-03 after a mutation showed the zero-viewport pin above survives
     * a neutered catch (its repair changes dims, which re-clears the memo via
     * the resize path anyway).
     */
    public function testFailedFrameAtUnchangedDimsStillForcesFullRepaint(): void
    {
        $poison = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('boom-mid-render');
            }
        };

        $base = Model::new()->withViewport(10, 2)->withItemsFromArray(['a', 'b']);
        $this->assertSame("a\nb\n", $base->view()); // memo established

        // The poison sits at index 2 — off a 2-row window anchored at the
        // start. Moving the cursor to the end pulls it into the window, so
        // lines() dies mid-render at exactly the dimensions that rendered
        // fine one frame ago.
        $atEnd = $base->withItem($poison)->withCursorToEnd();
        $error = $atEnd->view();
        $this->assertStringContainsString('boom-mid-render', $error);

        $fixed = $atEnd->removeItem(2);
        $frame = $fixed->view();
        $this->assertSame(
            "a\nb\n",
            $frame,
            'a frame that failed at unchanged dims must still force a full repaint',
        );
        $this->assertStringNotContainsString("\x1b[", $frame);
    }

    /**
     * A failed frame must not print exception prose raw: control bytes and
     * OSC payloads smuggled through a throwing Stringable value are swept
     * out of the error sink before the message reaches the terminal.
     */
    public function testErrorSinkSweepsForgedSentinelsAndControlBytes(): void
    {
        $poison = new class implements \Stringable {
            public function __toString(): string
            {
                // Throwing from __toString fails lines() mid-render, so the
                // RuntimeException prose below is exactly what the catch in
                // view() used to print raw as frame content.
                throw new \RuntimeException("boom\x07\x1b]0;title-stolen\x07!");
            }
        };
        $m = Model::new()->withViewport(10, 3)->withItem($poison);

        $out = $m->view();
        $this->assertStringContainsString('boom', $out, 'error prose still surfaces (sanitized)');
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertStringNotContainsString("\x1b]0;", $out);
    }

    /**
     * End-to-end through view() at the largest geometry the area guard admits:
     * the full frame plus a styled delta must lay out and encode quickly — the
     * old quadratic projection regression class, now guarded at renderer level.
     */
    public function testLargeViewportFullAndDeltaStayLinearTime(): void
    {
        $m = Model::new()
            ->withViewport(500, 200)
            ->withCursorOffset(0)
            ->withCurrentStyle('1');
        for ($i = 0; $i < 200; $i++) {
            $m = $m->withItem(new StringItem("x{$i}"));
        }

        $start = \hrtime(true);
        $frame1 = $m->view();
        $this->assertCount(200, \explode("\n", \rtrim($frame1, "\n")));
        $delta = $m->withCursor(1)->view();
        $elapsed = (\hrtime(true) - $start) / 1e9;

        $this->assertNotSame('', $delta);
        $this->assertLessThan(5.0, $elapsed,
            'large-viewport full frame plus delta regressed toward quadratic behaviour');
    }

    /** The exact small-frame bytes at a 2000×1 viewport, with timing guard. */
    public function testSingleRowWideFrameDeltaIsExactBytes(): void
    {
        $m = Model::new()->withViewport(2000, 1)
            ->withItem(new StringItem('alpha'))
            ->withItem(new StringItem('beta'));

        $start = \hrtime(true);
        $this->assertSame("alpha\n", $m->view());
        $this->assertSame("\x1b[1;1Hbeta\x1b[K\n", $m->withCursor(1)->view());
        $this->assertLessThan(5.0, (\hrtime(true) - $start) / 1e9);
    }

    // -------------------------------------------------------------------------

    /** @return list<string>|null */
    private function memo(Model $m): ?array
    {
        $property = new \ReflectionProperty(Model::class, 'previousRows');
        $property->setAccessible(true);
        return $property->getValue($m);
    }

    /**
     * Replay a delta produced by view() over the text of the previous full
     * frame: each write is CUP <row>;1H content EL newline, replacing row
     * $row-1 of the frame.
     */
    private function applyDelta(string $frame, string $delta): string
    {
        $rows = \explode("\n", \rtrim($frame, "\n"));
        $matched = \preg_match_all('/\x1b\[(\d+);1H(.*?)\x1b\[K\n/s', $delta, $writes, PREG_SET_ORDER);
        $this->assertGreaterThan(0, $matched, 'delta carried no row writes');
        $consumed = 0;
        foreach ($writes as $write) {
            $rows[(int) $write[1] - 1] = $write[2];
            $consumed += \strlen($write[0]);
        }
        $stripped = \preg_replace('/\x1b\[(\d+);1H(.*?)\x1b\[K\n/s', '', $delta);
        $this->assertSame('', $stripped, 'delta carried bytes outside row writes');
        return \implode("\n", $rows) . "\n";
    }
}
