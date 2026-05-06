<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\TextArea;

use CandyCore\Bits\TextArea\TextArea;
use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class TextAreaTest extends TestCase
{
    private function focused(string $initial = ''): TextArea
    {
        $t = TextArea::new();
        if ($initial !== '') {
            $t = $t->setValue($initial);
        }
        [$t, ] = $t->focus();
        return $t;
    }

    public function testInitialState(): void
    {
        $t = TextArea::new();
        $this->assertSame([''], $t->lines);
        $this->assertSame(0, $t->row);
        $this->assertSame(0, $t->col);
        $this->assertSame('', $t->value());
    }

    public function testInsertChar(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'h'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertSame('hi', $t->value());
        $this->assertSame(2, $t->col);
    }

    public function testEnterSplitsLine(): void
    {
        $t = $this->focused('hello');
        [$t, ] = $t->update(new KeyMsg(KeyType::Home));
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        // cursor between 'e' and 'l'
        [$t, ] = $t->update(new KeyMsg(KeyType::Enter));
        $this->assertSame("he\nllo", $t->value());
        $this->assertSame(1, $t->row);
        $this->assertSame(0, $t->col);
    }

    public function testBackspaceMergesLines(): void
    {
        $t = $this->focused("ab\ncd");
        [$t, ] = $t->update(new KeyMsg(KeyType::Home));
        // cursor at start of line 1 (the second line)
        [$t, ] = $t->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame("abcd", $t->value());
        $this->assertSame(0, $t->row);
        $this->assertSame(2, $t->col);
    }

    public function testDeleteForwardMergesLines(): void
    {
        $t = $this->focused("ab\ncd");
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a', ctrl: true)); // line start
        [$t, ] = $t->update(new KeyMsg(KeyType::Up)); // first line
        [$t, ] = $t->update(new KeyMsg(KeyType::End));
        [$t, ] = $t->update(new KeyMsg(KeyType::Delete));
        $this->assertSame('abcd', $t->value());
    }

    public function testArrowsCrossLines(): void
    {
        $t = $this->focused("ab\ncd");
        [$t, ] = $t->update(new KeyMsg(KeyType::Home));
        // cursor at (1,0)
        [$t, ] = $t->update(new KeyMsg(KeyType::Left));
        // should cross to end of previous line
        $this->assertSame(0, $t->row);
        $this->assertSame(2, $t->col);
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        $this->assertSame(1, $t->row);
        $this->assertSame(0, $t->col);
    }

    public function testUpDownPreservesColumn(): void
    {
        $t = $this->focused("abcdef\nxy");
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        $this->assertSame(0, $t->row);
        $this->assertSame(2, $t->col); // clamped from 2 (was at end of "xy")
    }

    public function testTabInsertsFourSpaces(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        $this->assertSame('    ', $t->value());
        $this->assertSame(4, $t->col);
    }

    public function testCtrlAAndCtrlELine(): void
    {
        $t = $this->focused('hello');
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a', ctrl: true));
        $this->assertSame(0, $t->col);
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'e', ctrl: true));
        $this->assertSame(5, $t->col);
    }

    public function testCtrlUDeletesToLineStart(): void
    {
        $t = $this->focused("one\ntwo");
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'u', ctrl: true));
        $this->assertSame("one\n", $t->value());
        $this->assertSame(1, $t->row);
        $this->assertSame(0, $t->col);
    }

    public function testCtrlKDeletesToLineEnd(): void
    {
        $t = $this->focused('hello');
        [$t, ] = $t->update(new KeyMsg(KeyType::Home));
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'k', ctrl: true));
        $this->assertSame('h', $t->value());
    }

    public function testMultibyte(): void
    {
        $t = $this->focused('日本');
        [$t, ] = $t->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame('日', $t->value());
    }

    public function testCharLimit(): void
    {
        $t = TextArea::new()->withCharLimit(3);
        [$t, ] = $t->focus();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'b'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'c'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame('abc', $t->value());
    }

    public function testPlaceholderShownWhenEmptyAndUnfocused(): void
    {
        $t = TextArea::new()->withPlaceholder('type something…');
        $this->assertSame('type something…', $t->view());
    }

    public function testIgnoresKeysWhenUnfocused(): void
    {
        $t = TextArea::new();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('', $t->value());
    }

    public function testReset(): void
    {
        $t = $this->focused("a\nb")->reset();
        $this->assertSame('', $t->value());
        $this->assertSame(0, $t->row);
        $this->assertSame(0, $t->col);
    }

    public function testLineCount(): void
    {
        $t = $this->focused("one\ntwo\nthree");
        $this->assertSame(3, $t->lineCount());
    }

    public function testSetValueClampsToCharLimit(): void
    {
        $t = TextArea::new()->withCharLimit(5)->setValue("hello world");
        $this->assertSame('hello', $t->value());
    }

    public function testShowLineNumbersAddsGutter(): void
    {
        $t = TextArea::new()
            ->setValue("a\nb\nc")
            ->showLineNumbers();
        $out = $t->view();
        $this->assertStringContainsString(' 1 a', $out);
        $this->assertStringContainsString(' 2 b', $out);
        $this->assertStringContainsString(' 3 c', $out);
    }

    public function testEndOfBufferFillerAppearsBelowContent(): void
    {
        $t = TextArea::new()
            ->setValue('hi')
            ->withHeight(4);
        $out = $t->view();
        $lines = explode("\n", $out);
        $this->assertSame('hi', $lines[0]);
        $this->assertSame('~',  $lines[1]);
        $this->assertSame('~',  $lines[2]);
        $this->assertSame('~',  $lines[3]);
    }

    public function testWithEndOfBufferCharacterReplacesFiller(): void
    {
        $t = TextArea::new()
            ->setValue('x')
            ->withHeight(2)
            ->withEndOfBufferCharacter('*');
        $out = $t->view();
        $this->assertSame("x\n*", $out);
    }

    public function testWithPromptPrefixesEachLine(): void
    {
        $t = TextArea::new()
            ->setValue("a\nb")
            ->withPrompt('| ');
        $this->assertSame("| a\n| b", $t->view());
    }

    public function testValidatorRunsOnEdit(): void
    {
        $t = TextArea::new()
            ->withValidator(static fn(string $v): ?string
                => str_contains($v, 'bad') ? 'no' : null)
            ->setValue('good text');
        $this->assertNull($t->err());
        $t = $t->setValue('bad text');
        $this->assertSame('no', $t->err());
    }

    public function testInsertStringSplitsOnNewlines(): void
    {
        $t = TextArea::new()->setValue('start')->setCursorColumn(5);
        $t = $t->insertString("\nmiddle\nend");
        $this->assertSame("start\nmiddle\nend", $t->value());
    }

    public function testLineInfo(): void
    {
        $t = TextArea::new()->setValue("abc\ndef");
        $info = $t->lineInfo();
        $this->assertSame(1, $info['row']);
        $this->assertSame(3, $info['col']);
        $this->assertSame(2, $info['totalLines']);
    }

    public function testCursorUpDownClampsAtBoundaries(): void
    {
        $t = TextArea::new()->setValue("abc\ndef\nghi")->setCursor(0, 1);
        $t = $t->cursorDown();
        $this->assertSame(1, $t->row);
        $this->assertSame(1, $t->col);
        $t = $t->cursorDown()->cursorDown();
        $this->assertSame(2, $t->row); // clamped at last row
        $t = $t->cursorUp();
        $this->assertSame(1, $t->row);
        $t = $t->cursorUp()->cursorUp();
        $this->assertSame(0, $t->row); // clamped at first row
    }

    public function testMoveToBeginAndEnd(): void
    {
        $t = TextArea::new()->setValue("abc\ndef\nghi")->setCursor(1, 2);
        $t = $t->moveToBegin();
        $this->assertSame(0, $t->row);
        $this->assertSame(0, $t->col);
        $t = $t->moveToEnd();
        $this->assertSame(2, $t->row);
        $this->assertSame(3, $t->col); // 'ghi' length
    }

    public function testPageUpDownUsesHeight(): void
    {
        // setValue() leaves the cursor at the last row; reset to (0,0).
        $t = TextArea::new()->withHeight(3)
            ->setValue("a\nb\nc\nd\ne\nf\ng\nh\ni")
            ->moveToBegin();
        $down = $t->pageDown();
        $this->assertSame(3, $down->row);
        $down2 = $down->pageDown();
        $this->assertSame(6, $down2->row);
        $down3 = $down2->pageDown()->pageDown();
        $this->assertSame(8, $down3->row); // clamped at last row
        $up = $down2->pageUp();
        $this->assertSame(3, $up->row);
    }

    public function testPageUpDownFallsBackToOneRowWhenHeightZero(): void
    {
        $t = TextArea::new()->setValue("a\nb\nc")->setCursor(1, 0);
        $this->assertSame(0, $t->pageUp()->row);
        $this->assertSame(2, $t->pageDown()->row);
    }

    public function testInsertRuneInsertsSingleCharacter(): void
    {
        $t = TextArea::new()->setValue('ac')->setCursorColumn(1);
        $t = $t->insertRune('b');
        $this->assertSame('abc', $t->value());
        $this->assertSame(2, $t->col);
    }

    public function testInsertRuneAcceptsMultibyteCluster(): void
    {
        $t = TextArea::new()->setValue('a')->setCursorColumn(1);
        $t = $t->insertRune('日');
        $this->assertSame('a日', $t->value());
    }

    public function testWordReturnsCurrentWord(): void
    {
        $t = TextArea::new()->setValue('hello world')->setCursorColumn(2);
        $this->assertSame('hello', $t->word());
        // Cursor on the space → no word.
        $t = TextArea::new()->setValue('hello world')->setCursorColumn(5);
        $this->assertSame('', $t->word());
        // Cursor in second word.
        $t = TextArea::new()->setValue('hello world')->setCursorColumn(7);
        $this->assertSame('world', $t->word());
    }

    public function testWordHandlesEmptyLine(): void
    {
        $this->assertSame('', TextArea::new()->word());
    }

    public function testSetPromptFuncWinsOverStaticPrompt(): void
    {
        $t = TextArea::new()
            ->setValue("a\nb\nc")
            ->withPrompt('> ')
            ->withHeight(3)
            ->setPromptFunc(fn(int $row, string $line) => sprintf('[%d] ', $row));
        $rendered = $t->view();
        $this->assertStringContainsString('[0] a', $rendered);
        $this->assertStringContainsString('[1] b', $rendered);
        $this->assertStringContainsString('[2] c', $rendered);
        $this->assertStringNotContainsString('> a', $rendered);
    }

    public function testSetPromptFuncNullRevertsToStaticPrompt(): void
    {
        $t = TextArea::new()
            ->setValue('hi')
            ->withPrompt('> ')
            ->setPromptFunc(fn() => '!! ')
            ->setPromptFunc(null);
        $this->assertStringContainsString('> hi', $t->view());
    }
}
