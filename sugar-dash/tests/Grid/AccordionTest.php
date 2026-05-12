<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Accordion;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class AccordionTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testAccordionImplementsSizer(): void
    {
        $accordion = Accordion::new([]);
        $this->assertInstanceOf(Sizer::class, $accordion);
    }

    public function testAccordionImplementsItem(): void
    {
        $accordion = Accordion::new([]);
        $this->assertInstanceOf(Item::class, $accordion);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Section 1', 'content' => 'Content 1'],
        ]);
        $rendered = $accordion->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTitle(): void
    {
        $accordion = Accordion::new([
            ['title' => 'My Section', 'content' => 'Content'],
        ]);
        $rendered = $accordion->render();

        $this->assertStringContainsString('My Section', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Section', 'content' => 'My Content'],
        ]);
        $rendered = $accordion->render();

        $this->assertStringContainsString('My Content', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Collapsed/expanded states
    // ═══════════════════════════════════════════════════════════════

    public function testCollapsedSectionShowsExpandedIcon(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Closed', 'content' => 'Hidden', 'isOpen' => false],
        ]);
        $rendered = $accordion->render();

        // Collapsed shows ▶ by default
        $this->assertStringContainsString('▶', $rendered);
    }

    public function testExpandedSectionShowsCollapsedIcon(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Open', 'content' => 'Visible', 'isOpen' => true],
        ]);
        $rendered = $accordion->render();

        // Expanded shows ▼ by default
        $this->assertStringContainsString('▼', $rendered);
    }

    public function testClosedSectionHidesContent(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Closed', 'content' => 'Secret content', 'isOpen' => false],
        ]);
        $rendered = $accordion->render();

        // Content should not be visible
        $this->assertStringNotContainsString('Secret content', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multiple sections
    // ═══════════════════════════════════════════════════════════════

    public function testMultipleSections(): void
    {
        $accordion = Accordion::new([
            ['title' => 'One', 'content' => 'Content 1'],
            ['title' => 'Two', 'content' => 'Content 2'],
            ['title' => 'Three', 'content' => 'Content 3'],
        ]);
        $rendered = $accordion->render();

        $this->assertStringContainsString('One', $rendered);
        $this->assertStringContainsString('Two', $rendered);
        $this->assertStringContainsString('Three', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testFromPairsFactory(): void
    {
        $accordion = Accordion::fromPairs([
            ['title' => 'First', 'content' => 'Content A'],
            ['title' => 'Second', 'content' => 'Content B'],
        ]);
        $rendered = $accordion->render();

        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Second', $rendered);
    }

    public function testFromPairsFirstSectionOpen(): void
    {
        $accordion = Accordion::fromPairs([
            ['title' => 'First', 'content' => 'Open'],
            ['title' => 'Second', 'content' => 'Closed'],
        ]);
        $rendered = $accordion->render();

        // First should be open (▼), second should be closed (▶)
        $this->assertStringContainsString('Open', $rendered);
        $this->assertStringNotContainsString('Closed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testHeaderColorAddsAnsiCodes(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Test', 'content' => 'Content'],
        ])->withHeaderColor(Color::ansi(13));
        $rendered = $accordion->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Test', 'content' => 'Content'],
        ])->withHeaderColor(Color::ansi(9));
        $rendered = $accordion->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Icon handling
    // ═══════════════════════════════════════════════════════════════

    public function testCustomIcons(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Test', 'content' => 'Content', 'isOpen' => true],
        ])->withIcons('[+]', '[-]');
        $rendered = $accordion->render();

        $this->assertStringContainsString('[+]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border handling
    // ═══════════════════════════════════════════════════════════════

    public function testShowBorder(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Test', 'content' => 'Content'],
        ])->withShowBorder(true);
        $rendered = $accordion->render();

        // Should have border characters
        $this->assertMatchesRegularExpression('/[─│┌┐└┘]/', $rendered);
    }

    public function testHideBorder(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Test', 'content' => 'Content', 'isOpen' => true],
        ])->withShowBorder(false);
        $rendered = $accordion->render();

        // Should not have border characters
        $this->assertDoesNotMatchRegularExpression('/[┌┐└┘]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Accordion::new([['title' => 'T', 'content' => 'C']]);
        $resized = $original->setSize(50, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithSectionsReturnsNewInstance(): void
    {
        $original = Accordion::new([['title' => 'Old', 'content' => 'X']]);
        $updated = $original->withSections([['title' => 'New', 'content' => 'Y', 'isOpen' => true]]);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('New', $updated->render());
    }

    public function testWithOpenSection(): void
    {
        $accordion = Accordion::new([
            ['title' => 'One', 'content' => 'A'],
            ['title' => 'Two', 'content' => 'B'],
        ])->withOpenSection(1);
        $rendered = $accordion->render();

        // Second section should be open
        $this->assertStringContainsString('B', $rendered);
        $this->assertStringNotContainsString('A', $rendered);
    }

    public function testOriginalUnchangedAfterWithSections(): void
    {
        $original = Accordion::new([['title' => 'Original', 'content' => 'X']]);
        $original->withSections([['title' => 'Changed', 'content' => 'Y', 'isOpen' => true]]);
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Test', 'content' => 'Content'],
        ]);
        [$w, $h] = $accordion->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithOpenSectionIsHigher(): void
    {
        $accordionClosed = Accordion::new([
            ['title' => 'Test', 'content' => 'Content', 'isOpen' => false],
        ]);
        $accordionOpen = Accordion::new([
            ['title' => 'Test', 'content' => 'Content', 'isOpen' => true],
        ]);

        [, $hClosed] = $accordionClosed->getInnerSize();
        [, $hOpen] = $accordionOpen->getInnerSize();

        $this->assertGreaterThan($hClosed, $hOpen);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptySections(): void
    {
        $accordion = Accordion::new([]);
        $rendered = $accordion->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongContentWraps(): void
    {
        $accordion = Accordion::new([
            ['title' => 'Test', 'content' => 'This is very long content that should wrap within the accordion section to test word wrapping functionality.'],
        ]);
        $accordion = $accordion->setSize(30, 30);
        $rendered = $accordion->render();

        // Should have multiple lines due to wrapping
        $this->assertGreaterThan(5, substr_count($rendered, "\n"));
    }

    public function testUnicodeContent(): void
    {
        $accordion = Accordion::new([
            ['title' => 'テスト', 'content' => 'コンテンツ'],
        ]);
        $rendered = $accordion->render();

        $this->assertStringContainsString('テスト', $rendered);
    }
}
