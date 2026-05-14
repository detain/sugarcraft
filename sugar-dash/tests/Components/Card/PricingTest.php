<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use SugarCraft\Dash\Components\Card\Pricing;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testPricingImplementsSizer(): void
    {
        $pricing = Pricing::new([
            ['name' => 'Basic', 'price' => '$9', 'period' => '/mo'],
        ]);
        $this->assertInstanceOf(Sizer::class, $pricing);
    }

    public function testPricingImplementsItem(): void
    {
        $pricing = Pricing::new([
            ['name' => 'Basic', 'price' => '$9', 'period' => '/mo'],
        ]);
        $this->assertInstanceOf(Item::class, $pricing);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $pricing = Pricing::new([
            ['name' => 'Basic', 'price' => '$9', 'period' => '/mo'],
        ]);
        $rendered = $pricing->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsPlanName(): void
    {
        $pricing = Pricing::new([
            ['name' => 'Pro', 'price' => '$19', 'period' => '/mo'],
        ]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('Pro', $rendered);
    }

    public function testRenderContainsPrice(): void
    {
        $pricing = Pricing::new([
            ['name' => 'Pro', 'price' => '$19', 'period' => '/mo'],
        ]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('$19', $rendered);
    }

    public function testEmptyPlansReturnsEmpty(): void
    {
        $pricing = Pricing::new([]);
        $rendered = $pricing->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactory(): void
    {
        $pricing = Pricing::new([
            ['name' => 'Basic', 'price' => '$9', 'period' => '/mo'],
            ['name' => 'Pro', 'price' => '$19', 'period' => '/mo'],
        ]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('Basic', $rendered);
        $this->assertStringContainsString('Pro', $rendered);
    }

    public function testCompactFactory(): void
    {
        $pricing = Pricing::compact([
            ['name' => 'Basic', 'price' => '$9'],
            ['name' => 'Pro', 'price' => '$19'],
        ]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('Basic', $rendered);
        $this->assertStringContainsString('$9', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multiple plans
    // ═══════════════════════════════════════════════════════════════

    public function testMultiplePlansRenderAll(): void
    {
        $pricing = Pricing::new([
            ['name' => 'Starter', 'price' => '$9', 'period' => '/mo'],
            ['name' => 'Basic', 'price' => '$19', 'period' => '/mo'],
            ['name' => 'Pro', 'price' => '$49', 'period' => '/mo'],
        ]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('Starter', $rendered);
        $this->assertStringContainsString('Basic', $rendered);
        $this->assertStringContainsString('Pro', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Description
    // ═══════════════════════════════════════════════════════════════

    public function testDescriptionRenders(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
            'description' => 'Best for teams',
        ]]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('Best for teams', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Features
    // ═══════════════════════════════════════════════════════════════

    public function testFeaturesRender(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
            'features' => ['+Feature 1', '+Feature 2'],
        ]]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('Feature 1', $rendered);
        $this->assertStringContainsString('Feature 2', $rendered);
    }

    public function testCheckMarkForIncludedFeature(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
            'features' => ['+Unlimited users'],
        ]]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('✓', $rendered);
    }

    public function testXMarkForExcludedFeature(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Basic',
            'price' => '$19',
            'period' => '/mo',
            'features' => ['-Advanced analytics'],
        ]]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('✗', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Highlighted plan
    // ═══════════════════════════════════════════════════════════════

    public function testHighlightedPlanRenders(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
            'highlighted' => true,
        ]]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('Pro', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testHeaderColorAddsAnsiCodes(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]])->withHeaderColor(Color::ansi(12));
        $rendered = $pricing->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testPriceColorAddsAnsiCodes(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]])->withPriceColor(Color::ansi(9));
        $rendered = $pricing->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBorderColorAddsAnsiCodes(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]])->withBorderColor(Color::ansi(8));
        $rendered = $pricing->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border handling
    // ═══════════════════════════════════════════════════════════════

    public function testBordersVisibleByDefault(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('│', $rendered);
    }

    public function testWithoutBordersNoBoxCharacters(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]])->withBorders(false);
        $rendered = $pricing->render();

        // Should not contain box-drawing characters
        $this->assertStringNotContainsString('┌', $rendered);
        $this->assertStringNotContainsString('│', $rendered);
        $this->assertStringNotContainsString('└', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]]);
        $resized = $original->setSize(100, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithPlansReturnsNewInstance(): void
    {
        $original = Pricing::new([[
            'name' => 'Basic',
            'price' => '$9',
            'period' => '/mo',
        ]]);
        $updated = $original->withPlans([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]]);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Pro', $updated->render());
        $this->assertStringNotContainsString('Basic', $updated->render());
    }

    public function testWithHeaderColorReturnsNewInstance(): void
    {
        $original = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]]);
        $updated = $original->withHeaderColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithPriceColorReturnsNewInstance(): void
    {
        $original = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]]);
        $updated = $original->withPriceColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBordersReturnsNewInstance(): void
    {
        $original = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]]);
        $updated = $original->withBorders(false);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithPlans(): void
    {
        $original = Pricing::new([[
            'name' => 'Original',
            'price' => '$9',
            'period' => '/mo',
        ]]);
        $original->withPlans([[
            'name' => 'Changed',
            'price' => '$19',
            'period' => '/mo',
        ]]);
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]]);
        [$w, $h] = $pricing->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithFeaturesHasMoreHeight(): void
    {
        $pricingNoFeatures = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
        ]]);
        $pricingWithFeatures = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
            'features' => ['+Feature 1', '+Feature 2', '+Feature 3'],
        ]]);

        [, $hNoFeatures] = $pricingNoFeatures->getInnerSize();
        [, $hWithFeatures] = $pricingWithFeatures->getInnerSize();

        $this->assertGreaterThan($hNoFeatures, $hWithFeatures);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodePlanName(): void
    {
        $pricing = Pricing::new([[
            'name' => 'プロ',
            'price' => '¥980',
            'period' => '/月',
        ]]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('プロ', $rendered);
    }

    public function testVeryLongPrice(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Enterprise',
            'price' => '$' . str_repeat('9', 20),
            'period' => '/mo',
        ]]);
        $rendered = $pricing->render();

        $this->assertStringContainsString('Enterprise', $rendered);
    }

    public function testVeryLongFeature(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
            'features' => ['+' . str_repeat('Feature ', 20)],
        ]]);
        $rendered = $pricing->render();

        $this->assertNotSame('', $rendered);
    }

    public function testHighlightColorSetting(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
            'highlighted' => true,
        ]])->withHighlightColor(Color::ansi(13));
        $rendered = $pricing->render();

        // Just verify it renders without error
        $this->assertNotSame('', $rendered);
    }

    public function testFeatureColorSetting(): void
    {
        $pricing = Pricing::new([[
            'name' => 'Pro',
            'price' => '$49',
            'period' => '/mo',
            'features' => ['+Some feature'],
        ]])->withFeatureColor(Color::ansi(8));
        $rendered = $pricing->render();

        // Just verify it renders without error
        $this->assertNotSame('', $rendered);
    }
}
