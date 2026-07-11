<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs\Tests;

use SugarCraft\Crumbs\{Breadcrumb, NavStack};
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Scanner;
use PHPUnit\Framework\TestCase;

final class BreadcrumbTest extends TestCase
{
    // ─── withScanner() ─────────────────────────────────────────────────────

    public function testWithScannerAttachesScanner(): void
    {
        $scanner = Scanner::new();
        $bc = (new Breadcrumb())->withScanner($scanner);

        // Rendering with a scanner attached should produce zone markers
        $s = new NavStack();
        $s->push('Home')->push('Settings');
        $rendered = $bc->render($s);

        // The scanner should be able to scan this rendered output
        $bc->scan($rendered);
        $zone = $bc->hit(1, 1);

        // With scanner attached, rendering wraps crumbs in zone markers
        // so scanning should produce at least one zone
        $this->assertNotNull($zone);
    }

    public function testWithScannerReturnsNewInstance(): void
    {
        $original = new Breadcrumb();
        $scanner = Scanner::new();
        $modified = $original->withScanner($scanner);

        $this->assertNotSame($original, $modified);
    }

    public function testWithScannerNullDetachesScanner(): void
    {
        $bc = (new Breadcrumb())->withScanner(null);
        $s = new NavStack();
        $s->push('Home');

        // Rendering without scanner should produce plain text (no zone markers)
        $rendered = $bc->render($s);
        $bc->scan($rendered);

        // hit() returns null when no scanner is attached
        $zone = $bc->hit(1, 1);
        $this->assertNull($zone);
    }

    // ─── scan() / hit() zone detection ────────────────────────────────────

    public function testScanThenHitDetectsCrumbZone(): void
    {
        $scanner = Scanner::new();
        $bc = (new Breadcrumb())->withScanner($scanner);

        $s = new NavStack();
        $s->push('Root')->push('Child');

        $rendered = $bc->render($s);

        // After rendering, scan the output to register zones
        $bc->scan($rendered);

        // hit() should find a zone (crumb-1 for "Child" which is at index 1)
        $zone = $bc->hit(1, 1);
        $this->assertNotNull($zone);
        $this->assertStringContainsString('crumb-', $zone->id);
    }

    public function testScanThenHitReturnsNullOutsideZone(): void
    {
        $scanner = Scanner::new();
        $bc = (new Breadcrumb())->withScanner($scanner);

        $s = new NavStack();
        $s->push('Home')->push('Settings');

        $rendered = $bc->render($s);
        $bc->scan($rendered);

        // Coordinates far outside any crumb zone should return null
        $zone = $bc->hit(999, 999);
        $this->assertNull($zone);
    }

    public function testScanReturnsSelfForChaining(): void
    {
        $scanner = Scanner::new();
        $bc = (new Breadcrumb())->withScanner($scanner);

        $s = new NavStack();
        $s->push('A');
        $rendered = $bc->render($s);

        $result = $bc->scan($rendered);
        $this->assertSame($bc, $result);
    }

    public function testHitWithoutScannerReturnsNull(): void
    {
        $bc = new Breadcrumb(); // no scanner attached
        $zone = $bc->hit(1, 1);
        $this->assertNull($zone);
    }

    // ─── Rendering integration ─────────────────────────────────────────────

    public function testRenderWithScannerAddsZoneMarkers(): void
    {
        $scanner = Scanner::new();
        $bc = (new Breadcrumb())->withScanner($scanner);

        $s = new NavStack();
        $s->push('One')->push('Two');

        $rendered = $bc->render($s);

        // Zone markers use U+E000/U+E001 private-use sentinels
        $this->assertStringContainsString("\u{E000}", $rendered);
        $this->assertStringContainsString("\u{E001}", $rendered);
    }

    public function testRenderWithoutScannerNoZoneMarkers(): void
    {
        $bc = new Breadcrumb(); // no scanner

        $s = new NavStack();
        $s->push('Plain')->push('Text');

        $rendered = $bc->render($s);

        // Without scanner, no zone markers are added
        $this->assertStringNotContainsString("\u{E000}", $rendered);
    }

    // ─── Step 2: setSeparator validation ─────────────────────────────────────

    public function testSetSeparatorRejectsEmpty(): void
    {
        $bc = new Breadcrumb();
        $this->expectException(\InvalidArgumentException::class);
        $bc->setSeparator('');
    }

    public function testSetSeparatorRejectsNewline(): void
    {
        $bc = new Breadcrumb();
        $this->expectException(\InvalidArgumentException::class);
        $bc->setSeparator("Home \n Settings");
    }

    // ─── Step 3: itemRenderer return-type enforcement ─────────────────────────

    public function testItemRendererReturningNullFallsBackToTitle(): void
    {
        $s = new NavStack();
        $s->push('Home')->push('Settings');

        $bc = (new Breadcrumb())->setItemRenderer(
            fn($item, $i) => $i === 0 ? null : $item->title
        );

        // Index 0 renderer returns null → should fall back to item's own title
        $result = $bc->render($s);
        $this->assertSame('Home › Settings', $result);
    }

    public function testItemRendererReturningNonStringThrows(): void
    {
        $s = new NavStack();
        $s->push('Home');

        $bc = (new Breadcrumb())->setItemRenderer(
            fn($item, $i) => 42 // invalid: must return string|null
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('itemRenderer must return string|null');
        $bc->render($s);
    }

    // ─── Step 5: ::new() factory ───────────────────────────────────────────────

    public function testNewFactory(): void
    {
        $s = new NavStack();
        $s->push('Home')->push('Settings');

        $this->assertSame(
            (new Breadcrumb())->render($s),
            Breadcrumb::new()->render($s)
        );
    }

    // ─── Step 8: Additional coverage tests ─────────────────────────────────────

    public function testBreadcrumbCustomTruncatorViaSetTruncator(): void
    {
        $s = new NavStack();
        $s->push('Very Long Root Navigation Item')
          ->push('Medium Length Parent Item')
          ->push('Current Page Title');

        // Custom truncator '>> ' (distinct from default '… ')
        $bc = (new Breadcrumb())->setTruncator('>> ')->setMaxWidth(25);
        $result = $bc->render($s);

        $this->assertStringContainsString('>> ', $result);
        $this->assertStringNotContainsString('…', $result);
        $this->assertStringContainsString('Current Page Title', $result);
    }

    public function testTruncationWithScannerZoneCountMatchesVisibleCrumbs(): void
    {
        // A title that itself contains the separator (' › ') must map to exactly
        // ONE zone: titles are carried as a list (no string round-trip), so
        // 'A › B' must not split into two crumbs and inflate the zone count.
        $scanner = Scanner::new();
        $s = new NavStack();
        $s->push('A › B')->push('C');

        // Wide enough that BOTH crumbs stay visible (width 'A › B › C' = 9).
        $bc = (new Breadcrumb())->withScanner($scanner)->setMaxWidth(20);
        $rendered = $bc->render($s);
        $bc->scan($rendered);

        // Two visible crumbs → exactly two zones (crumb-0, crumb-1), NOT three
        // despite the separator inside 'A › B'.
        $zones = $scanner->prefixed('crumb-');
        $this->assertCount(2, $zones);
        $this->assertNotNull($scanner->get('crumb-0'));
        $this->assertNotNull($scanner->get('crumb-1'));
        $this->assertNull($scanner->get('crumb-2'));
    }

    public function testTruncationWithScannerZoneCountShrinksToVisibleAfterTruncation(): void
    {
        // When truncation drops all but the most-recent crumb, the zone count
        // must shrink to match: exactly one visible crumb → exactly one zone.
        $scanner = Scanner::new();
        $s = new NavStack();
        $s->push('A › B')->push('C');

        // Narrow enough that only 'C' survives (width 'A › B › C' = 9 > 5).
        $bc = (new Breadcrumb())->withScanner($scanner)->setMaxWidth(5);
        $rendered = $bc->render($s);
        $bc->scan($rendered);

        $this->assertCount(1, $scanner->prefixed('crumb-'));
        $this->assertNotNull($scanner->get('crumb-0'));
        $this->assertNull($scanner->get('crumb-1'));
    }

    // ─── SEC: control-sequence injection ────────────────────────────────────

    public function testRenderSanitizesControlSequenceInjection(): void
    {
        // Titles reaching render() are user-controlled (Shell::pushDirectory /
        // Url::parse). A raw ANSI sequence + NUL + newline must be neutralized.
        $s = new NavStack();
        $s->push('Home');
        $s->push("Ev\x1b[31mil\x00\nName");

        $rendered = (new Breadcrumb())->render($s);

        $this->assertStringNotContainsString("\x1b", $rendered);
        $this->assertStringNotContainsString("\x00", $rendered);
        $this->assertStringNotContainsString("\n", $rendered);
        $this->assertStringNotContainsString('[31m', $rendered);
        $this->assertStringContainsString('Home', $rendered);
        $this->assertStringContainsString('Evil', $rendered);
    }

    // ─── BUG: setMaxWidth() must be a hard cap on the final segment ──────────

    public function testTruncateEllipsizesFinalSegmentToHonorMaxWidth(): void
    {
        $bc = (new Breadcrumb())->setMaxWidth(10);
        // The most-recent segment alone is far wider than maxWidth; upstream
        // keeps it verbatim (busting the cap), we ellipsis-truncate it.
        $result = $bc->renderTitles(['Home', 'AVeryLongCurrentTitle']);

        // setMaxWidth() is a hard cap on the visible width...
        $this->assertLessThanOrEqual(10, Width::string($result));
        // ...achieved by ellipsis-truncating the final segment.
        $this->assertStringEndsWith('…', $result);
    }

    public function testBreadcrumbTitleContainingSeparatorRendersCorrectly(): void
    {
        // A title equal to 'A › B' with default separator ' › '
        // renders as ONE crumb (not two), post-Step-1 fix.
        $bc = new Breadcrumb();
        $result = $bc->renderTitles(['A › B']);

        // Should be exactly one item, no extra splitting
        $this->assertSame('A › B', $result);
        // Must not be split into 'A' and 'B'
        $this->assertStringNotContainsString('A › B ›', $result);
    }
}
