<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\TextInput;

use SugarCraft\Bits\TextInput\EchoMode;
use SugarCraft\Bits\TextInput\Styles;
use SugarCraft\Bits\TextInput\TextInput;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

final class TextInputTest extends TestCase
{
    private function focused(?string $initial = null): TextInput
    {
        $t = TextInput::new();
        if ($initial !== null) {
            $t = $t->setValue($initial);
        }
        [$t, ] = $t->focus();
        return $t;
    }

    public function testInitialState(): void
    {
        $t = TextInput::new();
        $this->assertSame('', $t->value);
        $this->assertSame(0, $t->cursorPos);
        $this->assertFalse($t->focused);
    }

    public function testInsertChar(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'h'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertSame('hi', $t->value);
        $this->assertSame(2,    $t->cursorPos);
    }

    public function testIgnoresKeysWhenUnfocused(): void
    {
        $t = TextInput::new();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('', $t->value);
    }

    public function testBackspaceDeletesPrev(): void
    {
        $t = $this->focused('hello');
        [$t, ] = $t->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('hell', $t->value);
        $this->assertSame(4,      $t->cursorPos);
    }

    public function testBackspaceAtZeroNoOp(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('', $t->value);
        $this->assertSame(0,  $t->cursorPos);
    }

    public function testDeleteRemovesAtCursor(): void
    {
        $t = $this->focused('hello');
        [$t, ] = $t->update(new KeyMsg(KeyType::Home));
        [$t, ] = $t->update(new KeyMsg(KeyType::Delete));
        $this->assertSame('ello', $t->value);
        $this->assertSame(0,      $t->cursorPos);
    }

    public function testArrowsMoveCursor(): void
    {
        $t = $this->focused('hello');
        [$t, ] = $t->update(new KeyMsg(KeyType::Left));
        $this->assertSame(4, $t->cursorPos);
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        $this->assertSame(5, $t->cursorPos);
        [$t, ] = $t->update(new KeyMsg(KeyType::Home));
        $this->assertSame(0, $t->cursorPos);
        [$t, ] = $t->update(new KeyMsg(KeyType::End));
        $this->assertSame(5, $t->cursorPos);
    }

    public function testCtrlAAndCtrlE(): void
    {
        $t = $this->focused('hello');
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a', ctrl: true));
        $this->assertSame(0, $t->cursorPos);
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'e', ctrl: true));
        $this->assertSame(5, $t->cursorPos);
    }

    public function testCtrlUDeletesToStart(): void
    {
        $t = $this->focused('hello');
        // Cursor is at end.
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'u', ctrl: true));
        $this->assertSame('', $t->value);
        $this->assertSame(0,  $t->cursorPos);
    }

    public function testCtrlKDeletesToEnd(): void
    {
        $t = $this->focused('hello');
        [$t, ] = $t->update(new KeyMsg(KeyType::Home));
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'k', ctrl: true));
        $this->assertSame('h', $t->value);
        $this->assertSame(1,   $t->cursorPos);
    }

    public function testCharLimit(): void
    {
        $t = $this->focused()->withCharLimit(3);
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'c'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame('abc', $t->value);
    }

    public function testInsertInMiddle(): void
    {
        $t = $this->focused('helo');
        [$t, ] = $t->update(new KeyMsg(KeyType::Home));
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'l'));
        $this->assertSame('hello', $t->value);
        $this->assertSame(4, $t->cursorPos);
    }

    public function testMultibyteSafeBackspace(): void
    {
        $t = $this->focused('日本');
        [$t, ] = $t->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('日', $t->value);
        $this->assertSame(1,    $t->cursorPos);
    }

    public function testPlaceholderShownWhenEmptyAndUnfocused(): void
    {
        $t = TextInput::new()->withPlaceholder('type here…');
        // Placeholder is styled with faint (dim) by default
        $view = $t->view();
        $this->assertStringContainsString('type here…', $view);
        $this->assertStringContainsString("\x1b[2m", $view); // SGR 2 = faint
    }

    public function testPlaceholderHiddenWhenFocused(): void
    {
        [$t, ] = TextInput::new()->withPlaceholder('type here…')->focus();
        $this->assertStringNotContainsString('type here', $t->view());
    }

    public function testPasswordEcho(): void
    {
        [$t, ] = TextInput::new()->withEchoMode(EchoMode::Password)->focus();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('ab', $t->value);
        $this->assertStringContainsString('**', $t->view());
        $this->assertStringNotContainsString('ab', $t->view());
    }

    public function testNoneEchoHidesValue(): void
    {
        [$t, ] = TextInput::new()->withEchoMode(EchoMode::None)->focus();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame('x', $t->value);
        $this->assertStringNotContainsString('x', $t->view());
    }

    public function testReset(): void
    {
        $t = $this->focused('hello');
        $t = $t->reset();
        $this->assertSame('', $t->value);
        $this->assertSame(0,  $t->cursorPos);
    }

    public function testSetValueClampsToCharLimit(): void
    {
        $t = TextInput::new()->withCharLimit(3)->setValue('hello');
        $this->assertSame('hel', $t->value);
        $this->assertSame(3,     $t->cursorPos);
    }

    public function testSetSuggestionsAndMatch(): void
    {
        $t = TextInput::new()
            ->setSuggestions(['apple', 'apricot', 'banana', 'blueberry'])
            ->showSuggestions()
            ->setValue('ap');
        $matched = $t->matchedSuggestions();
        $this->assertSame(['apple', 'apricot'], $matched);
        $this->assertSame('apple', $t->currentSuggestion());
    }

    public function testNextAndPrevSuggestion(): void
    {
        $t = TextInput::new()
            ->setSuggestions(['apple', 'apricot'])
            ->setValue('ap');
        $next = $t->nextSuggestion();
        $this->assertSame('apricot', $next->currentSuggestion());
        // Cycle forward wraps back to the start.
        $this->assertSame('apple', $next->nextSuggestion()->currentSuggestion());
        $prev = $t->prevSuggestion();
        $this->assertSame('apricot', $prev->currentSuggestion());
    }

    public function testAcceptSuggestionReplacesValue(): void
    {
        $t = TextInput::new()
            ->setSuggestions(['hello', 'world'])
            ->setValue('he')
            ->acceptSuggestion();
        $this->assertSame('hello', $t->value);
    }

    public function testValidatorRunsOnSetValue(): void
    {
        $t = TextInput::new()
            ->withValidator(static fn(string $s): ?string
                => strlen($s) >= 3 ? null : 'too short')
            ->setValue('hi');
        $this->assertSame('too short', $t->err());
        $t = $t->setValue('hello');
        $this->assertNull($t->err());
    }

    public function testValidatorRunsImmediatelyWhenSet(): void
    {
        $t = TextInput::new()->setValue('hi');
        $this->assertNull($t->err());
        $t = $t->withValidator(static fn(string $s) => strlen($s) >= 3 ? null : 'short');
        $this->assertSame('short', $t->err());
    }

    public function testSetCursorClamps(): void
    {
        $t = TextInput::new()->setValue('hello')->setCursor(100);
        $this->assertSame(5, $t->cursorPos);
        $this->assertSame(0, $t->cursorStart()->cursorPos);
        $this->assertSame(5, $t->cursorEnd()->cursorPos);
    }

    public function testPasteStripsNewlines(): void
    {
        $t = TextInput::new()->setValue('a')->setCursor(1);
        $t = $t->paste("b\nc\rd");
        $this->assertSame('abcd', $t->value);
    }

    public function testWithStylesAppliesPromptStyle(): void
    {
        $t = TextInput::new()->withPrompt('> ')
            ->withStyles(new Styles(prompt: Style::new()->bold()));
        $view = $t->view();
        $this->assertStringContainsString("\x1b[1m> \x1b[0m", $view);
    }

    public function testWithStylesAppliesPlaceholder(): void
    {
        $t = TextInput::new()
            ->withPlaceholder('type here')
            ->withStyles(new Styles(placeholder: Style::new()->faint()));
        $view = $t->view();
        $this->assertStringContainsString("\x1b[2mtype here\x1b[0m", $view);
    }

    public function testWithStylesAppliesText(): void
    {
        $t = TextInput::new()->setValue('hello');
        $t = $t->withStyles(new Styles(text: Style::new()->italic()));
        $view = $t->view();
        $this->assertStringContainsString("\x1b[3mhello\x1b[0m", $view);
    }

    public function testWithStylesNullClears(): void
    {
        $t = TextInput::new()
            ->withStyles(new Styles(prompt: Style::new()->bold()))
            ->withStyles(null);
        $this->assertNull($t->getStyles());
    }

    public function testGetWidth(): void
    {
        $t = TextInput::new()->withWidth(40);
        $this->assertSame(40, $t->getWidth());
    }

    public function testPositionAccessor(): void
    {
        $t = TextInput::new()->setValue('hello')->setCursor(3);
        $this->assertSame(3, $t->position());
    }

    public function testIsFocusedAccessor(): void
    {
        $t = TextInput::new();
        $this->assertFalse($t->isFocused());
        [$t, ] = $t->focus();
        $this->assertTrue($t->isFocused());
    }

    public function testCursorAccessor(): void
    {
        $t = TextInput::new();
        $this->assertSame($t->cursor, $t->cursor());
    }

    public function testAvailableSuggestions(): void
    {
        $t = TextInput::new()->withSuggestions(['foo', 'bar', 'baz']);
        $this->assertSame(['foo', 'bar', 'baz'], $t->availableSuggestions());
    }

    public function testCurrentSuggestionIndex(): void
    {
        $t = TextInput::new();
        $this->assertSame(0, $t->currentSuggestionIndex());
    }

    // ---- placeholderStyle tests --------------------------------------------

    public function testPlaceholderStyleDefaultIsFaint(): void
    {
        $t = TextInput::new()->withPlaceholder('type here…');
        // Default placeholder style should render with faint (dim) ANSI code
        $view = $t->view();
        $this->assertStringContainsString("\x1b[2m", $view); // SGR 2 = faint
    }

    public function testWithPlaceholderStyleAppliesCustomStyle(): void
    {
        $t = TextInput::new()
            ->withPlaceholder('type here')
            ->withPlaceholderStyle(Style::new()->foreground(Color::hex('#ff0000')));
        $view = $t->view();
        // Should contain red color ANSI code (RGB) for true color
        $this->assertStringContainsString("\x1b[38;2;255;0;0m", $view);
    }

    public function testPlaceholderStyleShortAlias(): void
    {
        $t = TextInput::new()
            ->placeholderStyle(Style::new()->italic());
        $this->assertSame(Style::new()->italic()->value(), null); // Style was set
        $view = $t->withPlaceholder('test')->view();
        $this->assertStringContainsString("\x1b[3m", $view); // SGR 3 = italic
    }

    // ---- prefix/suffix tests ------------------------------------------------

    public function testPrefixRendersBeforePrompt(): void
    {
        $t = TextInput::new()->withPrefix('$ ');
        $view = $t->view();
        $this->assertStringStartsWith('$ ', $view);
    }

    public function testSuffixRendersAfterPlaceholder(): void
    {
        $t = TextInput::new()
            ->withPlaceholder('type here')
            ->withSuffix(' <');
        $view = $t->view();
        $this->assertStringEndsWith(' <', $view);
    }

    public function testPrefixAndSuffixWithValue(): void
    {
        [$t, ] = TextInput::new()
            ->withPrefix('> ')
            ->withSuffix(' |')
            ->focus();
        $t = $t->setValue('hello');
        $view = $t->view();
        // Prefix should be at start, suffix at end
        $this->assertStringStartsWith('> ', $view);
        $this->assertStringEndsWith(' |', $view);
        $this->assertStringContainsString('hello', $view);
    }

    public function testPrefixAndSuffixNotEditable(): void
    {
        [$t, ] = TextInput::new()
            ->withPrefix('$ ')
            ->withSuffix(' |')
            ->focus();
        // Type "hello" - prefix/suffix should remain unchanged
        foreach (str_split('hello') as $char) {
            [$t, ] = $t->update(new KeyMsg(KeyType::Char, $char));
        }
        $this->assertSame('hello', $t->value);
        $view = $t->view();
        // Check prefix/suffix appear once each
        $this->assertSame(1, substr_count($view, '$ '));
        $this->assertSame(1, substr_count($view, ' |'));
    }

    public function testPrefixShortAlias(): void
    {
        $t = TextInput::new()->prefix('$');
        $view = $t->view();
        $this->assertStringStartsWith('$', $view);
    }

    public function testSuffixShortAlias(): void
    {
        $t = TextInput::new()->suffix('%');
        $view = $t->view();
        $this->assertStringEndsWith('%', $view);
    }

    // ---- vim mode reset on blur --------------------------------------------

    public function testVimModeResetsToNormalOnBlur(): void
    {
        $t = TextInput::new()->withVimMode(true);
        $this->assertTrue($t->vimNormalMode);

        // Focus and enter insert mode
        [$t, ] = $t->focus();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'i')); // 'i' enters insert mode
        $this->assertFalse($t->vimNormalMode);

        // Blur should reset to normal mode
        $t = $t->blur();
        $this->assertTrue($t->vimNormalMode);
    }

    public function testVimModeStaysNormalOnBlurWhenAlreadyNormal(): void
    {
        $t = TextInput::new()->withVimMode(true);
        $this->assertTrue($t->vimNormalMode);

        [$t, ] = $t->focus();
        $this->assertTrue($t->vimNormalMode);

        $t = $t->blur();
        $this->assertTrue($t->vimNormalMode);
    }

    public function testPlaceholderShownWithPrefixSuffix(): void
    {
        $t = TextInput::new()
            ->withPrefix('$ ')
            ->withPlaceholder('amount')
            ->withSuffix(' USD');
        $view = $t->view();
        // Should contain prefix, placeholder, and suffix
        $this->assertStringContainsString('$ ', $view);
        $this->assertStringContainsString('amount', $view);
        $this->assertStringContainsString(' USD', $view);
    }

    // ---- vim mode tests ----------------------------------------------------

    public function testVimModeHKeyMovesLeft(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        // In normal mode, 'h' should move left
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'h'));
        $this->assertSame(4, $t->cursorPos);
    }

    public function testVimModeLKeyMovesRight(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        $t = $t->setCursor(0); // Go to start
        // In normal mode, 'l' should move right
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'l'));
        $this->assertSame(1, $t->cursorPos);
    }

    public function testVimModeZeroKeyGoesToStart(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        $t = $t->setCursor(5); // Go to end
        // In normal mode, '0' should go to start
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '0'));
        $this->assertSame(0, $t->cursorPos);
    }

    public function testVimModeDollarKeyGoesToEnd(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        // In normal mode, '$' should go to end
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '$'));
        $this->assertSame(5, $t->cursorPos);
    }

    public function testVimModeIKeyEntersInsertMode(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $this->assertTrue($t->vimNormalMode);
        // 'i' enters insert mode
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertFalse($t->vimNormalMode);
    }

    public function testVimModeEscapeReturnsToNormalMode(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        // Enter insert mode first
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertFalse($t->vimNormalMode);
        // Escape returns to normal mode
        [$t, ] = $t->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($t->vimNormalMode);
    }

    public function testVimModeAKeyAppendsAndEntersInsertMode(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        $t = $t->setCursor(2); // cursor at 'l'
        // 'a' moves cursor right and enters insert mode
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(3, $t->cursorPos); // Cursor moved right
        $this->assertFalse($t->vimNormalMode); // Now in insert mode
    }

    public function testVimModeAKeyAtEndStaysAtEnd(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        $t = $t->setCursor(5); // At end
        // 'a' at end stays at end
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(5, $t->cursorPos);
        $this->assertFalse($t->vimNormalMode);
    }

    public function testVimModeIKeyInsertsAtPosition(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hllo');
        $t = $t->setCursor(1); // cursor at 'l'
        // 'i' enters insert mode at current position
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'i'));
        // Type 'e' to insert
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertSame('hello', $t->value);
        $this->assertSame(2, $t->cursorPos);
    }

    public function testVimModeIKeyAtStart(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        $t = $t->setCursor(0); // At start
        // 'I' inserts at start of line
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'I'));
        $this->assertSame(0, $t->cursorPos);
        $this->assertFalse($t->vimNormalMode);
    }

    public function testVimModeAKeyAppendsAfterCursor(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hell');
        $t = $t->setCursor(4); // At end
        // 'A' appends at end of line and enters insert mode
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'A'));
        // Type 'o' to append
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'o'));
        $this->assertSame('hello', $t->value);
    }

    public function testVimModeXKeyDeletesCharUnderCursor(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        $t = $t->setCursor(1); // cursor at 'e'
        // 'x' deletes character under cursor
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame('hllo', $t->value);
        $this->assertSame(1, $t->cursorPos);
    }

    public function testVimModeXKeyAtStart(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        $t = $t->setCursor(0); // At start
        // 'x' deletes character at cursor
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame('ello', $t->value);
        $this->assertSame(0, $t->cursorPos);
    }

    public function testVimModeXKeyAtEndOfSingleChar(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('a');
        $t = $t->setCursor(0); // At start
        // 'x' on first character
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame('', $t->value);
        $this->assertSame(0, $t->cursorPos);
    }

    public function testVimModeWKeyMovesWordForward(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello world');
        $t = $t->setCursor(0);
        // 'w' moves to start of next word
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertSame(6, $t->cursorPos); // at 'w'
    }

    public function testVimModeBKeyMovesWordBackward(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello world');
        $t = $t->setCursor(7); // at 'w'
        // 'b' moves to start of previous word
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame(6, $t->cursorPos); // at 'w' of 'world'
    }

    public function testVimModeWKeyAtEndNoOp(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        $t = $t->setCursor(5); // At end
        // 'w' at end of line
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertSame(5, $t->cursorPos);
    }

    public function testVimModeBKeyAtStartNoOp(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        $t = $t->setCursor(0); // At start
        // 'b' at start of line
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame(0, $t->cursorPos);
    }

    public function testVimModeDisabledDoesNotProcessVimKeys(): void
    {
        [$t, ] = TextInput::new()->focus(); // vim mode disabled by default
        $t = $t->setValue('hello');
        $t = $t->setCursor(2);
        // 'h' should be inserted when vim mode is off (not move left)
        // Insert at position 2 (between 'e' and 'l') gives 'hehllo'
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'h'));
        $this->assertSame(3, $t->cursorPos); // cursor moved right after insert
        $this->assertSame('hehllo', $t->value); // 'h' was inserted between 'e' and 'l'
    }

    public function testVimModeShortAlias(): void
    {
        $t = TextInput::new()->vimMode(true);
        $this->assertTrue($t->vimMode);
    }

    public function testVimModeWithVimModeTrue(): void
    {
        $t = TextInput::new()->withVimMode(true);
        $this->assertTrue($t->vimMode);
        $this->assertTrue($t->vimNormalMode);
    }

    public function testVimModeWordBoundary(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello   world');
        $t = $t->setCursor(0);
        // Skip word (hello), skip non-word (spaces), land on 'world'
        // h=0,e=1,l=2,l=3,o=4,space=5,space=6,space=7,w=8,...
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertSame(8, $t->cursorPos); // at 'w' of 'world'
    }

    public function testVimModeMultipleWPresses(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('one two three');
        $t = $t->setCursor(0);
        // 'w' multiple times
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertSame(4, $t->cursorPos); // at 't' of 'two'
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'w'));
        $this->assertSame(8, $t->cursorPos); // at 't' of 'three'
    }

    public function testVimModeNormalModeArrowKeysWork(): void
    {
        [$t, ] = TextInput::new()->withVimMode(true)->focus();
        $t = $t->setValue('hello');
        // In normal mode, arrow keys should still work
        [$t, ] = $t->update(new KeyMsg(KeyType::Left));
        $this->assertSame(4, $t->cursorPos);
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        $this->assertSame(5, $t->cursorPos);
    }

    // ═══════════════════════════════════════════════════════════════
    // History navigation tests
    // ═══════════════════════════════════════════════════════════════

    public function testWithHistorySetsHistoryAndResetsIndex(): void
    {
        $t = TextInput::new()->withHistory(['cmd1', 'cmd2', 'cmd3']);
        $this->assertSame(['cmd1', 'cmd2', 'cmd3'], $t->history);
        $this->assertSame(-1, $t->historyIndex);
    }

    public function testHistoryNavigateUpShowsOlderEntry(): void
    {
        [$t, ] = TextInput::new()
            ->withHistory(['first', 'second', 'third'])  // chronological: first=oldest, third=newest
            ->focus();

        // Navigate up - should show 'third' (newest, index 2)
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        $this->assertSame('third', $t->value);
        $this->assertSame(2, $t->historyIndex);  // At newest (index 2)

        // Navigate up again - should show 'second' (index 1)
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        $this->assertSame('second', $t->value);
        $this->assertSame(1, $t->historyIndex);

        // Navigate up again - should show 'first' (index 0)
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        $this->assertSame('first', $t->value);
        $this->assertSame(0, $t->historyIndex);

        // At the oldest entry, should stay there
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        $this->assertSame('first', $t->value);
        $this->assertSame(0, $t->historyIndex);
    }

    public function testHistoryNavigateDownShowsNewerEntry(): void
    {
        [$t, ] = TextInput::new()
            ->withHistory(['first', 'second', 'third'])  // chronological: first=oldest, third=newest
            ->focus();

        // DOWN from current input does nothing
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame('', $t->value);
        $this->assertSame(-1, $t->historyIndex);

        // Go up to enter history at newest (index 2)
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        $this->assertSame('third', $t->value);
        $this->assertSame(2, $t->historyIndex);

        // DOWN from newest returns to current (empty)
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame('', $t->value);
        $this->assertSame(-1, $t->historyIndex);

        // Navigate: UP to enter, UP twice to reach oldest, then DOWN toward newer
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));  // index 2 (newest)
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));  // index 1
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));  // index 0 (oldest)
        $this->assertSame('first', $t->value);
        $this->assertSame(0, $t->historyIndex);

        // DOWN from oldest goes toward newer: index 0 → 1
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame('second', $t->value);
        $this->assertSame(1, $t->historyIndex);

        // DOWN from index 1 goes to newest: index 1 → 2
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame('third', $t->value);
        $this->assertSame(2, $t->historyIndex);

        // DOWN from newest returns to current
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame('', $t->value);
        $this->assertSame(-1, $t->historyIndex);
    }

    public function testHistoryNavigateDownFromCurrentResetsToEmpty(): void
    {
        [$t, ] = TextInput::new()
            ->withHistory(['first', 'second'])
            ->focus();

        // Without navigating up first, down should stay at current (empty)
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame('', $t->value);
        $this->assertSame(-1, $t->historyIndex);
    }

    public function testHistoryLimitTrimsOldEntries(): void
    {
        $t = TextInput::new()
            ->withHistoryLimit(2)
            ->withHistory(['a', 'b', 'c', 'd']);

        // withHistory sets exact history provided; limit only applies to addToHistory
        $this->assertSame(['a', 'b', 'c', 'd'], $t->history);

        // Adding new entry: ['a', 'b', 'c', 'd', 'e'] trimmed to last 2 = ['d', 'e']
        $t = $t->addToHistory('e');
        $this->assertSame(['d', 'e'], $t->history);  // 'a', 'b', 'c' removed

        // Adding more: ['d', 'e', 'f'] trimmed to last 2 = ['e', 'f']
        $t = $t->addToHistory('f');
        $this->assertSame(['e', 'f'], $t->history);  // 'd' removed
    }

    public function testAddToHistoryDeduplicates(): void
    {
        $t = TextInput::new()
            ->withHistory(['cmd1', 'cmd2'])
            ->addToHistory('cmd3');

        // addToHistory adds new entries at end (newest position)
        $this->assertSame(['cmd1', 'cmd2', 'cmd3'], $t->history);

        // Adding existing entry moves it to end (as newest)
        $t = $t->addToHistory('cmd1');
        $this->assertSame(['cmd2', 'cmd3', 'cmd1'], $t->history);
    }

    public function testAddToHistoryRespectsLimit(): void
    {
        $t = TextInput::new()
            ->withHistoryLimit(3)
            ->withHistory(['old1', 'old2']);

        $t = $t->addToHistory('new1');
        // new1 added at end: ['old1', 'old2', 'new1']
        $this->assertSame(['old1', 'old2', 'new1'], $t->history);

        $t = $t->addToHistory('new2');
        // new2 added at end: ['old1', 'old2', 'new1', 'new2'] trimmed to 3 -> ['old2', 'new1', 'new2']
        $this->assertSame(['old2', 'new1', 'new2'], $t->history);

        $t = $t->addToHistory('new3');
        // new3 added at end, old2 pushed out: ['new1', 'new2', 'new3']
        $this->assertSame(['new1', 'new2', 'new3'], $t->history);
    }

    public function testHistoryInVimMode(): void
    {
        [$t, ] = TextInput::new()
            ->withVimMode(true)
            ->withHistory(['vimcmd1', 'vimcmd2'])
            ->focus();

        // In vim normal mode, up arrow should navigate history
        // History is ['vimcmd1', 'vimcmd2'], newest is 'vimcmd2' at index 1
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        $this->assertSame('vimcmd2', $t->value);
        $this->assertSame(1, $t->historyIndex);

        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame('', $t->value);  // Back to current
        $this->assertSame(-1, $t->historyIndex);
    }

    public function testEmptyHistoryDoesNothing(): void
    {
        [$t, ] = TextInput::new()->focus();

        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        $this->assertSame('', $t->value);

        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame('', $t->value);
    }

    public function testHistoryMethodAliases(): void
    {
        $t = TextInput::new()
            ->withHistory(['h1', 'h2'])
            ->withHistoryLimit(10);

        $this->assertSame(['h1', 'h2'], $t->history);
        $this->assertSame(10, $t->historyLimit);
    }
}
