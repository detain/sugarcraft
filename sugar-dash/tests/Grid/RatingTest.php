<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Rating;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class RatingTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testRatingImplementsSizer(): void
    {
        $rating = Rating::new();
        $this->assertInstanceOf(Sizer::class, $rating);
    }

    public function testRatingImplementsItem(): void
    {
        $rating = Rating::new();
        $this->assertInstanceOf(Item::class, $rating);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $rating = Rating::new();
        $rendered = $rating->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsStarCharacters(): void
    {
        $rating = Rating::new(3, 2.0);
        $rendered = $rating->render();

        $this->assertStringContainsString('★', $rendered);
    }

    public function testRenderEmptyRatingShowsAllEmptyStars(): void
    {
        $rating = Rating::new(3, 0.0);
        $rendered = $rating->render();

        $this->assertStringContainsString('☆', $rendered);
        $this->assertStringNotContainsString('★', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Value rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderFullStars(): void
    {
        $rating = Rating::new(5, 3.0);
        $rendered = $rating->render();

        // Should have 3 filled stars
        $this->assertEquals(3, substr_count($rendered, '★'));
    }

    public function testRenderMaxRatingShowsAllFilled(): void
    {
        $rating = Rating::new(5, 5.0);
        $rendered = $rating->render();

        $this->assertEquals(5, substr_count($rendered, '★'));
        $this->assertStringNotContainsString('☆', $rendered);
    }

    public function testOfFactory(): void
    {
        $rating = Rating::of(4.0, 5);
        $rendered = $rating->render();

        $this->assertEquals(4, substr_count($rendered, '★'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom characters
    // ═══════════════════════════════════════════════════════════════

    public function testCustomCharacters(): void
    {
        $rating = Rating::new(3, 2.0)->withChars('*', '-');
        $rendered = $rating->render();

        $this->assertStringContainsString('*', $rendered);
        $this->assertStringContainsString('-', $rendered);
        $this->assertStringNotContainsString('★', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testFilledColorAddsAnsiCodes(): void
    {
        $rating = Rating::new()->withFilledColor(Color::ansi(9));
        $rendered = $rating->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testEmptyColorAddsAnsiCodes(): void
    {
        $rating = Rating::new(3, 1.0)->withEmptyColor(Color::ansi(8));
        $rendered = $rating->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $rating = Rating::new()->withFilledColor(Color::ansi(9));
        $rendered = $rating->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithValueReturnsNewInstance(): void
    {
        $original = Rating::new(5, 2.0);
        $updated = $original->withValue(4.0);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMaxStarsReturnsNewInstance(): void
    {
        $original = Rating::new(5, 2.0);
        $updated = $original->withMaxStars(10);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithValue(): void
    {
        $original = Rating::new(5, 2.0);
        $original->withValue(5.0);

        $rendered = $original->render();
        $this->assertEquals(2, substr_count($rendered, '★'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Rating::new();
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectWidth(): void
    {
        $rating = Rating::new(5);
        [$w, $h] = $rating->getInnerSize();

        $this->assertSame(5, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithCustomChars(): void
    {
        $rating = Rating::new(3)->withChars('***', '---');
        [$w, $h] = $rating->getInnerSize();

        // Should use max width of either char
        $this->assertGreaterThan(0, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeValueClampedToZero(): void
    {
        $rating = Rating::new(5, -3.0);
        $rendered = $rating->render();

        $this->assertStringNotContainsString('★', $rendered);
    }

    public function testOversizedValueClampedToMax(): void
    {
        $rating = Rating::new(5, 10.0);
        $rendered = $rating->render();

        $this->assertEquals(5, substr_count($rendered, '★'));
    }

    public function testSingleStar(): void
    {
        $rating = Rating::new(1, 1.0);
        $rendered = $rating->render();

        $this->assertStringContainsString('★', $rendered);
        $this->assertStringNotContainsString('☆', $rendered);
    }

    public function testPartialStarRendersAsEmpty(): void
    {
        $rating = Rating::new(5, 2.5);
        $rendered = $rating->render();

        // 2.5 should show 2 full stars and 1 "empty" (representing half)
        $this->assertEquals(2, substr_count($rendered, '★'));
    }
}
