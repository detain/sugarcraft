<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Editor;
use SugarCraft\Dash\Grid\CursorState;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class EditorTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testEditorImplementsSizer(): void
    {
        $editor = Editor::new();
        $this->assertInstanceOf(Sizer::class, $editor);
    }

    public function testEditorImplementsItem(): void
    {
        $editor = Editor::new();
        $this->assertInstanceOf(Item::class, $editor);
    }

    // ═══════════════════════════════════════════════════════════════
    // Creation
    // ═══════════════════════════════════════════════════════════════

    public function testEditorNewFactory(): void
    {
        $editor = Editor::new('Hello World');

        $this->assertSame('Hello World', $editor->getContent());
    }

    public function testEditorWithNullContent(): void
    {
        $editor = Editor::new(null);

        $this->assertSame('', $editor->getContent());
    }

    public function testEditorWithEmptyContent(): void
    {
        $editor = Editor::new('');

        $this->assertSame('', $editor->getContent());
        $this->assertSame(1, $editor->getLineCount());
    }

    public function testEditorMultiLineContent(): void
    {
        $editor = Editor::new("Line 1\nLine 2\nLine 3");

        $this->assertSame(3, $editor->getLineCount());
        $this->assertSame('Line 1', $editor->getLine(0));
        $this->assertSame('Line 2', $editor->getLine(1));
        $this->assertSame('Line 3', $editor->getLine(2));
    }

    public function testEditorGetContent(): void
    {
        $content = "Multi\nLine\nContent";
        $editor = Editor::new($content);

        $this->assertSame($content, $editor->getContent());
    }

    // ═══════════════════════════════════════════════════════════════
    // Line operations
    // ═══════════════════════════════════════════════════════════════

    public function testGetLine(): void
    {
        $editor = Editor::new("First\nSecond\nThird");

        $this->assertSame('First', $editor->getLine(0));
        $this->assertSame('Second', $editor->getLine(1));
        $this->assertSame('Third', $editor->getLine(2));
    }

    public function testGetLineOutOfBounds(): void
    {
        $editor = Editor::new('Single line');

        $this->assertSame('', $editor->getLine(-1));
        $this->assertSame('', $editor->getLine(100));
    }

    public function testGetLineCount(): void
    {
        $editor = Editor::new("Line 1\nLine 2\nLine 3");

        $this->assertSame(3, $editor->getLineCount());
    }

    public function testGetLineCountEmptyContent(): void
    {
        $editor = Editor::new('');

        $this->assertSame(1, $editor->getLineCount());
    }

    // ═══════════════════════════════════════════════════════════════
    // Cursor operations
    // ═══════════════════════════════════════════════════════════════

    public function testGetCursorPositionDefault(): void
    {
        $editor = Editor::new('Content');

        [$x, $y] = $editor->getCursorPosition();

        $this->assertSame(0, $x);
        $this->assertSame(0, $y);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsSizer(): void
    {
        $editor = Editor::new();
        $result = $editor->setSize(80, 24);

        $this->assertInstanceOf(Sizer::class, $result);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $editor = Editor::new();
        $resized = $editor->setSize(80, 24);

        $this->assertNotSame($editor, $resized);
    }

    public function testGetInnerSize(): void
    {
        $editor = Editor::new("Line 1\nLine 2");

        [$w, $h] = $editor->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThanOrEqual(3, $h); // 2 lines + border
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyEditor(): void
    {
        $editor = Editor::new()->setSize(40, 10);
        $rendered = $editor->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithContent(): void
    {
        $editor = Editor::new('Hello World')->setSize(40, 10);
        $rendered = $editor->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderWithLineNumbers(): void
    {
        $editor = Editor::new("Line 1\nLine 2")
            ->withLineNumbers(true)
            ->setSize(40, 10);
        $rendered = $editor->render();

        // Should contain line numbers
        $this->assertStringContainsString('1', $rendered);
        $this->assertStringContainsString('2', $rendered);
    }

    public function testRenderWithoutLineNumbers(): void
    {
        $editor = Editor::new("Line 1\nLine 2")
            ->withLineNumbers(false)
            ->setSize(40, 10);
        $rendered = $editor->render();

        // Should contain content but fewer numbers
        $this->assertStringContainsString('Line 1', $rendered);
    }

    public function testRenderReadOnly(): void
    {
        $editor = Editor::new('Secret content')
            ->withReadOnly(true)
            ->setSize(40, 10);
        $rendered = $editor->render();

        $this->assertStringContainsString('Secret content', $rendered);
    }

    public function testRenderWithBorder(): void
    {
        $editor = Editor::new('Content')
            ->setSize(40, 10);
        $rendered = $editor->render();

        // Should contain border characters
        $this->assertMatchesRegularExpression('/[┌┐└┘╔╗╚╝╭╮╰╯]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Word wrap
    // ═══════════════════════════════════════════════════════════════

    public function testWithWordWrap(): void
    {
        $longLine = str_repeat('word ', 30);
        $editor = Editor::new($longLine)
            ->withWordWrap(true)
            ->setSize(20, 20);
        $rendered = $editor->render();

        // Should handle without crashing
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithContentReturnsNewInstance(): void
    {
        $editor = Editor::new('Original');
        $updated = $editor->withContent('Modified');

        $this->assertNotSame($editor, $updated);
        $this->assertSame('Modified', $updated->getContent());
    }

    public function testWithLineNumbersReturnsNewInstance(): void
    {
        $editor = Editor::new('Content');
        $updated = $editor->withLineNumbers(false);

        $this->assertNotSame($editor, $updated);
    }

    public function testWithWordWrapReturnsNewInstance(): void
    {
        $editor = Editor::new('Content');
        $updated = $editor->withWordWrap(true);

        $this->assertNotSame($editor, $updated);
    }

    public function testWithReadOnlyReturnsNewInstance(): void
    {
        $editor = Editor::new('Content');
        $updated = $editor->withReadOnly(true);

        $this->assertNotSame($editor, $updated);
    }

    public function testWithShowCursorReturnsNewInstance(): void
    {
        $editor = Editor::new('Content');
        $updated = $editor->withShowCursor(false);

        $this->assertNotSame($editor, $updated);
    }

    public function testWithTextColorReturnsNewInstance(): void
    {
        $editor = Editor::new('Content');
        $updated = $editor->withTextColor(Color::hex('#FF0000'));

        $this->assertNotSame($editor, $updated);
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $editor = Editor::new('Content');
        $updated = $editor->withStyle('double');

        $this->assertNotSame($editor, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Editor::forFile factory
    // ═══════════════════════════════════════════════════════════════

    public function testEditorForFileWithValidPath(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'editor_test_');
        file_put_contents($tmpFile, "File content\nLine 2");

        $editor = Editor::forFile($tmpFile);

        $this->assertStringContainsString('File content', $editor->getContent());

        unlink($tmpFile);
    }

    public function testEditorForFileWithInvalidPath(): void
    {
        $editor = Editor::forFile('/nonexistent/path/file.txt');

        $this->assertSame('', $editor->getContent());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithVerySmallSize(): void
    {
        $editor = Editor::new('Content')->setSize(5, 3);
        $rendered = $editor->render();

        // Should handle small sizes gracefully
        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithZeroSize(): void
    {
        $editor = Editor::new('Content')->setSize(0, 10);
        $rendered = $editor->render();

        // Should return empty for invalid sizes
        $this->assertSame('', $rendered);
    }

    public function testRenderWithSpecialCharacters(): void
    {
        $editor = Editor::new('Special: <>&"\'αβγδ日本語')
            ->setSize(60, 10);
        $rendered = $editor->render();

        $this->assertStringContainsString('αβγδ', $rendered);
        $this->assertStringContainsString('日本語', $rendered);
    }

    public function testMultipleLinesRendering(): void
    {
        $editor = Editor::new("Line 1\nLine 2\nLine 3\nLine 4\nLine 5")
            ->setSize(40, 10);
        $rendered = $editor->render();

        // Content should be visible
        $this->assertStringContainsString('Line 1', $rendered);
        $this->assertStringContainsString('Line 5', $rendered);
    }

    public function testGetLineAfterContentModification(): void
    {
        $editor = Editor::new("Original\nContent");
        $modified = $editor->withContent("New\nContent\nHere");

        $this->assertSame('New', $modified->getLine(0));
        $this->assertSame('Content', $modified->getLine(1));
        $this->assertSame('Here', $modified->getLine(2));
    }
}
