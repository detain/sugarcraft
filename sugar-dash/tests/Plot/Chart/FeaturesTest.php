<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Components\Card\Features;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class FeaturesTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testFeaturesImplementsSizer(): void
    {
        $features = Features::new([['icon' => '★', 'title' => 'Test', 'description' => 'Desc']]);
        $this->assertInstanceOf(Sizer::class, $features);
    }

    public function testFeaturesImplementsItem(): void
    {
        $features = Features::new([['icon' => '★', 'title' => 'Test', 'description' => 'Desc']]);
        $this->assertInstanceOf(Item::class, $features);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $features = Features::new([['icon' => '★', 'title' => 'Test', 'description' => 'Description']]);
        $rendered = $features->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsIcon(): void
    {
        $features = Features::new([['icon' => '★', 'title' => 'Test', 'description' => 'Desc']]);
        $rendered = $features->render();

        $this->assertStringContainsString('★', $rendered);
    }

    public function testRenderContainsTitle(): void
    {
        $features = Features::new([['icon' => '★', 'title' => 'Fast', 'description' => 'Speed']]);
        $rendered = $features->render();

        $this->assertStringContainsString('Fast', $rendered);
    }

    public function testRenderContainsDescription(): void
    {
        $features = Features::new([['icon' => '★', 'title' => 'Test', 'description' => 'Lightning fast']]);
        $rendered = $features->render();

        $this->assertStringContainsString('Lightning fast', $rendered);
    }

    public function testEmptyItemsReturnsEmpty(): void
    {
        $features = Features::new([]);
        $rendered = $features->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactory(): void
    {
        $features = Features::new([
            ['icon' => '⚡', 'title' => 'Speed', 'description' => 'Lightning fast'],
            ['icon' => '🔒', 'title' => 'Security', 'description' => 'Enterprise grade'],
        ]);
        $rendered = $features->render();

        $this->assertStringContainsString('Speed', $rendered);
        $this->assertStringContainsString('Security', $rendered);
    }

    public function testCompactFactory(): void
    {
        $features = Features::compact([
            ['icon' => '★', 'title' => 'Compact', 'description' => 'Small'],
        ]);

        $this->assertNotSame('', $features->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Multiple features
    // ═══════════════════════════════════════════════════════════════

    public function testMultipleFeaturesRenderAll(): void
    {
        $features = Features::new([
            ['icon' => '★', 'title' => 'Feature 1', 'description' => 'Desc 1'],
            ['icon' => '◆', 'title' => 'Feature 2', 'description' => 'Desc 2'],
            ['icon' => '●', 'title' => 'Feature 3', 'description' => 'Desc 3'],
        ]);
        $rendered = $features->render();

        $this->assertStringContainsString('Feature 1', $rendered);
        $this->assertStringContainsString('Feature 2', $rendered);
        $this->assertStringContainsString('Feature 3', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testIconColorAddsAnsiCodes(): void
    {
        $features = Features::new([['icon' => '★', 'title' => 'Test', 'description' => 'Desc']])
            ->withIconColor(Color::ansi(12));
        $rendered = $features->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTitleColorAddsAnsiCodes(): void
    {
        $features = Features::new([['icon' => '★', 'title' => 'Test', 'description' => 'Desc']])
            ->withTitleColor(Color::ansi(9));
        $rendered = $features->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testDescriptionColorAddsAnsiCodes(): void
    {
        $features = Features::new([['icon' => '★', 'title' => 'Test', 'description' => 'Desc']])
            ->withDescriptionColor(Color::ansi(8));
        $rendered = $features->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBorderColorAddsAnsiCodes(): void
    {
        $features = Features::new([
            ['icon' => 'A', 'title' => 'Test1', 'description' => 'D1'],
            ['icon' => 'B', 'title' => 'Test2', 'description' => 'D2'],
        ])->withBorderColor(Color::ansi(8));
        $rendered = $features->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Column handling
    // ═══════════════════════════════════════════════════════════════

    public function testCustomColumns(): void
    {
        $features = Features::new([
            ['icon' => '★', 'title' => 'Test', 'description' => 'Desc'],
        ])->withColumns(2);
        $rendered = $features->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSingleColumnLayout(): void
    {
        $features = Features::new([
            ['icon' => '★', 'title' => 'Test', 'description' => 'Desc'],
        ])->withColumns(1);
        $rendered = $features->render();

        $this->assertStringContainsString('Test', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Features::new([['icon' => '★', 'title' => 'T', 'description' => 'D']]);
        $resized = $original->setSize(80, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = Features::new([['icon' => '★', 'title' => 'A', 'description' => 'D1']]);
        $updated = $original->withItems([['icon' => '◆', 'title' => 'B', 'description' => 'D2']]);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('B', $updated->render());
        $this->assertStringNotContainsString('A', $updated->render());
    }

    public function testWithIconColorReturnsNewInstance(): void
    {
        $original = Features::new([['icon' => '★', 'title' => 'T', 'description' => 'D']]);
        $updated = $original->withIconColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithItems(): void
    {
        $original = Features::new([['icon' => '★', 'title' => 'Original', 'description' => 'D']]);
        $original->withItems([['icon' => '◆', 'title' => 'Changed', 'description' => 'D2']]);
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $features = Features::new([['icon' => '★', 'title' => 'Test', 'description' => 'Desc']]);
        [$w, $h] = $features->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodeIcons(): void
    {
        $features = Features::new([
            ['icon' => '🚀', 'title' => 'Rocket', 'description' => 'Fast'],
            ['icon' => '⚡', 'title' => 'Power', 'description' => 'Strong'],
        ]);
        $rendered = $features->render();

        $this->assertStringContainsString('🚀', $rendered);
        $this->assertStringContainsString('⚡', $rendered);
    }

    public function testUnicodeTitles(): void
    {
        $features = Features::new([
            ['icon' => '★', 'title' => '特徴', 'description' => '日本語'],
        ]);
        $rendered = $features->render();

        $this->assertStringContainsString('特徴', $rendered);
    }

    public function testVeryLongDescription(): void
    {
        $features = Features::new([[
            'icon' => '★',
            'title' => 'Test',
            'description' => str_repeat('Word ', 50),
        ]]);
        $rendered = $features->render();

        $this->assertNotSame('', $rendered);
    }
}