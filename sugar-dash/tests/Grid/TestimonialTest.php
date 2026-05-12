<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Testimonial;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class TestimonialTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTestimonialImplementsSizer(): void
    {
        $testimonial = Testimonial::new([['text' => 'Great!', 'author' => 'John']]);
        $this->assertInstanceOf(Sizer::class, $testimonial);
    }

    public function testTestimonialImplementsItem(): void
    {
        $testimonial = Testimonial::new([['text' => 'Great!', 'author' => 'John']]);
        $this->assertInstanceOf(Item::class, $testimonial);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $testimonial = Testimonial::new([['text' => 'Amazing product!', 'author' => 'Jane Doe']]);
        $rendered = $testimonial->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsQuoteText(): void
    {
        $testimonial = Testimonial::new([['text' => 'This changed my life', 'author' => 'Jane']]);
        $rendered = $testimonial->render();

        $this->assertStringContainsString('This changed my life', $rendered);
    }

    public function testRenderContainsAuthor(): void
    {
        $testimonial = Testimonial::new([['text' => 'Great!', 'author' => 'Sarah Connor']]);
        $rendered = $testimonial->render();

        $this->assertStringContainsString('Sarah Connor', $rendered);
    }

    public function testRenderContainsQuoteCharacters(): void
    {
        $testimonial = Testimonial::new([['text' => 'Wow', 'author' => 'Test']]);
        $rendered = $testimonial->render();

        $this->assertStringContainsString('「', $rendered);
        $this->assertStringContainsString('」', $rendered);
    }

    public function testEmptyItemsReturnsEmpty(): void
    {
        $testimonial = Testimonial::new([]);
        $rendered = $testimonial->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactory(): void
    {
        $testimonial = Testimonial::new([
            ['text' => 'Excellent service!', 'author' => 'Mike Johnson', 'role' => 'CEO'],
        ]);
        $rendered = $testimonial->render();

        $this->assertStringContainsString('Excellent service!', $rendered);
        $this->assertStringContainsString('Mike Johnson', $rendered);
    }

    public function testSingleFactory(): void
    {
        $testimonial = Testimonial::single([
            'text' => 'Best purchase ever',
            'author' => 'Alice',
            'role' => 'CTO',
            'company' => 'TechCorp',
        ]);
        $rendered = $testimonial->render();

        $this->assertStringContainsString('Best purchase ever', $rendered);
        $this->assertStringContainsString('Alice', $rendered);
        $this->assertStringContainsString('CTO at TechCorp', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multiple testimonials
    // ═══════════════════════════════════════════════════════════════

    public function testMultipleTestimonialsRenderAll(): void
    {
        $testimonial = Testimonial::new([
            ['text' => 'First review', 'author' => 'Author 1'],
            ['text' => 'Second review', 'author' => 'Author 2'],
        ]);
        $rendered = $testimonial->render();

        $this->assertStringContainsString('First review', $rendered);
        $this->assertStringContainsString('Second review', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Role and company
    // ═══════════════════════════════════════════════════════════════

    public function testRoleRenders(): void
    {
        $testimonial = Testimonial::new([[
            'text' => 'Great!',
            'author' => 'Dev',
            'role' => 'Senior Engineer',
        ]]);
        $rendered = $testimonial->render();

        $this->assertStringContainsString('Senior Engineer', $rendered);
    }

    public function testCompanyRenders(): void
    {
        $testimonial = Testimonial::new([[
            'text' => 'Great!',
            'author' => 'Dev',
            'company' => 'Acme Inc',
        ]]);
        $rendered = $testimonial->render();

        $this->assertStringContainsString('Acme Inc', $rendered);
    }

    public function testAvatarRenders(): void
    {
        $testimonial = Testimonial::new([[
            'text' => 'Great!',
            'author' => 'Dev',
            'avatar' => 'JD',
        ]]);
        $rendered = $testimonial->render();

        $this->assertStringContainsString('[JD]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testQuoteColorAddsAnsiCodes(): void
    {
        $testimonial = Testimonial::new([['text' => 'Test', 'author' => 'A']])
            ->withQuoteColor(Color::ansi(12));
        $rendered = $testimonial->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testAuthorColorAddsAnsiCodes(): void
    {
        $testimonial = Testimonial::new([['text' => 'Test', 'author' => 'A']])
            ->withAuthorColor(Color::ansi(9));
        $rendered = $testimonial->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testRoleColorAddsAnsiCodes(): void
    {
        $testimonial = Testimonial::new([['text' => 'Test', 'author' => 'A', 'role' => 'Boss']])
            ->withRoleColor(Color::ansi(8));
        $rendered = $testimonial->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testAccentColorAddsAnsiCodes(): void
    {
        $testimonial = Testimonial::new([['text' => 'Test', 'author' => 'A']])
            ->withAccentColor(Color::ansi(13));
        $rendered = $testimonial->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Quote characters
    // ═══════════════════════════════════════════════════════════════

    public function testCustomQuoteChars(): void
    {
        $testimonial = Testimonial::new([['text' => 'Test', 'author' => 'A']])
            ->withQuoteChars('"', '"');
        $rendered = $testimonial->render();

        $this->assertStringContainsString('"', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Testimonial::new([['text' => 'Test', 'author' => 'A']]);
        $resized = $original->setSize(80, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = Testimonial::new([['text' => 'Original', 'author' => 'A']]);
        $updated = $original->withItems([['text' => 'Updated', 'author' => 'B']]);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
        $this->assertStringNotContainsString('Original', $updated->render());
    }

    public function testWithQuoteColorReturnsNewInstance(): void
    {
        $original = Testimonial::new([['text' => 'Test', 'author' => 'A']]);
        $updated = $original->withQuoteColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithItems(): void
    {
        $original = Testimonial::new([['text' => 'Original', 'author' => 'A']]);
        $original->withItems([['text' => 'Changed', 'author' => 'B']]);
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $testimonial = Testimonial::new([['text' => 'Short quote', 'author' => 'Author']]);
        [$w, $h] = $testimonial->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testLongTextWraps(): void
    {
        $testimonial = Testimonial::new([[
            'text' => str_repeat('word ', 50),
            'author' => 'Test',
        ]]);
        $rendered = $testimonial->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeText(): void
    {
        $testimonial = Testimonial::new([[
            'text' => 'これは素晴らしい製品です！',
            'author' => '田中太郎',
            'role' => '開発者',
            'company' => 'テックCorp',
        ]]);
        $rendered = $testimonial->render();

        $this->assertStringContainsString('これは素晴らしい製品です！', $rendered);
        $this->assertStringContainsString('田中太郎', $rendered);
    }

    public function testUnicodeQuotes(): void
    {
        $testimonial = Testimonial::new([['text' => 'Test', 'author' => 'A']])
            ->withQuoteChars('『', '』');
        $rendered = $testimonial->render();

        $this->assertStringContainsString('『', $rendered);
        $this->assertStringContainsString('』', $rendered);
    }
}
