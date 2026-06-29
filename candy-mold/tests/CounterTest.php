<?php

declare(strict_types=1);

namespace App\Tests;

use App\Counter;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
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

    public function testCtrlCDispatchesQuitCmd(): void
    {
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertInstanceOf(Counter::class, $next);
        $this->assertSame(7, $next->n, 'ctrl+c must not mutate count');
        $this->assertNotNull($cmd, 'ctrl+c returns Cmd::quit()');
    }

    public function testEscDispatchesQuitCmd(): void
    {
        [$next, $cmd] = (new Counter(7))->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertInstanceOf(Counter::class, $next);
        $this->assertSame(7, $next->n, 'Esc must not mutate count');
        $this->assertNotNull($cmd, 'Esc returns Cmd::quit()');
    }

    public function testSubscriptionsReturnsNull(): void
    {
        $this->assertNull((new Counter())->subscriptions());
    }

    public function testViewRendersStyledBorder(): void
    {
        $view = (new Counter(42))->view();
        // Rounded border corner glyphs must be present
        $this->assertStringContainsString("\u{256d}", $view, 'top-left corner ╭ missing');
        $this->assertStringContainsString("\u{2570}", $view, 'bottom-left corner ╰ missing');
        $this->assertStringContainsString('42', $view);
    }

    public function testUpdateIsPure(): void
    {
        $start = new Counter(10);
        $start->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(10, $start->n, 'original Counter must remain unchanged');
    }
}
