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
}
