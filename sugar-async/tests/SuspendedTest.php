<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Suspended;

/**
 * @covers \SugarCraft\Async\Suspended
 */
final class SuspendedTest extends TestCase
{
    public function testResumeReturnsClosureResult(): void
    {
        // The resume callable returns a Closure (Cmd is Closure(): ?Msg)
        $innerCmd = static fn(): null => null;
        $suspended = new Suspended(fn() => $innerCmd);

        $result = $suspended->resume();

        $this->assertSame($innerCmd, $result);
        $this->assertInstanceOf(\Closure::class, $result);
    }

    public function testResumeReturnsNullWhenCallableReturnsNull(): void
    {
        $suspended = new Suspended(fn() => null);

        $result = $suspended->resume();

        $this->assertNull($result);
    }

    public function testStateReturnsCarriedOpaqueState(): void
    {
        $state = ['key' => 'value', 'count' => 42];
        $suspended = new Suspended(fn() => null, $state);

        $result = $suspended->state();

        $this->assertSame($state, $result);
    }

    public function testStateDefaultsToNullWhenOmitted(): void
    {
        $suspended = new Suspended(fn() => null);

        $result = $suspended->state();

        $this->assertNull($result);
    }
}
