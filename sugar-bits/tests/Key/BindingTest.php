<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\Key;

use CandyCore\Bits\Key\Binding;
use CandyCore\Bits\Key\Help;
use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class BindingTest extends TestCase
{
    public function testMatchesNamedKey(): void
    {
        $b = new Binding(['up', 'k']);
        $this->assertTrue($b->matches(new KeyMsg(KeyType::Up)));
        $this->assertTrue($b->matches(new KeyMsg(KeyType::Char, 'k')));
        $this->assertFalse($b->matches(new KeyMsg(KeyType::Char, 'j')));
    }

    public function testMatchesCtrlModifier(): void
    {
        $b = new Binding(['ctrl+c']);
        $this->assertTrue($b->matches(new KeyMsg(KeyType::Char, 'c', ctrl: true)));
        $this->assertFalse($b->matches(new KeyMsg(KeyType::Char, 'c')));
    }

    public function testDisabledNeverMatches(): void
    {
        $b = (new Binding(['q']))->disable();
        $this->assertFalse($b->matches(new KeyMsg(KeyType::Char, 'q')));
    }

    public function testWithHelpReturnsCopyWithLabel(): void
    {
        $b = new Binding(['q']);
        $b2 = $b->withHelp('q', 'quit');
        $this->assertNotSame($b, $b2);
        $this->assertSame('q',    $b2->help->key);
        $this->assertSame('quit', $b2->help->desc);
        $this->assertSame('',     $b->help->key); // original untouched
    }

    public function testHelpDefaults(): void
    {
        $h = new Help();
        $this->assertSame('', $h->key);
        $this->assertSame('', $h->desc);
    }

    public function testSetKeysReplaces(): void
    {
        $b = new Binding(['up'], new Help('↑', 'up'));
        $b2 = $b->setKeys(['k']);
        $this->assertSame(['k'], $b2->getKeys());
        $this->assertSame(['up'], $b->getKeys()); // immutable
    }

    public function testSetHelpAlias(): void
    {
        $b = new Binding(['up']);
        $b2 = $b->setHelp('↑/k', 'move up');
        $this->assertSame('↑/k',     $b2->getHelp()->key);
        $this->assertSame('move up', $b2->getHelp()->desc);
    }

    public function testEnabledReflectsDisabledFlag(): void
    {
        $b = new Binding(['up']);
        $this->assertTrue($b->enabled());
        $disabled = $b->disable();
        $this->assertFalse($disabled->enabled());
    }

    public function testSetEnabledFlipsFlag(): void
    {
        $b = (new Binding(['up']))->disable();
        $on = $b->setEnabled(true);
        $this->assertTrue($on->enabled());
        $off = $on->setEnabled(false);
        $this->assertFalse($off->enabled());
    }

    public function testUnbindClearsKeys(): void
    {
        $b = new Binding(['up', 'k'], new Help('↑/k', 'up'));
        $u = $b->unbind();
        $this->assertSame([], $u->getKeys());
        $this->assertSame('↑/k', $u->getHelp()->key);
    }

    public function testAnyMatchesAcrossBindings(): void
    {
        $up   = new Binding(['up', 'k']);
        $down = new Binding(['down', 'j']);
        $key  = new KeyMsg(KeyType::Down);
        $this->assertTrue(Binding::any($key, $up, $down));

        $j = new KeyMsg(KeyType::Char, 'j');
        $this->assertTrue(Binding::any($j, $up, $down));

        $other = new KeyMsg(KeyType::Char, 'x');
        $this->assertFalse(Binding::any($other, $up, $down));
    }

    public function testAnySkipsDisabled(): void
    {
        $down  = (new Binding(['down', 'j']))->disable();
        $other = new Binding(['other']);
        $j = new KeyMsg(KeyType::Char, 'j');
        $this->assertFalse(Binding::any($j, $down, $other));
    }

    public function testWithDisabledFactory(): void
    {
        $b = Binding::withDisabled(['?'], new Help('?', 'help'));
        $this->assertFalse($b->enabled());
        $this->assertSame(['?'], $b->getKeys());
        $this->assertSame('?', $b->getHelp()->key);
        $this->assertFalse($b->matches(new KeyMsg(KeyType::Char, '?')));
    }

    public function testNewFactoryAcceptsDefaults(): void
    {
        $b = Binding::new();
        $this->assertSame([], $b->getKeys());
        $this->assertTrue($b->enabled());

        $c = Binding::new(keys: ['ctrl+c'], help: new Help('ctrl+c', 'quit'));
        $this->assertTrue($c->matches(new KeyMsg(KeyType::Char, 'c', ctrl: true)));
    }
}
