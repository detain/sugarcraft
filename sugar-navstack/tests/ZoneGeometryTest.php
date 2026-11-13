<?php

declare(strict_types=1);

namespace SugarCraft\NavStack\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\Scanner;
use SugarCraft\NavStack\Breadcrumb;
use SugarCraft\NavStack\NavStack;

/**
 * Geometry contract of the click layer: scan() speaks the Breadcrumb's own
 * rendered frame, row() shifts it onto a terminal row, hit()/hitItem() translate
 * terminal coordinates back into items, and the maxWidth budget is a hard
 * invariant even in degenerate corners.
 */
final class ZoneGeometryTest extends TestCase
{
    private function stackWith(string ...$titles): NavStack
    {
        $stack = new NavStack();
        foreach ($titles as $title) {
            $stack->push($title);
        }

        return $stack;
    }

    public function testBarOnTerminalRowThreeResolvesViaRowArgument(): void
    {
        $stack = $this->stackWith('Home');
        $bc = (new Breadcrumb())->withScanner(new Scanner());
        $out = $bc->render($stack);

        $bc->scan($out, 10_000, row: 3);

        self::assertSame('crumb-0', $bc->hit(1, 3)?->id);
        // Rows above the bar, and rows past its single line, are dead cells.
        self::assertNull($bc->hit(1, 2));
        self::assertNull($bc->hit(1, 4));
    }

    public function testTerminalMouseReportFeedsHitDirectly(): void
    {
        // The documented recipe: a decoded MouseEvent carries 1-based terminal
        // coordinates which hit() consumes untouched once scan() names its row.
        $stack = $this->stackWith('Home', 'Settings');
        $bc = (new Breadcrumb())->withScanner(new Scanner());
        $out = $bc->render($stack);
        $bc->scan($out, 10_000, row: 5);

        $event = MouseEvent::press(9, 5);
        $clicked = $bc->hit($event->x, $event->y);

        // 'Home › Settings' — with the gutter fold col 9 sits inside crumb-1.
        self::assertNotNull($clicked);
        self::assertSame('crumb-1', $clicked->id);
    }

    public function testViewportWidthForwardsToScanner(): void
    {
        $stack = $this->stackWith('Home', 'Settings');
        $bc = (new Breadcrumb())->withScanner(new Scanner());
        $out = $bc->render($stack);

        // A narrow viewport must not make scan() explode; zones still resolve.
        $bc->scan($out, 10);
        self::assertSame('crumb-0', $bc->hit(1, 1)?->id);
    }

    public function testHitAfterFreshRenderThrowsStaleNotSilent(): void
    {
        $stack = $this->stackWith('Home');
        $bc = (new Breadcrumb())->withScanner(new Scanner());
        $bc->scan($bc->render($stack), 10_000);
        self::assertNotNull($bc->hit(1, 1));

        $bc->render($stack); // geometry recomputed; the old scan is history now

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('before scan()');
        $bc->hit(1, 1);
    }

    public function testGutterSeparatorCellsBelongToTheLeftCrumb(): void
    {
        $stack = $this->stackWith('Home', 'Settings', 'Display');
        $bc = (new Breadcrumb())->withScanner(new Scanner());
        $out = $bc->render($stack);
        $bc->scan($out, 10_000);

        // 'Home › Settings › Display' — the gutter after 'Home' (cols 6-7) is
        // part of the Home zone, not a dead cell.
        self::assertSame('Home', $bc->hitItem($stack, 6, 1)?->title);
        self::assertSame('Home', $bc->hitItem($stack, 1, 1)?->title);
        self::assertSame('Settings', $bc->hitItem($stack, 8, 1)?->title);
        self::assertSame('Settings', $bc->hitItem($stack, 17, 1)?->title);
        self::assertSame('Display', $bc->hitItem($stack, 19, 1)?->title);
        // Display width of the plain render; the marker bytes are zero cells.
        $displayEnd = Width::string('Home › Settings › Display');
        self::assertSame(25, $displayEnd);
        self::assertSame('Display', $bc->hitItem($stack, $displayEnd, 1)?->title);
        self::assertNull($bc->hitItem($stack, $displayEnd + 1, 1));
    }

    public function testZonePrefixIsConfigurableAndValidated(): void
    {
        $bc = (new Breadcrumb())->withScanner(new Scanner())->setZonePrefix('nav2');
        $stack = $this->stackWith('Home', 'Settings');
        $bc->scan($bc->render($stack), 10_000);

        $ids = array_keys($bc->scanner()->prefixed('nav2-'));
        sort($ids);
        self::assertSame(['nav2-0', 'nav2-1'], $ids);
        self::assertSame('Home', $bc->hitItem($stack, 1, 1)?->title);

        foreach (['', 'bad id', 'bad/id', "\x1b[0m"] as $rejected) {
            try {
                (new Breadcrumb())->setZonePrefix($rejected);
                self::fail('prefix accepted: ' . json_encode($rejected));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('zone prefix', $e->getMessage());
            }
        }
    }

    public function testNegativeMaxWidthRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('>= 0');
        (new Breadcrumb())->setMaxWidth(-1);
    }

    public function testHardCapHoldsInDegenerateCorners(): void
    {
        $render = static function (int $cap, array $titles): string {
            return (new Breadcrumb())->setMaxWidth($cap)->renderTitles($titles);
        };

        // Marker alone at/beyond the budget: drop it, ellipsize the lone crumb.
        self::assertSame('b', $render(1, ['a', 'b']));
        self::assertSame('b', $render(2, ['a', 'b']));   // '… ' is 2 wide = cap -> dropped
        self::assertSame('… b', $render(3, ['a', 'b']));
        self::assertSame('H…', $render(2, ['Home']));
        self::assertSame('H', $render(1, ['Home']));       // budget 1: single-cell clip
        self::assertSame('a › b', $render(0, ['a', 'b'])); // cap 0 disables the cap entirely

        foreach ([1, 2, 3, 4, 5, 8] as $cap) {
            $out = $render($cap, ['alpha', 'beta', 'gammagamma']);
            self::assertLessThanOrEqual($cap, Width::string($out), "cap {$cap} breached: '{$out}'");
        }
    }

    public function testVisibleIndicesTracksSurvivors(): void
    {
        $bc = new Breadcrumb();
        self::assertSame([], $bc->visibleIndices()); // nothing rendered yet

        $bc->renderTitles(['Home', 'Settings', 'Display']);
        self::assertSame([0, 1, 2], $bc->visibleIndices());

        $bc->setMaxWidth(10)->renderTitles(['Home', 'Settings', 'Display']);
        // Survivors are 'Display' (elision dropped the ancestors): index 2 only.
        self::assertSame([2], $bc->visibleIndices());

        $bc->renderTitles([]);
        self::assertSame([], $bc->visibleIndices());
    }

    public function testTruncationPrefixCarriesNoPhantomZone(): void
    {
        $stack = $this->stackWith('old-entry', 'a2', 'Current');
        $bc = (new Breadcrumb())->withScanner(new Scanner())->setMaxWidth(8);
        $out = $bc->render($stack);
        $bc->scan($out, 10_000);

        // Zones == surviving crumbs exactly ('… ' rides the first surviving zone).
        self::assertCount(1, $bc->scanner()->prefixed('crumb-'));
        self::assertStringStartsWith('… ', $out);
    }

    public function testMarkerlessRenderIsPlainTextWithoutScanner(): void
    {
        $stack = $this->stackWith('Home', 'Settings');
        $out = (new Breadcrumb())->render($stack);

        self::assertSame('Home › Settings', $out);
        self::assertStringNotContainsString("\u{E000}", $out);
    }

    // ─── NS-08: generation stamp — cross-generation dispatch throws ──────────

    public function testHitItemRefusesStackMutatedAfterRender(): void
    {
        // TUI-M2 class: only a re-render flips `scanned`, so a push after
        // scan used to resolve the OLD zones against NEW contents silently.
        $stack = $this->stackWith('Home', 'Settings');
        $bc    = (new Breadcrumb())->withScanner(new Scanner());
        $bc->scan($bc->render($stack), 10_000);

        $stack->push('Mutated'); // no re-render follows

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not the generation this breadcrumb rendered');
        $bc->hitItem($stack, 1, 1);
    }

    public function testHitItemRefusesFilteredCopyConfusion(): void
    {
        // The renumber trap, now load-bearing: zones painted from a
        // filtered() copy dispatched against the ORIGINAL throw (and the
        // reverse pairing too) instead of misrouting.
        $original = $this->stackWith('Home', 'QA', 'Reports');
        $filtered = $original->filtered('QA');
        self::assertSame(1, $filtered->depth()); // QA alone; renumbered to crumb-0

        $bc = (new Breadcrumb())->withScanner(new Scanner());
        $bc->scan($bc->render($filtered), 10_000);

        $this->expectException(\LogicException::class);
        $bc->hitItem($original, 1, 1);
    }

    public function testHitItemHappyPathStillResolvesSameGeneration(): void
    {
        $stack = $this->stackWith('Home', 'Settings', 'Display');
        $bc    = (new Breadcrumb())->withScanner(new Scanner());
        $bc->scan($bc->render($stack), 10_000);

        self::assertSame('Home', $bc->hitItem($stack, 1, 1)?->title);
        // Untouched-stack repeat dispatch keeps working (no false refusal):
        self::assertSame('Settings', $bc->hitItem($stack, 8, 1)?->title);
    }

    public function testHitItemOutsideEveryCrumbStaysQuietUnderStaleState(): void
    {
        // Generation refusal is for DISPATCH; a dead-cell click is the
        // existing quiet-null contract even when the stack has moved on.
        $stack = $this->stackWith('Home', 'Settings');
        $bc    = (new Breadcrumb())->withScanner(new Scanner());
        $bc->scan($bc->render($stack), 10_000);
        $stack->push('Later');

        self::assertNull($bc->hitItem($stack, 100, 1));
    }

    public function testRenderTitlesLeavesDispatchUntampedLegacyShape(): void
    {
        // Host-owned bare titles carry no stack identity: hitItem keeps the
        // pre-NS-08 POSITIONAL resolution for them (documented door) — the
        // clicked zone maps to the passed-in stack's own items, on the
        // host's honor.
        $stack = $this->stackWith('Home', 'Settings');
        $bc    = (new Breadcrumb())->withScanner(new Scanner());
        $bc->scan($bc->renderTitles(['Alpha', 'Beta']), 10_000);

        self::assertSame('Home', $bc->hitItem($stack, 1, 1)?->title);
    }
    /**
     * NS-20 (sugar-navstack-033): the viewport width is REQUIRED whenever a
     * scanner is attached — zones past the terminal edge must clamp, never
     * mint phantom clicks. Enforced by signature, not policy: null (the old
     * "forgot to clamp" value) and omission both fatal before the scanner
     * ever sees the string.
     */
    public function testViewportWidthIsRequiredBySignature(): void
    {
        $bc = (new Breadcrumb())->withScanner(new Scanner());
        $out = $bc->render($this->stackWith('Home', 'Settings'));

        try {
            $bc->scan($out, null);
            self::fail('null viewport width accepted');
        } catch (\TypeError $e) {
            self::assertStringContainsString('viewportWidth', $e->getMessage());
        }

        try {
            $bc->scan($out);
            self::fail('omitted viewport width accepted');
        } catch (\ArgumentCountError $e) {
            self::assertStringContainsString('Too few arguments', $e->getMessage());
        }

        // The clamping leg itself stays reachable and honest: a zone that
        // would run past the edge is cut at it (phantom clicks blocked).
        $bc->scan($out, 5);
        self::assertNull($bc->hit(9_999, 1), 'cells past the viewport edge must not resolve');
    }

    /**
     * NS-21 (sugar-navstack-034): the elision marker is a dead cell by
     * design — hosts get elisionSpan() [start, end, row] as the reveal
     * handle instead of a forged clickable crumb.
     */
    public function testElisionSpanPublishesMarkerCells(): void
    {
        $bc    = (new Breadcrumb())->withScanner(new Scanner())->setMaxWidth(20);
        $stack = $this->stackWith('Home', 'Settings', 'Display');
        $out   = $bc->render($stack);
        self::assertSame('… Settings › Display', \SugarCraft\Mouse\Scan::strip($out), 'visible row; sentinels ride inside for the scanner');

        $bc->scan($out, 100);
        self::assertSame([1, 2, 1], $bc->elisionSpan(), 'the marker owns cells 1-2 of row 1');
        self::assertNull($bc->hit(1, 1), 'marker cells stay dead: no forged crumb zone');
        self::assertNull($bc->hit(2, 1));
        self::assertNotNull($bc->hit(3, 1), 'the first survivor crumb still resolves');

        $bc->scan($out, 100, row: 4);
        self::assertSame([1, 2, 4], $bc->elisionSpan(), 'absolute row follows scan()');

        // An empty render skips doRender() — its early path must still clear
        // the tracker, or a later scan() of a fresh row would resurrect the
        // stale marker span (NS-21 leak proof).
        self::assertSame('', $bc->render(new NavStack()));
        $bc->scan('', 100);
        self::assertNull($bc->elisionSpan(), 'stale span must not survive an empty re-render');
    }

    public function testElisionSpanHonoursCustomTruncatorAndCorners(): void
    {
        $bc = (new Breadcrumb())->withScanner(new Scanner())->setMaxWidth(24)->setTruncator('[more] ');
        $out = $bc->render($this->stackWith('Home', 'Settings', 'Display'));
        $bc->scan($out, 100);
        self::assertSame([1, 7, 1], $bc->elisionSpan(), 'span width follows the marker actually painted');

        // Degenerate corner: the marker itself is dropped (cap 2 over 'a','b')
        $dropped = (new Breadcrumb())->withScanner(new Scanner())->setMaxWidth(2);
        $dropped->scan($dropped->render($this->stackWith('a', 'b')), 100);
        self::assertNull($dropped->elisionSpan(), 'no marker painted, nothing to bind');

        // No elision at all:
        $wide = (new Breadcrumb())->withScanner(new Scanner());
        $wide->scan($wide->render($this->stackWith('Home', 'Settings')), 100);
        self::assertNull($wide->elisionSpan());

        // Scanner-less and unscanned renders answer null quietly:
        $bare = (new Breadcrumb())->setMaxWidth(20);
        $bare->render($this->stackWith('Home', 'Settings', 'Display'));
        self::assertNull($bare->elisionSpan(), 'no scanner: paint-time query, not a hit() throw');

        $noScan = (new Breadcrumb())->withScanner(new Scanner())->setMaxWidth(20);
        $noScan->render($this->stackWith('Home', 'Settings', 'Display'));
        self::assertNull($noScan->elisionSpan(), 'geometry not published until scan()');

        // Re-render that stops eliding clears the previous span:
        $noScan->setMaxWidth(100);
        $noScan->scan($noScan->render($this->stackWith('Home', 'Settings', 'Display')), 100);
        self::assertNull($noScan->elisionSpan());
    }
}