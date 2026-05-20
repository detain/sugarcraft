<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\Overflow;
use PHPUnit\Framework\TestCase;

final class OverflowTest extends TestCase
{
    public function testCases(): void
    {
        $cases = Overflow::cases();
        $this->assertCount(3, $cases);
        $this->assertSame('DropOldest', $cases[0]->name);
        $this->assertSame('DropNewest', $cases[1]->name);
        $this->assertSame('Enqueue', $cases[2]->name);
    }

    public function testDropOldest(): void
    {
        $this->assertSame(Overflow::DropOldest, Overflow::DropOldest);
    }

    public function testDropNewest(): void
    {
        $this->assertSame(Overflow::DropNewest, Overflow::DropNewest);
    }

    public function testEnqueue(): void
    {
        $this->assertSame(Overflow::Enqueue, Overflow::Enqueue);
    }
}
