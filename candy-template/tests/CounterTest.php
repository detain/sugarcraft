<?php

declare(strict_types=1);

namespace App\Tests;

use App\Counter;
use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Msg\WindowSizeMsg;
use PHPUnit\Framework\TestCase;

final class CounterTest extends TestCase
{
    public function testStartsAtZero(): void
    {
        $this->assertSame(0, (new Counter())->n);
    }

    public function testUpIncrementsCount(): void
    {
        [$next, $cmd] = (new Counter())->update(new KeyMsg(KeyType::Up, ''));
        $this->assertInstanceOf(Counter::class, $next);
        $this->assertSame(1, $next->n);
        $this->assertNull($cmd);
    }

    public function testDownDecrementsCount(): void
    {
        [$next] = (new Counter(5))->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(4, $next->n);
    }

    public function testQuitDispatchesQuitCmd(): void
    {
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertInstanceOf(Counter::class, $next);
        $this->assertSame(7, $next->n, 'quit must not mutate count');
        $this->assertNotNull($cmd, 'quit returns Cmd::quit()');
    }

    public function testNonKeyMessageIgnored(): void
    {
        [$next, $cmd] = (new Counter(3))->update(new WindowSizeMsg(80, 24));
        $this->assertSame(3, $next->n);
        $this->assertNull($cmd);
    }

    public function testInitReturnsNoCmd(): void
    {
        $this->assertNull((new Counter())->init());
    }

    public function testViewContainsCount(): void
    {
        $view = (new Counter(42))->view();
        $this->assertStringContainsString('42', $view);
        $this->assertStringContainsString('q to quit', $view);
    }

    public function testUpdateIsPure(): void
    {
        $start = new Counter(10);
        $start->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(10, $start->n, 'original Counter must remain unchanged');
    }
}
