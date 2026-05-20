<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Prompt\Field\MultiSelect;
use PHPUnit\Framework\TestCase;

final class MultiSelectVimKeysTest extends TestCase
{
    private function focused(): MultiSelect
    {
        $f = MultiSelect::new('foods')->withOptions('Pizza', 'Burger', 'Salad');
        [$f, ] = $f->focus();
        return $f;
    }

    public function testJKeyMovesCursorDown(): void
    {
        $f = $this->focused();
        $this->assertSame(0, $f->cursor);
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $f->cursor);
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(2, $f->cursor);
    }

    public function testKKeyMovesCursorUp(): void
    {
        $f = $this->focused();
        // Move to end first.
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'G'));
        $this->assertSame(2, $f->cursor);
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertSame(1, $f->cursor);
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertSame(0, $f->cursor);
    }

    public function testJKeyClampsAtLastOption(): void
    {
        $f = $this->focused();
        for ($i = 0; $i < 10; $i++) {
            [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'j'));
        }
        $this->assertSame(2, $f->cursor);
    }

    public function testKKeyClampsAtFirstOption(): void
    {
        $f = $this->focused();
        $this->assertSame(0, $f->cursor);
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertSame(0, $f->cursor);
    }

    public function testJKeyTogglesCurrentSelection(): void
    {
        $f = $this->focused();
        // Move down with j then toggle with space.
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $f->cursor);
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertSame(['Burger'], $f->value());
    }

    public function testConsumesJKey(): void
    {
        $f = $this->focused();
        $this->assertTrue($f->consumes(new KeyMsg(KeyType::Char, 'j')));
    }

    public function testConsumesKKey(): void
    {
        $f = $this->focused();
        $this->assertTrue($f->consumes(new KeyMsg(KeyType::Char, 'k')));
    }

    public function testDoesNotConsumeJKeyWhenUnfocused(): void
    {
        $f = MultiSelect::new('x')->withOptions('a', 'b');
        $this->assertFalse($f->consumes(new KeyMsg(KeyType::Char, 'j')));
    }
}
