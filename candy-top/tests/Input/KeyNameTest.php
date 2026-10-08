<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Input;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Top\Input\KeyName;

/** btop's key / mouse vocabulary. */
final class KeyNameTest extends TestCase
{
    public function testKeysUseBtopNames(): void
    {
        $cases = [
            'up' => new KeyMsg(KeyType::Up), 'page_down' => new KeyMsg(KeyType::PageDown), 'page_up' => new KeyMsg(KeyType::PageUp),
            'home' => new KeyMsg(KeyType::Home), 'end' => new KeyMsg(KeyType::End), 'enter' => new KeyMsg(KeyType::Enter),
            'escape' => new KeyMsg(KeyType::Escape), 'backspace' => new KeyMsg(KeyType::Backspace), 'space' => new KeyMsg(KeyType::Space, ' '),
            'delete' => new KeyMsg(KeyType::Delete), 'insert' => new KeyMsg(KeyType::Insert), 'tab' => new KeyMsg(KeyType::Tab),
            'shift_tab' => new KeyMsg(KeyType::Tab, shift: true), 'f1' => new KeyMsg(KeyType::F1), 'f2' => new KeyMsg(KeyType::F2),
            'down' => new KeyMsg(KeyType::Down), 'left' => new KeyMsg(KeyType::Left), 'right' => new KeyMsg(KeyType::Right),
            'K' => new KeyMsg(KeyType::Char, 'K'), '?' => new KeyMsg(KeyType::Char, '?'),
            '' => new KeyMsg(KeyType::Char, 'c', ctrl: true),
        ];
        foreach ($cases as $name => $msg) {
            $this->assertSame((string) $name, KeyName::of($msg));
        }
        $this->assertSame('', KeyName::of(new KeyMsg(KeyType::F5)), 'unmapped keys have no name');
        $this->assertSame('', KeyName::of(new WindowSizeMsg(1, 1)));
    }

    public function testMouseNamesOnlyForBareEvents(): void
    {
        $m = static fn (MouseButton $b, MouseAction $a, bool $shift = false): MouseMsg => new MouseMsg(3, 4, $b, $a, $shift);
        $this->assertSame('mouse_click', KeyName::of($m(MouseButton::Left, MouseAction::Press)));
        $this->assertSame('mouse_scroll_up', KeyName::of($m(MouseButton::WheelUp, MouseAction::Press)));
        $this->assertSame('mouse_scroll_down', KeyName::of($m(MouseButton::WheelDown, MouseAction::Press)));
        $this->assertSame('mouse_release', KeyName::of($m(MouseButton::Left, MouseAction::Release)));
        $this->assertSame('mouse_drag', KeyName::of($m(MouseButton::Left, MouseAction::Motion)));
        $this->assertSame('', KeyName::of($m(MouseButton::Right, MouseAction::Press)));
        $this->assertSame('', KeyName::of($m(MouseButton::Left, MouseAction::Press, true)));
    }

    public function testMappedResolvesClicksAndDragsInsideARectangle(): void
    {
        $map = ['button_1' => [2, 3, 4, 2]];
        $this->assertSame('button_1', KeyName::mapped(new MouseMsg(3, 4, MouseButton::Left, MouseAction::Press), $map), '1-based (3,4) is cell (2,3)');
        $this->assertSame('button_1', KeyName::mapped(new MouseMsg(6, 5, MouseButton::Left, MouseAction::Motion), $map));
        $this->assertSame('mouse_click', KeyName::mapped(new MouseMsg(7, 4, MouseButton::Left, MouseAction::Press), $map));
        $this->assertSame('mouse_scroll_up', KeyName::mapped(new MouseMsg(3, 4, MouseButton::WheelUp, MouseAction::Press), $map), 'only clicks and drags are mapped');
        $this->assertSame('q', KeyName::mapped(new KeyMsg(KeyType::Char, 'q'), $map));
    }
}
