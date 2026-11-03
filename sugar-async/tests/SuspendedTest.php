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

    public function testTheFactoryMirrorsTheConstructor(): void
    {
        $innerCmd = static fn(): null => null;
        $suspended = Suspended::new(fn() => $innerCmd, 'carry-me');

        $this->assertSame($innerCmd, $suspended->resume());
        $this->assertSame('carry-me', $suspended->state());
    }

    public function testAnyCallableIsAdmittedAndNormalised(): void
    {
        // A plain string callable is not a Closure; the seam admits it.
        $suspended = Suspended::new(StringResumer::class . '::toCmd');

        $this->assertSame('cmd', ($suspended->resume())());
    }

    public function testResumeIsOneShotSoSharedHandlesCannotDoubleFire(): void
    {
        $runs = 0;
        $suspended = new Suspended(
            static function () use (&$runs): \Closure {
                $runs++;
                return static fn(): null => null;
            },
        );

        $this->assertInstanceOf(\Closure::class, $suspended->resume());
        $this->assertNull($suspended->resume());
        $this->assertSame(1, $runs, 'the resume callable runs at most once');
    }
}

final class StringResumer
{
    public static function toCmd(): \Closure
    {
        return static fn (): string => 'cmd';
    }
}
