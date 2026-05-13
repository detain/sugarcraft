<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Bullet;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class BulletTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBulletImplementsSizer(): void
    {
        $bullet = Bullet::new(50.0, 100.0);
        $this->assertInstanceOf(Sizer::class, $bullet);
    }

    public function testBulletImplementsItem(): void
    {
        $bullet = Bullet::new(50.0, 100.0);
        $this->assertInstanceOf(Item::class, $bullet);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $bullet = Bullet::new(50.0, 100.0);
        $rendered = $bullet->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsFilledChar(): void
    {
        $bullet = Bullet::new(50.0, 100.0);
        $rendered = $bullet->render();

        $this->assertStringContainsString('█', $rendered);
    }

    public function testZeroWidthRendersEmpty(): void
    {
        $bullet = Bullet::new(50.0, 100.0)->withWidth(0);
        $this->assertSame('', $bullet->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Actual vs Target display
    // ═══════════════════════════════════════════════════════════════

    public function testActualLessThanTarget(): void
    {
        $bullet = Bullet::new(50.0, 100.0)->withWidth(10);
        $rendered = $bullet->render();

        // Should show partial fill (less than target)
        $this->assertStringContainsString('█', $rendered);
        $this->assertStringContainsString('░', $rendered);
    }

    public function testActualEqualToTarget(): void
    {
        $bullet = Bullet::new(100.0, 100.0)->withWidth(10);
        $rendered = $bullet->render();

        // Should show full fill reaching target
        $this->assertStringContainsString('█', $rendered);
    }

    public function testActualGreaterThanTarget(): void
    {
        $bullet = Bullet::new(120.0, 100.0)->withWidth(10);
        $rendered = $bullet->render();

        // Should show overflow past target
        $this->assertStringContainsString('█', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Target marker
    // ═══════════════════════════════════════════════════════════════

    public function testShowTargetDisplaysTargetMarker(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(10)
            ->withShowTarget(true);
        $rendered = $bullet->render();

        // Should contain target character
        $this->assertStringContainsString('│', $rendered);
    }

    public function testHideTargetHidesTargetMarker(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(10)
            ->withShowTarget(false);
        $rendered = $bullet->render();

        // Should NOT contain target character
        $this->assertStringNotContainsString('│', $rendered);
    }

    public function testTargetLabelShowsTargetValue(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(10)
            ->withShowTarget(true);
        $rendered = $bullet->render();

        // Should contain target value
        $this->assertStringContainsString('100.0', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Comparative measure
    // ═══════════════════════════════════════════════════════════════

    public function testShowComparativeDisplaysComparativeLine(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(10)
            ->withShowComparative(true)
            ->withComparative(75.0);
        $rendered = $bullet->render();

        // Should contain comparative character
        $this->assertStringContainsString('─', $rendered);
    }

    public function testHideComparativeHidesComparativeLine(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(10)
            ->withShowComparative(false)
            ->withComparative(75.0);
        $rendered = $bullet->render();

        // Should NOT contain comparative character
        $this->assertStringNotContainsString('─', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testActualColorAddsAnsiCodes(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(10)
            ->withActualColor(Color::ansi(9));
        $rendered = $bullet->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTargetColorAddsAnsiCodes(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(10)
            ->withTargetColor(Color::ansi(10));
        $rendered = $bullet->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(10)
            ->withActualColor(Color::ansi(9))
            ->withTargetColor(Color::ansi(10));
        $rendered = $bullet->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    public function testNullColorsNoAnsiCodes(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(10)
            ->withActualColor(null)
            ->withTargetColor(null)
            ->withBackgroundColor(null);
        $rendered = $bullet->render();

        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom characters
    // ═══════════════════════════════════════════════════════════════

    public function testWithCharsChangesBarCharacters(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(10)
            ->withChars('=', '-');
        $rendered = $bullet->render();

        $this->assertStringContainsString('=', $rendered);
        $this->assertStringContainsString('-', $rendered);
        $this->assertStringNotContainsString('█', $rendered);
        $this->assertStringNotContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithActualReturnsNewInstance(): void
    {
        $original = Bullet::new(50.0, 100.0);
        $updated = $original->withActual(75.0);

        $this->assertNotSame($original, $updated);
    }

    public function testWithTargetReturnsNewInstance(): void
    {
        $original = Bullet::new(50.0, 100.0);
        $updated = $original->withTarget(150.0);

        $this->assertNotSame($original, $updated);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $original = Bullet::new(50.0, 100.0);
        $updated = $original->withWidth(20);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowTargetReturnsNewInstance(): void
    {
        $original = Bullet::new(50.0, 100.0);
        $updated = $original->withShowTarget(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithComparativeReturnsNewInstance(): void
    {
        $original = Bullet::new(50.0, 100.0);
        $updated = $original->withComparative(75.0);

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Bullet::new(50.0, 100.0);
        $resized = $original->setSize(20, 1);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $bullet = Bullet::new(50.0, 100.0)->withWidth(20);
        [$w, $h] = $bullet->getInnerSize();

        $this->assertGreaterThanOrEqual(20, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithComparativeIsTwoLines(): void
    {
        $bullet = Bullet::new(50.0, 100.0)
            ->withWidth(20)
            ->withShowComparative(true)
            ->withComparative(75.0);
        [$w, $h] = $bullet->getInnerSize();

        $this->assertSame(2, $h);
    }

    public function testGetInnerSizeEmptyReturnsZeroWidth(): void
    {
        $bullet = new Bullet(
            actual: 50.0,
            target: 100.0,
            widthConstraint: null,
            showTarget: true,
            showComparative: false,
            comparative: null,
            actualColor: null,
            targetColor: null,
            comparativeColor: null,
            backgroundColor: null
        );
        [$w, $h] = $bullet->getInnerSize();

        $this->assertSame(0, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testZeroTarget(): void
    {
        $bullet = Bullet::new(50.0, 0.0)->withWidth(10);
        $rendered = $bullet->render();

        // Should handle zero target gracefully
        $this->assertNotSame('', $rendered);
    }

    public function testNegativeActual(): void
    {
        $bullet = Bullet::new(-50.0, 100.0)->withWidth(10);
        $rendered = $bullet->render();

        // Should handle negative actual values
        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithNoSizeAndNoConstraint(): void
    {
        $bullet = new Bullet(
            actual: 50.0,
            target: 100.0,
            widthConstraint: null,
            showTarget: true,
            showComparative: false,
            comparative: null,
            actualColor: null,
            targetColor: null,
            comparativeColor: null,
            backgroundColor: null,
            filledChar: '█',
            emptyChar: '░',
            targetChar: '│',
            comparativeChar: '─',
        );
        $this->assertSame('', $bullet->render());
    }

    public function testExactTargetMatch(): void
    {
        $bullet = Bullet::new(100.0, 100.0)->withWidth(10)->withShowTarget(true);
        $rendered = $bullet->render();

        $this->assertStringContainsString('█', $rendered);
        $this->assertStringContainsString('│', $rendered);
    }
}
