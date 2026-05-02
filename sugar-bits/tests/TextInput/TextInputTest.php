<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\TextInput;

use CandyCore\Bits\TextInput\EchoMode;
use CandyCore\Bits\TextInput\TextInput;
use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
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
        $this->assertSame('> type here…', $t->view());
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
}
