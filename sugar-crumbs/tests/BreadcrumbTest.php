<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs\Tests;

use SugarCraft\Crumbs\{Breadcrumb, NavStack};
use SugarCraft\Mouse\Scanner;
use SugarCraft\Zone\Manager;
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

    // ─── Back-compat: withZoneManager() ─────────────────────────────────────

    public function testWithZoneManagerBackCompatDoesNotThrow(): void
    {
        $manager = Manager::newGlobal();
        $bc = new Breadcrumb();

        // withZoneManager() is a no-op (deprecated, ignored)
        // Should not throw even with null manager
        $result = $bc->withZoneManager($manager);
        $this->assertInstanceOf(Breadcrumb::class, $result);
    }

    public function testWithZoneManagerAcceptsNull(): void
    {
        $bc = new Breadcrumb();
        $result = $bc->withZoneManager(null);
        $this->assertInstanceOf(Breadcrumb::class, $result);
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
}
