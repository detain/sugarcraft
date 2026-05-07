<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Prompt\Field\Confirm;
use SugarCraft\Prompt\Field\Input;
use SugarCraft\Prompt\Form;
use SugarCraft\Prompt\KeyMap;

/**
 * Tests for {@see KeyMap} + {@see Form::withKeyMap()} — mirrors upstream
 * charmbracelet/huh #272 ("Overriding KeyMaps and KeyBinds").
 */
final class KeyMapTest extends TestCase
{
    public function testDefaultKeyMapMatchesHuhStyleBindings(): void
    {
        $km = KeyMap::default();
        $this->assertTrue($km->isNext(new KeyMsg(KeyType::Tab)));
        $this->assertTrue($km->isNext(new KeyMsg(KeyType::Down)));
        $this->assertTrue($km->isPrev(new KeyMsg(KeyType::Tab, alt: true)));
        $this->assertTrue($km->isPrev(new KeyMsg(KeyType::Up)));
        $this->assertTrue($km->isSubmit(new KeyMsg(KeyType::Enter)));
        $this->assertTrue($km->isAbort(new KeyMsg(KeyType::Escape)));
        $this->assertTrue($km->isAbort(new KeyMsg(KeyType::Char, 'c', ctrl: true)));
    }

    public function testDefaultDoesNotMatchUnboundKeys(): void
    {
        $km = KeyMap::default();
        $this->assertFalse($km->isNext(new KeyMsg(KeyType::Char, 'j')));
        $this->assertFalse($km->isAbort(new KeyMsg(KeyType::Char, 'q')));
    }

    public function testWithNextReplacesNextBindings(): void
    {
        $km = KeyMap::default()->withNext([
            ['type' => KeyType::Char, 'rune' => 'j'],
        ]);
        $this->assertTrue($km->isNext(new KeyMsg(KeyType::Char, 'j')));
        // Tab no longer in the list.
        $this->assertFalse($km->isNext(new KeyMsg(KeyType::Tab)));
    }

    public function testFormUsesActiveKeyMap(): void
    {
        $form = Form::new(Input::new('a')->title('A'), Confirm::new('b')->title('B'));
        // Default should advance on Tab.
        [$next, ] = $form->update(new KeyMsg(KeyType::Tab));
        assert($next instanceof Form);
        $this->assertSame(1, $next->focusedIndex);
    }

    public function testFormWithCustomKeyMapHonorsTheOverride(): void
    {
        $custom = KeyMap::default()->withNext([
            ['type' => KeyType::Char, 'rune' => 'j'],
        ]);
        $form = Form::new(Input::new('a')->title('A'), Confirm::new('b')->title('B'))
            ->withKeyMap($custom);

        // Tab is no longer in the next-list, so it shouldn't advance.
        [$same, ] = $form->update(new KeyMsg(KeyType::Tab));
        assert($same instanceof Form);
        $this->assertSame(0, $same->focusedIndex, 'Tab unbound under custom keymap');

        // 'j' should advance instead.
        [$next, ] = $form->update(new KeyMsg(KeyType::Char, 'j'));
        assert($next instanceof Form);
        $this->assertSame(1, $next->focusedIndex);
    }

    public function testWithKeyMapNullRevertsToDefault(): void
    {
        $custom = KeyMap::default()->withNext([
            ['type' => KeyType::Char, 'rune' => 'j'],
        ]);
        $form = Form::new(Input::new('a')->title('A'), Confirm::new('b')->title('B'))
            ->withKeyMap($custom)
            ->withKeyMap(null); // revert

        [$next, ] = $form->update(new KeyMsg(KeyType::Tab));
        assert($next instanceof Form);
        $this->assertSame(1, $next->focusedIndex, 'reverting to default re-enables Tab');
    }

    public function testActiveKeyMapReturnsDefaultWhenNoneSet(): void
    {
        $form = Form::new(Input::new('a')->title('A'));
        $this->assertInstanceOf(KeyMap::class, $form->activeKeyMap());
        $this->assertTrue($form->activeKeyMap()->isNext(new KeyMsg(KeyType::Tab)));
    }

    public function testCustomAbortKeyAborts(): void
    {
        $custom = KeyMap::default()->withAbort([
            ['type' => KeyType::Char, 'rune' => 'q'],
        ]);
        $form = Form::new(Input::new('a')->title('A'))->withKeyMap($custom);
        [$next, ] = $form->update(new KeyMsg(KeyType::Char, 'q'));
        assert($next instanceof Form);
        $this->assertTrue($next->isAborted(), 'custom abort key should abort the form');
    }

    public function testKeyMapIgnoresModifierMismatch(): void
    {
        // Default abort: Ctrl-c only — bare 'c' should not abort.
        $km = KeyMap::default();
        $this->assertFalse($km->isAbort(new KeyMsg(KeyType::Char, 'c'))); // no ctrl
        $this->assertTrue($km->isAbort(new KeyMsg(KeyType::Char, 'c', ctrl: true)));
    }
}
