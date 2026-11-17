<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\ChildCapture;
use SugarCraft\Async\DrainResult;

final class ChildCaptureTest extends TestCase
{
    public function testKeepsTheHeadUpToTheCeilingAndCountsTheRest(): void
    {
        $c = new ChildCapture([1], [], 5);
        $c->offer(1, 'abc');
        $c->offer(1, 'defgh');
        $r = $c->result(DrainResult::CLOSED);

        $this->assertSame('abcde', $r->buffer(1));
        $this->assertSame(3, $r->droppedBytes(1));
        $this->assertTrue($r->truncated());
    }

    public function testOnceFullEveryFurtherByteIsRefused(): void
    {
        $c = new ChildCapture([1], [], 2);
        $c->offer(1, 'ab');
        $c->offer(1, 'cd');
        $c->offer(1, 'e');

        $this->assertSame('ab', $c->result(DrainResult::CLOSED)->buffer(1));
        $this->assertSame(3, $c->result(DrainResult::CLOSED)->droppedBytes(1));
    }

    public function testDiscardedSlotsAreNeverKeptButTheLiveFeedSeesThem(): void
    {
        $seen = [];
        $c = new ChildCapture([1, 2], [2], 100, static function (int|string $slot, string $bytes) use (&$seen): void {
            $seen[] = [$slot, $bytes];
        });
        $c->offer(1, 'out');
        $c->offer(2, 'err');
        $r = $c->result(DrainResult::CLOSED);

        $this->assertSame('out', $r->buffer(1));
        $this->assertSame('', $r->buffer(2));
        $this->assertSame(0, $r->droppedBytes(2));
        $this->assertSame([[1, 'out'], [2, 'err']], $seen);
    }

    public function testAnyFullReportsTheFirstStreamToReachTheCeiling(): void
    {
        $c = new ChildCapture([1, 2], [], 4);
        $this->assertFalse($c->anyFull());
        $c->offer(2, 'abcd');
        $this->assertTrue($c->anyFull());
    }

    public function testZeroCeilingKeepsNothingAndCountsEverything(): void
    {
        $c = new ChildCapture([1], [], 0);
        $c->offer(1, 'xyz');
        $r = $c->result(DrainResult::CLOSED);

        $this->assertSame('', $r->buffer(1));
        $this->assertSame(3, $r->droppedBytes(1));
        $this->assertTrue($c->anyFull(), 'a zero ceiling is full before the first byte');
    }
}
