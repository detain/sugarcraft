<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Input;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Bits\Input\TextEdit;
use SugarCraft\Top\Input\TextKeys;

/** btop TextEdit::command over sugar-bits TextEdit (proc filter + options editor). */
final class TextKeysTest extends TestCase
{
    private static function key(KeyType $type, string $rune = '', bool $ctrl = false): KeyMsg
    {
        return new KeyMsg($type, $rune, ctrl: $ctrl);
    }

    private static function cmd(?TextEdit $e, KeyMsg $key): ?TextEdit
    {
        return $e === null ? null : TextKeys::apply($e, $key);
    }

    public function testNewPutsTheCaretAtTheEnd(): void
    {
        $e = TextEdit::new('nginx');
        $this->assertSame(['nginx', 5], [$e->text, $e->caret]);
    }

    public function testEditingCommands(): void
    {
        $e = TextEdit::new('ab');
        $e = self::cmd($e, self::key(KeyType::Char, 'c'));
        $this->assertSame(['abc', 3], [$e?->text, $e?->caret]);
        $e = self::cmd(self::cmd($e, self::key(KeyType::Left)), self::key(KeyType::Left));
        $this->assertSame(1, $e?->caret);
        $e = self::cmd($e, self::key(KeyType::Space));
        $this->assertSame(['a bc', 2], [$e?->text, $e?->caret]);
        $e = self::cmd($e, self::key(KeyType::Backspace));
        $this->assertSame(['abc', 1], [$e?->text, $e?->caret]);
        $e = self::cmd($e, self::key(KeyType::Delete));
        $this->assertSame(['ac', 1], [$e?->text, $e?->caret]);
        $e = self::cmd($e, self::key(KeyType::Home));
        $this->assertSame(0, $e?->caret);
        $this->assertSame($e, self::cmd($e, self::key(KeyType::Backspace)), 'backspace at 0 is a no-op');
        $e = self::cmd($e, self::key(KeyType::End));
        $this->assertSame(2, $e?->caret);
        $this->assertSame($e, self::cmd($e, self::key(KeyType::Delete)), 'delete at the end is a no-op');
        $e = self::cmd($e, self::key(KeyType::Right));
        $this->assertSame(2, $e?->caret, 'right clamps');
        $e = self::cmd($e, self::key(KeyType::Char, 'é'));
        $this->assertSame(['acé', 3], [$e?->text, $e?->caret], 'one multibyte cluster');
    }

    public function testNonEditKeysAreRejected(): void
    {
        $e = TextEdit::new('x');
        $this->assertNull(self::cmd($e, self::key(KeyType::Up)));
        $this->assertNull(self::cmd($e, self::key(KeyType::Tab)));
        $this->assertNull(self::cmd($e, self::key(KeyType::Char, 'a', ctrl: true)));
        $this->assertNull(self::cmd($e, self::key(KeyType::Char, 'ab')));
    }

    public function testViewUnderlinesTheCaretCell(): void
    {
        $this->assertSame('ab' . TextEdit::UL . ' ' . TextEdit::UUL, TextEdit::new('ab')->view(10));
        $mid = self::cmd(TextEdit::new('abc'), self::key(KeyType::Left));
        $this->assertSame('ab' . TextEdit::UL . 'c' . TextEdit::UUL, $mid?->view(10));
        $this->assertSame(TextEdit::UL . ' ' . TextEdit::UUL, TextEdit::new('')->view(6));
    }

    public function testViewKeepsTheCaretInsideTheLimit(): void
    {
        $view = TextEdit::new('abcdefghij')->view(6);
        $this->assertSame('fghij' . TextEdit::UL . ' ' . TextEdit::UUL, $view);
        $home = self::cmd(TextEdit::new('abcdefghij'), self::key(KeyType::Home));
        $this->assertSame(TextEdit::UL . 'a' . TextEdit::UUL . 'bcdef', $home?->view(6));
    }

    public function testShiftedCaretKeysAreNotEdits(): void
    {
        $e = TextEdit::new('abc');
        foreach ([KeyType::Left, KeyType::Right, KeyType::Home, KeyType::End] as $type) {
            $this->assertNull(TextKeys::apply($e, new KeyMsg($type, shift: true)), $type->value);
        }
        $this->assertSame(2, TextKeys::apply($e, new KeyMsg(KeyType::Left))?->caret);
    }

    public function testNumericEditorTakesDigitsOnly(): void
    {
        $e = TextEdit::new('20', numeric: true);
        $this->assertNull(TextKeys::apply($e, self::key(KeyType::Char, 'x')));
        $this->assertNull(TextKeys::apply($e, self::key(KeyType::Space)));
        $this->assertSame('205', TextKeys::apply($e, self::key(KeyType::Char, '5'))?->text);
    }
}
