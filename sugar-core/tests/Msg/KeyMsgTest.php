<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Msg;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Modifiers;
use SugarCraft\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class KeyMsgTest extends TestCase
{
    public function testStringForPrintableChar(): void
    {
        $msg = new KeyMsg(KeyType::Char, 'a');
        $this->assertSame('a', $msg->string());
    }

    public function testStringForNamedKey(): void
    {
        $msg = new KeyMsg(KeyType::Up);
        $this->assertSame('up', $msg->string());
    }

    public function testStringWithCtrlPrefix(): void
    {
        $msg = new KeyMsg(KeyType::Char, 'c', ctrl: true);
        $this->assertSame('ctrl+c', $msg->string());
    }

    public function testStringWithAllModifiers(): void
    {
        $msg = new KeyMsg(KeyType::Char, 'k', alt: true, ctrl: true, shift: true);
        $this->assertSame('ctrl+alt+shift+k', $msg->string());
    }

    public function testTextReturnsRuneForChar(): void
    {
        $msg = new KeyMsg(KeyType::Char, 'x');
        $this->assertSame('x', $msg->text());
    }

    public function testTextEmptyForNamedKey(): void
    {
        $msg = new KeyMsg(KeyType::Enter);
        $this->assertSame('', $msg->text());
    }

    public function testCodeReturnsType(): void
    {
        $msg = new KeyMsg(KeyType::PageUp);
        $this->assertSame(KeyType::PageUp, $msg->code());
    }

    public function testModifiersBundlesFlags(): void
    {
        $msg = new KeyMsg(KeyType::Char, 'a', alt: true, ctrl: false, shift: true);
        $mods = $msg->modifiers();
        $this->assertInstanceOf(Modifiers::class, $mods);
        $this->assertTrue($mods->alt);
        $this->assertFalse($mods->ctrl);
        $this->assertTrue($mods->shift);
    }

    public function testDefaultsHaveNoModifiers(): void
    {
        $msg = new KeyMsg(KeyType::Char, 'a');
        $this->assertFalse($msg->alt);
        $this->assertFalse($msg->ctrl);
        $this->assertFalse($msg->shift);
        $this->assertTrue($msg->modifiers()->isEmpty());
    }
}
