<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sprinkles\Align;

final class AlignTest extends TestCase
{
    public function testEnumCases(): void
    {
        $cases = Align::cases();
        $this->assertCount(3, $cases);
    }

    public function testLeftCase(): void
    {
        $this->assertSame('left', Align::Left->value);
    }

    public function testCenterCase(): void
    {
        $this->assertSame('center', Align::Center->value);
    }

    public function testRightCase(): void
    {
        $this->assertSame('right', Align::Right->value);
    }

    public function testFromValue(): void
    {
        $this->assertSame(Align::Left, Align::from('left'));
        $this->assertSame(Align::Center, Align::from('center'));
        $this->assertSame(Align::Right, Align::from('right'));
    }

    public function testTryFromInvalidValue(): void
    {
        $this->assertNull(Align::tryFrom('invalid'));
    }
}
