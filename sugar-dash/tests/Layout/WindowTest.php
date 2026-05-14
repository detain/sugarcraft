<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout;

use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Layout\Window;
use SugarCraft\Sprinkles\VAlign;
use PHPUnit\Framework\TestCase;

final class WindowTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testWindowImplementsSizer(): void
    {
        $window = Window::new(Text::new('content'), 'Title');
        $this->assertInstanceOf(Sizer::class, $window);
    }

    public function testWindowImplementsItem(): void
    {
        $window = Window::new(Text::new('content'), 'Title');
        $this->assertInstanceOf(Item::class, $window);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithoutSetSizeReturnsContent(): void
    {
        $text = Text::new('Hello');
        $window = Window::new($text, 'My Window');
        $rendered = $window->render();

        // Without setSize, should return raw content
        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testRenderWithSizeReturnsWindowFrame(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text, 'Title')->setSize(20, 8);
        $rendered = $window->render();

        // Should contain content
        $this->assertStringContainsString('Content', $rendered);
    }

    public function testRenderWithTitleShowsTitle(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text, 'My Window Title')->setSize(25, 8);
        $rendered = $window->render();

        $this->assertStringContainsString('My Window Title', $rendered);
    }

    public function testRenderWithNullTitleShowsNoTitle(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text)->setSize(20, 8);
        $rendered = $window->render();

        // Should render without title text
        $this->assertStringContainsString('Content', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size constraints
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithTinyWidthReturnsContent(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text, 'Title')->setSize(3, 8);
        $rendered = $window->render();

        // Too narrow - should return raw content
        $this->assertStringContainsString('Content', $rendered);
    }

    public function testRenderWithTinyHeightReturnsContent(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text, 'Title')->setSize(20, 2);
        $rendered = $window->render();

        // Too short - should return raw content
        $this->assertStringContainsString('Content', $rendered);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Window::new(Text::new('Content'));
        $resized = $original->setSize(20, 8);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeWithSetSize(): void
    {
        $window = Window::new(Text::new('Content'))->setSize(20, 8);
        [$w, $h] = $window->getInnerSize();

        // Inner size should be smaller than total due to borders and title
        $this->assertLessThan(20, $w);
        $this->assertLessThan(8, $h);
    }

    public function testGetInnerSizeWithZeroSize(): void
    {
        $window = Window::new(Text::new('Content'));
        [$w, $h] = $window->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Control buttons
    // ═══════════════════════════════════════════════════════════════

    public function testControlsNoneShowsNoButtons(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text, 'Title')
            ->withControls(Window::ControlsNone)
            ->setSize(25, 8);
        $rendered = $window->render();

        $this->assertStringNotContainsString('[X]', $rendered);
        $this->assertStringNotContainsString('[-]', $rendered);
    }

    public function testControlsCloseShowsCloseButton(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text, 'Title')
            ->withControls(Window::ControlsClose)
            ->setSize(25, 8);
        $rendered = $window->render();

        $this->assertStringContainsString('[X]', $rendered);
    }

    public function testControlsAllShowsAllButtons(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text, 'Title')
            ->withControls(Window::ControlsAll)
            ->setSize(30, 8);
        $rendered = $window->render();

        $this->assertStringContainsString('[X]', $rendered);
        $this->assertStringContainsString('[-]', $rendered);
        $this->assertStringContainsString('[□]', $rendered);
    }

    public function testControlsConstantValues(): void
    {
        $this->assertSame('none', Window::ControlsNone);
        $this->assertSame('close', Window::ControlsClose);
        $this->assertSame('all', Window::ControlsAll);
    }

    // ═══════════════════════════════════════════════════════════════
    // Shadow effect
    // ═══════════════════════════════════════════════════════════════

    public function testShadowDisabledByDefault(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text, 'Title')->setSize(20, 8);
        $rendered = $window->render();

        // Should not have shadow characters at line ends
        $lines = explode("\n", $rendered);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('░', $line);
        }
    }

    public function testShadowEnabledAddsShadowCharacters(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text, 'Title')
            ->withShadow(true)
            ->setSize(20, 8);
        $rendered = $window->render();

        // Should have shadow characters
        $this->assertStringContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Wither chaining
    // ═══════════════════════════════════════════════════════════════

    public function testChainedWithers(): void
    {
        $window = Window::new(Text::new('Content'))
            ->withTitle('New Title')
            ->withControls(Window::ControlsClose)
            ->withShadow(true)
            ->setSize(25, 8);

        $rendered = $window->render();
        $this->assertStringContainsString('New Title', $rendered);
        $this->assertStringContainsString('[X]', $rendered);
        $this->assertStringContainsString('░', $rendered);
    }

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = Window::new(Text::new('Content'), 'Old')->setSize(20, 8);
        $modified = $original->withTitle('New')->setSize(20, 8);

        $this->assertNotSame($original, $modified);
        $this->assertStringContainsString('New', $modified->render());
        $this->assertStringNotContainsString('Old', $modified->render());
    }

    public function testWithPaddingReturnsNewInstance(): void
    {
        $original = Window::new(Text::new('Content'))->setSize(20, 8);
        $modified = $original->withPadding(2);

        $this->assertNotSame($original, $modified);
    }

    public function testWithVerticalAlignReturnsNewInstance(): void
    {
        $original = Window::new(Text::new('Content'))->setSize(20, 8);
        $modified = $original->withVerticalAlign(VAlign::Bottom);

        $this->assertNotSame($original, $modified);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer content propagation
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizePropagatesToSizerContent(): void
    {
        // Text is a Sizer - it should receive the setSize call
        $text = Text::new('This is a longer piece of text that needs wrapping');
        $window = Window::new($text, 'Title')->setSize(15, 10);
        $rendered = $window->render();

        // Should contain content, possibly wrapped
        $this->assertStringContainsString('This is', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyContentRenders(): void
    {
        $text = Text::new('');
        $window = Window::new($text, 'Title')->setSize(20, 8);
        $rendered = $window->render();

        // Should still produce window frame
        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeTitle(): void
    {
        $text = Text::new('Content');
        $window = Window::new($text, '日本語タイトル')->setSize(25, 8);
        $rendered = $window->render();

        $this->assertStringContainsString('日本語タイトル', $rendered);
    }

    public function testLongTitleTruncated(): void
    {
        $longTitle = 'This is a very long window title that should be truncated';
        $text = Text::new('Content');
        $window = Window::new($text, $longTitle)->setSize(20, 8);
        $rendered = $window->render();

        // Title should be truncated - we can see the start of it
        $this->assertStringContainsString('This is', $rendered);
        // Title should NOT contain the full unwrapped title
        $this->assertStringNotContainsString('that should be truncated', $rendered);
    }

    public function testWindowWithNonSizerContent(): void
    {
        // Using a non-Sizer Item directly
        $nonSizer = new class implements Item {
            public function render(): string { return 'Plain content'; }
        };
        $window = Window::new($nonSizer, 'Title')->setSize(25, 8);
        $rendered = $window->render();

        $this->assertStringContainsString('Plain content', $rendered);
    }
}
