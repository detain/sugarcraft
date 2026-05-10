<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests\Border;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Border\BorderTitle;
use SugarCraft\Sprinkles\Border\TitleAnchor;

/**
 * Comprehensive unit tests for Border functionality.
 *
 * Tests cover:
 * - Different border styles (single, double, rounded, thick, block, ascii, hidden, markdown)
 * - Border positioning (top, right, bottom, left, corners, middle variants)
 * - Border with title (withTitle, withTitles, getTitles)
 * - Custom border characters via constructor
 */
final class BorderTest extends TestCase
{
    // =========================================================================
    // Border Style Factory Methods
    // =========================================================================

    public function testNormalBorderHasCorrectRunes(): void
    {
        $b = Border::normal();

        $this->assertSame('─', $b->top);
        $this->assertSame('─', $b->bottom);
        $this->assertSame('│', $b->left);
        $this->assertSame('│', $b->right);
        $this->assertSame('┌', $b->topLeft);
        $this->assertSame('┐', $b->topRight);
        $this->assertSame('└', $b->bottomLeft);
        $this->assertSame('┘', $b->bottomRight);
        $this->assertSame('├', $b->middleLeft);
        $this->assertSame('┤', $b->middleRight);
        $this->assertSame('┼', $b->middle);
        $this->assertSame('┬', $b->middleTop);
        $this->assertSame('┴', $b->middleBottom);
    }

    public function testRoundedBorderHasArcCorners(): void
    {
        $b = Border::rounded();

        $this->assertSame('╭', $b->topLeft);
        $this->assertSame('╮', $b->topRight);
        $this->assertSame('╰', $b->bottomLeft);
        $this->assertSame('╯', $b->bottomRight);
        // Horizontal and vertical strokes should still be standard
        $this->assertSame('─', $b->top);
        $this->assertSame('│', $b->left);
    }

    public function testThickBorderHasHeavyRunes(): void
    {
        $b = Border::thick();

        $this->assertSame('━', $b->top);
        $this->assertSame('━', $b->bottom);
        $this->assertSame('┃', $b->left);
        $this->assertSame('┃', $b->right);
        $this->assertSame('┏', $b->topLeft);
        $this->assertSame('┓', $b->topRight);
        $this->assertSame('┗', $b->bottomLeft);
        $this->assertSame('┛', $b->bottomRight);
        $this->assertSame('┣', $b->middleLeft);
        $this->assertSame('┫', $b->middleRight);
        $this->assertSame('╋', $b->middle);
        $this->assertSame('┳', $b->middleTop);
        $this->assertSame('┻', $b->middleBottom);
    }

    public function testDoubleBorderHasDoubleLineRunes(): void
    {
        $b = Border::double();

        $this->assertSame('═', $b->top);
        $this->assertSame('═', $b->bottom);
        $this->assertSame('║', $b->left);
        $this->assertSame('║', $b->right);
        $this->assertSame('╔', $b->topLeft);
        $this->assertSame('╗', $b->topRight);
        $this->assertSame('╚', $b->bottomLeft);
        $this->assertSame('╝', $b->bottomRight);
        $this->assertSame('╠', $b->middleLeft);
        $this->assertSame('╣', $b->middleRight);
        $this->assertSame('╬', $b->middle);
        $this->assertSame('╦', $b->middleTop);
        $this->assertSame('╩', $b->middleBottom);
    }

    public function testBlockBorderIsSolid(): void
    {
        $b = Border::block();

        // All characters should be solid block
        $this->assertSame('█', $b->top);
        $this->assertSame('█', $b->bottom);
        $this->assertSame('█', $b->left);
        $this->assertSame('█', $b->right);
        $this->assertSame('█', $b->topLeft);
        $this->assertSame('█', $b->topRight);
        $this->assertSame('█', $b->bottomLeft);
        $this->assertSame('█', $b->bottomRight);
        // Block border uses default middle characters (single space)
        $this->assertSame(' ', $b->middleLeft);
        $this->assertSame(' ', $b->middleRight);
        $this->assertSame(' ', $b->middle);
    }

    public function testAsciiBorderUsesPlainCharacters(): void
    {
        $b = Border::ascii();

        $this->assertSame('-', $b->top);
        $this->assertSame('-', $b->bottom);
        $this->assertSame('|', $b->left);
        $this->assertSame('|', $b->right);
        $this->assertSame('+', $b->topLeft);
        $this->assertSame('+', $b->topRight);
        $this->assertSame('+', $b->bottomLeft);
        $this->assertSame('+', $b->bottomRight);
        $this->assertSame('+', $b->middleLeft);
        $this->assertSame('+', $b->middleRight);
        $this->assertSame('+', $b->middle);
        $this->assertSame('+', $b->middleTop);
        $this->assertSame('+', $b->middleBottom);
    }

    public function testHiddenBorderIsAllSpaces(): void
    {
        $b = Border::hidden();

        $this->assertSame(' ', $b->top);
        $this->assertSame(' ', $b->bottom);
        $this->assertSame(' ', $b->left);
        $this->assertSame(' ', $b->right);
        $this->assertSame(' ', $b->topLeft);
        $this->assertSame(' ', $b->topRight);
        $this->assertSame(' ', $b->bottomLeft);
        $this->assertSame(' ', $b->bottomRight);
        $this->assertSame(' ', $b->middleLeft);
        $this->assertSame(' ', $b->middleRight);
        $this->assertSame(' ', $b->middle);
        $this->assertSame(' ', $b->middleTop);
        $this->assertSame(' ', $b->middleBottom);
    }

    public function testMarkdownBorderHasPipesAndDashes(): void
    {
        $b = Border::markdownBorder();

        // Horizontal edges use dashes
        $this->assertSame('-', $b->top);
        $this->assertSame('-', $b->bottom);
        // Vertical edges use pipes
        $this->assertSame('|', $b->left);
        $this->assertSame('|', $b->right);
        // All corners use pipe (GFM tables don't have rounded corners)
        $this->assertSame('|', $b->topLeft);
        $this->assertSame('|', $b->topRight);
        $this->assertSame('|', $b->bottomLeft);
        $this->assertSame('|', $b->bottomRight);
        // Middle variants use pipe for table grid compatibility
        $this->assertSame('|', $b->middleLeft);
        $this->assertSame('|', $b->middleRight);
        $this->assertSame('|', $b->middle);
        $this->assertSame('|', $b->middleTop);
        $this->assertSame('|', $b->middleBottom);
    }

    // =========================================================================
    // Border Positioning
    // =========================================================================

    /**
     * @dataProvider borderStyleProvider
     */
    public function testBorderStylePositions(Border $border): void
    {
        // Verify all 8 primary position properties exist and are strings
        $this->assertIsString($border->top);
        $this->assertIsString($border->bottom);
        $this->assertIsString($border->left);
        $this->assertIsString($border->right);
        $this->assertIsString($border->topLeft);
        $this->assertIsString($border->topRight);
        $this->assertIsString($border->bottomLeft);
        $this->assertIsString($border->bottomRight);

        // Verify middle variants
        $this->assertIsString($border->middleLeft);
        $this->assertIsString($border->middleRight);
        $this->assertIsString($border->middle);
        $this->assertIsString($border->middleTop);
        $this->assertIsString($border->middleBottom);
    }

    public static function borderStyleProvider(): array
    {
        return [
            'normal'      => [Border::normal()],
            'rounded'     => [Border::rounded()],
            'thick'       => [Border::thick()],
            'double'      => [Border::double()],
            'block'       => [Border::block()],
            'ascii'       => [Border::ascii()],
            'hidden'      => [Border::hidden()],
            'markdown'    => [Border::markdownBorder()],
        ];
    }

    // =========================================================================
    // Custom Border Characters
    // =========================================================================

    public function testCustomBorderWithAllCharacters(): void
    {
        $b = new Border(
            top: 'T',
            bottom: 'B',
            left: 'L',
            right: 'R',
            topLeft: 'TL',
            topRight: 'TR',
            bottomLeft: 'BL',
            bottomRight: 'BR',
            middleLeft: 'ML',
            middleRight: 'MR',
            middle: 'M',
            middleTop: 'MT',
            middleBottom: 'MB',
        );

        $this->assertSame('T', $b->top);
        $this->assertSame('B', $b->bottom);
        $this->assertSame('L', $b->left);
        $this->assertSame('R', $b->right);
        $this->assertSame('TL', $b->topLeft);
        $this->assertSame('TR', $b->topRight);
        $this->assertSame('BL', $b->bottomLeft);
        $this->assertSame('BR', $b->bottomRight);
        $this->assertSame('ML', $b->middleLeft);
        $this->assertSame('MR', $b->middleRight);
        $this->assertSame('M', $b->middle);
        $this->assertSame('MT', $b->middleTop);
        $this->assertSame('MB', $b->middleBottom);
    }

    public function testCustomBorderWithMinimalArguments(): void
    {
        $b = new Border('T', 'B', 'L', 'R', 'TL', 'TR', 'BL', 'BR');

        $this->assertSame('T', $b->top);
        $this->assertSame('B', $b->bottom);
        $this->assertSame('L', $b->left);
        $this->assertSame('R', $b->right);
        $this->assertSame('TL', $b->topLeft);
        $this->assertSame('TR', $b->topRight);
        $this->assertSame('BL', $b->bottomLeft);
        $this->assertSame('BR', $b->bottomRight);
        // Middle variants should default to space
        $this->assertSame(' ', $b->middleLeft);
        $this->assertSame(' ', $b->middleRight);
        $this->assertSame(' ', $b->middle);
        $this->assertSame(' ', $b->middleTop);
        $this->assertSame(' ', $b->middleBottom);
    }

    public function testCustomBorderWithPartialMiddleVariants(): void
    {
        $b = new Border(
            'T', 'B', 'L', 'R', 'TL', 'TR', 'BL', 'BR',
            middleLeft: 'ML',
            middleRight: 'MR',
        );

        $this->assertSame('ML', $b->middleLeft);
        $this->assertSame('MR', $b->middleRight);
        $this->assertSame(' ', $b->middle);  // Default
        $this->assertSame(' ', $b->middleTop);  // Default
        $this->assertSame(' ', $b->middleBottom);  // Default
    }

    // =========================================================================
    // Border with Title
    // =========================================================================

    public function testWithTitleAddsTitleToDefaultAnchor(): void
    {
        $b = Border::normal()->withTitle('Test Title');

        $titles = $b->getTitles();
        $this->assertArrayHasKey('TopLeft', $titles);
        $this->assertCount(1, $titles['TopLeft']);
        $this->assertSame('Test Title', $titles['TopLeft'][0]->text);
        $this->assertSame(TitleAnchor::TopLeft, $titles['TopLeft'][0]->anchor);
    }

    public function testWithTitleUsesSpecifiedAnchor(): void
    {
        $b = Border::normal()->withTitle('Center Title', TitleAnchor::TopCenter);

        $titles = $b->getTitles();
        $this->assertArrayHasKey('TopCenter', $titles);
        $this->assertCount(1, $titles['TopCenter']);
        $this->assertSame('Center Title', $titles['TopCenter'][0]->text);
        $this->assertSame(TitleAnchor::TopCenter, $titles['TopCenter'][0]->anchor);
    }

    /**
     * @dataProvider titleAnchorProvider
     */
    public function testWithTitleAllAnchors(TitleAnchor $anchor): void
    {
        $titleText = 'Positioned Title';
        $b = Border::normal()->withTitle($titleText, $anchor);

        $titles = $b->getTitles();
        $this->assertArrayHasKey($anchor->name, $titles);
        $this->assertCount(1, $titles[$anchor->name]);
        $this->assertSame($titleText, $titles[$anchor->name][0]->text);
        $this->assertSame($anchor, $titles[$anchor->name][0]->anchor);
    }

    public static function titleAnchorProvider(): array
    {
        return [
            'TopLeft'      => [TitleAnchor::TopLeft],
            'TopCenter'    => [TitleAnchor::TopCenter],
            'TopRight'     => [TitleAnchor::TopRight],
            'BottomLeft'   => [TitleAnchor::BottomLeft],
            'BottomCenter' => [TitleAnchor::BottomCenter],
            'BottomRight'  => [TitleAnchor::BottomRight],
        ];
    }

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = Border::normal();
        $withTitle = $original->withTitle('New Title');

        // Original should be unchanged
        $this->assertEmpty($original->getTitles());

        // New instance should have the title
        $this->assertNotSame($original, $withTitle);
        $this->assertArrayHasKey('TopLeft', $withTitle->getTitles());
    }

    public function testWithTitleMultipleTitlesSameAnchor(): void
    {
        $b = Border::normal()
            ->withTitle('First', TitleAnchor::TopLeft)
            ->withTitle('Second', TitleAnchor::TopLeft);

        $titles = $b->getTitles();
        $this->assertCount(2, $titles['TopLeft']);
        $this->assertSame('First', $titles['TopLeft'][0]->text);
        $this->assertSame('Second', $titles['TopLeft'][1]->text);
    }

    public function testWithTitleMultipleTitlesDifferentAnchors(): void
    {
        $b = Border::normal()
            ->withTitle('Top', TitleAnchor::TopLeft)
            ->withTitle('Bottom', TitleAnchor::BottomRight);

        $titles = $b->getTitles();
        $this->assertArrayHasKey('TopLeft', $titles);
        $this->assertArrayHasKey('BottomRight', $titles);
        $this->assertSame('Top', $titles['TopLeft'][0]->text);
        $this->assertSame('Bottom', $titles['BottomRight'][0]->text);
    }

    // =========================================================================
    // Border withTitles Method
    // =========================================================================

    public function testWithTitlesWithArrayOfTexts(): void
    {
        $b = Border::normal()->withTitles([
            'TopLeft' => 'Title 1',
            'TopRight' => 'Title 2',
        ]);

        $titles = $b->getTitles();
        $this->assertSame('Title 1', $titles['TopLeft'][0]->text);
        $this->assertSame('Title 2', $titles['TopRight'][0]->text);
    }

    public function testWithTitlesWithMultipleTextsPerAnchor(): void
    {
        $b = Border::normal()->withTitles([
            'TopLeft' => ['First', 'Second'],
        ]);

        $titles = $b->getTitles();
        $this->assertCount(2, $titles['TopLeft']);
        $this->assertSame('First', $titles['TopLeft'][0]->text);
        $this->assertSame('Second', $titles['TopLeft'][1]->text);
    }

    public function testWithTitlesReplacesPreviousTitles(): void
    {
        $b = Border::normal()
            ->withTitle('Old Title', TitleAnchor::TopLeft)
            ->withTitles(['TopLeft' => 'New Title']);

        $titles = $b->getTitles();
        $this->assertCount(1, $titles['TopLeft']);
        $this->assertSame('New Title', $titles['TopLeft'][0]->text);
    }

    public function testWithTitlesWithStringKeys(): void
    {
        $b = Border::normal()->withTitles([
            'TopLeft' => 'From String Key',
        ]);

        $titles = $b->getTitles();
        $this->assertSame('From String Key', $titles['TopLeft'][0]->text);
    }

    public function testWithTitlesInvalidAnchorThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown title anchor: InvalidAnchor');

        Border::normal()->withTitles([
            'InvalidAnchor' => 'Test',
        ]);
    }

    // =========================================================================
    // Border Immutability
    // =========================================================================

    public function testBorderStylesReturnNewInstances(): void
    {
        $normal = Border::normal();

        $rounded = Border::rounded();
        $thick = Border::thick();
        $double = Border::double();
        $block = Border::block();
        $ascii = Border::ascii();
        $hidden = Border::hidden();
        $markdown = Border::markdownBorder();

        // Original should be unchanged
        $this->assertSame('┌', $normal->topLeft);

        // Each should be distinct instances
        $this->assertNotSame($normal, $rounded);
        $this->assertNotSame($normal, $thick);
        $this->assertNotSame($normal, $double);
        $this->assertNotSame($normal, $block);
        $this->assertNotSame($normal, $ascii);
        $this->assertNotSame($normal, $hidden);
        $this->assertNotSame($normal, $markdown);
    }

    public function testWithTitleReturnsNewBorderInstance(): void
    {
        $original = Border::normal();
        $modified = $original->withTitle('Test');

        $this->assertNotSame($original, $modified);
        $this->assertEmpty($original->getTitles());
        $this->assertNotEmpty($modified->getTitles());
    }

    public function testWithTitlesReturnsNewBorderInstance(): void
    {
        $original = Border::normal();
        $modified = $original->withTitles(['TopLeft' => 'Test']);

        $this->assertNotSame($original, $modified);
        $this->assertEmpty($original->getTitles());
        $this->assertNotEmpty($modified->getTitles());
    }

    // =========================================================================
    // Border Title Structure
    // =========================================================================

    public function testBorderTitleHasCorrectProperties(): void
    {
        $title = new BorderTitle('Test Text', TitleAnchor::TopCenter, '_');

        $this->assertSame('Test Text', $title->text);
        $this->assertSame(TitleAnchor::TopCenter, $title->anchor);
        $this->assertSame('_', $title->separator);
    }

    public function testBorderTitleDefaultSeparator(): void
    {
        $title = new BorderTitle('Test', TitleAnchor::TopLeft);

        $this->assertSame(' ', $title->separator);
    }

    public function testGetTitlesReturnsCorrectStructure(): void
    {
        $b = Border::normal()
            ->withTitle('Title 1', TitleAnchor::TopLeft)
            ->withTitle('Title 2', TitleAnchor::TopRight);

        $titles = $b->getTitles();

        $this->assertIsArray($titles);
        $this->assertCount(2, $titles);

        $this->assertArrayHasKey('TopLeft', $titles);
        $this->assertArrayHasKey('TopRight', $titles);

        $this->assertContainsOnlyInstancesOf(BorderTitle::class, $titles['TopLeft']);
        $this->assertContainsOnlyInstancesOf(BorderTitle::class, $titles['TopRight']);
    }

    public function testEmptyTitlesReturnsEmptyArray(): void
    {
        $b = Border::normal();

        $this->assertSame([], $b->getTitles());
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testBorderStyleConsistency(): void
    {
        // All border styles should produce valid single-cell characters
        $styles = [
            Border::normal(),
            Border::rounded(),
            Border::thick(),
            Border::double(),
            Border::block(),
            Border::ascii(),
            Border::hidden(),
            Border::markdownBorder(),
        ];

        foreach ($styles as $style) {
            // Each character should be a single Unicode grapheme
            $this->assertSame(
                1,
                mb_strlen($style->top, 'UTF-8'),
                sprintf('Top character for %s should be single cell', $style::class)
            );
            $this->assertSame(
                1,
                mb_strlen($style->topLeft, 'UTF-8'),
                sprintf('TopLeft character for %s should be single cell', $style::class)
            );
        }
    }

    public function testAllBorderStylesAreDistinct(): void
    {
        $styles = [
            'normal'   => Border::normal(),
            'rounded'  => Border::rounded(),
            'thick'    => Border::thick(),
            'double'   => Border::double(),
            'block'    => Border::block(),
            'ascii'    => Border::ascii(),
            'hidden'   => Border::hidden(),
            'markdown' => Border::markdownBorder(),
        ];

        $seen = [];
        foreach ($styles as $name => $border) {
            $signature = $border->top . $border->topLeft . $border->bottomLeft;
            $this->assertNotContains(
                $signature,
                $seen,
                "Border style '$name' produces same signature as existing style"
            );
            $seen[] = $signature;
        }
    }
}
