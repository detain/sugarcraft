<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\TextInput\Styles;
use SugarCraft\Sprinkles\Style;

/**
 * Tests for {@see Styles} — per-element styles for {@see TextInput} rendering.
 */
final class StylesTest extends TestCase
{
    // =========================================================================
    // Default constructor — all slots default to no-op Style
    // =========================================================================

    public function testAllSlotsDefaultToNoopStyle(): void
    {
        $s = new Styles();

        $this->assertInstanceOf(Style::class, $s->prompt);
        $this->assertInstanceOf(Style::class, $s->placeholder);
        $this->assertInstanceOf(Style::class, $s->text);
        $this->assertInstanceOf(Style::class, $s->cursor);

        // Default style renders text verbatim
        $this->assertSame('Hello', $s->prompt->render('Hello'));
        $this->assertSame('World', $s->placeholder->render('World'));
    }

    public function testDefaultStylesAreSameInstanceDueToStyleNewSingletons(): void
    {
        $s = new Styles();
        // Style::new() returns a singleton (no-op) instance in this codebase.
        // All four slots share that same singleton when no custom style is passed.
        $this->assertSame($s->prompt, $s->text);
        $this->assertSame($s->text, $s->cursor);
        $this->assertSame($s->cursor, $s->placeholder);
    }

    // =========================================================================
    // Constructor accepts nullable Style args; null becomes no-op
    // =========================================================================

    public function testNullPromptDefaultsToNoop(): void
    {
        $s = new Styles(prompt: null);
        $this->assertSame('test', $s->prompt->render('test'));
    }

    public function testNullPlaceholderDefaultsToNoop(): void
    {
        $s = new Styles(placeholder: null);
        $this->assertSame('test', $s->placeholder->render('test'));
    }

    public function testNullTextDefaultsToNoop(): void
    {
        $s = new Styles(text: null);
        $this->assertSame('test', $s->text->render('test'));
    }

    public function testNullCursorDefaultsToNoop(): void
    {
        $s = new Styles(cursor: null);
        $this->assertSame('test', $s->cursor->render('test'));
    }

    // =========================================================================
    // Constructor accepts actual Style instances
    // =========================================================================

    public function testConstructorAcceptsStyleInstances(): void
    {
        $boldPrompt = Style::new()->bold();
        $coloredPlaceholder = Style::new()->foreground(
            \SugarCraft\Core\Util\Color::ansi(13)
        );

        $s = new Styles(
            prompt: $boldPrompt,
            placeholder: $coloredPlaceholder,
        );

        $this->assertSame($boldPrompt, $s->prompt);
        $this->assertSame($coloredPlaceholder, $s->placeholder);
        // Unspecified slots still default to noop
        $this->assertInstanceOf(Style::class, $s->text);
        $this->assertInstanceOf(Style::class, $s->cursor);
    }

    public function testAllFourSlotsCanBeCustomStyles(): void
    {
        $prompt = Style::new()->foreground(
            \SugarCraft\Core\Util\Color::hex('#ff5fd2')
        );
        $placeholder = Style::new()->faint();
        $text = Style::new()->bold();
        $cursor = Style::new()->reverse();

        $s = new Styles(
            prompt: $prompt,
            placeholder: $placeholder,
            text: $text,
            cursor: $cursor,
        );

        $this->assertSame($prompt, $s->prompt);
        $this->assertSame($placeholder, $s->placeholder);
        $this->assertSame($text, $s->text);
        $this->assertSame($cursor, $s->cursor);
    }

    // =========================================================================
    // Styles compose into styled output
    // =========================================================================

    public function testStyledPromptRendersAnsiCodes(): void
    {
        $s = new Styles(
            prompt: Style::new()->bold()->foreground(
                \SugarCraft\Core\Util\Color::hex('#5fafff')
            ),
        );

        $rendered = $s->prompt->render('$');
        $this->assertStringStartsWith("\x1b[", $rendered);
        $this->assertStringContainsString('$', $rendered);
    }

    public function testStyledCursorWithReverseVideo(): void
    {
        $s = new Styles(
            cursor: Style::new()->reverse(),
        );

        $rendered = $s->cursor->render('X');
        $this->assertStringStartsWith("\x1b[7m", $rendered);
    }

    public function testStyledPlaceholderWithFaint(): void
    {
        $s = new Styles(
            placeholder: Style::new()->faint(),
        );

        $rendered = $s->placeholder->render('enter text...');
        $this->assertStringStartsWith("\x1b[2m", $rendered);
    }

    public function testStyledTextWithBold(): void
    {
        $s = new Styles(
            text: Style::new()->bold(),
        );

        $rendered = $s->text->render('input text');
        $this->assertStringStartsWith("\x1b[1m", $rendered);
    }
}
