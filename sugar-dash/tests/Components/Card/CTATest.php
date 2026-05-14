<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\CTA;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class CTATest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testCTAImplementsSizer(): void
    {
        $cta = CTA::new('Get Started');
        $this->assertInstanceOf(Sizer::class, $cta);
    }

    public function testCTAImplementsItem(): void
    {
        $cta = CTA::new('Get Started');
        $this->assertInstanceOf(Item::class, $cta);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $cta = CTA::new('Get Started', 'Start your journey');
        $rendered = $cta->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $cta = CTA::new('Get Started');
        $rendered = $cta->render();

        $this->assertStringContainsString('Get Started', $rendered);
    }

    public function testRenderContainsDescription(): void
    {
        $cta = CTA::new('Get Started', 'Start your journey today');
        $rendered = $cta->render();

        $this->assertStringContainsString('Start your journey today', $rendered);
    }

    public function testRenderContainsArrow(): void
    {
        $cta = CTA::new('Get Started');
        $rendered = $cta->render();

        $this->assertStringContainsString('→', $rendered);
    }

    public function testRenderContainsBorderChars(): void
    {
        $cta = CTA::new('Get Started');
        $rendered = $cta->render();

        $this->assertStringContainsString('═', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactory(): void
    {
        $cta = CTA::new('Get Started', 'Try it free');
        $rendered = $cta->render();

        $this->assertStringContainsString('Get Started', $rendered);
        $this->assertStringContainsString('Try it free', $rendered);
    }

    public function testPrimaryFactory(): void
    {
        $cta = CTA::primary('Sign Up', 'Create account');
        $rendered = $cta->render();

        $this->assertStringContainsString('Sign Up', $rendered);
        $this->assertNotSame('', $rendered);
    }

    public function testSuccessFactory(): void
    {
        $cta = CTA::success('Download', 'Get the app');
        $rendered = $cta->render();

        $this->assertStringContainsString('Download', $rendered);
        $this->assertNotSame('', $rendered);
    }

    public function testDangerFactory(): void
    {
        $cta = CTA::danger('Delete', 'This cannot be undone');
        $rendered = $cta->render();

        $this->assertStringContainsString('Delete', $rendered);
        $this->assertNotSame('', $rendered);
    }

    public function testOutlineFactory(): void
    {
        $cta = CTA::outline('Learn More');
        $rendered = $cta->render();

        $this->assertStringContainsString('Learn More', $rendered);
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Arrow handling
    // ═══════════════════════════════════════════════════════════════

    public function testHideArrow(): void
    {
        $cta = CTA::new('Get Started')->withShowArrow(false);
        $rendered = $cta->render();

        $this->assertStringNotContainsString('→', $rendered);
    }

    public function testShowArrow(): void
    {
        $cta = CTA::new('Get Started')->withShowArrow(true);
        $rendered = $cta->render();

        $this->assertStringContainsString('→', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBgColorAddsAnsiCodes(): void
    {
        $cta = CTA::new('Test')->withBgColor(Color::ansi(12));
        $rendered = $cta->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTextColorAddsAnsiCodes(): void
    {
        $cta = CTA::new('Test')->withTextColor(Color::ansi(15));
        $rendered = $cta->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBorderColorAddsAnsiCodes(): void
    {
        $cta = CTA::new('Test')->withBorderColor(Color::ansi(8));
        $rendered = $cta->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testNullBgColorRendersWithoutBackground(): void
    {
        $cta = CTA::outline('Test');
        $rendered = $cta->render();

        $this->assertStringContainsString('Test', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border character handling
    // ═══════════════════════════════════════════════════════════════

    public function testCustomBorderChar(): void
    {
        $cta = CTA::new('Test')->withBorderChar('-');
        $rendered = $cta->render();

        $this->assertStringContainsString('-', $rendered);
    }

    public function testDifferentBorderCharForOutline(): void
    {
        $cta = CTA::outline('Test');
        $rendered = $cta->render();

        // Outline style uses '─' not '═'
        $this->assertStringContainsString('─', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = CTA::new('Test');
        $resized = $original->setSize(80, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testSetWidthAffectsRender(): void
    {
        $cta1 = CTA::new('Test')->setSize(40, 3);
        $cta2 = CTA::new('Test')->setSize(80, 3);

        $rendered1 = $cta1->render();
        $rendered2 = $cta2->render();

        // Wider CTA should have more padding
        $this->assertGreaterThan(strlen($rendered1), strlen($rendered2));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithLabelReturnsNewInstance(): void
    {
        $original = CTA::new('Original');
        $updated = $original->withLabel('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
        $this->assertStringNotContainsString('Original', $updated->render());
    }

    public function testWithDescriptionReturnsNewInstance(): void
    {
        $original = CTA::new('Test', 'Old desc');
        $updated = $original->withDescription('New desc');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('New desc', $updated->render());
    }

    public function testWithBgColorReturnsNewInstance(): void
    {
        $original = CTA::new('Test');
        $updated = $original->withBgColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithLabel(): void
    {
        $original = CTA::new('Original');
        $original->withLabel('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $cta = CTA::new('Get Started', 'Try it free');
        [$w, $h] = $cta->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithDescriptionHasMoreHeight(): void
    {
        $withoutDesc = CTA::new('Test');
        $withDesc = CTA::new('Test', 'Description');

        [, $h1] = $withoutDesc->getInnerSize();
        [, $h2] = $withDesc->getInnerSize();

        $this->assertGreaterThan($h1, $h2);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVeryLongLabel(): void
    {
        $cta = CTA::new(str_repeat('A', 50));
        $rendered = $cta->render();

        $this->assertStringContainsString(str_repeat('A', 50), $rendered);
    }

    public function testVeryLongDescription(): void
    {
        $cta = CTA::new('Test', str_repeat('word ', 20));
        $rendered = $cta->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeLabel(): void
    {
        $cta = CTA::new('始める', '始めましょう');
        $rendered = $cta->render();

        $this->assertStringContainsString('始める', $rendered);
        $this->assertStringContainsString('始めましょう', $rendered);
    }

    public function testEmptyDescriptionStillRenders(): void
    {
        $cta = CTA::new('Get Started', '');
        $rendered = $cta->render();

        $this->assertStringContainsString('Get Started', $rendered);
    }

    public function testNoArrowForOutline(): void
    {
        $cta = CTA::outline('Learn More');
        $rendered = $cta->render();

        // Outline also shows arrow by default
        $this->assertStringContainsString('→', $rendered);
    }
}
