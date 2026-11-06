<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\KeyMap;

/**
 * Tests for {@see KeyMap} + {@see Form::withKeyMap()} — mirrors upstream
 * charmbracelet/huh #272 ("Overriding KeyMaps and KeyBinds").
 */
final class KeyMapTest extends TestCase
{
    public function testDefaultKeyMapMatchesHuhStyleBindings(): void
    {
        $km = KeyMap::new();
        $this->assertTrue($km->isNext(new KeyMsg(KeyType::Tab)));
        $this->assertTrue($km->isNext(new KeyMsg(KeyType::Down)));
        // The canonical retreat is Shift+Tab — on the wire that is CSI 'Z',
        // which sugar-core's InputReader decodes as Tab with shift:true.
        // alt+Tab stays bound as the legacy alias the old default used.
        $this->assertTrue($km->isPrev(new KeyMsg(KeyType::Tab, shift: true)));
        $this->assertTrue($km->isPrev(new KeyMsg(KeyType::Tab, alt: true)));
        $this->assertTrue($km->isPrev(new KeyMsg(KeyType::Up)));
        $this->assertTrue($km->isSubmit(new KeyMsg(KeyType::Enter)));
        $this->assertTrue($km->isAbort(new KeyMsg(KeyType::Escape)));
        $this->assertTrue($km->isAbort(new KeyMsg(KeyType::Char, 'c', ctrl: true)));
    }

    public function testShiftTabMatchesPrevNotNext(): void
    {
        // Form::update() consults `next` before `prev`; the default next-Tab
        // entry pins shift:false so a Shift+Tab message can never be judged
        // an advance (SF-1 order guard).
        $km = KeyMap::new();
        $shiftTab = new KeyMsg(KeyType::Tab, shift: true);
        $this->assertFalse($km->isNext($shiftTab));
        $this->assertTrue($km->isPrev($shiftTab));
    }

    public function testShiftTabWireBytesRetreatThroughTheDecoder(): void
    {
        // End-to-end regression pin: real wire bytes in, focus moves back
        // out. No test in this suite ever sent Tab+shift before SF-1, which
        // is exactly how the dead `prev` binding survived the docs claim.
        $messages = (new InputReader())->parse("\x1b[Z");
        $this->assertCount(1, $messages);
        $key = $messages[0];
        assert($key instanceof KeyMsg);
        $this->assertSame(KeyType::Tab, $key->type);
        $this->assertTrue($key->shift, "CSI 'Z' must decode to Tab + shift");

        $form = Form::new(Input::new('a')->title('A'), Confirm::new('b')->title('B'));
        [$advanced] = $form->update(new KeyMsg(KeyType::Tab));
        assert($advanced instanceof Form);
        $this->assertSame(1, $advanced->focusedIndex);

        [$retreated] = $advanced->update($key);
        assert($retreated instanceof Form);
        $this->assertSame(0, $retreated->focusedIndex, 'Shift+Tab wire bytes must retreat');
    }

    public function testLabelsForDefaultBindings(): void
    {
        $km = KeyMap::new();
        $this->assertSame(['tab', '↓'], $km->labelsFor('next'));
        $this->assertSame(['shift+tab', 'alt+tab', '↑'], $km->labelsFor('prev'));
        $this->assertSame(['enter'], $km->labelsFor('submit'));
        $this->assertSame(['esc', 'ctrl+c'], $km->labelsFor('abort'));
    }

    public function testLabelsForRejectsUnknownBinding(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeyMap::new()->labelsFor('nope');
    }

    public function testKeyBindsDeriveFromActiveKeyMap(): void
    {
        // m21: the help rows describe the map the form actually uses —
        // before SF-1 they were hardcoded strings.
        $custom = KeyMap::new()
            ->withNext([['type' => KeyType::Char, 'rune' => 'j']])
            ->withAbort([['type' => KeyType::Char, 'rune' => 'q']]);
        $form = Form::new(Input::new('a')->title('A'))->withKeyMap($custom);

        $rows = [];
        foreach ($form->keyBinds() as [$label, $keys]) {
            $rows[$label] = $keys;
        }
        $this->assertSame('j', $rows['next'], 'custom next binding must surface in keyBinds');
        $this->assertSame('q', $rows['quit'], 'custom abort binding must surface in keyBinds');
        $this->assertSame('shift+tab / alt+tab / ↑', $rows['prev'], 'untouched prev keeps default labels');
    }

    public function testDefaultDoesNotMatchUnboundKeys(): void
    {
        $km = KeyMap::new();
        $this->assertFalse($km->isNext(new KeyMsg(KeyType::Char, 'j')));
        $this->assertFalse($km->isAbort(new KeyMsg(KeyType::Char, 'q')));
    }

    public function testWithNextReplacesNextBindings(): void
    {
        $km = KeyMap::new()->withNext([
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
        $custom = KeyMap::new()->withNext([
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
        $custom = KeyMap::new()->withNext([
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
        $custom = KeyMap::new()->withAbort([
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
        $km = KeyMap::new();
        $this->assertFalse($km->isAbort(new KeyMsg(KeyType::Char, 'c'))); // no ctrl
        $this->assertTrue($km->isAbort(new KeyMsg(KeyType::Char, 'c', ctrl: true)));
    }
}
